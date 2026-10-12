"""Tests for SceneGenerationService orchestration (Spec 017)."""

from __future__ import annotations

import json
import hashlib
from pathlib import Path
from typing import Any, Mapping, Optional
import pytest

from v3_engine.scene_generation.artifact_store import ArtifactCorruptError, SceneArtifactStore
from v3_engine.scene_generation.builder import SceneBuilder
from v3_engine.scene_generation.config import load_provider_config
from v3_engine.scene_generation.contracts import (
    AIProvider,
    HandoffInventory,
    InputSnapshot,
    ProviderCheckResult,
    ProviderResponse,
    RawReceiptSink,
    SceneGenerationRequest,
    SceneInput,
)
from v3_engine.scene_generation.service import SceneGenerationService
from v3_engine.scene_generation.providers import decode_generation_response
from v3_engine.scene_generation.source_package import GeneratedFile, ReactSceneSourceV1


class FakeProvider(AIProvider):
    def __init__(self, responses: Optional[dict[str, ProviderResponse]] = None) -> None:
        self.responses = responses or {}
        self.calls: list[str] = []

    async def check_connection(self) -> ProviderCheckResult:
        return ProviderCheckResult(passed=True, safe_code="ok", requested_model="fake-model")

    async def generate_scene(
        self, request: SceneGenerationRequest, *, receipt_sink: RawReceiptSink
    ) -> ProviderResponse:
        self.calls.append(request.prompt)
        # Find matching response or default
        if request.prompt in self.responses:
            resp = self.responses[request.prompt]
        else:
            default_body = json.dumps({
                "schema_version": 1, "files": [{"path":"index.html","content":"<!doctype html><html><head><link rel=\"stylesheet\" href=\"./styles.css\"></head><body><main id=\"app\">Scene</main><script src=\"./script.js\"></script></body></html>"},{"path":"styles.css","content":"body{font:16px sans-serif}"},{"path":"script.js","content":"document.documentElement.dataset.ready=\"true\""}],
            }).encode("utf-8")
            receipt_ref = await receipt_sink(default_body, {"http_status": 200, "complete": True, "truncated": False})
            resp = ProviderResponse(
                outcome="complete",
                safe_code="ok",
                http_status=200,
                text=default_body.decode("utf-8"),
                raw_receipt_ref=receipt_ref,
            )
        return resp

    async def aclose(self) -> None:
        pass


class FakeBuilder(SceneBuilder):
    def __init__(self, fail_for_scenes: Optional[set[str]] = None) -> None:
        super().__init__()
        self.fail_for_scenes = fail_for_scenes or set()
        self.build_calls: list[str] = []

    def get_scaffold_files(self, selected_dependencies: list[str]) -> dict[str, str]:
        return {
            "index.html": "<html/>",
            "src/main.tsx": "// main",
            "src/vite-env.d.ts": "// dts",
            "tsconfig.json": "{}",
            "vite.config.mjs": "// vite",
            "package.json": "{}",
        }

    def build_scene(
        self,
        attempt_dir: Path,
        scene: SceneInput,
        source_pkg: ReactSceneSourceV1,
        build_ordinal: int = 1,
        cancel_check: Any = None,
        heartbeat_cb: Any = None,
    ) -> dict[str, Any]:
        self.build_calls.append(scene.scene_key)
        build_dir = attempt_dir / "builds" / f"build-{build_ordinal:04d}"
        build_dir.mkdir(parents=True, exist_ok=True)
        dist_dir = build_dir / "dist"
        dist_dir.mkdir(parents=True, exist_ok=True)

        if scene.scene_key in self.fail_for_scenes:
            report = {"outcome": "failed", "safe_code": "typecheck_failed"}
            (build_dir / "build-report.json").write_text(json.dumps(report), encoding="utf-8")
            return {"status": "failed", "outcome": "failed", "safe_code": "typecheck_failed"}

        (dist_dir / "index.html").write_text("<html>Build Ok</html>", encoding="utf-8")
        report = {"outcome": "complete", "safe_code": "ok"}
        (build_dir / "build-report.json").write_text(json.dumps(report), encoding="utf-8")

        manifest_file = build_dir / "manifest.json"
        manifest_doc = {
            "schema_version": 1,
            "run_id": json.loads((attempt_dir / "attempt.json").read_text())["run_id"],
            "scene_key": scene.scene_key,
            "attempt_ordinal": 1,
            "build_ordinal": build_ordinal,
            "entrypoint": "index.html",
            "files": [{"path": "index.html", "bytes": len(b"<html>Build Ok</html>"), "sha256": hashlib.sha256(b"<html>Build Ok</html>").hexdigest()}],
            "qualification": {"mode": "owner_waived_terminal_demo", "jev_called": False},
            "verification": {"typecheck": True, "production_build": True},
        }
        manifest_file.write_text(json.dumps(manifest_doc), encoding="utf-8")

        scene_dir = attempt_dir.parent
        current_file = scene_dir / "current.json"
        current_doc = {
            "schema_version": 1,
            "run_id": json.loads((attempt_dir / "attempt.json").read_text())["run_id"],
            "scene_key": scene.scene_key,
            "attempt_ordinal": 1,
            "build_ordinal": build_ordinal,
            "manifest_path": str(manifest_file.relative_to(scene_dir)),
            "manifest_sha256": hashlib.sha256(manifest_file.read_bytes()).hexdigest(),
        }
        current_file.write_text(json.dumps(current_doc), encoding="utf-8")

        return {"status": "complete", "outcome": "complete", "safe_code": "ok"}


