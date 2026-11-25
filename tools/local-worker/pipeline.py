"""Three resumable local Codex stages. No network or model calls during import/setup."""
import datetime
import hashlib
import json
import os
import re
from pathlib import Path
import subprocess
import urllib.parse


def obj(properties):
    return {'type': 'object', 'additionalProperties': False, 'required': list(properties), 'properties': properties}


def string(maximum=3000):
    return {'type': 'string', 'maxLength': maximum}


def array(items, maximum=30):
    return {'type': 'array', 'items': items, 'maxItems': maximum}


LANGUAGE = {'type': 'string', 'enum': ['pl', 'uk', 'en']}
LINK = obj({'url': string(2000), 'label': string(160), 'category': {'type': 'string', 'enum': ['certificate', 'code', 'linkedin', 'social', 'portfolio', 'website', 'email', 'other']}})
REPORT = obj({'source': string(40), 'status': {'type': 'string', 'enum': ['checked', 'no_results', 'blocked', 'not_checked']}, 'details': string(), 'checked_urls': array(string(2000), 20)})
ADAPTATION = obj({'kind': {'type': 'string', 'enum': ['wording', 'inference']}, 'description': string(), 'basis': string()})
IDENTITY = {'request_id': string(32), 'source_sha256': string(64)}
VACANCY = {'source': string(40), **{key: string() for key in ['title', 'company', 'url', 'location', 'salary', 'fit', 'gaps', 'checked_at']}}
PROFILE_SCHEMA = obj({**IDENTITY, 'resume_language': {'type': 'string', 'enum': ['pl', 'uk', 'en', 'other']}, 'output_language': LANGUAGE,
    'summary': string(6000), 'facts': array(obj({'claim': string(), 'quote': string()}), 100), 'links': array(LINK, 100), 'constraints': array(string()), 'uncertainties': array(string())})
SEARCH_SCHEMA = obj({**IDENTITY, 'language': LANGUAGE, 'source_report': array(REPORT, 8),
    'vacancies': array(obj({**VACANCY, 'description': string(12000)}), 10)})
RESULT_SCHEMA = obj({**IDENTITY, 'protocol_version': {'type': 'integer', 'enum': [2]}, 'language': LANGUAGE, 'summary': string(12000),
    'candidate_links': array(LINK, 100), 'source_report': array(REPORT, 8),
    'matches': array(obj({**VACANCY, 'conclusion': string(5000), 'cv_text': string(20000), 'evidence': array(string()), 'adaptations': array(ADAPTATION)}), 10)})
STAGES = [
    ('01-profile.json', 'profile.schema.json', '01-Intake.md', PROFILE_SCHEMA, 'disabled',
     'Execute ONLY stage 1. Read package.json text and link metadata; ignore photos/images and do not open the PDF as an image. '
     'Return 01-profile.json only. Use output_language from the explicit choice, otherwise the detected CV language (or output_language_hint for other/ambiguous languages). Do not search vacancies yet.'),
    ('02-vacancies.json', 'vacancies.schema.json', '02-Search.md', SEARCH_SCHEMA, 'live',
     'Execute ONLY stage 2. Read package.json and 01-profile.json. Search the selected sites and save verified responsibilities and requirements '
     'as plain description text for each vacancy so stage 3 can tailor CVs without guessing job requirements. Explain in output_language. '
     'Report every selected source honestly. Do not tailor CVs yet. Return 02-vacancies.json only.'),
    ('result.json', 'result.schema.json', '03-Adapt.md', RESULT_SCHEMA, 'disabled',
     'Execute ONLY stage 3. Read package.json, 01-profile.json and 02-vacancies.json. Produce complete, vacancy-specific CVs and precise conclusions. '
     'Respect adaptation_mode. Copy language and source_report from stage 2. Preserve source URLs and meaningful link classifications. '
     'Keep each vacancy title/company/URL/source and checked_at from stage 2. Return result.json only. Do not browse or publish anything.'),
]


