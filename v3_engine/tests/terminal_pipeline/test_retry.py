"""Tests for explicit retry, linked recovery, build-only restoration, and owner reservations (Spec 018 / T022)."""

from __future__ import annotations

import hashlib
import json
import uuid
from pathlib import Path
import pytest
from temporalio import activity
from temporalio.worker import Worker

from v3_engine.scene_generation.artifact_store import SceneArtifactStore
from v3_engine.scene_generation.builder import SceneBuilder
from v3_engine.scene_generation.contracts import (
    HandoffInventory,
    ProviderConfig,
    SceneInput,
)
from v3_engine.scene_generation.service import SceneGenerationService
from v3_engine.terminal_pipeline.contracts import (
    SafeCode,
    SafeErrorCode,
)
from v3_engine.terminal_pipeline.controls import (
    CommandIntent,
    CommandReceipt,
    validate_and_apply_control,
)
from v3_engine.terminal_pipeline.store import (
    LifecycleTransitionError,
    TerminalIndexStore,
)
from v3_engine.terminal_pipeline.supervisor import TerminalSupervisorWorkflow
from v3_engine.tests.terminal_pipeline.test_scene_steps import (
    CountingDoubleProvider,
    FastUnitDoubleBuilder,
)


def test_reserve_recovery_one_active_owner_and_rejection(tmp_path: Path) -> None:
    db_file = tmp_path / "index.sqlite"
    store = TerminalIndexStore(db_file)
    store.initialize_schema()

    gen_ref = "runs/00000000-0000-4000-8000-000000000001"
    rec1_id = str(uuid.uuid4())
    sub1_id = str(uuid.uuid4())
    digest1 = "d1" * 32

    # 1. First recovery reservation succeeds
    ok1 = store.reserve_recovery(
        recovery_id=rec1_id,
        original_generation_ref=gen_ref,
        recovery_subject_id=sub1_id,
        mode="build",
        command_digest=digest1,
    )
    assert ok1 is True

    # 2. Duplicate same-command reservation is idempotent
    ok1_dup = store.reserve_recovery(
        recovery_id=rec1_id,
        original_generation_ref=gen_ref,
        recovery_subject_id=sub1_id,
        mode="build",
        command_digest=digest1,
    )
    assert ok1_dup is True

    # 3. Competing second recovery on same generation root raises recovery_busy
    rec2_id = str(uuid.uuid4())
    sub2_id = str(uuid.uuid4())
    digest2 = "d2" * 32

    with pytest.raises(LifecycleTransitionError) as exc_info:
        store.reserve_recovery(
            recovery_id=rec2_id,
            original_generation_ref=gen_ref,
            recovery_subject_id=sub2_id,
            mode="build",
            command_digest=digest2,
        )
    assert exc_info.value.safe_code == SafeCode.recovery_busy