def make_two_lesson_inventory() -> HandoffInventory:
    # Lesson 1 has 2 scenes, Lesson 2 has 3 scenes
    scenes = [
        SceneInput("nb:conv:W1:S1", "nb", "conv", 1, 1, "L1", "S1", None, "Prompt 1.1", "sha1", None, 0, "/scenes/0"),
        SceneInput("nb:conv:W1:S2", "nb", "conv", 1, 2, "L1", "S2", None, "Prompt 1.2", "sha2", None, 0, "/scenes/1"),
        SceneInput("nb:conv:W2:S1", "nb", "conv", 2, 1, "L2", "S1", None, "Prompt 2.1", "sha3", None, 1, "/scenes/0"),
        SceneInput("nb:conv:W2:S2", "nb", "conv", 2, 2, "L2", "S2", None, "Prompt 2.2", "sha4", None, 1, "/scenes/1"),
        SceneInput("nb:conv:W2:S3", "nb", "conv", 2, 3, "L2", "S3", None, "Prompt 2.3", "sha5", None, 1, "/scenes/2"),
    ]
    snaps = [
        InputSnapshot(0, "f1.json", "f1.json", hashlib.sha256(b"{}").hexdigest(), 2, "snapshot/inputs/0001.json"),
        InputSnapshot(1, "f2.json", "f2.json", hashlib.sha256(b"{}").hexdigest(), 2, "snapshot/inputs/0002.json"),
    ]
    return HandoffInventory("nb", "conv", scenes, [], snaps, 2)


@pytest.mark.asyncio
async def test_full_batch_generation_two_lessons(tmp_path: Path) -> None:
    store = SceneArtifactStore(tmp_path)
    store.acquire_lock()
    inv = make_two_lesson_inventory()
    cfg = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "k"})
    store.initialize_run(inv, cfg, "template text", "template_sha", [b"{}", b"{}"])

    provider = FakeProvider()
    builder = FakeBuilder()
    progress_events: list[str] = []

    service = SceneGenerationService(
        provider=provider,
        store=store,
        builder=builder,
        template_text="template text",
        template_sha="template_sha",
    )

    summary = await service.execute_run(inv, progress_cb=progress_events.append)
    store.close()

    assert summary["status"] == "complete"
    assert summary["counts"]["generated"] == 5
    assert summary["counts"]["failed"] == 0
    assert summary["counts"]["queued"] == 0
    assert len(provider.calls) == 5
    assert len(builder.build_calls) == 5

    # Check progress events were emitted
    assert any("scene_sent nb:conv:W1:S1" in e for e in progress_events)
    assert any("scene_generated nb:conv:W2:S3" in e for e in progress_events)


