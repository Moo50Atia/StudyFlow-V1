"""Real terminal capture/archive/export logic; external provider and transport are doubles."""

import asyncio
from datetime import datetime, timedelta, timezone
import hashlib
import json
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import AsyncMock, MagicMock
import uuid

import pytest

from v3_engine.local_worker.journal import LocalEffectJournal
from v3_engine.local_worker.runner import StageContext, TaskRunner
import httpx
from v3_engine.local_worker.terminal import TerminalPipelineAdapter, TerminalPipelineError
from v3_engine.local_worker.protocol import validate_authoring_result, validate_scene_result
from v3_engine.local_worker.protocol import LocalWorkerProtocolError
from v3_engine.notebooklm.terminal_service import NotebookTerminalService
from v3_engine.terminal_pipeline import activities
from v3_engine.terminal_pipeline.cli import run_cli
from v3_engine.terminal_pipeline.manifest import capture_manifest
from v3_engine.tests.terminal_pipeline.test_per_pdf import NotebookDouble, _write_test_pdf
from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT, EXECUTION_PROMPT


def make_terminal_command_double(monkeypatch, terminal_root, lesson_response=None):
    class ResponseDouble(NotebookDouble):
        async def ask(self, notebook, question, source_ids, conversation_id=None):
            response = await super().ask(notebook, question, source_ids, conversation_id)
            if lesson_response is not None and question not in (DIVIDE_PROMPT, EXECUTION_PROMPT):
                response.answer = lesson_response
                self.histories[self.current][-1] = (question, lesson_response)
            return response
    double = ResponseDouble()
    monkeypatch.setattr(activities, 'NotebookTerminalService', lambda: NotebookTerminalService(double))
    commands = []

    async def finish_terminal(subject, run_dir):
        pdf_count = sum(row.kind == 'pdf' for row in subject.inputs)
        for ordinal in range(1, pdf_count + 1):
            # Keep one mapped lesson per PDF, each with three real parsed prompts.
            double.active_pdf = 1
            common_turn = {'subject_run_id': subject.subject_run_id, 'notebook_id': 'one-notebook',
                           'pdf_ordinal': ordinal, 'pdf_source_ids': [f'pdf-source-{ordinal}'],
                           'framework_source_id': 'source-steps'}
            for kind, question, sources, lesson_ordinal in (
                ('divide', DIVIDE_PROMPT, [f'pdf-source-{ordinal}'], None),
                ('execution', EXECUTION_PROMPT, [f'pdf-source-{ordinal}', 'source-steps'], None),
                ('lesson', 'Start with First lesson', [f'pdf-source-{ordinal}', 'source-steps'], 1),
            ):
                turn = await activities.send_terminal_turn({**common_turn,
                    'operation_id': f'pdf-{ordinal:04d}:{kind}', 'question': question,
                    'source_ids': sources, 'conversation_id': None if kind == 'divide' else f'conv-{ordinal}',
                    **({'fresh_after_conversation_id': f'conv-{ordinal - 1}'} if ordinal > 1 and kind == 'divide' else {}),
                })
                await activities.capture_terminal_turn({**common_turn, 'turn_kind': kind,
                    'lesson_ordinal': lesson_ordinal, 'receipt_ref': turn['receipt_ref'],
                    'conversation_id': turn['result_ref']})
            common = {'subject_run_id': subject.subject_run_id, 'pdf_ordinal': ordinal,
                      'notebook_id': 'one-notebook', 'conversation_id': f'conv-{ordinal}'}
            assert (await activities.archive_terminal_pdf(common))['outcome'] == 'complete'
            if ordinal < pdf_count:
                assert (await activities.reset_terminal_conversation(common))['outcome'] == 'complete'
        exported = await activities.export_terminal_scene_prompts({'subject_run_id': subject.subject_run_id})
        assert exported['outcome'] == 'complete'
        await activities.finalize_terminal_run({'subject_run_id': subject.subject_run_id,
            'terminal_status': 'prompts_exported', 'scene_generation_mode': 'export_prompts',
            'prompt_export_ref': exported['result_ref'], 'prompt_export_count': exported['counts']['scene_count']})

    def execute(argv, **kwargs):
        assert kwargs['cwd'] == Path(__file__).resolve().parents[3]
        assert kwargs['env']['V3_TERMINAL_ROOT'] == str(terminal_root)
        args = argv[2:]
        commands.append(args)
        assert args[0] == 'submit' and '--export-prompts' in args and 'per-pdf' in args
        manifest_path = Path(args[args.index('--manifest') + 1])
        update = MagicMock(result=AsyncMock(return_value={'outcome': 'accepted'}))
        client = MagicMock(start_update_with_start_workflow=AsyncMock(return_value=update))
        code, stdout = run_cli(args, client)
        assert code == 0, stdout
        snapshot, _ = capture_manifest(json.loads(manifest_path.read_text()), terminal_root, kwargs['cwd'],
                                       scene_generation_mode='export_prompts', pdf_processing='per_pdf',
                                       prepared_input_manifest=Path(args[args.index('--prepared-input-manifest') + 1]))
        subject = snapshot.subjects[0]
        run_dir = terminal_root / 'runs' / subject.subject_run_id
        run_dir.mkdir(parents=True)
        (run_dir / 'subject-config.json').write_text(json.dumps(subject.to_dict()))
        asyncio.run(finish_terminal(subject, run_dir))
        return SimpleNamespace(returncode=code, stdout=stdout)

    return execute, commands


