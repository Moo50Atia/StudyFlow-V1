"""Direct-service generation, configuration, prerequisite, receipt recovery, and immutable build tests (Spec 019 US3)."""

from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
from typing import Any, Mapping
import pytest

from v3_engine.scene_generation.artifact_store import (
    ArtifactCorruptError,
    SceneArtifactStore,
)
from v3_engine.scene_generation.builder import SceneBuilder, check_toolchain_drift
from v3_engine.scene_generation.contracts import (
    AIProvider,
    HandoffInventory,
    ProviderCheckResult,
    ProviderConfig,
    ProviderResponse,
    RawReceiptSink,
    SceneGenerationRequest,
    SceneInput,
)
from v3_engine.scene_generation.service import SceneGenerationService
from v3_engine.scene_generation.source_package import GeneratedFile, HtmlSceneSourceV1


class RecordingMockProvider(AIProvider):
    def __init__(self, response_text: str, duration_ms: int = 450) -> None:
        self.call_count = 0
        self.response_text = response_text
        self.duration_ms = duration_ms
        self.config = ProviderConfig(
            provider="gemini",
            provider_id="gemini_api",
            requested_model="gemini-2.5-flash",
            endpoint="https://generativelanguage.googleapis.com",
            gemini_thinking_budget=24576,
        )

    async def check_connection(self) -> ProviderCheckResult:
        return ProviderCheckResult(passed=True, safe_code="ok", requested_model="gemini-2.5-flash")

    async def generate_scene(
        self,
        request: SceneGenerationRequest,
        *,
        receipt_sink: RawReceiptSink,
    ) -> ProviderResponse:
        self.call_count += 1
        raw_body = json.dumps({
            "modelVersion": "gemini-2.5-flash",
            "responseId": "resp-test-1234",
            "candidates": [
                {
                    "finishReason": "STOP",
                    "content": {
                        "parts": [{"text": self.response_text}],
                    },
                }
            ],
            "usageMetadata": {
                "promptTokenCount": 120,
                "candidatesTokenCount": 350,
                "totalTokenCount": 470,
            },
        }).encode("utf-8")

        raw_ref = await receipt_sink(
            raw_body,
            {"http_status": 200, "complete": True, "truncated": False},
        )

        return ProviderResponse(
            outcome="complete",
            safe_code="ok",
            http_status=200,
            requested_model="gemini-2.5-flash",
            returned_model="gemini-2.5-flash",
            model_reported=True,
            response_id="resp-test-1234",
            finish_reason="STOP",
            usage={
                "input_tokens": 120,
                "output_tokens": 350,
            },
            duration_ms=self.duration_ms,
            raw_receipt_ref=raw_ref,
            text=self.response_text,
        )

    async def aclose(self) -> None:
        pass


def _make_sample_scene() -> SceneInput:
    prompt_text = "Create an interactive harmonic oscillator simulation with stiffness slider."
    return SceneInput(
        scene_key="nb-test-1:conv-test-1:W1:S1",
        notebook_id="nb-test-1",
        conversation_id="conv-test-1",
        lesson_ordinal=1,
        scene_ordinal=1,
        lesson_title="Harmonic Motion",
        section_title="Spring Simulation",
        scene_type="Interactive 2D Canvas",
        prompt=prompt_text,
        prompt_sha256=hashlib.sha256(prompt_text.encode("utf-8")).hexdigest(),
        input_error=None,
        source_index=0,
        source_json_pointer="/scenes/0",
    )


def _make_valid_html_payload() -> str:
    return json.dumps({
        "schema_version": 1,
        "files": [
            {
                "path": "index.html",
                "content": "<!doctype html><html><head><link rel=\"stylesheet\" href=\"./styles.css\"></head><body><main id=\"app\">Oscillator</main><script src=\"./script.js\"></script></body></html>",
            },
            {
                "path": "styles.css",
                "content": "body { font-family: sans-serif; margin: 0; }",
            },
            {
                "path": "script.js",
                "content": "document.documentElement.dataset.ready = 'true';",
            },
        ],
    })


@pytest.fixture
def service_env(tmp_path: Path) -> dict[str, Any]:
    output_dir = tmp_path / "scene_exec_run"
    output_dir.mkdir(parents=True, exist_ok=True)
    store = SceneArtifactStore(output_dir)
    store.acquire_lock()

    scene = _make_sample_scene()
    inventory = HandoffInventory(
        notebook_id="nb-test-1",
        conversation_id="conv-test-1",
        scene_inputs=[scene],
        input_issues=[],
        input_snapshots=[],
        expected_work_count=1,
    )

    valid_json = _make_valid_html_payload()
    provider = RecordingMockProvider(valid_json, duration_ms=520)
    builder = SceneBuilder()
    template_text = "System template for interactive simulations."
    template_sha = hashlib.sha256(template_text.encode("utf-8")).hexdigest()

    service = SceneGenerationService(
        provider=provider,
        store=store,
        builder=builder,
        template_text=template_text,
        template_sha=template_sha,
    )
    service.initialize_run(inventory=inventory, config=provider.config)

    return {
        "output_dir": output_dir,
        "store": store,
        "provider": provider,
        "builder": builder,
        "service": service,
        "scene": scene,
        "template_text": template_text,
        "template_sha": template_sha,
    }


