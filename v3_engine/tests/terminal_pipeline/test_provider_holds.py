"""An uncertain provider result must remain queryable and require safe recovery."""

from unittest.mock import AsyncMock
import uuid

import pytest

from v3_engine.terminal_pipeline import subject_workflow as module


@pytest.mark.asyncio
async def test_uncertain_turn_inspects_same_operation_then_waits_for_versioned_resume(monkeypatch):
    subject = module.TerminalSubjectWorkflow()
    subject.phase = 'generating_lessons'
    payload = {'subject_run_id': str(uuid.uuid4()), 'operation_id': 'pdf-0001:lesson-010'}
    uncertain = {'outcome': 'uncertain', 'safe_code': 'delivery_uncertain'}
    complete = {'outcome': 'complete', 'receipt_ref': 'retained-answer'}
    execute = AsyncMock(side_effect=[uncertain, uncertain, uncertain, uncertain, complete])
    sleep = AsyncMock()
    observed = []

    async def wait(condition):
        if not condition():
            progress = subject.progress_v1()
            observed.append(progress)
            assert progress['status'] == 'paused' and progress['phase'] == 'held_uncertain'
            assert execute.await_count == 4  # no fifth attempt before owner resume
            receipt = await subject.control_v1({'command_id': str(uuid.uuid4()), 'action': 'resume',
                'subject_run_id': payload['subject_run_id'], 'expected_version': progress['state_version']})
            assert receipt['outcome'] == 'applied'
        assert condition()

    monkeypatch.setattr(module.workflow, 'execute_activity', execute)
    monkeypatch.setattr(module.workflow, 'sleep', sleep)
    monkeypatch.setattr(module.workflow, 'wait_condition', wait)
    assert await subject._run_activity('send_terminal_turn', payload) == complete
    assert len(observed) == 1 and subject.status == 'running'
    assert subject.phase == 'generating_lessons'
    assert all(call.args[1] == payload for call in execute.await_args_list)


@pytest.mark.asyncio
@pytest.mark.parametrize('activity_name', ['verify_notebook_session', 'export_terminal_scene_prompts'])
async def test_failure_is_queryable_before_operator_resume(monkeypatch, activity_name):
    subject = module.TerminalSubjectWorkflow()
    execute = AsyncMock(side_effect=[{'outcome': 'failed', 'safe_code': 'authentication_required'}, {'outcome': 'complete'}])
    held = []

    async def wait(condition):
        if not condition():
            held.append(subject.progress_v1())
            await subject.control_v1({'command_id': str(uuid.uuid4()), 'action': 'resume',
                'subject_run_id': str(uuid.uuid4()), 'expected_version': subject.state_version})

    monkeypatch.setattr(module.workflow, 'execute_activity', execute)
    monkeypatch.setattr(module.workflow, 'wait_condition', wait)
    assert (await subject._run_activity(activity_name, {}))['outcome'] == 'complete'
    assert len(held) == 1 and held[0]['status'] == 'paused'


@pytest.mark.asyncio
async def test_export_uses_validated_counts_after_reparsing_retained_raw(monkeypatch):
    subject = module.TerminalSubjectWorkflow()
    subject.lessons_saved = 19
    subject._run_activity = AsyncMock(side_effect=[
        {'counts': {'scene_count': 39, 'lesson_count': 19}, 'result_ref': 'verified-export'},
        {'result_ref': 'final-report'}, {'outcome': 'complete'},
    ])
    result = await subject._export_prompts_and_settle('subject', ['old-index'] * 37, 1, None)
    assert result['scene_prompts_exported'] == 39
    assert result['lessons_saved'] == 19


@pytest.mark.asyncio
async def test_local_export_recovers_automatically_without_operator_resume(monkeypatch):
    subject=module.TerminalSubjectWorkflow()
    subject.phase='exporting_scene_prompts'
    failed={'outcome':'failed','safe_code':'storage_unavailable'}
    complete={'outcome':'complete','counts':{'lesson_count':19,'scene_count':39}}
    execute=AsyncMock(side_effect=[failed,failed,complete])
    sleep=AsyncMock()
    wait=AsyncMock()
    monkeypatch.setattr(module.workflow,'patched',lambda _:True)
    monkeypatch.setattr(module.workflow,'execute_activity',execute)
    monkeypatch.setattr(module.workflow,'sleep',sleep)
    monkeypatch.setattr(module.workflow,'wait_condition',wait)
    assert await subject._run_activity('export_terminal_scene_prompts',{})==complete
    assert not subject.paused and subject.status=='running'
    assert [call.args[0] for call in sleep.await_args_list]==[5,15]
    assert all(call.args[0]=='export_terminal_scene_prompts' for call in execute.await_args_list)


@pytest.mark.asyncio
async def test_local_export_retry_respects_operator_cancellation(monkeypatch):
    subject=module.TerminalSubjectWorkflow()
    execute=AsyncMock(return_value={'outcome':'failed','safe_code':'input_invalid'})
    async def sleep(_):
        subject.cancelled=True
    monkeypatch.setattr(module.workflow,'patched',lambda _:True)
    monkeypatch.setattr(module.workflow,'execute_activity',execute)
    monkeypatch.setattr(module.workflow,'sleep',sleep)
    monkeypatch.setattr(module.workflow,'wait_condition',AsyncMock())
    with pytest.raises(module._ControlCancelled):
        await subject._run_activity('export_terminal_scene_prompts',{})
    assert execute.await_count==1


@pytest.mark.asyncio
async def test_persistent_local_export_error_remains_bounded_and_queryable(monkeypatch):
    subject=module.TerminalSubjectWorkflow()
    failed={'outcome':'failed','safe_code':'input_invalid'}
    execute=AsyncMock(side_effect=[failed]*4+[{'outcome':'complete'}])
    sleep=AsyncMock()
    held=[]
    async def wait(condition):
        if not condition():
            held.append(subject.progress_v1())
            assert execute.await_count==4
            subject.paused=False
    monkeypatch.setattr(module.workflow,'patched',lambda _:True)
    monkeypatch.setattr(module.workflow,'execute_activity',execute)
    monkeypatch.setattr(module.workflow,'sleep',sleep)
    monkeypatch.setattr(module.workflow,'wait_condition',wait)
    assert (await subject._run_activity('export_terminal_scene_prompts',{}))['outcome']=='complete'
    assert len(held)==1 and held[0]['status']=='paused'
    assert [call.args[0] for call in sleep.await_args_list]==[5,15,30]
