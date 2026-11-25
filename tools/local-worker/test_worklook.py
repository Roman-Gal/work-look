import contextlib
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('worklook', Path(__file__).with_name('worklook.py'))
w = importlib.util.module_from_spec(spec)
spec.loader.exec_module(w)


class BatchTransferTest(unittest.TestCase):
    def setUp(self):
        base = Path(__file__).resolve().parents[2] / 'var/local-worker-tests'
        base.mkdir(parents=True, exist_ok=True)
        self.temporary = tempfile.TemporaryDirectory(dir=base)
        self.root = Path(self.temporary.name).resolve()
        self.assertTrue(self.root.is_relative_to(base.resolve()))
        self.addCleanup(self.temporary.cleanup)
        self.job = 'a' * 32
        self.pdf = b'%PDF-1.4 private fixture'
        self.package = {'request_id': self.job, 'instructions': 'Trusted task instructions.',
                        'input': {'source_sha256': hashlib.sha256(self.pdf).hexdigest(), 'resume_text': 'Candidate-specific source'},
                        'knowledge': [{'scope': 'private', 'content': 'Owner-specific prompt'}]}
        self.rows = [{'id': self.job, 'status': 'queued'}]
        self.posts = []

    def fake_api(self, origin, token, path, payload=None, **kwargs):
        self.assertEqual('test-credential', token)
        if payload is not None:
            self.posts.append(payload)
            return b'{"published":true,"notification_sent":true}'
        if path == 'manifest':
            return json.dumps({'schema_version': 1, 'requests': self.rows}).encode()
        if path == 'knowledge':
            return b'{"entries":[{"scope":"reviewed_shared","content":"Shared tip"}]}'
        if path.endswith('/package'):
            return json.dumps(self.package).encode()
        if path.endswith('/original.pdf'):
            return self.pdf
        self.fail('Unexpected transfer')

    def mocked(self):
        stack = contextlib.ExitStack()
        stack.enter_context(patch.object(w, 'connection', return_value=('https://worklook.test', 'test-credential')))
        stack.enter_context(patch.object(w, 'api', side_effect=self.fake_api))
        stack.enter_context(contextlib.redirect_stdout(io.StringIO()))
        return stack

    def test_download_integrity_separate_knowledge_and_resume_without_ai(self):
        with self.mocked(), patch.object(w.subprocess, 'run') as agent:
            w.sync(self.root)
            folder = self.root / 'jobs' / self.job
            self.assertEqual(self.pdf, (folder / 'resume.pdf').read_bytes())
            self.assertIn('Owner-specific prompt', (folder / 'package.json').read_text())
            self.assertNotIn('Owner-specific prompt', (self.root / 'KB/shared.json').read_text())
            w.save_json(folder / 'result.json', {'draft': 'keep this'})
            w.sync(self.root)
            self.assertEqual({'draft': 'keep this'}, json.loads((folder / 'result.json').read_text()))
            agent.assert_not_called()

    def test_wrong_hash_or_path_traversal_never_installs_a_pdf(self):
        self.package['input']['source_sha256'] = '0' * 64
        with self.mocked(), self.assertRaises(w.TransferError):
            w.sync(self.root)
        self.assertFalse((self.root / 'jobs' / self.job / 'resume.pdf').exists())
        self.rows = [{'id': '../outside', 'status': 'queued'}]
        with self.mocked(), self.assertRaises(w.TransferError):
            w.sync(self.root)

    def test_server_deletion_cleans_only_managed_request_folder(self):
        with self.mocked():
            w.sync(self.root)
            unrelated = self.root / 'operator-notes.txt'
            unrelated.write_text('keep')
            self.rows = []
            w.sync(self.root)
            self.assertFalse((self.root / 'jobs' / self.job).exists())
            self.assertEqual('keep', unrelated.read_text())

    def test_publish_requires_review_preserves_identity_and_receipt_prevents_resend(self):
        with self.mocked():
            w.sync(self.root)
            folder = self.root / 'jobs' / self.job
            result = {'request_id': self.job, 'source_sha256': self.package['input']['source_sha256'], 'summary': 'Result', 'matches': []}
            w.save_json(folder / 'result.json', result)
            with patch('builtins.input', return_value='NO'):
                w.publish(self.root)
            self.assertEqual([], self.posts)
            w.publish(self.root, reviewed=True)
            self.assertEqual([result], self.posts)
            w.publish(self.root, reviewed=True)
            self.assertEqual(1, len(self.posts))

    def test_codex_failure_keeps_partial_output_separate_and_stops_next_jobs(self):
        with self.mocked():
            w.sync(self.root)
        folder = self.root / 'jobs' / self.job
        def fail(command, **kwargs):
            self.assertIn('--sandbox', command)
            self.assertIn('workspace-write', command)
            self.assertEqual(str(folder), command[command.index('-C') + 1])
            w.save_json(folder / 'result.json', {'incomplete': True})
            return type('Failed', (), {'returncode': 1})()
        with patch.object(w.shutil, 'which', return_value='codex.exe'), patch.object(w.subprocess, 'run', side_effect=fail), self.assertRaises(w.TransferError):
            w.run_codex(self.root)
        self.assertFalse((folder / 'result.json').exists())
        self.assertTrue((folder / 'result.incomplete.json').exists())

    @unittest.skipUnless(os.name == 'nt', 'Windows credential protection')
    def test_windows_credential_roundtrip_and_no_raw_secret_on_disk(self):
        with contextlib.redirect_stdout(io.StringIO()):
            w.setup(self.root, 'https://worklook.test')
        origin, token = w.connection(self.root)
        self.assertEqual('https://worklook.test', origin)
        self.assertEqual(64, len(token))
        for file in self.root.iterdir():
            self.assertNotIn(token.encode(), file.read_bytes())
        self.assertTrue((self.root / '01-Download.cmd').exists())
        with self.assertRaises(w.TransferError):
            w.setup(self.root, 'https://worklook.test')

    def test_http_publication_uploads_the_exact_utf8_json_document(self):
        payload={'summary':'Досвід PHP, Symfony; doświadczenie i testy.','matches':[{'cv_text':'Jane Doe\nPHP | SQL | API'}]}
        with patch.object(w.urllib.request,'build_opener') as opener:
            opener.return_value.open.return_value.__enter__.return_value.read.return_value=b'{"published":true}'
            response=w.api('https://worklook.test','fixture-token','a'*32+'/result',payload)
            self.assertEqual(b'{"published":true}',response)
            request=opener.return_value.open.call_args.args[0]
            self.assertEqual('Bearer fixture-token',request.get_header('Authorization'))
            self.assertEqual('yes',request.get_header('X-operator-reviewed'))
            content_type=request.get_header('Content-type')
            self.assertEqual('application/octet-stream',content_type)
            self.assertEqual('attachment; filename="result.json"',request.get_header('Content-disposition'))
            self.assertEqual(payload,json.loads(request.data.decode('utf-8')))

    def test_oversized_result_is_rejected_before_network_access(self):
        with patch.object(w.urllib.request,'build_opener') as opener,self.assertRaises(w.TransferError):
            w.api('https://worklook.test','fixture-token','a'*32+'/result',{'summary':'x'*500001})
        opener.assert_not_called()


if __name__ == '__main__':
    unittest.main()
