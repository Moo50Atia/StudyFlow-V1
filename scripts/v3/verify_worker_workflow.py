#!/usr/bin/env python3
"""Observe production worker + real Temporal using retained 114-page responses.

No Google/paid scene requests. Fresh isolated DB, run, daemon and Temporal per
attempt. Setup reuses already accepted split PDFs, not old processing results.
After launch only polls, supplies named placeholder HTML, and requests teacher
Pull. Never calls TaskRunner, claims tasks, submits results, or edits run state.
"""
from __future__ import annotations
import argparse
import hashlib
import html
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

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
SOURCE_RUN = 'bd6ec553-4f22-4a05-90cf-86cf7729aa50'


def sha(data):
    return hashlib.sha256(data).hexdigest()


def save(path, value):
    path.write_text(json.dumps(value, indent=2, ensure_ascii=False))
    path.chmod(0o600)


def port():
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        return s.getsockname()[1]


def read_db(path):
    db = sqlite3.connect(path.as_uri() + '?mode=ro', uri=True, timeout=10)
    db.row_factory = sqlite3.Row
    return db


def fixture_from_source():
    from v3_engine.notebooklm.terminal_service import NotebookTerminalService
    service = NotebookTerminalService()
    storage = ROOT / 'storage/app/v3'
    parts, expected = [], []
    with read_db(ROOT / 'database/database.sqlite') as db:
        for item in db.execute('SELECT * FROM v3_subject_run_items WHERE run_id=? ORDER BY pdf_ordinal', (SOURCE_RUN,)):
            def answer(q):
                data = (storage / q['answer_path']).read_bytes()
                assert sha(data) == q['answer_sha256']
                return data.decode()
            mapped = db.execute('SELECT q.* FROM v3_lesson_maps m JOIN v3_lesson_queries q ON q.id=m.divide_query_id WHERE m.case_id=? AND m.state=?', (item['case_id'], 'ready')).fetchone()
            execution = db.execute("SELECT * FROM v3_lesson_queries WHERE case_id=? AND kind='execution' AND state='recorded'", (item['case_id'],)).fetchone()
            document = db.execute('SELECT * FROM v3_documents WHERE id=?', (item['document_id'],)).fetchone()
            lessons = []
            for q in db.execute('SELECT q.*,b.work_ordinal FROM v3_notebook_lesson_bundles b JOIN v3_lesson_queries q ON q.id=b.query_id WHERE b.case_id=? ORDER BY b.work_ordinal', (item['case_id'],)):
                raw = answer(q)
                captured = service.capture_turn('lesson', raw, lesson_ordinal=q['work_ordinal'])
                lessons.append({'lesson_ordinal': q['work_ordinal'], 'raw_answer': raw, 'sha256': sha(raw.encode())})
                for scene in captured['handoff_data']['scenes']:
                    expected.append({'pdf_ordinal': item['pdf_ordinal'], 'lesson_ordinal': q['work_ordinal'],
                                     'scene_ordinal': scene['scene_ordinal'], 'prompt_text': scene['generated_prompt']})
            divided = answer(mapped)
            assert service.capture_turn('divide', divided)['work_count'] == len(lessons)
            parts.append({'pdf_ordinal': item['pdf_ordinal'], 'pdf_sha256': document['sha256'],
                          'lineage': json.loads(item['pdf_lineage']), 'divide_answer': divided,
                          'execution_answer': answer(execution), 'lessons': lessons})
    assert len(parts) == 4 and len(expected) == 96
    return {'source_run_id': SOURCE_RUN, 'parts': parts, 'expected_prompts': expected}


