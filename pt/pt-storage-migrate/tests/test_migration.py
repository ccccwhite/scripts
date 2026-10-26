"""Local failure/recovery tests; contacts only a loopback mock, uses temporary files.
Run: python3 tests/test_migration.py [--php 'php']
"""
import argparse
import copy
import fcntl
import json
import os
from pathlib import Path
import shlex
import signal
import subprocess
import tempfile
import threading
import time
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

BASE = Path(__file__).resolve().parents[1]
HASH = 'a' * 40
OTHER = 'b' * 40
parser = argparse.ArgumentParser()
parser.add_argument('--php', default=os.environ.get('PHP_BIN', 'php'))
args, remaining = parser.parse_known_args()
PHP = shlex.split(args.php)


class Fixture:
    def __init__(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='pt-storage-test-')
        self.root = Path(self.tmp.name)
        self.host = self.root / 'disk'; self.host.mkdir()
        self.cfg = json.loads((BASE / 'config.json').read_text())
        self.cfg.update(dry_run=False, enable_move=True, hdd_host_path=str(self.host),
                        state_file=str(self.root / 'state.json'), credentials_file=str(self.root / 'credentials.json'))
        self.q = [dict(hash=HASH, state='stoppedUP', progress=1, amount_left=0,
                       save_path='/temp/nicept', content_path='/temp/nicept/video.bin', category='nicept', tags='storage:ssd')]
        self.t = [dict(id=1, hash_string=HASH, name='video.bin', labels=['nicept', 'move:hdd', 'storage:ssd'],
                       download_dir='/temp/nicept', percent_done=1, files=[dict(name='video.bin', length=4)],
                       status=6, error=0, error_string='', have_valid=4, have_unchecked=0, size_when_done=4, left_until_done=0)]
        self.calls = []; self.qreads = 0; self.treads = 0
        self.fail_labels = False; self.fail_delete = False; self.fail_move = False
        self.on_qread = None; self.on_tread = None
        self.on_move = None
        fixture = self
        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_): pass
            def reply(self, code, value):
                data = json.dumps(value).encode(); self.send_response(code)
                self.send_header('Content-Length', str(len(data))); self.end_headers(); self.wfile.write(data)
            def do_GET(self):
                fixture.calls.append(('GET', self.path))
                fixture.qreads += 1
                if fixture.on_qread: fixture.on_qread(fixture)
                self.reply(200, fixture.q)
            def do_POST(self):
                body = self.rfile.read(int(self.headers.get('Content-Length', 0)))
                if self.path.startswith('/api/v2/'):
                    form = parse_qs(body.decode()); fixture.calls.append((self.path, form))
                    if self.path.endswith('/delete'):
                        if form.get('deleteFiles') != ['false']: raise AssertionError('Unsafe deletion')
                        fixture.q = [q for q in fixture.q if q['hash'] not in form['hashes'][0].split('|')]
                        if fixture.fail_delete:
                            fixture.fail_delete = False; self.reply(500, {}); return
                    self.reply(200, {}); return
                request = json.loads(body); method = request['method']; params = request['params']
                fixture.calls.append((method, params)); result = {}
                if method == 'torrent_get':
                    fixture.treads += 1
                    if fixture.on_tread: fixture.on_tread(fixture)
                    result = {'torrents': copy.deepcopy([t for t in fixture.t if not params.get('ids') or t['id'] in params['ids']])}
                elif method == 'torrent_set_location':
                    if fixture.on_move: fixture.on_move(fixture)
                    if fixture.fail_move:
                        fixture.fail_move = False; self.reply(500, {}); return
                    t = next(t for t in fixture.t if t['id'] in params['ids']); t['download_dir'] = params['location']
                    for file in t['files']:
                        target = fixture.host / params['location'].split('/')[-1] / file['name']
                        target.parent.mkdir(parents=True, exist_ok=True); target.write_bytes(b'x' * file['length'])
                elif method == 'torrent_verify':
                    for t in fixture.t:
                        if t['id'] in params['ids']: t['status'] = 2
                elif method == 'torrent_set':
                    if fixture.fail_labels:
                        fixture.fail_labels = False; self.reply(500, {}); return
                    for t in fixture.t:
                        if t['id'] in params['ids']: t['labels'] = params['labels']
                self.reply(200, {'jsonrpc': '2.0', 'id': request['id'], 'result': result})
        self.server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True); self.thread.start()
        url = 'http://127.0.0.1:' + str(self.server.server_port)
        (self.root / 'credentials.json').write_text(json.dumps({'qbittorrent': {'url': url, 'api_key': 'test'},
                                                               'transmission': {'rpc_url': url + '/rpc'}}))
        bin_dir = self.root / 'bin'; bin_dir.mkdir()
        # Simulate only mount detection. All other filesystem safety checks run normally.
        findmnt = bin_dir / 'findmnt'; findmnt.write_text('#!/bin/sh\nprintf "%s\\n" "$TEST_MOUNT_ROOT"\n'); findmnt.chmod(0o755)
        self.env = dict(os.environ, PATH=str(bin_dir) + ':' + os.environ['PATH'], TEST_MOUNT_ROOT=str(self.host))
    def run(self, *options, expected=0):
        (self.root / 'config.json').write_text(json.dumps(self.cfg))
        r = subprocess.run(PHP + [str(BASE / 'migrate.php'), str(self.root / 'config.json'), *options], env=self.env,
                           text=True, capture_output=True, timeout=25)
        if r.returncode != expected: raise AssertionError(f'Exit {r.returncode}, wanted {expected}\n{r.stdout}\n{r.stderr}')
        return r.stdout + r.stderr
    def state(self):
        path = self.root / 'state.json'
        return json.loads(path.read_text()) if path.exists() else {}
    def count(self, method): return sum(call[0] == method for call in self.calls)
    def finish_verify(self): self.t[0]['status'] = 6
    def workflow_command(self, *options):
        (self.root / 'config.json').write_text(json.dumps(self.cfg))
        launcher = self.root / 'php-test'
        launcher.write_text('#!/bin/sh\nprintf "%s\\n" "$$" >> "$TEST_CHILD_PIDS"\nexec ' + shlex.join(PHP) + ' "$@"\n'); launcher.chmod(0o755)
        self.env['PHP_BIN'] = str(launcher)
        self.env['TEST_CHILD_PIDS'] = str(self.root / 'child-pids')
        return ['bash', str(BASE / 'script'), '--config=' + str(self.root / 'config.json'), '--interval=1', *options]
    def workflow(self, *options, expected=0):
        r = subprocess.run(self.workflow_command(*options), env=self.env, text=True, capture_output=True, timeout=25)
        if r.returncode != expected: raise AssertionError(f'Workflow exit {r.returncode}, wanted {expected}\n{r.stdout}\n{r.stderr}')
        return r.stdout + r.stderr
    def auto_verify(self):
        seen = {}
        def advance(f):
            for t in f.t:
                if t['status'] == 2:
                    seen[t['id']] = seen.get(t['id'], 0) + 1
                    if seen[t['id']] >= 2: t['status'] = 6
        self.on_tread = advance
    def pending(self):
        self.run(); self.run(); self.finish_verify()
    def close(self):
        self.server.shutdown(); self.server.server_close(); self.thread.join(); self.tmp.cleanup()