@pytest.fixture
def terminal_setup(tmp_path, monkeypatch):
    workspace = tmp_path / 'worker'
    terminal_root = tmp_path / 'terminal'
    monkeypatch.setenv('V3_TERMINAL_ROOT', str(terminal_root))
    master = tmp_path / 'terminal-session.json'
    master.write_text('{"cookies":[],"origins":[]}')
    monkeypatch.setenv('V3_NOTEBOOKLM_STORAGE_STATE', str(master))
    ids = [str(uuid.uuid4()) for _ in range(4)]
    manifest = {}
    ctx = StageContext(ids[0], ids[1], ids[2], 'author_prompts', ids[3], 1, 1, manifest, 'c' * 64, workspace)
    for ordinal in (1, 2):
        path = ctx.inputs_dir / f'{ordinal:04d}.pdf'
        _write_test_pdf(path, 'A real fixture PDF ' + str(ordinal))
        artifact_id = str(uuid.uuid4())
        from v3_engine.tests.local_worker.test_runner import make_dummy_manifest
        base = json.loads(make_dummy_manifest(ids[0], ids[1], ids[2], artifact_id, path.read_bytes()))
        part = next(row for row in base['artifacts'] if row['artifact_id'] == artifact_id)
        part.update(logical_name=path.name, purpose='prepared_pdf')
        base['items'][0].update(upload_ordinal=ordinal, source_ordinal=ordinal)
        next(row for row in base['artifacts'] if row['purpose'] == 'original_pdf')['logical_name'] = f'original-{ordinal}.pdf'
        if ordinal == 1:
            manifest.update(base)
        else:
            manifest['items'].extend(base['items'])
            manifest['artifacts'].extend(row for row in base['artifacts'] if row['purpose'] in {'prepared_pdf', 'original_pdf'})
    (ctx.inputs_dir / 'framework.txt').write_text('Approved framework body')
    raw_manifest = json.dumps(manifest).encode()
    ctx.manifest_sha256 = hashlib.sha256(raw_manifest).hexdigest()
    (ctx.task_dir / 'input_manifest.json').write_bytes(raw_manifest)
    (ctx.task_dir / 'input_manifest.sha256').write_text(ctx.manifest_sha256)
    template = tmp_path / 'existing-terminal-submission.json'
    template.write_text(json.dumps({'subjects': [{'mode': 'new', 'provider': 'gemini',
        'model': 'gemini-2.5-flash', 'account_check_notebook_id': 'proof'}]}))
    execute, commands = make_terminal_command_double(monkeypatch, terminal_root)
    adapter = TerminalPipelineAdapter(template, terminal_root=terminal_root, command_runner=execute, poll_interval=0)
    return ctx, adapter, commands


