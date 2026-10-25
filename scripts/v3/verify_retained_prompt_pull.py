#!/usr/bin/env python3
"""Text-only transport/order probe using retained lessons; never calls a provider.

Copies a previous isolated HTTP verification database/storage. The copied
authoring fixture is deliberately scoped to the selected retained lessons; it
does not finalize, resume, or change the original incomplete terminal run.
Uses real PHP result projection, Dashboard Pull, claims and upload gateway.
Text uploads remain probe artifacts, never HTML candidates or student content.
"""
from __future__ import annotations

import argparse
from datetime import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import socket
import sqlite3
import subprocess
import sys
import time
import uuid
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
import httpx
from v3_engine.local_worker.client import LocalWorkerHttpClient
from v3_engine.local_worker.journal import LocalEffectJournal
from v3_engine.local_worker.runner import HeartbeatManager, StageContext, TaskRunner


def digest(data):
    return hashlib.sha256(data).hexdigest()


def save(path, data):
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding='utf-8')


def login(browser, email):
    page = browser.get('/login')
    csrf = re.search(r'<meta name="csrf-token" content="([^"]+)"', page.text).group(1)
    response = browser.post('/login', data={'email': email, 'password': 'secret123', '_token': csrf})
    assert response.status_code == 200 and '/login' not in str(response.url), 'Fixture login failed'
    csrf = re.search(r'<meta name="csrf-token" content="([^"]+)"', response.text).group(1)
    return {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--runtime', type=Path, required=True)
    parser.add_argument('--terminal-run-id', required=True)
    parser.add_argument('--lesson-count', type=int, default=9)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    project = Path(__file__).resolve().parents[2]
    source = args.runtime.resolve()
    work = next(source.glob('lw_e2_*'))
    original_db = work / 'e2_database.sqlite'
    terminal_run = source / 'terminal/runs' / str(uuid.UUID(args.terminal_run_id))
    handoffs = sorted((terminal_run / 'pdfs/pdf-0001/lesson_handoffs').glob('lesson-*.json'))[:args.lesson_count]
    assert len(handoffs) == args.lesson_count > 0, 'Insufficient retained lessons'
    snapshots = {str(path): digest(path.read_bytes()) for path in handoffs}
    lessons = [json.loads(path.read_text()) for path in handoffs]
    assert [row['lesson_ordinal'] for row in lessons] == list(range(1, args.lesson_count + 1))
    for row in lessons:
        assert digest(row['raw_answer'].encode()) == row['raw_answer_sha256'], 'Retained raw response changed'
    original_hash = digest(original_db.read_bytes())
    out = args.output.resolve()
    out.mkdir(parents=True, exist_ok=False, mode=0o700)
    database = out / 'probe.sqlite'
    with sqlite3.connect(f'file:{original_db}?mode=ro', uri=True) as src, sqlite3.connect(database) as dst:
        src.backup(dst)
    private = out / 'v3_private'
    shutil.copytree(work / 'storage/v3_private', private)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    origin = f'http://127.0.0.1:{port}'
    env = dict(os.environ, APP_ENV='testing', APP_DEBUG='false',
               APP_KEY='base64:pAeRvprsT7xvgtJnI7RY2CtfRhhwt5HflVxit0LZEH0=',
               DB_CONNECTION='sqlite', DB_DATABASE=str(database), V3_PRIVATE_ROOT=str(private),
               CACHE_STORE='array', SESSION_DRIVER='database', QUEUE_CONNECTION='sync')
    # Test fixture setup is confined to the cloned database. No original task,
    # unknown-effect record, provider receipt or Temporal state is modified.
    fixture = r'''<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('testing')) { throw new RuntimeException('Test fixture only'); }
$task = App\Models\V3\LocalTask::where('task_kind', 'author_prompts')->sole();
$job = App\Models\V3\LocalJob::findOrFail($task->job_id);
$run = App\Models\V3\SubjectRun::findOrFail($job->run_id);
$previous = App\Models\V3\LocalAssignment::where('task_id', $task->id)->orderByDesc('assignment_epoch')->firstOrFail();
App\Models\V3\LocalAssignment::where('task_id', $task->id)->update(['status' => 'expired']);
$assignment = App\Models\V3\LocalAssignment::create([
 'id'=>(string)Illuminate\Support\Str::uuid(), 'task_id'=>$task->id,
 'device_id'=>$previous->device_id, 'claim_id'=>(string)Illuminate\Support\Str::uuid(),
 'assignment_epoch'=>$previous->assignment_epoch+1, 'status'=>'active',
 'lease_expires_at'=>now()->addMinutes(10), 'heartbeat_at'=>now(), 'control_version'=>$task->control_version,
]);
$task->update(['status'=>'leased', 'active_assignment_id'=>$assignment->id, 'active_epoch'=>$assignment->assignment_epoch]);
$run->update(['state'=>'running']);
$teacher = App\Models\User::findOrFail($job->teacher_id);
$teacher->update(['password'=>bcrypt('secret123')]);
echo json_encode(['task_id'=>$task->id, 'job_id'=>$job->id, 'run_id'=>$run->id,
 'teacher_email'=>$teacher->email, 'device_id'=>$assignment->device_id, 'claim_id'=>$assignment->claim_id]);
'''
    seeded = subprocess.run(['php'], input=fixture, text=True, cwd=project, env=env,
                            capture_output=True, check=True)
    ids = json.loads(seeded.stdout)
    save(out / 'fixture-scope.json', dict(ids, test_only=True, retained_lessons=args.lesson_count,
        original_terminal_run_id=args.terminal_run_id, original_run_is_incomplete=True))
    server = None
    hb = None
    try:
        with (out / 'php-server.log').open('w') as log:
            server = subprocess.Popen(['php', '-d', 'variables_order=EGPCS', '-S', f'127.0.0.1:{port}',
                str(project / 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],
                cwd=project / 'public', env=env, stdout=log, stderr=subprocess.STDOUT)
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.2):
                        break
                except OSError:
                    time.sleep(.1)
            with httpx.Client(base_url=origin, follow_redirects=True, timeout=30) as owner:
                headers = login(owner, 'admin@test.com')
                # Real owner-authorized rotation on the cloned fixture device.
                rotated = owner.post(f"/v3/admin/devices/{ids['device_id']}/rotate", headers=headers)
                assert rotated.status_code == 200
                client = LocalWorkerHttpClient(origin, rotated.json()['device_token'], timeout=30)
            claim = client.get_claim(ids['claim_id'])
            workspace = out / 'worker'
            ctx = StageContext.resolve_and_download(client, claim, workspace)
            runner = TaskRunner(client, LocalEffectJournal(workspace / 'journal.sqlite'), workspace)
            references, submitted = [], []
            assert len(ctx.manifest['items']) == 1
            item_id = ctx.manifest['items'][0]['item_id']
            for row in lessons:
                ref = runner._upload_terminal_bytes(ctx, 'retained-lesson-' + str(row['lesson_ordinal']), row['raw_answer'].encode())
                references.append(ref)
                submitted.append({'item_id': item_id, 'lesson_ordinal': row['lesson_ordinal'] - 1,
                    'lesson_title': row['mapped_title'], 'raw_response_artifact_id': ref['artifact_id'],
                    'raw_response_sha256': ref['digest_sha256'], 'scenes': [
                        {'scene_ordinal': index, 'scene_title': scene['scene_type'],
                         'prompt_text': scene['generated_prompt'], 'prompt_sha256': digest(scene['generated_prompt'].encode()),
                         'generation_context': {'template_version': 'retained-terminal-probe', 'scene_type': scene['scene_type']}}
                        for index, scene in enumerate(row['scenes'])]})
            result = dict(schema_version=1, kind='local_worker_authoring_result',
                result_id=str(uuid.uuid5(uuid.UUID(ctx.task_id), 'retained-nine-lesson-probe')),
                task_id=ctx.task_id, job_id=ctx.job_id, run_id=ctx.run_id,
                assignment_id=ctx.assignment_id, assignment_epoch=ctx.assignment_epoch,
                input_manifest_sha256=ctx.manifest_sha256, protocol_version=ctx.manifest['protocol_version'],
                settings_sha256=ctx.manifest['settings_sha256'], preparation_sha256=ctx.manifest['preparation']['inventory_sha256'],
                artifact_references=references, lessons=submitted, archive_inventory=[],
                metadata={'authoring_backend': 'run_temporal_pipeline', 'terminal_run_id': args.terminal_run_id,
                          'verification_scope': 'retained_nine_lessons_only', 'original_terminal_complete': False})
            receipt = client.submit_result(ctx.task_id, result, ctx.assignment_id, ctx.assignment_epoch)
            save(out / 'authoring-probe-receipt.json', receipt)
            bindings = receipt['scene_bindings']
            expected_text = [scene['generated_prompt'] for lesson in lessons for scene in lesson['scenes']]
            assert [row['prompt_text'] for row in bindings] == expected_text
            with httpx.Client(base_url=origin, follow_redirects=True, timeout=30) as teacher:
                headers = login(teacher, ids['teacher_email'])
                request_id = str(uuid.uuid4())
                pull = teacher.post(f"/v3/teacher/subject-runs/{ids['run_id']}/pull-scenes",
                    headers=headers, json={'request_id': request_id})
                assert pull.status_code == 202, f'Pull status {pull.status_code}'
                repeat = teacher.post(f"/v3/teacher/subject-runs/{ids['run_id']}/pull-scenes",
                    headers=headers, json={'request_id': request_id})
                assert repeat.status_code == 202 and repeat.json()['request_id'] == request_id
                save(out / 'dashboard-pull-receipt.json', pull.json())
                returned = client.claim(str(uuid.uuid4()), ['scene_return_v1', 'static_three_file_v1'])
                assert returned and returned['task_kind'] == 'return_scenes'
                assert returned['run_id'] == ids['run_id'] and returned['device_id'] == ids['device_id']
                return_ctx = StageContext.resolve_and_download(client, returned, workspace)
                targets = return_ctx.manifest['required_scenes']
                assert [row['scene_id'] for row in targets] == [row['scene_id'] for row in bindings]
                lease = datetime.fromisoformat(returned['lease_expires_at'].replace('Z', '+00:00')).timestamp()
                hb = HeartbeatManager(client, returned['task_id'], returned['assignment_id'], returned['assignment_epoch'], returned['control_version'], lease)
                hb.start()
                rows = []
                for index, binding in enumerate(bindings, 1):
                    data = binding['prompt_text'].encode()
                    local = workspace / 'runs' / ids['run_id'] / 'scenes' / binding['scene_id'] / 'prompt.txt'
                    local.parent.mkdir(parents=True, exist_ok=True)
                    local.write_bytes(data)
                    artifact_id = str(uuid.uuid5(uuid.UUID(returned['task_id']), binding['scene_id'] + ':' + digest(data)))
                    upload_id = str(uuid.uuid5(uuid.UUID(artifact_id), 'upload'))
                    rows.append(dict(position=index, scene_id=binding['scene_id'], prompt_version_id=binding['prompt_version_id'],
                        pdf_ordinal=binding['pdf_ordinal'], lesson_ordinal=binding['lesson_ordinal'], scene_ordinal=binding['scene_ordinal'],
                        sha256=digest(data), artifact_id=artifact_id, upload_id=upload_id, local_path=str(local)))
                # Arrival order must not dictate presentation or placement order.
                for row in reversed(rows):
                    data = Path(row['local_path']).read_bytes()
                    client.create_upload_slot(returned['task_id'], row['upload_id'], row['artifact_id'],
                        'prompt_export', 'text/plain', len(data), row['sha256'])
                    client.upload_bytes(returned['task_id'], row['upload_id'], data, 'text/plain')
                manifest_bytes = json.dumps({'kind': 'retained_prompt_transfer_probe', 'run_id': ids['run_id'],
                    'task_id': returned['task_id'], 'files': [{k: v for k, v in row.items() if k != 'local_path'} for row in rows]},
                    ensure_ascii=False, indent=2).encode()
                manifest_id = str(uuid.uuid5(uuid.UUID(returned['task_id']), 'ordered-prompt-transfer-index'))
                manifest_upload = str(uuid.uuid5(uuid.UUID(manifest_id), 'upload'))
                client.create_upload_slot(returned['task_id'], manifest_upload, manifest_id, 'prompt_export',
                    'application/json', len(manifest_bytes), digest(manifest_bytes))
                client.upload_bytes(returned['task_id'], manifest_upload, manifest_bytes, 'application/json')
                received = out / 'received-prompts'
                received.mkdir()
                with sqlite3.connect(f'file:{database}?mode=ro', uri=True) as db:
                    def stored(upload_id):
                        artifact = db.execute('select storage_path,digest_sha256,is_quarantine,task_id,job_id from v3_local_artifacts where id=?', (upload_id,)).fetchone()
                        assert artifact and not artifact[2] and artifact[3] == returned['task_id'] and artifact[4] == ids['job_id']
                        data = (private / artifact[0]).read_bytes()
                        assert digest(data) == artifact[1]
                        return data
                    observed_index = json.loads(stored(manifest_upload))
                    assert observed_index['files'] == [{k: v for k, v in row.items() if k != 'local_path'} for row in rows]
                    for row in rows:
                        data = stored(row['upload_id'])
                        assert data == Path(row['local_path']).read_bytes()
                        filename = f"{row['position']:03d}-lesson-{row['lesson_ordinal']+1:03d}-scene-{row['scene_ordinal']+1:03d}.txt"
                        (received / filename).write_bytes(data)
                    assert db.execute('select count(*) from v3_scene_candidates').fetchone()[0] == 0
                exported = teacher.get(f"/v3/teacher/subject-runs/{ids['run_id']}/prompt-export", headers={'Accept': 'application/json'})
                exported.raise_for_status()
                dashboard_prompts = exported.json()['prompts']
                assert [row['scene_id'] for row in dashboard_prompts] == [row['scene_id'] for row in bindings], 'Dashboard export order differs'
                assert [row['prompt_text'] for row in dashboard_prompts] == expected_text, 'Dashboard prompt bytes differ'
                scenes = teacher.get(f"/v3/teacher/subject-runs/{ids['run_id']}/scenes", headers={'Accept': 'application/json'})
                scenes.raise_for_status()
                assert [row['section']['id'] for row in scenes.json()['data']['scenes']] == [row['scene_id'] for row in bindings], 'Dashboard scene order differs'
                save(out / 'dashboard-prompts.json', exported.json())
                text = teacher.get(f"/v3/teacher/subject-runs/{ids['run_id']}/prompt-export?format=text")
                text.raise_for_status()
                (out / 'dashboard-prompts.txt').write_bytes(text.content)
                client.release(returned['task_id'], 'manual_scenes_missing', returned['assignment_id'], returned['assignment_epoch'])
                # Text probes are not accepted as scene results. No fake HTML or
                # finalized archive is created; the original run is still held.
            assert digest(original_db.read_bytes()) == original_hash
            assert all(digest(Path(path).read_bytes()) == sha for path, sha in snapshots.items())
            with zipfile.ZipFile(out / 'received-prompts.zip', 'w', zipfile.ZIP_DEFLATED) as archive:
                for path in sorted(received.glob('*.txt')):
                    archive.write(path, path.name)
            save(out / 'verification.json', dict(status='passed', run_id=ids['run_id'], terminal_run_id=args.terminal_run_id,
                lessons=len(lessons), prompt_files=len(rows), upload_order='reverse', dashboard_order='pdf_lesson_scene',
                bytes_match=True, ids_match=True, original_unchanged=True, provider_calls=0, temporal_resume_calls=0,
                scope='Real retained prompts; cloned authoring fixture, real HTTP Pull/claims/uploads/dashboard exports.',
                html_result_acceptance='not_exercised_by_text_probe', scene_candidates_created=0))
            print(f"PASSED: {len(lessons)} retained lessons / {len(rows)} prompt files; reversed uploads, ordered Dashboard, exact bytes and IDs.")
            print(f"Evidence: {out / 'verification.json'}")
    finally:
        if hb:
            hb.stop()
        if server and server.poll() is None:
            server.terminate()
            try:
                server.wait(timeout=8)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=3)
        print('Temporary HTTP server stopped; no NotebookLM or Temporal execution was started.')


if __name__ == '__main__':
    main()