@pytest.mark.asyncio
async def test_selected_generation_makes_exact_provider_call_and_records_timing(
    service_env: dict[str, Any],
) -> None:
    service: SceneGenerationService = service_env["service"]
    provider: RecordingMockProvider = service_env["provider"]
    scene: SceneInput = service_env["scene"]
    store: SceneArtifactStore = service_env["store"]

    result = await service.generate_one(scene)

    assert result["outcome"] == "complete"
    assert result["safe_code"] == "ok"
    assert result["attempt_ordinal"] == 1
    assert provider.call_count == 1

    # Verify provider result doc preserves measured duration_ms
    attempt_dir = store.get_attempt_dir(scene, 1)
    provider_res_file = attempt_dir / "provider-result.json"
    assert provider_res_file.exists()
    saved = json.loads(provider_res_file.read_text(encoding="utf-8"))
    assert saved["duration_ms"] == 520
    assert saved["requested_model"] == "gemini-2.5-flash"
    assert saved["raw_receipt_ref"].endswith("response-receipt.json")


@pytest.mark.asyncio
async def test_retained_receipt_recovery_preserves_original_timing_and_zero_new_calls(
    service_env: dict[str, Any],
) -> None:
    service: SceneGenerationService = service_env["service"]
    provider: RecordingMockProvider = service_env["provider"]
    scene: SceneInput = service_env["scene"]

    # First: generate once
    await service.generate_one(scene)
    assert provider.call_count == 1

    # Recover provider attempt from retained receipt
    recovered = service._recover_provider_attempt(scene, 1)
    assert recovered["outcome"] == "complete"
    assert recovered["safe_code"] == "ok"
    assert recovered["attempt_ordinal"] == 1

    # Invariant: zero additional provider calls
    assert provider.call_count == 1

    # Verify duration_ms was preserved verbatim
    store: SceneArtifactStore = service_env["store"]
    attempt_dir = store.get_attempt_dir(scene, 1)
    saved = json.loads((attempt_dir / "provider-result.json").read_text(encoding="utf-8"))
    assert saved["duration_ms"] == 520


@pytest.mark.asyncio
async def test_corrupt_raw_receipt_rejects_recovery_with_artifact_corrupt(
    service_env: dict[str, Any],
) -> None:
    service: SceneGenerationService = service_env["service"]
    scene: SceneInput = service_env["scene"]
    store: SceneArtifactStore = service_env["store"]

    await service.generate_one(scene)

    # Tamper with the raw response body bytes
    attempt_dir = store.get_attempt_dir(scene, 1)
    body_file = attempt_dir / "provider-body.bin"
    assert body_file.exists()
    body_file.write_bytes(b"tampered-corrupted-bytes")

    # Recovery must fail with artifact_corrupt safe code
    recovered = service._recover_provider_attempt(scene, 1)
    assert recovered["outcome"] == "failed"
    assert recovered["safe_code"] == "artifact_corrupt"

    with pytest.raises(ArtifactCorruptError):
        service.restore_receipt(scene, 1)


@pytest.mark.asyncio
async def test_source_drift_or_tampering_rejects_build(
    service_env: dict[str, Any],
) -> None:
    service: SceneGenerationService = service_env["service"]
    scene: SceneInput = service_env["scene"]
    store: SceneArtifactStore = service_env["store"]

    await service.generate_one(scene)
    service.materialize_source(scene, 1)

    # Tamper with project/script.js content
    attempt_dir = store.get_attempt_dir(scene, 1)
    script_file = attempt_dir / "project" / "script.js"
    assert script_file.exists()
    script_file.write_text("console.log('tampered content');", encoding="utf-8")

    # verify_source_package should fail
    assert not store.verify_source_package(scene, 1)

    # build_one should reject with source_artifact_invalid
    build_res = service.build_one(scene, 1)
    assert build_res["outcome"] == "failed"
    assert build_res["safe_code"] == "source_artifact_invalid"