BOOT = r"""
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('testing')) throw new RuntimeException('Test-only setup');
"""
SEED = BOOT + r"""
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use App\Models\V3\ProcessingCase;
use Illuminate\Support\Str;
$source = SubjectRun::findOrFail(getenv('WATCHER_SOURCE_RUN'));
// All changes below affect only the disposable cloned database.
\Illuminate\Support\Facades\DB::table('v3_local_tasks')->update(['status'=>'cancelled']);
$teacher = $source->owner;
$teacher->password = bcrypt(getenv('WATCHER_TEST_PASSWORD')); $teacher->save();
$run = $source->replicate(['id','submission_key','request_sha256','workflow_id','temporal_run_id','namespace','finished_at','created_at','updated_at']);
$run->id = (string) Str::uuid();
$run->submission_key = (string) Str::uuid();
$run->request_sha256 = hash('sha256', $run->submission_key);
$disk = \Illuminate\Support\Facades\Storage::disk('v3_private');
$prep = $source->pdf_preparation;
$oldRoot = dirname($prep['inventory_ref']);
$newRoot = 'web-preparations/'.$source->owner_user_id.'/'.$run->submission_key.'/package';
foreach ($disk->allFiles($oldRoot) as $path) {
    $disk->copy($path, $newRoot.substr($path, strlen($oldRoot)));
}
$prep['inventory_ref'] = $newRoot.'/inventory.json';
$run->pdf_preparation = $prep;
$run->execution_mode = 'local_worker_v1';
$run->state = 'queued'; $run->state_version = 1;
$run->total_pdfs = 4; $run->total_lessons = 0; $run->total_scenes = 0;
$run->generation_phase = null; $run->counters_json = []; $run->last_event_sequence = 0;
$run->last_updated_at = null;
$run->save();
foreach ($source->items as $old) {
    $item = $old->replicate(['id','created_at','updated_at']);
    $item->id = (string) Str::uuid(); $item->run_id = $run->id;
    // Reuse the accepted upload, but let normal admission build all NEW
    // document/case/lecture/binding/activation associations itself.
    $item->document_id = null; $item->case_id = null; $item->lecture_id = null;
    $item->state = 'staged'; $item->state_version = 1; $item->save();
}
$job = app(\App\Services\V3\LocalWorker\LocalWorkerAdmissionCoordinator::class)->admit($run, 'prompts_and_scenes');
$owner = \App\Models\User::where('email','admin@test.com')->firstOrFail();
$pairing = app(\App\Services\V3\LocalWorker\DeviceManager::class)->createPairingInvitation($owner,
    'Watcher verification daemon',null,['tenant:all','authoring_v1','scene_return_v1'],
    ['notebooklm_v1','notebooklm_authoring_v1','static_three_file_v1','authoring_v1','scene_return_v1']);
echo json_encode(['run_id'=>$run->id,'job_id'=>$job->id,'teacher_email'=>$teacher->email,
                 'pairing_code'=>$pairing['pairing_code']]);
"""