def test_terminal_cli_is_reused_and_prepared_input_identity_is_retained(terminal_setup):
    ctx, adapter, commands = terminal_setup
    request = adapter.prepare(ctx)
    first = (ctx.task_dir / 'terminal_submission.json').read_bytes()
    adapter.manifest_template.write_text('{"subjects": []}')
    assert adapter.prepare(ctx) == request  # Preserve captured local settings on restart.
    assert (ctx.task_dir / 'terminal_submission.json').read_bytes() == first
    hb = SimpleNamespace(is_lease_healthy=lambda: True)
    exported = json.loads(adapter.execute_operation('terminal_pipeline_export', request, {'context': ctx, 'heartbeat': hb}))
    assert exported['scene_count'] == 6 and exported['pdf_count'] == 2
    assert len(commands) == 1
    link = json.loads((ctx.task_dir / 'terminal_link.json').read_text())
    capture = Path(link['run_dir']) / 'pdfs/pdf-0001/private/turn-captures/lesson-001.json'
    capture.write_text('{}')
    with pytest.raises(ValueError, match='archive_corrupt'):
        adapter.load_export(ctx, link)


def test_legacy_prepared_part_is_not_split_and_changed_manifest_cannot_replay(terminal_setup, monkeypatch):
    from reportlab.pdfgen import canvas
    ctx, adapter, _ = terminal_setup
    pdf = ctx.inputs_dir / '0001.pdf'
    c = canvas.Canvas(str(pdf))
    for page in range(70):
        c.drawString(100, 700, f'Legacy intact source page {page + 1}')
        c.showPage()
    c.save()
    item = ctx.manifest['items'][0]
    item.update(original_page_count=70, original_page_end=70, prepared_page_count=70,
                prepared_sha256=hashlib.sha256(pdf.read_bytes()).hexdigest(), prepared_byte_count=pdf.stat().st_size)
    art = next(row for row in ctx.manifest['artifacts'] if row['artifact_id'] == item['prepared_artifact_id'])
    art.update(digest_sha256=item['prepared_sha256'], size_bytes=item['prepared_byte_count'])
    raw = json.dumps(ctx.manifest).encode()
    ctx.manifest_sha256 = hashlib.sha256(raw).hexdigest()
    hosted_path = ctx.task_dir / 'input_manifest.json'
    hosted_path.write_bytes(raw)
    hosted_path.with_suffix('.sha256').write_text(ctx.manifest_sha256)
    adapter.prepare(ctx)
    def must_not_split(*args, **kwargs):
        raise AssertionError('Already prepared hosted PDFs must never be split locally')
    monkeypatch.setattr('v3_engine.terminal_pipeline.pdf_preparation.prepare_pdf_sources', must_not_split)
    subject_raw = json.loads((ctx.task_dir / 'terminal_submission.json').read_text())
    snapshot, _ = capture_manifest(subject_raw, adapter.terminal_root, adapter.project_root,
        scene_generation_mode='export_prompts', pdf_processing='per_pdf', prepared_input_manifest=hosted_path)
    subject = snapshot.subjects[0]
    assert len([row for row in subject.inputs if row.kind == 'pdf']) == 2
    assert subject.inputs[0].sha256 == item['prepared_sha256']
    assert subject.inputs[0].pdf_lineage['end_page'] == 70
    capture_manifest(subject_raw, adapter.terminal_root, adapter.project_root,
        scene_generation_mode='export_prompts', pdf_processing='per_pdf', prepared_input_manifest=hosted_path)
    ctx.manifest['items'][0]['item_id'] = str(uuid.uuid4())
    hosted_path.write_text(json.dumps(ctx.manifest))
    hosted_path.with_suffix('.sha256').write_text(hashlib.sha256(hosted_path.read_bytes()).hexdigest())
    with pytest.raises(Exception, match='Hosted manifest changed'):
        capture_manifest(subject_raw, adapter.terminal_root, adapter.project_root,
            scene_generation_mode='export_prompts', pdf_processing='per_pdf', prepared_input_manifest=hosted_path)