def test_build_only_recovery_damaged_output_without_provider_credentials(tmp_path: Path) -> None:
    output_dir = tmp_path / "scene_output"
    output_dir.mkdir()
    store = SceneArtifactStore(output_dir)
    store.acquire_lock()

    scene = SceneInput(
        scene_key="nb-1:conv-1:W1:S1",
        notebook_id="nb-1",
        conversation_id="conv-1",
        lesson_ordinal=1,
        scene_ordinal=1,
        lesson_title="Dynamics",
        section_title="Force",
        scene_type="interactive_diagram",
        prompt="Visualize Newton's second law",
        prompt_sha256="b" * 64,
        input_error=None,
        source_index=0,
        source_json_pointer="/scenes/0",
    )
    inv = HandoffInventory(
        notebook_id="nb-1",
        conversation_id="conv-1",
        scene_inputs=[scene],
        input_issues=[],
        input_snapshots=[],
        expected_work_count=1,
    )
    from v3_engine.scene_generation.builder import get_host_toolchain_info
    host_tc = get_host_toolchain_info()
    store.initialize_run(
        inventory=inv,
        config=ProviderConfig(
            provider="gemini",
            provider_id="gemini_api",
            requested_model="gemini-2.5-flash",
            endpoint="https://generativelanguage.googleapis.com",
        ),
        template_text="Template text",
        template_sha="0" * 64,
        input_raw_bytes=[],
        catalog_sha=host_tc.get("catalog_sha256", "catalog-sha-placeholder"),
    )

    # Simulate attempt-0001 with saved source package but damaged/missing build
    attempt = store.start_attempt(scene)
    from v3_engine.scene_generation.source_package import ReactSceneSourceV1, SourceFile
    source_content = "export default function Scene() { return <div>Scene</div>; }"
    source_sha = hashlib.sha256(source_content.encode("utf-8")).hexdigest()
    source_pkg = ReactSceneSourceV1(
        schema_version=1,
        dependencies=[],
        files=[SourceFile(path="src/Scene.tsx", content=source_content, bytes=len(source_content), sha256=source_sha)],
    )
    store.save_source_package(scene, attempt.ordinal, source_pkg, scaffold_files={})

    # Build directory is damaged / missing manifest
    build_dir = attempt.attempt_dir / "builds" / "build-0001"
    build_dir.mkdir(parents=True, exist_ok=True)
    # No manifest.json -> damaged

    # Service constructed WITHOUT provider (provider=None or empty double)
    provider = CountingDoubleProvider()
    builder = FastUnitDoubleBuilder()

    service = SceneGenerationService(
        provider=None,  # NO API credentials / client!
        store=store,
        builder=builder,
        template_text="Template text",
        template_sha="0" * 64,
    )

    # Perform build-only recovery
    build_res = service.build_only_scene(scene, attempt_ordinal=attempt.ordinal)
    assert build_res["outcome"] == "complete"
    assert build_res["safe_code"] == "ok"
    # Zero provider calls were made
    assert provider.send_count == 0
    # Builder was called to create build-0002
    assert builder.build_calls == 1


def test_build_recovery_rejects_intact_healthy_output(tmp_path: Path) -> None:
    output_dir = tmp_path / "scene_output"
    output_dir.mkdir()
    store = SceneArtifactStore(output_dir)
    store.acquire_lock()

    scene = SceneInput(
        scene_key="nb-1:conv-1:W1:S1",
        notebook_id="nb-1",
        conversation_id="conv-1",
        lesson_ordinal=1,
        scene_ordinal=1,
        lesson_title="Dynamics",
        section_title="Force",
        scene_type="interactive_diagram",
        prompt="Visualize Newton's second law",
        prompt_sha256="b" * 64,
        input_error=None,
        source_index=0,
        source_json_pointer="/scenes/0",
    )
    inv = HandoffInventory(
        notebook_id="nb-1",
        conversation_id="conv-1",
        scene_inputs=[scene],
        input_issues=[],
        input_snapshots=[],
        expected_work_count=1,
    )
    store.initialize_run(
        inventory=inv,
        config=ProviderConfig(
            provider="gemini",
            provider_id="gemini_api",
            requested_model="gemini-2.5-flash",
            endpoint="https://generativelanguage.googleapis.com",
        ),
        template_text="Template text",
        template_sha="0" * 64,
        input_raw_bytes=[],
    )

    attempt = store.start_attempt(scene)
    source_dir = attempt.attempt_dir / "source"
    source_dir.mkdir(parents=True, exist_ok=True)
    src_dir = source_dir / "src"
    src_dir.mkdir(parents=True, exist_ok=True)
    (src_dir / "Scene.tsx").write_text("export default function Scene() { return <div>Scene</div>; }", encoding="utf-8")

    # A manifest status string alone is not evidence of a healthy published build.
    build_dir = attempt.attempt_dir / "builds" / "build-0001"
    build_dir.mkdir(parents=True, exist_ok=True)
    manifest_file = build_dir / "manifest.json"
    manifest_file.write_text(json.dumps({"schema_version": 1, "status": "complete"}), encoding="utf-8")

    builder = FastUnitDoubleBuilder()
    service = SceneGenerationService(
        provider=None,
        store=store,
        builder=builder,
        template_text="Template text",
        template_sha="0" * 64,
    )

    # A forged/synthetic status field cannot block recovery; the real artifact
    # verifier rejects this incomplete pointer/manifest chain.
    result = service.build_only_scene(scene, attempt_ordinal=attempt.ordinal)
    assert result["outcome"] == "failed"
    assert result["safe_code"] == "source_artifact_invalid"
    assert builder.build_calls == 0