@pytest.mark.asyncio
async def test_partial_failure_continuation(tmp_path: Path) -> None:
    store = SceneArtifactStore(tmp_path)
    store.acquire_lock()
    inv = make_two_lesson_inventory()
    cfg = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "k"})
    store.initialize_run(inv, cfg, "template text", "template_sha", [b"{}", b"{}"])

    # Scene 1.1 fails at provider level with rate limit
    fail_resp = ProviderResponse(
        outcome="failed",
        safe_code="provider_rate_limit",
        http_status=429,
        raw_receipt_ref="err-ref",
    )
    provider = FakeProvider(responses={"Prompt 1.1": fail_resp})
    builder = FakeBuilder()

    service = SceneGenerationService(
        provider=provider,
        store=store,
        builder=builder,
        template_text="template text",
        template_sha="template_sha",
    )

    summary = await service.execute_run(inv)
    store.close()

    assert summary["status"] == "partial"
    assert summary["counts"]["failed"] == 1
    assert summary["counts"]["generated"] == 4
    # All 5 scenes were attempted
    assert len(provider.calls) == 5


@pytest.mark.asyncio
async def test_limit_semantics(tmp_path: Path) -> None:
    store = SceneArtifactStore(tmp_path)
    store.acquire_lock()
    inv = make_two_lesson_inventory()
    cfg = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "k"})
    store.initialize_run(inv, cfg, "template text", "template_sha", [b"{}", b"{}"])

    provider = FakeProvider()
    builder = FakeBuilder()

    service = SceneGenerationService(
        provider=provider,
        store=store,
        builder=builder,
        template_text="template text",
        template_sha="template_sha",
    )

    # Limit to 2 sends
    summary = await service.execute_run(inv, limit=2)
    store.close()

    assert summary["status"] == "partial"
    assert summary["counts"]["generated"] == 2
    assert summary["counts"]["queued"] == 3
    assert len(provider.calls) == 2


@pytest.mark.asyncio
async def test_skip_already_generated_scenes(tmp_path: Path) -> None:
    store = SceneArtifactStore(tmp_path)
    store.acquire_lock()
    inv = make_two_lesson_inventory()
    cfg = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "k"})
    store.initialize_run(inv, cfg, "template text", "template_sha", [b"{}", b"{}"])

    provider = FakeProvider()
    builder = FakeBuilder()

    service = SceneGenerationService(
        provider=provider,
        store=store,
        builder=builder,
        template_text="template text",
        template_sha="template_sha",
    )

    # First run generates all 5
    await service.execute_run(inv)
    assert len(provider.calls) == 5

    # Second run skips all 5
    summary2 = await service.execute_run(inv)
    store.close()

    assert summary2["status"] == "complete"
    assert len(provider.calls) == 5  # No extra calls!


@pytest.mark.parametrize("interrupted_state", ["sending", "response_saved"])
@pytest.mark.asyncio
async def test_interrupted_complete_receipt_is_recovered_without_provider_send(
    tmp_path: Path, interrupted_state: str
) -> None:
    """A persisted, hash-valid provider receipt is parsed locally on normal resume."""
    store = SceneArtifactStore(tmp_path)
    store.acquire_lock()
    inventory = make_two_lesson_inventory()
    config = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "k"})
    store.initialize_run(inventory, config, "template text", "template_sha", [b"{}", b"{}"])
    scene = inventory.scene_inputs[0]
    attempt = store.start_attempt(scene)
    raw_body = json.dumps({
        "modelVersion": "gemini-2.5-flash",
        "responseId": "receipt-response-1",
        "usageMetadata": {"promptTokenCount": 11, "candidatesTokenCount": 7},
        "candidates": [{
            "finishReason": "STOP",
            "content": {"parts": [{"text": json.dumps({
                "schema_version": 1, "files": [{"path":"index.html","content":"<!doctype html><html><head><link rel=\"stylesheet\" href=\"./styles.css\"></head><body><main id=\"app\">Scene</main><script src=\"./script.js\"></script></body></html>"},{"path":"styles.css","content":"body{font:16px sans-serif}"},{"path":"script.js","content":"document.documentElement.dataset.ready=\"true\""}],
            })}]},
        }],
    }).encode()
    store.save_raw_receipt(scene, attempt.ordinal, raw_body, 200, True, False)
    if interrupted_state == "response_saved":
        receipt_ref = "lesson-0001/scene-0001/attempt-0001/response-receipt.json"
        decoded = decode_generation_response("gemini", "gemini-2.5-flash", raw_body, 200, receipt_ref)
        assert decoded.outcome == "complete"
        store.save_response_text(scene, attempt.ordinal, decoded.text or "")
        store.save_provider_result(scene, attempt.ordinal, {
            "outcome": decoded.outcome,
            "safe_code": decoded.safe_code,
            "http_status": decoded.http_status,
            "requested_model": decoded.requested_model,
            "returned_model": decoded.returned_model,
            "model_reported": decoded.model_reported,
            "response_id": decoded.response_id,
            "finish_reason": decoded.finish_reason,
            "usage": decoded.usage,
            "raw_receipt_ref": decoded.raw_receipt_ref,
            "duration_ms": 16,
        })
    checkpoint = store.load_checkpoint()
    checkpoint["items"][0]["status"] = interrupted_state
    checkpoint["items"][0]["attempts"][0]["state"] = interrupted_state
    store._save_checkpoint(checkpoint)

    provider = FakeProvider()
    builder = FakeBuilder()
    service = SceneGenerationService(provider, store, builder, "template text", "template_sha")
    summary = await service.execute_run(inventory, limit=1)
    final = store.load_checkpoint()["items"][0]
    store.close()

    assert final["status"] == "generated"
    assert final["attempts"][0]["ordinal"] == attempt.ordinal
    assert scene.prompt not in provider.calls
    assert len(provider.calls) <= 1
    assert summary["counts"]["generated"] >= 1