def test_owner_authorized_completed_terminal_is_reused_without_submit(terminal_setup):
    ctx, adapter, commands = terminal_setup
    request = adapter.prepare(ctx)
    hb = SimpleNamespace(is_lease_healthy=lambda: True)
    expected = adapter.execute_operation('terminal_pipeline_export', request, {'context': ctx, 'heartbeat': hb})
    digest = hashlib.sha256(json.dumps(request, sort_keys=True).encode()).hexdigest()
    evidence = adapter.recovery_evidence(ctx, digest)
    assert evidence['terminal_status'] == 'prompts_exported'
    assert 'manifest_path' not in evidence and 'notebook_id' not in evidence
    result = adapter.execute_operation('terminal_pipeline_export', request,
        {'context': ctx, 'heartbeat': hb, 'terminal_recovery': evidence})
    assert result == expected and len(commands) == 1


def test_owner_authorized_paused_terminal_resumes_same_run_without_submit(terminal_setup):
    ctx, adapter, commands = terminal_setup
    request = adapter.prepare(ctx)
    hb = SimpleNamespace(is_lease_healthy=lambda: True)
    original = adapter.execute_operation('terminal_pipeline_export', request, {'context': ctx, 'heartbeat': hb})
    link = json.loads((ctx.task_dir / 'terminal_link.json').read_text())
    report = Path(link['run_dir']) / 'final-report.json'
    retained = report.read_bytes()
    report.unlink()
    controls = []
    def command(*args):
        controls.append(args)
        if args[0] == 'status':
            return {'subject_run_id': link['terminal_run_id'], 'freshness': 'realtime', 'status': 'paused', 'state_version': 4}
        assert args[0] == 'resume' and args[args.index('--run-id') + 1] == link['terminal_run_id']
        report.write_bytes(retained)
        return {'outcome': 'accepted'}
    adapter.command = command
    digest = hashlib.sha256(json.dumps(request, sort_keys=True).encode()).hexdigest()
    evidence = adapter.recovery_evidence(ctx, digest)
    assert evidence['terminal_status'] == 'paused'
    actual = adapter.execute_operation('terminal_pipeline_export', request,
        {'context': ctx, 'heartbeat': hb, 'terminal_recovery': evidence})
    assert actual == original and len(commands) == 1
    assert [row[0] for row in controls] == ['status', 'status', 'resume']


def test_worker_reconciles_unknown_terminal_effect_using_retained_export(terminal_setup):
    ctx, adapter, commands = terminal_setup
    request = adapter.prepare(ctx)
    hb = SimpleNamespace(is_lease_healthy=lambda: True)
    adapter.execute_operation('terminal_pipeline_export', request, {'context': ctx, 'heartbeat': hb})
    digest = hashlib.sha256(json.dumps(request, sort_keys=True).encode()).hexdigest()
    evidence = adapter.recovery_evidence(ctx, digest)
    client = MagicMock()
    journal = LocalEffectJournal(ctx.workspace_dir / 'journal.sqlite')
    effect_id, old_attempt, new_attempt = [str(uuid.uuid4()) for _ in range(3)]
    key = ctx.job_id + ':terminal-export-v1'
    journal.record_intent(effect_id, ctx.task_id, key, 'terminal_pipeline_export', digest)
    journal.record_permit(old_attempt, effect_id, 1, str(uuid.uuid4()), '2030-01-01T00:00:00Z')
    journal.mark_may_have_been_sent(old_attempt)
    client.reserve_effect.return_value = {'effect_id': effect_id, 'status': 'reserved', 'terminal_recovery': evidence}
    client.dispatch_effect.return_value = {'permit_id': str(uuid.uuid4()), 'attempt_id': new_attempt,
        'attempt_number': 2, 'send_not_after': (datetime.now(timezone.utc) + timedelta(seconds=10)).isoformat()}
    worker = TaskRunner(client, journal, ctx.workspace_dir, adapter)
    actual = worker.execute_effect(ctx, hb, 'terminal_pipeline_export', key, request, {'context': ctx, 'heartbeat': hb})
    assert json.loads(actual['raw_text'])['scene_count'] == 6
    assert journal.get_effect(effect_id).status == 'succeeded'
    assert len(commands) == 1