class MigrationTests(unittest.TestCase):
    def setUp(self): self.f = Fixture()
    def tearDown(self): self.f.close()
    def test_normal_lifecycle(self):
        f = self.f; f.pending(); f.run()
        self.assertFalse(f.state()); self.assertFalse(f.q)
        self.assertIn('storage:hdd', f.t[0]['labels']); self.assertNotIn('move:hdd', f.t[0]['labels'])
        self.assertEqual(f.count('torrent_set_location'), 1)
    def test_recover_after_delete_before_label_failure(self):
        f = self.f; f.pending(); f.fail_labels = True; f.run(expected=1)
        self.assertEqual(f.state()[HASH]['phase'], 'qbit_removed')
        f.run(); self.assertFalse(f.state()); self.assertEqual(f.count('/api/v2/torrents/delete'), 1)
    def test_recover_after_delete_response_lost(self):
        f = self.f; f.pending(); f.fail_delete = True; f.run(expected=1)
        self.assertEqual(f.state()[HASH]['phase'], 'deleting_qbit')
        f.run(); self.assertFalse(f.state()); self.assertEqual(f.count('/api/v2/torrents/delete'), 1)
    def test_move_failure_never_retries_without_explicit_option(self):
        f = self.f; f.fail_move = True; f.run(expected=1); f.run()
        self.assertEqual(f.count('torrent_set_location'), 1)
        f.run('--retry-move=' + HASH); self.assertEqual(f.count('torrent_set_location'), 2)
    def test_retry_refuses_partial_destination(self):
        f = self.f; f.fail_move = True; f.run(expected=1)
        target = f.host / 'nicept/video.bin'; target.parent.mkdir(exist_ok=True); target.write_bytes(b'x')
        f.run('--retry-move=' + HASH, expected=1); self.assertEqual(f.count('torrent_set_location'), 1)
    def test_symlink_parent_is_rejected(self):
        f = self.f; outside = f.root / 'outside'; outside.mkdir(); (f.host / 'nicept').symlink_to(outside, target_is_directory=True)
        f.run(expected=1); self.assertEqual(f.count('torrent_set_location'), 0); self.assertFalse(list(outside.iterdir()))
    def test_refreshed_qbit_running_blocks_move(self):
        f = self.f
        def change(f):
            if f.qreads == 2: f.q[0]['state'] = 'uploading'
        f.on_qread = change; f.run(expected=1); self.assertEqual(f.count('torrent_set_location'), 0)
    def test_refreshed_qbit_running_blocks_delete(self):
        f = self.f; f.pending(); start = f.qreads
        def change(f):
            if f.qreads == start + 2: f.q[0]['state'] = 'uploading'
        f.on_qread = change; f.run(); self.assertEqual(f.count('/api/v2/torrents/delete'), 0); self.assertTrue(f.state())
    def test_new_shared_transmission_task_blocks_move(self):
        f = self.f
        def change(f):
            if f.treads == 2:
                t = copy.deepcopy(f.t[0]); t.update(id=2, hash_string=OTHER); f.t.append(t)
        f.on_tread = change; f.run(expected=1); self.assertEqual(f.count('torrent_set_location'), 0)
    def test_changed_mapping_blocks_pending(self):
        f = self.f; f.run(); new = f.root / 'otherdisk'; new.mkdir(); f.cfg['hdd_host_path'] = str(new)
        f.run(expected=1); self.assertEqual(f.count('/api/v2/torrents/delete'), 0)
    def test_missing_qbit_without_delete_intent_is_rejected(self):
        f = self.f; f.pending(); f.q = []; f.run(expected=1); self.assertTrue(f.state())
    def test_dry_run_has_no_mutations(self):
        f = self.f; f.cfg['dry_run'] = True; f.run()
        self.assertFalse(f.state()); self.assertTrue(all(c[0] in ('GET', 'torrent_get') for c in f.calls))
    def test_direct_php_honors_lock(self):
        f = self.f
        with (f.root / 'run.lock').open('w') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.assertIn('Another migration', f.run()); self.assertFalse(f.calls)
    def test_progress_uses_custom_mapping(self):
        f = self.f; f.cfg['hdd_path'] = '/archive'; f.run()
        r = subprocess.run(PHP + [str(BASE / 'progress.php'), '--config=' + str(f.root / 'config.json')],
                           env=f.env, text=True, capture_output=True, timeout=5)
        self.assertEqual(r.returncode, 0, r.stderr); self.assertIn('files 1/1', r.stdout); self.assertIn('NOT completion', r.stdout)
    def test_pending_limit(self):
        f = self.f; t = copy.deepcopy(f.t[0]); t.update(id=2, hash_string=OTHER, name='other.bin')
        t['files'] = [dict(name='other.bin', length=4)]; f.t.append(t)
        f.run(); self.assertEqual(f.count('torrent_set_location'), 1); self.assertEqual(len(f.state()), 1)
    def test_unobserved_verification_never_auto_deletes(self):
        f = self.f; f.pending(); state = f.state(); state[HASH]['seen_verifying'] = False
        (f.root / 'state.json').write_text(json.dumps(state)); f.run()
        self.assertEqual(f.count('/api/v2/torrents/delete'), 0); self.assertTrue(f.state())
    def test_manual_confirmation_requires_complete_data(self):
        f = self.f; f.pending(); f.t[0]['have_valid'] = 0
        f.run('--accept-verified=' + HASH, expected=1)
        self.assertEqual(f.count('/api/v2/torrents/delete'), 0)
    def test_explicit_manual_confirmation_can_finish_fast_verification(self):
        f = self.f; f.pending(); state = f.state(); state[HASH]['seen_verifying'] = False
        (f.root / 'state.json').write_text(json.dumps(state))
        f.run('--accept-verified=' + HASH); self.assertFalse(f.state()); self.assertFalse(f.q)
    def test_recheck_resets_observation_and_requests_new_verification(self):
        f = self.f; f.pending(); f.run('--recheck=' + HASH)
        self.assertEqual(f.count('torrent_verify'), 2); self.assertEqual(f.count('/api/v2/torrents/delete'), 0)
        f.finish_verify(); f.run(); self.assertFalse(f.state())
    def test_mount_missing_blocks_pending_delete(self):
        f = self.f; f.pending(); f.env['TEST_MOUNT_ROOT'] = '/wrong-mount'; f.run()
        self.assertEqual(f.count('/api/v2/torrents/delete'), 0); self.assertTrue(f.state())
    def test_changed_manifest_blocks_pending(self):
        f = self.f; f.run(); f.t[0]['files'][0]['length'] = 8; f.run(expected=1)
        self.assertEqual(f.count('torrent_verify'), 0)
    def test_parent_file_collision_is_shared(self):
        f = self.f; t = copy.deepcopy(f.t[0]); t.update(id=2, hash_string=OTHER)
        t['files'] = [dict(name='video.bin/child', length=4)]; f.t.append(t)
        f.run(); self.assertEqual(f.count('torrent_set_location'), 0)
    def test_watch_refreshes_file_stat(self):
        f = self.f; f.run(); state = f.state(); state[HASH]['files'][0]['length'] = 8192
        (f.root / 'state.json').write_text(json.dumps(state))
        target = f.host / 'nicept/video.bin'; target.write_bytes(b'x' * 4096)
        proc = subprocess.Popen(PHP + [str(BASE / 'progress.php'), '--config=' + str(f.root / 'config.json'),
                                     '--watch', '--interval=1'], env=f.env, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        try:
            time.sleep(0.4); target.write_bytes(b'x' * 8192); time.sleep(1.2)
        finally:
            proc.terminate(); output, error = proc.communicate(timeout=3)
        self.assertIn('~50.0%', output, error); self.assertIn('~100.0%', output, error)
    def test_workflow_completes_two_tasks_in_one_invocation(self):
        f = self.f; f.auto_verify()
        t = copy.deepcopy(f.t[0]); t.update(id=2, hash_string=OTHER, name='other.bin')
        t['files'] = [dict(name='other.bin', length=4)]; f.t.append(t)
        output = f.workflow()
        self.assertFalse(f.state()); self.assertFalse(f.q)
        self.assertEqual(f.count('torrent_set_location'), 2); self.assertEqual(f.count('torrent_verify'), 2)
        self.assertTrue(all('move:hdd' not in t['labels'] for t in f.t)); self.assertIn('workflow finished', output)
    def test_workflow_monitor_runs_during_move_rpc(self):
        f = self.f; f.auto_verify(); f.on_move = lambda _: time.sleep(1.3)
        output = f.workflow()
        self.assertIn('phase move_requested', output); self.assertIn('NOT completion', output); self.assertFalse(f.state())
    def test_workflow_preview_is_one_pass(self):
        f = self.f; f.cfg['dry_run'] = True
        output = f.workflow(); self.assertIn('single pass finished', output)
        self.assertFalse(f.state()); self.assertEqual(f.treads, 1)
    def test_workflow_once_retains_pending(self):
        f = self.f; output = f.workflow('--once')
        self.assertEqual(f.state()[HASH]['phase'], 'moving'); self.assertEqual(f.count('torrent_verify'), 0)
    def test_workflow_duplicate_start_is_blocked(self):
        f = self.f
        with (f.root / 'workflow.lock').open('w') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.assertIn('Another migration workflow', f.workflow()); self.assertFalse(f.calls)
    def test_workflow_timeout_retains_state(self):
        f = self.f
        output = f.workflow('--timeout=1', expected=2)
        self.assertTrue(f.state()); self.assertIn('Wait timeout', output)
    def test_workflow_exits_for_unobserved_verification(self):
        f = self.f; f.pending(); state = f.state(); state[HASH]['seen_verifying'] = False
        (f.root / 'state.json').write_text(json.dumps(state))
        output = f.workflow(expected=2)
        self.assertIn('needs manual review', output); self.assertEqual(f.count('/api/v2/torrents/delete'), 0)
    def test_workflow_signal_stops_children_and_can_resume(self):
        self.stop_workflow_and_resume(signal.SIGTERM, 143)
    def test_workflow_ctrl_c_stops_children_and_can_resume(self):
        self.stop_workflow_and_resume(signal.SIGINT, 130)
    def stop_workflow_and_resume(self, signum, expected):
        f = self.f
        proc = subprocess.Popen(f.workflow_command(), env=f.env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, start_new_session=True)
        children = []
        try:
            deadline = time.monotonic() + 5
            while time.monotonic() < deadline:
                if f.count('torrent_set_location'):
                    child_file = f.root / 'child-pids'
                    children = child_file.read_text().split(); break
                time.sleep(0.05)
            self.assertTrue(children)
            proc.send_signal(signum); output, error = proc.communicate(timeout=5)
            self.assertEqual(proc.returncode, expected, output + error)
            for pid in children:
                try: os.kill(int(pid), 0)
                except ProcessLookupError: continue
                self.fail('Orphan child: ' + pid)
            self.assertTrue(f.state())
            f.auto_verify(); f.workflow(); self.assertFalse(f.state())
        finally:
            if proc.poll() is None: os.killpg(proc.pid, signal.SIGKILL); proc.communicate(timeout=5)


if __name__ == '__main__': unittest.main(argv=['test_migration.py', *remaining], verbosity=2)