@pytest.mark.asyncio
async def test_interrupted_send_without_complete_receipt_is_not_resent(
    tmp_path: Path,
) -> None:
    store = SceneArtifactStore(tmp_path)
    store.acquire_lock()
    inventory = make_two_lesson_inventory()
    config = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "k"})
    store.initialize_run(inventory, config, "template text", "template_sha", [b"{}", b"{}"])
    scene = inventory.scene_inputs[0]
    store.start_attempt(scene)
    provider = FakeProvider()
    service = SceneGenerationService(provider, store, FakeBuilder(), "template text", "template_sha")
    summary = await service.execute_run(inventory)
    item = store.load_checkpoint()["items"][0]
    store.close()

    assert item["status"] == "uncertain"
    assert len(item["attempts"]) == 1
    assert scene.prompt not in provider.calls
    assert summary["status"] == "partial"


@pytest.mark.asyncio
async def test_retained_source_build_failure_recovers_locally_without_provider_send(tmp_path: Path) -> None:
    store = SceneArtifactStore(tmp_path)
    store.acquire_lock()
    inventory = make_two_lesson_inventory()
    config = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "k"})
    store.initialize_run(inventory, config, "template text", "template_sha", [b"{}", b"{}"])
    scene = inventory.scene_inputs[0]
    provider = FakeProvider()
    first = await SceneGenerationService(
        provider, store, FakeBuilder({scene.scene_key}), "template text", "template_sha"
    ).execute_run(inventory, limit=1)
    assert first["counts"]["failed"] == 1
    assert (store.get_attempt_dir(scene, 1) / "source-package.json").is_file()
    calls_before = list(provider.calls)

    second = await SceneGenerationService(
        provider, store, FakeBuilder(), "template text", "template_sha"
    ).execute_run(inventory, limit=1)
    item = next(item for item in store.load_checkpoint()["items"] if item["scene_key"] == scene.scene_key)
    store.close()
    assert item["status"] == "generated"
    assert provider.calls.count(scene.prompt) == calls_before.count(scene.prompt)
    assert second["counts"]["generated"] >= 1


@pytest.mark.parametrize("damaged_evidence", ["dist", "pointer", "manifest"])
@pytest.mark.asyncio
async def test_damaged_published_build_recovers_from_verified_source_without_provider_send(
    tmp_path: Path, damaged_evidence: str
) -> None:
    store = SceneArtifactStore(tmp_path)
    store.acquire_lock()
    inventory = make_two_lesson_inventory()
    config = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "k"})
    store.initialize_run(inventory, config, "template text", "template_sha", [b"{}", b"{}"])
    scene = inventory.scene_inputs[0]
    provider = FakeProvider()
    service = SceneGenerationService(provider, store, FakeBuilder(), "template text", "template_sha")
    await service.execute_run(inventory, limit=1)
    before = len(provider.calls)
    scene_dir = store.output_dir / "lesson-0001/scene-0001"
    pointer_file = scene_dir / "current.json"
    pointer = json.loads(pointer_file.read_text())
    manifest_file = scene_dir / pointer["manifest_path"]
    if damaged_evidence == "dist":
        (manifest_file.parent / "dist/index.html").write_text("tampered", encoding="utf-8")
    elif damaged_evidence == "pointer":
        pointer_file.write_text("{truncated", encoding="utf-8")
    elif damaged_evidence == "manifest":
        manifest_file.unlink()
    else:  # pragma: no cover - parameter list is exhaustive
        raise AssertionError(damaged_evidence)
    await service.execute_run(inventory, limit=1)
    item = store.load_checkpoint()["items"][0]
    store.close()
    assert item["status"] == "generated"
    assert item["successful_build"] == 2
    assert provider.calls.count(scene.prompt) == before