class PipelineError(ValueError):
    pass


def validate_shape(schema, value):
    kind = schema['type']
    valid = {'object': isinstance(value, dict), 'array': isinstance(value, list), 'string': isinstance(value, str), 'integer': type(value) is int}[kind]
    if not valid or ('enum' in schema and value not in schema['enum']) or ('const' in schema and value != schema['const']):
        raise PipelineError('Stage output does not match its schema; private output preserved.')
    if kind == 'object':
        if set(value) != set(schema['required']):
            raise PipelineError('Stage output has missing or unexpected fields; private output preserved.')
        for key, child in schema['properties'].items():
            validate_shape(child, value[key])
    elif kind == 'array':
        if len(value) > schema['maxItems']:
            raise PipelineError('Stage output exceeds its item limit.')
        for item in value:
            validate_shape(schema['items'], item)
    elif kind == 'string' and len(value) > schema.get('maxLength', 100000):
        raise PipelineError('Stage output exceeds its text limit.')


def prepare(folder, package, atomic, save_json):
    for filename, schema_name, task_name, schema, search, task in STAGES:
        save_json(folder / schema_name, schema)
        prompt = package['instructions'] + '\n\nCURRENT INVOCATION\n' + task + '\n'
        prompt += 'Request ID: ' + package['request_id'] + '\nSource SHA256: ' + package['input']['source_sha256'] + '\n'
        atomic(folder / task_name, prompt.encode('utf-8'))


def identity(data, package):
    if data['request_id'] != package['request_id'] or data['source_sha256'] != package['input']['source_sha256']:
        raise PipelineError('A stage belongs to another request or CV; no next stage was run.')


def check_contract(stage, data, package, folder):
    identity(data, package)
    inputs = package['input']
    selected = package['selected_sources']
    source_text=re.sub(r'\s+',' ',inputs['resume_text'])
    def source_quote(value):
        if len(value)<8 or re.sub(r'\s+',' ',value) not in source_text:
            raise PipelineError('A purported source quote is absent from the source CV.')
    if stage == 0:
        requested = inputs['requested_language']
        expected = requested if requested != 'auto' else data['resume_language']
        if expected == 'other':
            expected = inputs['output_language_hint']
        if data['output_language'] != expected:
            raise PipelineError('Stage 1 output language does not match the CV or explicit choice.')
        for link in data['links']:
            if link['url'] not in inputs['resume_links']:
                raise PipelineError('Stage 1 invented a candidate link.')
        for fact in data['facts']:
            source_quote(fact['quote'])
    else:
        profile = json.loads((folder / '01-profile.json').read_text(encoding='utf-8'))
        if data['language'] != profile['output_language']:
            raise PipelineError('Stage output changed the requested result language.')
        reports = data['source_report']
        if len(reports) != len(selected) or {item['source'] for item in reports} != set(selected):
            raise PipelineError('A selected site is missing from the source report.')
        for report in reports:
            for url in report['checked_urls']:
                parts=urllib.parse.urlsplit(url);host=(parts.hostname or '').lower();domain=selected[report['source']]['domain']
                if parts.scheme!='https' or parts.username or parts.password or not (host==domain or host.endswith('.'+domain)):
                    raise PipelineError('A checked URL does not belong to its selected site.')
        rows = data['vacancies'] if stage == 1 else data['matches']
        for row in rows:
            source = selected.get(row['source'])
            parts = urllib.parse.urlsplit(row['url']); host = (parts.hostname or '').lower()
            if not source or parts.scheme != 'https' or parts.username or parts.password or not (host == source['domain'] or host.endswith('.' + source['domain'])):
                raise PipelineError('A vacancy does not belong to a selected site.')
        if stage == 2:
            search = json.loads((folder / '02-vacancies.json').read_text(encoding='utf-8'))
            if data['source_report'] != search['source_report']:
                raise PipelineError('Stage 3 changed the source coverage report.')
            known = {item['url']: item for item in search['vacancies']}
            for row in rows:
                if row['url'] not in known or any(row[key] != known[row['url']][key] for key in ['title', 'company', 'source', 'checked_at']):
                    raise PipelineError('Stage 3 changed a verified vacancy identity.')
                if inputs['adaptation_mode'] == 'strict' and any(change['kind'] == 'inference' for change in row['adaptations']):
                    raise PipelineError('An inference was added in confirmed-facts mode.')
                for quote in row['evidence']:
                    source_quote(quote)
                for change in row['adaptations']:
                    source_quote(change['basis'])
            for link in data['candidate_links']:
                if link['url'] not in inputs['resume_links']:
                    raise PipelineError('A tailored CV invented a candidate link.')


