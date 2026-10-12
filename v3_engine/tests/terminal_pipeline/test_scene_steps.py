"""Tests for individual scene generation steps, receipt restoration, and build cancellation (Spec 018 / T008)."""

import asyncio
import json
import threading
import time
import pytest
from pathlib import Path
from typing import Any

from v3_engine.scene_generation.artifact_store import SceneArtifactStore
from v3_engine.scene_generation.builder import SceneBuilder
from v3_engine.scene_generation.contracts import (
    AIProvider,
    HandoffInventory,
    ProviderCheckResult,
    ProviderConfig,
    ProviderResponse,
    SceneGenerationRequest,
    SceneInput,
)
from v3_engine.scene_generation.service import SceneGenerationService


class CountingDoubleProvider(AIProvider):
    def __init__(self, response_text: str | None = None, outcome: str = "complete", safe_code: str = "ok"):
        self.send_count = 0
        self.response_text = response_text or (
            "```json\n"
            '{"schema_version":1,"files":[{"path":"index.html","content":"<!doctype html><link rel=\\"stylesheet\\" href=\\"./styles.css\\"><main>Scene</main><script src=\\"./script.js\\"></script>"},{"path":"styles.css","content":"main{font:1rem sans-serif}"},{"path":"script.js","content":"document.body.dataset.ready=\\"true\\""}]}\n'
            "```"
        )
        self.outcome = outcome
        self.safe_code = safe_code

    async def check(self) -> ProviderCheckResult:
        return ProviderCheckResult(passed=True, provider_id="test", model="test-model", safe_code="ok")

    async def check_connection(self) -> ProviderCheckResult:
        return ProviderCheckResult(passed=True, provider_id="test", model="test-model", safe_code="ok")

    async def aclose(self) -> None:
        pass

    async def generate_scene(self, request: SceneGenerationRequest, receipt_sink=None) -> ProviderResponse:
        self.send_count += 1
        raw_body = json.dumps({
            "candidates": [{
                "finishReason": "STOP",
                "content": {"parts": [{"text": self.response_text}]},
            }],
            "modelVersion": "test-model",
            "responseId": "test-response",
            "usageMetadata": {"promptTokenCount": 1, "candidatesTokenCount": 1},
        }).encode("utf-8")
        raw_receipt_ref = None
        if receipt_sink is not None:
            raw_receipt_ref = await receipt_sink(raw_body, {"http_status": 200, "complete": True, "truncated": False})

        return ProviderResponse(
            outcome=self.outcome,
            safe_code=self.safe_code,
            text=self.response_text if self.outcome == "complete" else None,
            raw_receipt_ref=raw_receipt_ref,
            http_status=200,
            requested_model="test-model",
            returned_model="test-model",
        )


class FastUnitDoubleBuilder(SceneBuilder):
    def __init__(self):
        super().__init__()
        self.build_calls = 0

    def build_scene(self, attempt_dir: Path, scene: SceneInput, source_pkg, build_ordinal: int = 1, cancel_check=None, heartbeat_cb=None) -> dict[str, Any]:
        self.build_calls += 1
        if cancel_check and cancel_check():
            return {"outcome": "failed", "safe_code": "build_cancelled"}
        if heartbeat_cb:
            heartbeat_cb(0.1)
        return {
            "outcome": "complete",
            "safe_code": "ok",
            "manifest_path": str(attempt_dir / "builds" / f"build-{build_ordinal:04d}" / "manifest.json"),
        }


@pytest.fixture
def scene_env(tmp_path: Path) -> tuple[Path, SceneArtifactStore, SceneInput]:
    output_dir = tmp_path / "scene_output"
    output_dir.mkdir()
    store = SceneArtifactStore(output_dir)
    store.acquire_lock()

    prompt_text = "Visualize vector addition in 2D"
    scene = SceneInput(
        scene_key="nb-1:conv-1:W1:S1",
        notebook_id="nb-1",
        conversation_id="conv-1",
        lesson_ordinal=1,
        scene_ordinal=1,
        lesson_title="Vector Addition",
        section_title="Introduction",
        scene_type="interactive_diagram",
        prompt=prompt_text,
        prompt_sha256="a" * 64,
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
    return output_dir, store, scene


@pytest.mark.asyncio
async def test_one_scene_generation_and_receipt_persistence(scene_env: tuple[Path, SceneArtifactStore, SceneInput]) -> None:
    _, store, scene = scene_env
    provider = CountingDoubleProvider()
    builder = FastUnitDoubleBuilder()

    service = SceneGenerationService(
        provider=provider,
        store=store,
        builder=builder,
        template_text="Template text",
        template_sha="0" * 64,
    )

    # Call step-based generate_one
    result = await service.generate_one(scene)
    assert result["outcome"] == "complete"
    assert provider.send_count == 1

    # Verify receipt saved on disk
    receipt = service.restore_receipt(scene, attempt_ordinal=1)
    assert receipt is not None
    assert "text" in receipt


@pytest.mark.asyncio
async def test_restore_receipt_sends_zero_paid_requests(scene_env: tuple[Path, SceneArtifactStore, SceneInput]) -> None:
    _, store, scene = scene_env
    provider = CountingDoubleProvider()
    builder = FastUnitDoubleBuilder()

    service = SceneGenerationService(
        provider=provider,
        store=store,
        builder=builder,
        template_text="Template text",
        template_sha="0" * 64,
    )

    # First generation
    await service.generate_one(scene)
    assert provider.send_count == 1

    # Restore receipt and materialize source: sends ZERO additional requests!
    receipt = service.restore_receipt(scene, attempt_ordinal=1)
    assert receipt is not None
    source_pkg = service.materialize_source(scene, attempt_ordinal=1, raw_text=receipt["text"])
    assert source_pkg is not None
    assert provider.send_count == 1  # Still 1, no duplicate paid request!


@pytest.mark.asyncio
async def test_build_one_and_rebuild_without_provider_call(scene_env: tuple[Path, SceneArtifactStore, SceneInput]) -> None:
    _, store, scene = scene_env
    provider = CountingDoubleProvider()
    builder = FastUnitDoubleBuilder()

    service = SceneGenerationService(
        provider=provider,
        store=store,
        builder=builder,
        template_text="Template text",
        template_sha="0" * 64,
    )

    # Generate once
    await service.generate_one(scene)
    receipt = service.restore_receipt(scene, 1)
    source_pkg = service.materialize_source(scene, 1, receipt["text"])

    # Build once
    b1 = service.build_one(scene, 1, source_pkg=source_pkg, build_ordinal=1)
    assert b1["outcome"] == "complete"
    assert builder.build_calls == 1

    # Corrupt or rebuild: build_ordinal 2 without any provider calls!
    b2 = service.build_one(scene, 1, source_pkg=source_pkg, build_ordinal=2)
    assert b2["outcome"] == "complete"
    assert builder.build_calls == 2
    assert provider.send_count == 1  # ZERO provider requests for rebuild!


def test_build_cancellation_responsive(scene_env: tuple[Path, SceneArtifactStore, SceneInput]) -> None:
    _, store, scene = scene_env
    provider = CountingDoubleProvider()
    builder = FastUnitDoubleBuilder()

    service = SceneGenerationService(
        provider=provider,
        store=store,
        builder=builder,
        template_text="Template text",
        template_sha="0" * 64,
    )

    cancel_flag = True
    res = service.build_one(
        scene,
        1,
        source_pkg=None,
        build_ordinal=1,
        cancel_check=lambda: cancel_flag,
    )
    assert res["outcome"] == "failed"
    assert res["safe_code"] == "build_cancelled"