def run_attempt(output, timeout):
    import httpx
    from v3_engine.local_env import load_local_env
    from v3_engine.local_worker.client import LocalWorkerHttpClient
    from v3_engine.local_worker.config import WorkerConfig, save_config
    load_local_env(ROOT / '.env')
    output.mkdir(parents=True, mode=0o700)
    fixture = fixture_from_source()
    save(output / 'retained-provider.json', fixture)
    with read_db(ROOT / 'database/database.sqlite') as source, sqlite3.connect(output / 'server.sqlite') as target:
        source.backup(target)
    shutil.copytree(ROOT / 'storage/app/v3', output / 'server-storage')
    web_port, temporal_port = port(), port()
    origin = f'http://127.0.0.1:{web_port}'
    env = dict(os.environ, APP_ENV='testing', APP_DEBUG='false', APP_URL=origin,
               APP_CONFIG_CACHE=str(output / 'no-config-cache.php'),
               DB_CONNECTION='sqlite', DB_DATABASE=str(output / 'server.sqlite'),
               V3_PRIVATE_ROOT=str(output / 'server-storage'), V3_STORAGE_ROOT=str(output / 'server-storage'),
               SESSION_DRIVER='database', CACHE_STORE='array', QUEUE_CONNECTION='database',
               V3_TERMINAL_ROOT=str(output / 'terminal'),
               V3_TERMINAL_TEMPORAL_ADDRESS=f'127.0.0.1:{temporal_port}',
               V3_TEMPORAL_ADDRESS=f'127.0.0.1:{temporal_port}',
               V3_TERMINAL_TEMPORAL_NAMESPACE='watcher-verification',
               V3_TEMPORAL_NAMESPACE='watcher-verification',
               V3_NOTEBOOKLM_STORAGE_STATE=str(output / 'provider-placeholder-session.json'),
               WATCHER_REPLAY_FIXTURE=str(output / 'retained-provider.json'),
               WATCHER_PROVIDER_TRACE=str(output / 'provider-trace.jsonl'),
               WATCHER_SOURCE_RUN=SOURCE_RUN, WATCHER_TEST_PASSWORD=uuid.uuid4().hex,
               PYTHONUNBUFFERED='1', PYTHONPATH=str(ROOT))
    # No real provider session is read or copied into this test lane.
    save(output / 'provider-placeholder-session.json', {'cookies': [], 'origins': []})
    processes, handles = [], []
    def start(command, name, cwd=ROOT):
        log = (output / f'{name}.log').open('w'); handles.append(log)
        child_env = env
        if name in ('terminal-worker', 'outbound-daemon'):
            # Match the independent laptop runtime: no Laravel environment,
            # database credentials, callback secrets, or paid generation keys.
            import runpy
            runtime = runpy.run_path(str(ROOT / 'bin/local-worker-runtime'))
            config = {'terminal_root': env['V3_TERMINAL_ROOT'],
                      'temporal_address': env['V3_TERMINAL_TEMPORAL_ADDRESS'],
                      'temporal_namespace': env['V3_TERMINAL_TEMPORAL_NAMESPACE'],
                      'session_path': env['V3_NOTEBOOKLM_STORAGE_STATE']}
            child_env = runtime['runtime_environment'](config, env)
            child_env.pop('WATCHER_TEST_PASSWORD', None)
        proc = subprocess.Popen(command, env=child_env, cwd=cwd, stdout=log, stderr=subprocess.STDOUT)
        processes.append((name, proc)); return proc
    def php(code):
        result = subprocess.run(['php','-r',code], env=env, cwd=ROOT, capture_output=True, text=True)
        assert result.returncode == 0, result.stdout + result.stderr
        return json.loads(result.stdout)
    def wait_for(check, label):
        end = time.monotonic() + timeout
        last_print = 0
        while time.monotonic() < end:
            dead = [(n,p.returncode) for n,p in processes if p.poll() is not None]
            assert not dead, f'Process stopped during {label}: {dead}'
            daemon_log = output/'outbound-daemon.log'
            if daemon_log.exists() and 'Error executing task ' in daemon_log.read_text():
                raise AssertionError('Production daemon failed; inspect outbound-daemon.log')
            value = check()
            if value: return value
            if time.monotonic()-last_print > 20:
                print('Observing:',label,flush=True);last_print=time.monotonic()
            time.sleep(1)
        raise AssertionError('Timed out observing ' + label)
    def tasks():
        with read_db(output / 'server.sqlite') as db:
            return [dict(r) for r in db.execute('SELECT task_kind,status,active_epoch FROM v3_local_tasks WHERE job_id=? ORDER BY created_at,id',(ids['job_id'],))]
    (output/'launcher.pid').write_text(str(os.getpid()))
    report = {'passed': False, 'scope': 'Real daemon + CLI + Temporal + Laravel HTTP; retained provider responses and named placeholder HTML only',
              'source_run_id': SOURCE_RUN, 'setup': 'Fresh run over existing accepted four split PDFs in cloned DB/storage',
              'no_paid_calls': True, 'manual_task_advancement': False,
              'observer_actions': ['setup isolated accepted inputs and enroll device', 'read progress and evidence', 'write authorized placeholder HTML files', 'teacher HTTP Pull', 'graceful shutdown after result'],
              'stage_results': {}}
    try:
        migration = subprocess.run(['php','artisan','migrate','--force','--no-ansi'],env=env,cwd=ROOT,capture_output=True,text=True)
        (output/'migrations.log').write_text(migration.stdout+migration.stderr)
        assert migration.returncode == 0
        ids = php(SEED)
        report.update(run_id=ids['run_id'], job_id=ids['job_id'], server_origin=origin)
        save(output/'runtime.json',{k:v for k,v in report.items() if k!='stage_results'})
        start(['temporal','server','start-dev','--headless','--ip','127.0.0.1','--port',str(temporal_port),
               '--namespace','watcher-verification','--db-filename',str(output/'temporal.sqlite')],'temporal')
        def listening(p):
            try:
                with socket.create_connection(('127.0.0.1',p),timeout=.2):return True
            except OSError:return False
        wait_for(lambda:listening(temporal_port),'Temporal startup')
        start([sys.executable,'scripts/v3/verification/retained_provider.py'],'terminal-worker')
        start(['php','-d','variables_order=EGPCS','-S',f'127.0.0.1:{web_port}',str(ROOT/'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],'laravel',ROOT/'public')
        wait_for(lambda:listening(web_port),'Laravel startup')
        client = LocalWorkerHttpClient(server_origin=origin)
        enrolled = client.redeem_enrollment(pairing_secret=ids['pairing_code'],device_name='Watcher daemon',device_uuid=str(uuid.uuid4()),os_family='linux')
        template = output/'terminal-template.json'
        save(template,{'schema_version':1,'subjects':[{'mode':'new','provider':'gemini','model':'gemini-2.5-flash','account_check_notebook_id':'retained-test-proof'}]})
        save_config(WorkerConfig(origin,enrolled['device_id'],enrolled['device_token'],str(output/'worker'),['authoring_v1','scene_return_v1'],str(template)),output/'worker-config.json')
        # Actual production daemon, no offline-double flag and no Python calls to its runner.
        start([sys.executable,'-m','v3_engine.local_worker.cli','run','--config',str(output/'worker-config.json'),'--poll-interval','1'],'outbound-daemon')
        handoff_path = output/'worker/runs'/ids['run_id']/'run.json'
        def authored():
            states = tasks()
            for row in states:
                assert row['status'] not in ('failed','reconciliation_required','paused_auth','paused_quota'),states
            return handoff_path.exists() and any(r['task_kind']=='return_scenes' and r['status']=='awaiting_pull' for r in states)
        wait_for(authored,'worker authoring and automatic prompt storage')
        handoff=json.loads(handoff_path.read_text()); entries=handoff['generated_prompts']
        assert len(entries)==96, len(entries)
        with read_db(output/'server.sqlite') as db:
            lesson_count=db.execute('SELECT COUNT(*) FROM v3_prompt_work_items b JOIN v3_subject_run_items i ON i.case_id=b.case_id WHERE i.run_id=?',(ids['run_id'],)).fetchone()[0]
            assert lesson_count==61,lesson_count
        trace=[json.loads(x) for x in (output/'provider-trace.jsonl').read_text().splitlines()]
        uploads=[r for r in trace if r['kind']=='upload' and r['pdf_ordinal'] is not None]
        answers=[r for r in trace if r['kind']=='answer' and r['turn_kind']=='lesson']
        assert [r['answer_sha256'] for r in answers]==[lesson['sha256'] for part in fixture['parts'] for lesson in part['lessons']]
        assert [r['pdf_ordinal'] for r in uploads]==[1,2,3,4]
        assert [r['sha256'] for r in uploads]==[r['pdf_sha256'] for r in fixture['parts']]
        report['stage_results']['1_ordered_pdf_download']={'passed':True,'ranges':[[r['lineage']['start_page'],r['lineage']['end_page']] for r in fixture['parts']], 'sha256_verified':True}
        link=next((output/'worker/tasks').glob('*/terminal_link.json'))
        linked=json.loads(link.read_text()); terminal_run=linked['terminal_run_id']
        terminal_report=json.loads((output/'terminal/runs'/terminal_run/'final-report.json').read_text())
        assert terminal_report['status']=='prompts_exported',terminal_report
        import asyncio
        from temporalio.client import Client
        async def workflow_evidence():
            tc=await Client.connect(env['V3_TERMINAL_TEMPORAL_ADDRESS'],namespace='watcher-verification')
            handle=tc.get_workflow_handle('v3-terminal-subject-'+terminal_run)
            description=await handle.describe()
            history=await handle.fetch_history()
            from collections import Counter
            counts=Counter(e.activity_task_scheduled_event_attributes.activity_type.name for e in history.events if e.HasField('activity_task_scheduled_event_attributes'))
            return {'status':description.status.name,'event_count':len(history.events),'activity_counts':dict(counts)}
        temporal_evidence=asyncio.run(workflow_evidence())
        assert temporal_evidence['status']=='COMPLETED',temporal_evidence
        assert temporal_evidence['activity_counts']['send_terminal_turn']==69,temporal_evidence
        save(output/'temporal-evidence.json',temporal_evidence)
        report['stage_results']['2_real_terminal_temporal']={'passed':True,'terminal_run_id':terminal_run,**temporal_evidence}
        dashboard=httpx.Client(base_url=origin,follow_redirects=True,timeout=60)
        page=dashboard.get('/login');csrf=re.search(r'<meta name="csrf-token" content="([^"]+)"',page.text).group(1)
        response=dashboard.post('/login',data={'email':ids['teacher_email'],'password':env['WATCHER_TEST_PASSWORD'],'_token':csrf})
        assert response.status_code==200,response.status_code
        page=dashboard.get('/v3/teacher/subject-runs/'+ids['run_id']);assert page.status_code==200
        csrf=re.search(r'<meta name="csrf-token" content="([^"]+)"',page.text).group(1)
        exported=dashboard.get('/v3/teacher/subject-runs/'+ids['run_id']+'/prompt-export?format=json',headers={'Accept':'application/json'})
        assert exported.status_code==200,exported.text[:300]
        export=exported.json();save(output/'dashboard-prompts.json',export)
        rows=export['prompts'];assert len(rows)==96
        assert [r['prompt_text'] for r in rows]==[r['prompt_text'] for r in fixture['expected_prompts']]
        assert [r['prompt_text'] for r in entries]==[r['prompt_text'] for r in rows]
        assert [r['scene_id'] for r in entries]==[r['scene_id'] for r in rows]
        # Local-worker wire ordinals are zero-based; retained terminal labels are one-based.
        assert [(r['pdf_ordinal'],r['lesson_ordinal']+1,r['scene_ordinal']+1) for r in rows]==[(r['pdf_ordinal'],r['lesson_ordinal'],r['scene_ordinal']) for r in fixture['expected_prompts']]
        report['stage_results']['3_ordered_prompt_storage']={'passed':True,'lessons':61,'prompts':96,'retained_text_exact':True,'dashboard_order_verified':True}
        expected_files=[]
        # Authorized generation substitute, separate from worker automation.
        for index,(entry,row) in enumerate(zip(entries,rows),1):
            title=f"{index:03d} — {row['section_title']}"
            data=('<!doctype html><html lang="en"><head><meta charset="utf-8"><title>'+html.escape(title)+'</title></head><body><h1>'+html.escape(title)+'</h1><p>WORKFLOW TEST PLACEHOLDER — no educational scene generated.</p></body></html>').encode()
            target=Path(entry['entrypoint']);assert target.resolve().is_relative_to((output/'worker').resolve())
            target.write_bytes(data)
            expected_files.append({'scene_id':row['scene_id'],'prompt_version_id':row['prompt_version_id'],'sha256':sha(data),'path':str(target),'title':title})
        save(output/'placeholder-inventory.json',expected_files)
        pull=dashboard.post('/v3/teacher/subject-runs/'+ids['run_id']+'/pull-scenes',json={'request_id':str(uuid.uuid4())},headers={'Accept':'application/json','X-CSRF-TOKEN':csrf})
        assert pull.status_code==202,pull.text
        save(output/'pull-receipt.json',pull.json())
        def returned():
            states=tasks()
            assert not any(r['status'] in ('failed','reconciliation_required') for r in states),states
            return any(r['task_kind']=='return_scenes' and r['status']=='completed' for r in states)
        wait_for(returned,'daemon scene upload after teacher Pull')
        with read_db(output/'server.sqlite') as db:
            candidates=[dict(r) for r in db.execute('SELECT c.* FROM v3_scene_candidates c JOIN v3_subject_run_items i ON i.case_id=c.case_id WHERE i.run_id=?',(ids['run_id'],))]
            assert len(candidates)==96,len(candidates)
            by_scene={c['section_id']:c for c in candidates}
            for expected in expected_files:
                c=by_scene[expected['scene_id']]
                assert c['prompt_version_id']==expected['prompt_version_id']
                path=output/'server-storage'/Path(c['manifest_path']).parent/'index.html'
                assert sha(path.read_bytes())==expected['sha256']
                manifest=json.loads((output/'server-storage'/c['manifest_path']).read_text())
                assert manifest['section_id']==expected['scene_id']
        scenes=dashboard.get('/v3/teacher/subject-runs/'+ids['run_id']+'/scenes',headers={'Accept':'application/json'})
        assert scenes.status_code==200,scenes.text[:300]
        scene_data=scenes.json();save(output/'dashboard-scenes.json',scene_data)
        assert [r['section']['id'] for r in scene_data['data']['scenes']]==[r['scene_id'] for r in rows]
        assert all(r.get('candidate') for r in scene_data['data']['scenes'])
        # Preserve the server ordering response for independent review as well.
        report['stage_results']['4_placeholder_scene_return']={'passed':True,'candidates':96,'all_hosted_bytes_and_bindings_verified':True,'teacher_http_status':scenes.status_code,'educational_quality_tested':False}
        report['final_tasks']=tasks();report['passed']=True
        dashboard.close()
        print('PASS: real daemon / Temporal, four ordered PDFs, 61 lessons, 96 prompts, 96 returned placeholder HTML files.',flush=True)
    except BaseException as exc:
        report['error']=repr(exc)
        print('FAIL:',str(exc),flush=True)
        raise
    finally:
        save(output/'verification.json',report)
        # Graceful daemon drain is a normal shutdown, after the result is known.
        (output/'worker').mkdir(exist_ok=True)
        (output/'worker/drain.signal').touch()
        for name,proc in reversed(processes):
            if proc.poll() is None:
                if name=='outbound-daemon':
                    try:proc.wait(timeout=8)
                    except subprocess.TimeoutExpired:proc.terminate()
                else:proc.terminate()
                try:proc.wait(timeout=15)
                except subprocess.TimeoutExpired:proc.kill();proc.wait(timeout=3)
        for handle in handles:handle.close()
    return report


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output',type=Path,required=True)
    parser.add_argument('--timeout',type=int,default=600)
    args=parser.parse_args()
    run_attempt(args.output.resolve(),args.timeout)