def test_interrupted_scene_upload_reuses_frozen_bytes_after_operator_edit(tmp_path, monkeypatch):
    ids = [str(uuid.uuid4()) for _ in range(5)]
    targets = [{'scene_id': str(uuid.uuid4()), 'prompt_version_id': str(uuid.uuid4()), 'prompt_sha256': 'a' * 64}
               for _ in range(2)]
    ctx = StageContext(ids[0], ids[1], ids[2], 'return_scenes', ids[3], 1, 1,
        {'required_scenes': targets}, 'b' * 64, tmp_path)
    for target in targets:
        folder = tmp_path / 'runs' / ctx.run_id / 'scenes' / target['scene_id']
        folder.mkdir(parents=True)
        (folder / 'index.html').write_text('<html>Original transfer fixture</html>')
    monkeypatch.setattr(StageContext, 'resolve_and_download', lambda *args: ctx)
    monkeypatch.setattr('v3_engine.local_worker.runner.HeartbeatManager', MagicMock())
    claim = {'task_id': ctx.task_id, 'job_id': ctx.job_id, 'run_id': ctx.run_id,
        'task_kind': 'return_scenes', 'assignment_id': ctx.assignment_id, 'assignment_epoch': 1,
        'control_version': 1, 'input_manifest_sha256': ctx.manifest_sha256, 'device_id': ids[4],
        'lease_expires_at': (datetime.now(timezone.utc) + timedelta(seconds=120)).isoformat()}
    client = MagicMock()
    client.create_upload_slot.return_value = {'status': 'staging'}
    client.upload_bytes.side_effect = httpx.ConnectError('fixture disconnected during upload')
    worker = TaskRunner(client, LocalEffectJournal(tmp_path / 'journal.sqlite'), tmp_path)
    with pytest.raises(httpx.ConnectError):
        worker.run_scene_return_stage(claim, operator_provenance={'operator_id': 1})
    first_bytes = client.upload_bytes.call_args.kwargs['data']
    (tmp_path / 'runs' / ctx.run_id / 'scenes' / targets[0]['scene_id'] / 'index.html').write_text('Edited after interruption')
    client.upload_bytes.side_effect = None
    client.submit_result.return_value = {'status': 'completed'}
    worker.run_scene_return_stage(claim, operator_provenance={'operator_id': 1})
    assert client.upload_bytes.call_args_list[1].kwargs['data'] == first_bytes
    assert json.loads(first_bytes)['files'][0]['content'] == '<html>Original transfer fixture</html>'