@pytest.mark.asyncio
async def test_immutable_build_attempts_reserve_unique_ordinals_without_overwriting(
    service_env: dict[str, Any],
) -> None:
    service: SceneGenerationService = service_env["service"]
    scene: SceneInput = service_env["scene"]
    store: SceneArtifactStore = service_env["store"]

    await service.generate_one(scene)
    service.materialize_source(scene, 1)

    # First build reservation
    b1 = store.reserve_build(scene, 1)
    assert b1 == 1

    # Simulate completed build 1
    attempt_dir = store.get_attempt_dir(scene, 1)
    build_1_dir = attempt_dir / "builds" / "build-0001"
    build_1_dir.mkdir(parents=True, exist_ok=True)
    (build_1_dir / "log.txt").write_text("Build 1 executed", encoding="utf-8")
    store.finish_build(scene, 1, b1, "failed")

    # Second build reservation MUST allocate next ordinal without overwriting build 1
    b2 = store.reserve_build(scene, 1)
    assert b2 == 2

    build_2_dir = attempt_dir / "builds" / "build-0002"
    build_2_dir.mkdir(parents=True, exist_ok=True)
    (build_2_dir / "log.txt").write_text("Build 2 executed", encoding="utf-8")
    store.finish_build(scene, 1, b2, "complete")

    # Invariant: build 1 log is preserved intact
    assert (build_1_dir / "log.txt").read_text(encoding="utf-8") == "Build 1 executed"
    assert (build_2_dir / "log.txt").read_text(encoding="utf-8") == "Build 2 executed"


def test_toolchain_drift_rejects_build(
    service_env: dict[str, Any],
) -> None:
    output_dir: Path = service_env["output_dir"]
    run_file = output_dir / "run.json"
    assert run_file.exists()

    run_doc = json.loads(run_file.read_text(encoding="utf-8"))
    # Corrupt the toolchain node version
    run_doc["toolchain"]["node_version"] = "v999.0.0-unapproved"
    run_file.write_text(json.dumps(run_doc), encoding="utf-8")

    assert check_toolchain_drift(run_doc["toolchain"]) is not None


@pytest.mark.asyncio
async def test_scene_workflow_export_manual_mode() -> None:
    from v3_engine.web_pipeline.scene_workflow import ApplicationSceneWorkflow

    wf = ApplicationSceneWorkflow()
    executed_activities: list[str] = []

    async def mock_exec(name: str, args: dict[str, Any]) -> dict[str, Any]:
        executed_activities.append(name)
        return {"outcome": "completed", "safe_code": "ok"}

    wf._activity_executor = mock_exec

    result = await wf.run({
        "run_id": "run-test-manual",
        "scenes": [{"scene_ordinal": 1, "lesson_ordinal": 1}],
        "captured_settings": {"mode": "export_manual"},
    })

    assert result["outcome"] == "completed"
    assert result["scenes_generated"] == 0
    assert result["scenes_built"] == 0
    assert len(executed_activities) == 0
    progress = wf.progress_v1()
    assert progress["phase"] == "export_complete"
    assert progress["status"] == "prompts_ready"


@pytest.mark.asyncio
async def test_scene_workflow_selected_api_mode() -> None:
    from v3_engine.web_pipeline.scene_workflow import ApplicationSceneWorkflow

    wf = ApplicationSceneWorkflow()
    executed_activities: list[str] = []

    async def mock_exec(name: str, args: dict[str, Any]) -> dict[str, Any]:
        executed_activities.append(name)
        if name == "execute_scene_generation_activity":
            return {"outcome": "completed", "safe_code": "ok", "attempt_ordinal": 1}
        if name == "execute_scene_build_activity":
            return {"outcome": "completed", "safe_code": "ok", "manifest_path": "/path/to/manifest.json"}
        return {"outcome": "completed", "safe_code": "ok"}

    wf._activity_executor = mock_exec

    result = await wf.run({
        "run_id": "run-test-api",
        "scenes": [
            {"scene_ordinal": 1, "lesson_ordinal": 1, "prompt": "Prompt 1"},
            {"scene_ordinal": 2, "lesson_ordinal": 1, "prompt": "Prompt 2"},
        ],
        "captured_settings": {"mode": "selected_api", "provider": {"provider": "gemini"}},
    })

    assert result["outcome"] == "completed"
    assert result["scenes_generated"] == 2
    assert result["scenes_built"] == 2
    assert result["scenes_failed"] == 0
    assert executed_activities == [
        "execute_scene_generation_activity",
        "execute_scene_build_activity",
        "execute_scene_generation_activity",
        "execute_scene_build_activity",
    ]


@pytest.mark.asyncio
async def test_scene_workflow_control_pause_and_cancel() -> None:
    from v3_engine.web_pipeline.scene_workflow import ApplicationSceneWorkflow

    wf = ApplicationSceneWorkflow()

    # Test control idempotency
    rec1 = wf.control_v1({"action": "pause", "command_id": "c1", "command_digest": "d1"})
    assert rec1["outcome"] == "applied"
    assert wf.paused is True

    # Same command id + digest is idempotent
    rec2 = wf.control_v1({"action": "pause", "command_id": "c1", "command_digest": "d1"})
    assert rec2 == rec1

    rec3 = wf.control_v1({"action": "cancel", "command_id": "c2", "command_digest": "d2"})
    assert rec3["outcome"] == "applied"
    assert wf.cancelled is True

    res = await wf.run({
        "run_id": "run-cancelled",
        "scenes": [{"scene_ordinal": 1}],
        "captured_settings": {"mode": "selected_api"},
    })
    assert res["outcome"] == "cancelled"