@pytest.mark.parametrize(
    ("corruption", "expected_status", "expected_code"),
    [
        ("body_sha", "failed", "artifact_corrupt"),
        ("body_length", "failed", "artifact_corrupt"),
        ("attempt_ordinal", "failed", "artifact_corrupt"),
        ("incomplete", "uncertain", "provider_uncertain"),
        ("malformed_json", "failed", "provider_response"),
        ("http_500", "uncertain", "provider_uncertain"),
    ],
)
@pytest.mark.asyncio
async def test_unusable_or_unproven_receipt_never_triggers_automatic_send(
    tmp_path: Path, corruption: str, expected_status: str, expected_code: str
) -> None:
    store = SceneArtifactStore(tmp_path)
    store.acquire_lock()
    inventory = make_two_lesson_inventory()
    config = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "k"})
    store.initialize_run(inventory, config, "template text", "template_sha", [b"{}", b"{}"])
    scene = inventory.scene_inputs[0]
    attempt = store.start_attempt(scene)
    payload = b"not-json" if corruption == "malformed_json" else json.dumps({
        "candidates": [{"finishReason": "STOP", "content": {"parts": [{"text": "{}"}]}}],
    }).encode()
    status_code = 500 if corruption == "http_500" else 200
    receipt_ref = store.save_raw_receipt(scene, attempt.ordinal, payload, status_code, True, False)
    receipt_path = tmp_path / receipt_ref
    receipt = json.loads(receipt_path.read_text())
    if corruption == "body_sha":
        (receipt_path.parent / "provider-body.bin").write_bytes(payload + b"tampered")
    elif corruption == "body_length":
        receipt["body_bytes"] += 1
    elif corruption == "attempt_ordinal":
        receipt["attempt_ordinal"] += 1
    elif corruption == "incomplete":
        receipt["complete"] = False
    receipt_path.write_text(json.dumps(receipt))
    checkpoint = store.load_checkpoint()
    checkpoint["items"][0]["status"] = "response_saved"
    checkpoint["items"][0]["attempts"][0]["state"] = "response_saved"
    store._save_checkpoint(checkpoint)

    provider = FakeProvider()
    summary = await SceneGenerationService(
        provider, store, FakeBuilder(), "template text", "template_sha"
    ).execute_run(inventory, limit=1)
    item = store.load_checkpoint()["items"][0]
    store.close()

    assert item["status"] == expected_status
    assert item["error_code"] == expected_code
    assert len(item["attempts"]) == 1
    assert scene.prompt not in provider.calls
    assert summary["status"] == "partial"


@pytest.mark.asyncio
async def test_restore_receipt_rejects_raw_body_corruption_even_when_response_text_exists(tmp_path: Path) -> None:
    store = SceneArtifactStore(tmp_path)
    store.acquire_lock()
    inventory = make_two_lesson_inventory()
    config = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "k"})
    store.initialize_run(inventory, config, "template text", "template_sha", [b"{}", b"{}"])
    scene = inventory.scene_inputs[0]
    provider = FakeProvider()
    service = SceneGenerationService(provider, store, FakeBuilder(), "template text", "template_sha")
    await service.execute_run(inventory, limit=1)
    attempt_dir = store.get_attempt_dir(scene, 1)
    body = attempt_dir / "provider-body.bin"
    body.write_bytes(body.read_bytes() + b"corruption")
    store.close()

    store = SceneArtifactStore(tmp_path)
    store.acquire_lock()
    service = SceneGenerationService(provider, store, FakeBuilder(), "template text", "template_sha")
    with pytest.raises(ArtifactCorruptError, match="artifact_corrupt"):
        service.restore_receipt(scene, 1)
    store.close()