def test_worker_exports_agent_handoff_then_returns_exact_manual_html(terminal_setup, monkeypatch):
    ctx, adapter, commands = terminal_setup
    client = MagicMock()
    runner = TaskRunner(client, LocalEffectJournal(ctx.workspace_dir / 'effects.sqlite'), ctx.workspace_dir, adapter)
    claim = {'task_id': ctx.task_id, 'job_id': ctx.job_id, 'run_id': ctx.run_id,
             'task_kind': 'author_prompts', 'assignment_id': ctx.assignment_id,
             'assignment_epoch': 1, 'control_version': 1, 'input_manifest_sha256': ctx.manifest_sha256,
             'lease_expires_at': (datetime.now(timezone.utc) + timedelta(seconds=120)).isoformat()}
    monkeypatch.setattr(StageContext, 'resolve_and_download', lambda *args: ctx)
    monkeypatch.setattr('v3_engine.local_worker.runner.HeartbeatManager', MagicMock())
    def execute_effect(context, hb, op, key, request, inputs, **kwargs):
        raw = adapter.execute_operation(op, request, inputs)
        return {'raw_text': raw, 'raw_artifact_id': str(uuid.uuid4()), 'raw_sha256': hashlib.sha256(raw.encode()).hexdigest()}
    runner.execute_effect = execute_effect
    submissions = []
    def accept(task_id, result_payload, assignment_id, assignment_epoch):
        result = result_payload
        if result['kind'] == 'local_worker_scene_result':
            validate_scene_result(result)
            return {'status': 'completed'}
        validate_authoring_result(result)
        submissions.append(result)
        bindings = []
        for lesson in result['lessons']:
            for scene in lesson['scenes']:
                bindings.append({**scene, 'item_id': lesson['item_id'], 'lesson_ordinal': lesson['lesson_ordinal'],
                                 'scene_id': str(uuid.uuid4()), 'prompt_version_id': str(uuid.uuid4())})
        return {'scene_bindings': bindings, 'status': 'completed'}
    client.submit_result.side_effect = accept
    receipt = runner.run_authoring_stage(claim)
    handoff_path = ctx.workspace_dir / 'runs' / ctx.run_id / 'run.json'
    handoff = json.loads(handoff_path.read_text())
    assert handoff['run_id'] == ctx.run_id and len(handoff['input']['pdfs']) == 2
    assert len(handoff['generated_prompts']) == 6
    assert handoff['terminal_run_id'] != ctx.run_id
    assert handoff['generated_prompts'][0]['prompt_text'] == submissions[0]['lessons'][0]['scenes'][0]['prompt_text']
    for scene in handoff['generated_prompts']:
        Path(scene['entrypoint']).write_text('<html><script>/* real operator test file */</script></html>')
    # A separate scene-return task reads only the files that the agent was told to write.
    scene_task_id = str(uuid.uuid4())
    scene_ctx = StageContext(scene_task_id, ctx.job_id, ctx.run_id, 'return_scenes', str(uuid.uuid4()), 1, 1,
                             {'required_scenes': receipt['scene_bindings']}, 'd' * 64, ctx.workspace_dir)
    monkeypatch.setattr(StageContext, 'resolve_and_download', lambda *args: scene_ctx)
    scene_claim = {**claim, 'task_id': scene_task_id, 'task_kind': 'return_scenes',
                   'assignment_id': scene_ctx.assignment_id, 'input_manifest_sha256': 'd' * 64, 'device_id': str(uuid.uuid4())}
    client.create_upload_slot.return_value = {'status': 'staging'}
    assert runner.run_scene_return_stage(scene_claim, operator_provenance={'operator_id': 1})['status'] == 'completed'
    assert len(commands) == 1  # Manual return did not call a provider or rerun authoring.
    packages = [json.loads(call.kwargs['data']) for call in client.upload_bytes.call_args_list if call.kwargs.get('media_type') == 'application/json']
    assert len(packages) == 6
    assert all([f['path'] for f in pkg['files']] == ['index.html'] for pkg in packages)


def test_premature_pull_uploads_nothing_until_all_scene_files_exist(tmp_path, monkeypatch):
    task_id, job_id, run_id, assignment_id = [str(uuid.uuid4()) for _ in range(4)]
    targets = [{'scene_id': str(uuid.uuid4()), 'prompt_version_id': str(uuid.uuid4()),
                'prompt_sha256': 'a' * 64} for _ in range(3)]
    ctx = StageContext(task_id, job_id, run_id, 'return_scenes', assignment_id, 1, 1,
                       {'required_scenes': targets}, 'b' * 64, tmp_path)
    scene_dir = tmp_path / 'runs' / run_id / 'scenes' / targets[0]['scene_id']
    scene_dir.mkdir(parents=True)
    (scene_dir / 'index.html').write_text('<html>First scene really exists</html>')
    claim = {'task_id': task_id, 'job_id': job_id, 'run_id': run_id, 'task_kind': 'return_scenes',
             'assignment_id': assignment_id, 'assignment_epoch': 1, 'control_version': 1,
             'device_id': str(uuid.uuid4()), 'input_manifest_sha256': 'b' * 64,
             'lease_expires_at': (datetime.now(timezone.utc) + timedelta(seconds=120)).isoformat()}
    monkeypatch.setattr(StageContext, 'resolve_and_download', lambda *args: ctx)
    monkeypatch.setattr('v3_engine.local_worker.runner.HeartbeatManager', MagicMock())
    client = MagicMock()
    runner = TaskRunner(client, LocalEffectJournal(tmp_path / 'effects.sqlite'), tmp_path)
    with pytest.raises(LocalWorkerProtocolError, match='Scene output is missing') as missing:
        runner.run_scene_return_stage(claim, operator_provenance={'operator_id': 1})
    assert all(target['scene_id'] in str(missing.value) for target in targets[1:])
    client.create_upload_slot.assert_not_called()
    client.submit_result.assert_not_called()