def run(codex, folder, package, atomic, save_json):
    prepare(folder, package, atomic, save_json)
    receipt_path = folder / 'pipeline-state.json'
    receipt = json.loads(receipt_path.read_text(encoding='utf-8')) if receipt_path.exists() else {}
    base = hashlib.sha256(json.dumps(package, ensure_ascii=False, sort_keys=True).encode()).hexdigest()
    environment = {key: value for key, value in os.environ.items() if key not in ['OPENAI_API_KEY', 'CODEX_API_KEY']}
    for stage, (filename, schema_name, task_name, schema, search, task) in enumerate(STAGES):
        dependencies = base
        if stage > 0:
            dependencies += hashlib.sha256((folder / '01-profile.json').read_bytes()).hexdigest()
            dependencies += datetime.date.today().isoformat()
        if stage > 1:
            dependencies += hashlib.sha256((folder / '02-vacancies.json').read_bytes()).hexdigest()
        fingerprint = hashlib.sha256(dependencies.encode()).hexdigest()
        destination = folder / filename
        if destination.exists() and receipt.get(filename, {}).get('input') == fingerprint:
            data = json.loads(destination.read_text(encoding='utf-8-sig'))
            validate_shape(schema, data); check_contract(stage, data, package, folder)
            if hashlib.sha256(destination.read_bytes()).hexdigest() == receipt[filename]['output']:
                print(f'Stage {stage+1}/3 already complete: {package["request_id"]}')
                continue
        temporary = folder / (Path(filename).stem + '.running.json')
        command = [codex, '-c', 'forced_login_method="chatgpt"', '-c', 'web_search="' + search + '"', 'exec', '--ephemeral',
            '--sandbox', 'workspace-write', '--skip-git-repo-check', '-C', str(folder), '--output-schema', str(folder / schema_name), '--output-last-message', str(temporary), '-']
        with open(folder / (Path(filename).stem + '.log.txt'), 'w', encoding='utf-8') as log:
            result = subprocess.run(command, input=(folder / task_name).read_text(encoding='utf-8'), text=True, encoding='utf-8', stdout=log, stderr=log, env=environment)
        try:
            if result.returncode or not temporary.is_file() or temporary.stat().st_size > 500000:
                raise PipelineError('Codex stage stopped (limit, login or tool error). Completed stages were preserved.')
            data = json.loads(temporary.read_text(encoding='utf-8-sig'))
            validate_shape(schema, data); check_contract(stage, data, package, folder)
        except (ValueError, OSError) as error:
            if temporary.exists():
                os.replace(temporary, folder / (Path(filename).stem + '.incomplete.json'))
            raise PipelineError(str(error) if isinstance(error, PipelineError) else 'Invalid stage output; previous completed stages preserved.') from None
        if destination.exists():
            os.replace(destination, folder / (Path(filename).stem + '.previous-' + os.urandom(4).hex() + '.json'))
        os.replace(temporary, destination)
        receipt[filename] = {'input': fingerprint, 'output': hashlib.sha256(destination.read_bytes()).hexdigest()}
        save_json(receipt_path, receipt)
        print(f'Stage {stage+1}/3 complete: {package["request_id"]}')
