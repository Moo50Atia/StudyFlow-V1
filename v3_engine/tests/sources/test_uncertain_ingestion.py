"""Workflow branching test with activity doubles; not a Temporal integration test."""
from types import SimpleNamespace
import pytest
from v3_engine.workflows import case_workflow as module

@pytest.mark.asyncio
@pytest.mark.parametrize('status', ['uncertain', 'blocked', 'unexpected'])
async def test_unconfirmed_ingestion_never_completes_or_releases_mapping(monkeypatch, status):
    events = []
    async def execute(activity, payload, **kwargs):
        if activity.__name__ == 'extract_case_source':
            return {'status': 'ready', 'extracted_source_ref': 'source'}
        if activity.__name__ == 'ingest_case_source':
            return {'status': status, 'error_code': 'provider_unavailable'}
        if activity.__name__ in ('publish_case_event','publish_workflow_started'):
            events.append(payload['event_key'])
            return {'data': {'state_version': payload['expected_state_version'] + 1}}
        pytest.fail('Downstream activity was released before source confirmation')
    async def wait(predicate, **kwargs):
        assert predicate(), 'Workflow advanced beyond source failure'
    monkeypatch.setattr(module.workflow, 'patched', lambda marker: True)
    monkeypatch.setattr(module.workflow, 'execute_activity', execute)
    monkeypatch.setattr(module.workflow, 'wait_condition', wait)
    monkeypatch.setattr(module.workflow, 'info', lambda: SimpleNamespace(run_id='run',workflow_id='wf'))
    instance = module.CaseWorkflow()
    instance.activation = {'case_id':'case','activation_id':'activation'}
    await instance.run({'case_id':'case'})
    assert 'source:blocked:sourcing' in events
    assert 'source:sourcing:completed' not in events
