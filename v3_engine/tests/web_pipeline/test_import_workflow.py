"""Import admission calls Laravel for verified, transactional materialization."""

import uuid

import pytest
from temporalio import activity
from temporalio.worker import Worker
from contextlib import AsyncExitStack

from v3_engine.web_pipeline.import_workflow import ApplicationImportWorkflow

from v3_engine.web_pipeline import import_activities


@pytest.mark.asyncio
async def test_import_activity_resolves_manifest_and_calls_signed_laravel_import(monkeypatch):
    run_id = str(uuid.uuid4())
    manifest_ref = str(uuid.uuid4())
    import_id = str(uuid.uuid4())
    digest = "a" * 64
    calls = []

    class FakeApplicationClient:
        def __init__(self, timeout):
            assert timeout >= 60

        async def get_manifest(self, ref):
            calls.append(("manifest", ref))
            return {
                "kind": "web_import_v1",
                "run_id": run_id,
                "source_run_id": run_id,
                "import_id": import_id,
                "import_digest": digest,
                "terminal_root": "/private/terminal",
                "subject_id": 4,
                "teacher_id": 8,
                "actor_admin_id": 2,
            }

        async def materialize_import(self, payload):
            calls.append(("materialize", payload))
            return {"state": "completed", "safe_code": "ok", "counts": {"pdfs_accepted": 8}}

    monkeypatch.setattr(import_activities, "ApplicationClient", FakeApplicationClient)
    result = await import_activities.execute_terminal_import_activity({
        "run_id": run_id,
        "manifest_ref": manifest_ref,
    })

    assert result["outcome"] == "completed"
    assert result["safe_code"] == "ok"
    assert result["counts"] == {"pdfs_accepted": 8}
    assert calls == [
        ("manifest", manifest_ref),
        ("materialize", {
            "terminal_root": "/private/terminal",
            "run_id": run_id,
            "subject_id": 4,
            "teacher_id": 8,
            "actor_admin_id": 2,
            "import_id": import_id,
            "expected_digest": digest,
        }),
    ]


@pytest.mark.asyncio
async def test_import_activity_rejects_mismatched_manifest_without_materializing(monkeypatch):
    run_id = str(uuid.uuid4())
    manifest_ref = str(uuid.uuid4())

    class FakeApplicationClient:
        def __init__(self, timeout):
            pass

        async def get_manifest(self, ref):
            return {"kind": "web_subject_v1", "run_id": run_id}

        async def materialize_import(self, payload):
            raise AssertionError("invalid manifests must not reach materialization")

    monkeypatch.setattr(import_activities, "ApplicationClient", FakeApplicationClient)
    result = await import_activities.execute_terminal_import_activity({
        "run_id": run_id,
        "manifest_ref": manifest_ref,
    })
    assert result == {"outcome": "failed", "safe_code": "receipt_invalid"}


@pytest.mark.asyncio
async def test_local_import_does_not_claim_completion_without_laravel():
    from v3_engine.web_pipeline.import_workflow import execute_local_import

    result = await execute_local_import({
        "workflow_kind": "web_import_v1",
        "run_id": str(uuid.uuid4()),
        "manifest_ref": str(uuid.uuid4()),
    })
    assert result["outcome"] == "blocked"
    assert result["safe_code"] == "storage_unavailable"


def test_application_import_workflow_registered_name():
    assert (
        getattr(ApplicationImportWorkflow, "_workflow_definition_name", None) == "ApplicationImportWorkflow"
        or getattr(ApplicationImportWorkflow, "__temporal_workflow_definition__", None) is not None
    )


@pytest.mark.temporal
@pytest.mark.asyncio
async def test_import_workflow_schedules_real_laravel_materialization_activity(temporal_local_env):
    run_id = str(uuid.uuid4())
    manifest_ref = str(uuid.uuid4())
    calls = []

    @activity.defn(name="execute_terminal_import_activity")
    async def materialize(args):
        calls.append(args)
        return {"outcome": "completed", "safe_code": "ok", "counts": {"pdfs_accepted": 8}}

    async with AsyncExitStack() as stack:
        worker = Worker(
            temporal_local_env.client,
            task_queue="v3-terminal-control",
            workflows=[ApplicationImportWorkflow],
            activities=[materialize],
        )
        await stack.enter_async_context(worker)
        handle = await temporal_local_env.client.start_workflow(
            ApplicationImportWorkflow.run,
            {"workflow_kind": "web_import_v1", "run_id": run_id, "manifest_ref": manifest_ref},
            id=f"test-web-import-{run_id}",
            task_queue="v3-terminal-control",
        )
        result = await handle.result()

    assert result == {"outcome": "completed", "safe_code": "ok", "counts": {"pdfs_accepted": 8}}
    assert calls == [{"run_id": run_id, "manifest_ref": manifest_ref}]