def test_read_only_status_retries_native_process_crash(tmp_path, monkeypatch):
    from types import SimpleNamespace
    from v3_engine.local_worker.terminal import TerminalPipelineAdapter
    responses = iter([SimpleNamespace(returncode=-11, stdout=''),
                      SimpleNamespace(returncode=0, stdout='{"status":"running"}')])
    calls = []
    def command(*args, **kwargs):
        calls.append(args)
        return next(responses)
    monkeypatch.setattr('v3_engine.local_worker.terminal.time.sleep', lambda _: None)
    adapter = TerminalPipelineAdapter(tmp_path/'template.json',command_runner=command)
    assert adapter.command('status','--json')['status'] == 'running'
    assert len(calls) == 2


def test_mutating_terminal_commands_are_not_retried_after_crash(tmp_path):
    from types import SimpleNamespace
    from v3_engine.local_worker.terminal import TerminalPipelineAdapter, TerminalPipelineError
    calls = []
    def command(*args, **kwargs):
        calls.append(args)
        return SimpleNamespace(returncode=-11, stdout='')
    adapter = TerminalPipelineAdapter(tmp_path/'template.json',command_runner=command)
    with pytest.raises(TerminalPipelineError):
        adapter.command('submit','--json')
    assert len(calls) == 1


def test_recovery_observation_failure_reconnects_without_resubmitting(tmp_path,monkeypatch):
    from v3_engine.local_worker.terminal import TerminalObservationError
    task_dir=tmp_path/'task';task_dir.mkdir()
    run_dir=tmp_path/'run';run_dir.mkdir()
    terminal_id=str(uuid.uuid4())
    (task_dir/'terminal_link.json').write_text(json.dumps({'terminal_run_id':terminal_id,'run_dir':str(run_dir)}))
    adapter=TerminalPipelineAdapter(tmp_path/'template.json',poll_interval=0)
    adapter.recovery_evidence=MagicMock(side_effect=[TerminalObservationError('temporary'),{'terminal_status':'running'}])
    adapter.load_export=MagicMock(return_value={'verified':'retained'})
    adapter.command=MagicMock(side_effect=AssertionError('No submit or provider command is permitted'))
    hb=SimpleNamespace(is_lease_healthy=lambda:True)
    def wait(_):
        (run_dir/'final-report.json').write_text(json.dumps({'subject_run_id':terminal_id,'status':'prompts_exported','scene_generation_mode':'export_prompts'}))
    monkeypatch.setattr('v3_engine.local_worker.terminal.time.sleep',wait)
    result=adapter.execute_operation('terminal_pipeline_export',{}, {'context':SimpleNamespace(task_dir=task_dir),
        'heartbeat':hb,'terminal_recovery':{'terminal_run_id':terminal_id,'request_digest':'digest','terminal_status':'running'}})
    assert json.loads(result)=={'verified':'retained'}
    assert adapter.recovery_evidence.call_count==2
    adapter.command.assert_not_called()


def test_observation_reconnection_stops_when_lease_is_lost(tmp_path):
    adapter=TerminalPipelineAdapter(tmp_path/'template.json')
    with pytest.raises(TerminalPipelineError,match='Lease/control'):
        adapter._wait_for_observation_retry(SimpleNamespace(is_lease_healthy=lambda:False))
