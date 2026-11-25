import copy
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec=importlib.util.spec_from_file_location('pipeline_worker',Path(__file__).with_name('worklook.py'))
w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)
p=w.pipeline


class ThreeStageTest(unittest.TestCase):
    def setUp(self):
        base=Path(__file__).resolve().parents[2]/'var/local-worker-tests';base.mkdir(parents=True,exist_ok=True)
        self.temporary=tempfile.TemporaryDirectory(dir=base);self.addCleanup(self.temporary.cleanup);self.folder=Path(self.temporary.name)
        self.identity={'request_id':'a'*32,'source_sha256':'b'*64}
        self.package={'schema_version':2,'request_id':self.identity['request_id'],'instructions':'Private workflow instructions',
            'input':{'source_sha256':self.identity['source_sha256'],'resume_text':'Source PHP development.','requested_language':'auto','output_language_hint':'pl','adaptation_mode':'enhanced','resume_links':['https://github.com/example']},
            'selected_sources':{'solidjobs':{'domain':'solid.jobs'}},'knowledge':[]}
        self.profile={**self.identity,'resume_language':'pl','output_language':'pl','summary':'Profil.','facts':[{'claim':'PHP','quote':'Source PHP development.'}],
            'links':[{'url':'https://github.com/example','label':'GitHub: projekty','category':'code'}],'constraints':[],'uncertainties':[]}
        self.report=[{'source':'solidjobs','status':'checked','details':'Sprawdzono.','checked_urls':['https://solid.jobs/']}]
        vacancy={'source':'solidjobs','title':'PHP Developer','company':'Example','url':'https://solid.jobs/offer/example','location':'Remote','salary':'Unknown','fit':'PHP','gaps':'React unknown','checked_at':'2026-11-25'}
        self.search={**self.identity,'language':'pl','source_report':self.report,'vacancies':[{**vacancy,'description':'Verified PHP responsibilities and requirements.'}]}
        self.result={**self.identity,'protocol_version':2,'language':'pl','summary':'Wyniki.','candidate_links':self.profile['links'],'source_report':self.report,
            'matches':[{**vacancy,'conclusion':'Wzmocniono opis; brak nowych faktów.','cv_text':'Example CV PHP','evidence':['Source PHP development.'],'adaptations':[]}]}
        self.outputs=[self.profile,self.search,self.result];self.calls=[]
        w.save_json(self.folder/'package.json',self.package)

    def execute(self,command,**kwargs):
        target=Path(command[command.index('--output-last-message')+1]);stage=next(i for i,row in enumerate(p.STAGES) if target.stem.startswith(Path(row[0]).stem))
        self.calls.append(stage);w.save_json(target,self.outputs[stage])
        self.assertNotIn('OPENAI_API_KEY',kwargs['env']);self.assertIn('workspace-write',command)
        self.assertIn('web_search="live"' if stage==1 else 'web_search="disabled"',command)
        return type('Result',(),{'returncode':0})()

    def run_pipeline(self,executor=None):
        with patch.object(p.subprocess,'run',side_effect=executor or self.execute),contextlib.redirect_stdout(io.StringIO()):
            p.run('codex.exe',self.folder,self.package,w.atomic,w.save_json)

    def test_three_distinct_stages_preserve_language_links_and_vacancy_context(self):
        self.run_pipeline();self.assertEqual([0,1,2],self.calls)
        self.assertEqual(self.result,json.loads((self.folder/'result.json').read_text(encoding='utf-8')))
        self.assertIn('verified responsibilities',(self.folder/'02-Search.md').read_text(encoding='utf-8'))
        self.calls.clear();self.run_pipeline();self.assertEqual([],self.calls)

    def test_limit_during_search_resumes_without_repeating_intake(self):
        def limited(command,**kwargs):
            if '02-vacancies.running.json' in str(command):
                w.save_json(self.folder/'02-vacancies.running.json',{'partial':True});return type('Result',(),{'returncode':1})()
            return self.execute(command,**kwargs)
        with self.assertRaises(p.PipelineError):self.run_pipeline(limited)
        self.assertTrue((self.folder/'01-profile.json').exists());self.assertFalse((self.folder/'result.json').exists())
        self.assertTrue((self.folder/'02-vacancies.incomplete.json').exists())
        self.calls.clear();self.run_pipeline();self.assertEqual([1,2],self.calls)

    def test_changed_package_invalidates_completed_stage_receipts(self):
        self.run_pipeline();self.package['input']['requested_language']='pl';self.calls.clear();self.run_pipeline()
        self.assertEqual([0,1,2],self.calls)

    def test_wrong_language_or_invented_identity_stops_before_search(self):
        self.outputs[0]={**self.profile,'output_language':'uk'}
        with self.assertRaises(p.PipelineError):self.run_pipeline()
        self.assertEqual([0],self.calls);self.assertFalse((self.folder/'01-profile.json').exists())
        self.calls.clear();self.outputs[0]={**self.profile,'request_id':'c'*32}
        with self.assertRaises(p.PipelineError):self.run_pipeline()
        self.assertEqual([0],self.calls)

    def test_unselected_vacancy_source_and_changed_coverage_are_rejected(self):
        self.outputs[1]=copy.deepcopy(self.search);self.outputs[1]['vacancies'][0]['url']='https://evil.example/offer'
        with self.assertRaises(p.PipelineError):self.run_pipeline()
        self.assertEqual([0,1],self.calls);self.assertFalse((self.folder/'result.json').exists())
        self.outputs[1]=self.search;self.outputs[2]=copy.deepcopy(self.result);self.outputs[2]['source_report'][0]['details']='Invented new coverage'
        with self.assertRaises(p.PipelineError):self.run_pipeline()
        self.assertFalse((self.folder/'result.json').exists())