def test_subject_retry_update_never_claims_unlaunched_recovery_was_applied() -> None:
    receipts: dict[str, dict] = {}
    current_state = {
        "status": "failed",
        "phase": "generate",
        "state_version": 2,
    }

    # Subject control updates do not implement recovery. The supervisor recovery
    # endpoint is the only path that can truthfully acknowledge scheduled work.
    cmd_no_consent = CommandIntent(
        command_id="cmd-retry-1",
        action="retry",
        subject_run_id="run-001",
        expected_version=2,
        phase="generate",
        accept_duplicate_risk=False,
    )
    res_no_consent = validate_and_apply_control(cmd_no_consent, current_state, receipts)
    assert res_no_consent.outcome == "rejected"
    assert res_no_consent.safe_code == SafeCode.control_pending.value

    # Consent does not make this non-executing update an applied retry.
    cmd_with_consent = CommandIntent(
        command_id="cmd-retry-2",
        action="retry",
        subject_run_id="run-001",
        expected_version=2,
        phase="generate",
        accept_duplicate_risk=True,
    )
    res_with_consent = validate_and_apply_control(cmd_with_consent, current_state, receipts)
    assert res_with_consent.outcome == "rejected"
    assert res_with_consent.safe_code == SafeCode.control_pending.value
    assert res_with_consent.status == "failed"
    assert current_state["state_version"] == 2


@pytest.mark.temporal
async def test_supervisor_retry_scenes_v1_update(
    temporal_local_env, tmp_path: Path
) -> None:
    client = temporal_local_env.client

    @activity.defn(name="admit_terminal_recovery")
    async def mock_admit_recovery(inp: dict) -> dict:
        return {"outcome": "complete", "safe_code": "ok"}

    async with Worker(
        client,
        task_queue="v3-terminal-control",
        workflows=[TerminalSupervisorWorkflow],
        activities=[mock_admit_recovery],
    ):
        handle = await client.start_workflow(
            TerminalSupervisorWorkflow.run,
            id="v3-terminal-supervisor",
            task_queue="v3-terminal-control",
        )

        recovery_payload = {
            "schema_version": 1,
            "original_generation_ref": "runs/gen-root-001",
            "recovery_id": "rec-001",
            "recovery_subject_id": str(uuid.uuid4()),
            "command_digest": "h" * 64,
            "mode": "build",
            "selected_scene_keys": ["scene_001_01"],
            "accept_duplicate_risk": True,
        }

        # First retry update succeeds and queues linked recovery
        res1 = await handle.execute_update(
            TerminalSupervisorWorkflow.retry_scenes_v1,
            recovery_payload,
        )
        assert res1["outcome"] == "accepted"
        assert res1["recovery_id"] == "rec-001"

        # Duplicate same recovery request returns identical receipt
        res_dup = await handle.execute_update(
            TerminalSupervisorWorkflow.retry_scenes_v1,
            recovery_payload,
        )
        assert res_dup["outcome"] == "accepted"

        # Conflicting second recovery for same original root rejects with recovery_busy
        conflict_payload = dict(recovery_payload)
        conflict_payload["recovery_id"] = "rec-002"
        conflict_payload["command_digest"] = "different"

        res_conflict = await handle.execute_update(
            TerminalSupervisorWorkflow.retry_scenes_v1,
            conflict_payload,
        )
        assert res_conflict["outcome"] == "rejected"
        assert res_conflict["safe_code"] == SafeCode.recovery_busy.value


def test_build_recovery_toolchain_drift_detected() -> None:
    from v3_engine.scene_generation.builder import check_toolchain_drift

    saved_toolchain = {
        "node_version": "v18.0.0",
        "npm_version": "9.0.0",
        "catalog_sha256": "old-sha",
    }
    drift_msg = check_toolchain_drift(saved_toolchain)
    assert drift_msg is not None
    assert "drifted" in drift_msg

