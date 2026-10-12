"""Real npm/tsc/Vite build execution tests (Spec 017 local build gate)."""

from __future__ import annotations

import json
import hashlib
from pathlib import Path
import pytest

from v3_engine.scene_generation.artifact_store import SceneArtifactStore
from v3_engine.scene_generation.builder import SceneBuilder
from v3_engine.scene_generation.config import load_provider_config
from v3_engine.scene_generation.contracts import (
    HandoffInventory,
    InputSnapshot,
    SceneInput,
)
from v3_engine.scene_generation.source_package import GeneratedFile, ReactSceneSourceV1
from v3_engine.scene_generation.service import SceneGenerationService


def create_test_scene() -> SceneInput:
    return SceneInput(
        scene_key="test-nb:test-conv:W1:S1",
        notebook_id="test-nb",
        conversation_id="test-conv",
        lesson_ordinal=1,
        scene_ordinal=1,
        lesson_title="Real Build Lesson",
        section_title="Build Section",
        scene_type="Interactive",
        prompt="Build test prompt",
        prompt_sha256="fake-sha",
        input_error=None,
        source_index=0,
        source_json_pointer="/scenes/0",
    )


def record_provider_evidence(store: SceneArtifactStore, scene: SceneInput, attempt, cfg, source_pkg: ReactSceneSourceV1) -> None:
    response_text = json.dumps({"schema_version": 1, "dependencies": source_pkg.dependencies, "files": [{"path": f.path, "content": f.content} for f in source_pkg.files]})
    raw_body = json.dumps({"modelVersion": cfg.requested_model, "responseId": "test-response", "usageMetadata": {"promptTokenCount": 5, "candidatesTokenCount": 7}, "candidates": [{"finishReason": "STOP", "content": {"parts": [{"text": response_text}]}}]}).encode()
    receipt_ref = store.save_raw_receipt(scene, attempt.ordinal, raw_body, 200, True, False)
    store.save_response_text(scene, attempt.ordinal, response_text)
    store.save_provider_result(scene, attempt.ordinal, {"outcome": "complete", "safe_code": "ok", "http_status": 200, "requested_model": cfg.requested_model, "returned_model": cfg.requested_model, "model_reported": True, "response_id": "test-response", "finish_reason": "STOP", "usage": {"input_tokens": 5, "output_tokens": 7}, "raw_receipt_ref": receipt_ref, "duration_ms": 1})


def test_real_local_build_svg_fixture(tmp_path: Path) -> None:
    store = SceneArtifactStore(tmp_path)
    store.acquire_lock()

    scene = create_test_scene()
    snap = InputSnapshot(0, "in.json", "in.json", hashlib.sha256(b"{}").hexdigest(), 2, "snapshot/inputs/0001.json")
    inv = HandoffInventory("test-nb", "test-conv", [scene], [], [snap], 1)
    cfg = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "k"})
    store.initialize_run(inv, cfg, "tmpl", "8845", [b"{}"])

    attempt = store.start_attempt(scene)

    # Valid React component with useState
    scene_code = """import React, { useState } from 'react';

export default function Scene() {
  const [count, setCount] = useState(0);
  return (
    <div style={{ color: 'white', padding: 20 }}>
      <h1>Interactive Scene Test</h1>
      <button id="counter-btn" onClick={() => setCount(c => c + 1)}>
        Count: {count}
      </button>
    </div>
  );
}
"""
    gen_file = GeneratedFile("src/Scene.tsx", scene_code, len(scene_code.encode("utf-8")), hashlib.sha256(scene_code.encode("utf-8")).hexdigest())
    source_pkg = ReactSceneSourceV1(schema_version=1, dependencies=[], files=[gen_file])

    builder = SceneBuilder()
    scaffold_files = builder.get_scaffold_files(source_pkg.dependencies)
    store.save_source_package(scene, attempt.ordinal, source_pkg, scaffold_files)
    record_provider_evidence(store, scene, attempt, cfg, source_pkg)

    service = SceneGenerationService(
        provider=None,
        store=store,
        builder=builder,
        template_text="tmpl",
        template_sha=hashlib.sha256(b"tmpl").hexdigest(),
    )
    build_res = service.build_one(scene, attempt.ordinal, source_pkg)

    assert build_res["status"] == "complete"
    assert build_res["outcome"] == "complete"
    assert build_res["safe_code"] == "ok"

    dist_index = attempt.attempt_dir / "builds" / "build-0001" / "dist" / "index.html"
    assert dist_index.exists()
    assert len(dist_index.read_text(encoding="utf-8")) > 0

    manifest_file = attempt.attempt_dir / "builds" / "build-0001" / "manifest.json"
    assert manifest_file.exists()
    manifest_doc = json.loads(manifest_file.read_text(encoding="utf-8"))
    assert manifest_doc["qualification"]["mode"] == "owner_waived_terminal_demo"
    assert manifest_doc["verification"]["typecheck"] is True
    assert manifest_doc["verification"]["production_build"] is True

    # A real healthy manifest has no synthetic status field; build-only must
    # use the store's pointer/manifest/file verifier and refuse to rebuild it.
    before_checkpoint = store.load_checkpoint()
    with pytest.raises(ValueError, match="Intact healthy output cannot be rebuilt"):
        SceneGenerationService(
            provider=None,
            store=store,
            builder=builder,
            template_text="tmpl",
            template_sha=hashlib.sha256(b"tmpl").hexdigest(),
        ).build_only_scene(scene, attempt.ordinal)
    assert store.load_checkpoint() == before_checkpoint

    store.close()


def test_real_local_build_typecheck_error(tmp_path: Path) -> None:
    store = SceneArtifactStore(tmp_path)
    store.acquire_lock()

    scene = create_test_scene()
    snap = InputSnapshot(0, "in.json", "in.json", hashlib.sha256(b"{}").hexdigest(), 2, "snapshot/inputs/0001.json")
    inv = HandoffInventory("test-nb", "test-conv", [scene], [], [snap], 1)
    cfg = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "k"})
    store.initialize_run(inv, cfg, "tmpl", "8845", [b"{}"])

    attempt = store.start_attempt(scene)

    # Intentionally broken TypeScript code (type mismatch)
    bad_code = """import React from 'react';

export default function Scene() {
  const num: number = "not a number";
  return <div>{num}</div>;
}
"""
    gen_file = GeneratedFile("src/Scene.tsx", bad_code, len(bad_code.encode("utf-8")), hashlib.sha256(bad_code.encode("utf-8")).hexdigest())
    source_pkg = ReactSceneSourceV1(schema_version=1, dependencies=[], files=[gen_file])

    builder = SceneBuilder()
    scaffold_files = builder.get_scaffold_files(source_pkg.dependencies)
    store.save_source_package(scene, attempt.ordinal, source_pkg, scaffold_files)
    record_provider_evidence(store, scene, attempt, cfg, source_pkg)

    service = SceneGenerationService(
        provider=None,
        store=store,
        builder=builder,
        template_text="tmpl",
        template_sha=hashlib.sha256(b"tmpl").hexdigest(),
    )
    build_res = service.build_one(scene, attempt.ordinal, source_pkg)

    assert build_res["status"] == "failed"
    assert build_res["outcome"] == "failed"
    assert build_res["safe_code"] == "typecheck_failed"

    # T028: source and lockfile bytes remain bound to the captured evidence.
    assert store.verify_source_package(scene, attempt.ordinal)
    retained_source = attempt.attempt_dir / "project/src/Scene.tsx"
    original_source = retained_source.read_bytes()
    retained_source.write_bytes(original_source + b"\n// tampered after capture\n")
    assert not store.verify_source_package(scene, attempt.ordinal)
    retained_source.write_bytes(original_source)

    lockfile = attempt.attempt_dir / "project/package-lock.json"
    assert lockfile.is_file()
    original_lock = lockfile.read_bytes()
    lockfile.write_bytes(original_lock + b"\n")
    assert not store.verify_source_package(scene, attempt.ordinal)

    store.close()


def test_real_local_rebuild_after_transient_build_failure_uses_new_ordinal_without_provider(
    tmp_path: Path,
) -> None:
    """A local build retry reuses verified source and keeps the failed build record."""
    store = SceneArtifactStore(tmp_path)
    store.acquire_lock()

    scene = create_test_scene()
    raw_input = b"{}"
    snap = InputSnapshot(0, "in.json", "in.json", hashlib.sha256(raw_input).hexdigest(), len(raw_input), "snapshot/inputs/0001.json")
    inv = HandoffInventory("test-nb", "test-conv", [scene], [], [snap], 1)
    cfg = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "test-only"})
    store.initialize_run(inv, cfg, "tmpl", hashlib.sha256(b"tmpl").hexdigest(), [raw_input])
    attempt = store.start_attempt(scene)

    scene_code = "export default function Scene() { return <main><h1>Recovered locally</h1></main>; }"
    generated = GeneratedFile(
        "src/Scene.tsx",
        scene_code,
        len(scene_code.encode("utf-8")),
        hashlib.sha256(scene_code.encode("utf-8")).hexdigest(),
    )
    source_pkg = ReactSceneSourceV1(schema_version=1, dependencies=[], files=[generated])
    real_builder = SceneBuilder()
    store.save_source_package(scene, attempt.ordinal, source_pkg, real_builder.get_scaffold_files([]))
    record_provider_evidence(store, scene, attempt, cfg, source_pkg)

    class FailOnceBuilder:
        """Simulate one transient local tool failure, then invoke the real npm build."""

        def __init__(self) -> None:
            self.build_calls = 0

        def get_scaffold_files(self, dependencies):
            return real_builder.get_scaffold_files(dependencies)

        def build_scene(self, **kwargs):
            self.build_calls += 1
            if self.build_calls == 1:
                return {"outcome": "failed", "safe_code": "tool_process_failed"}
            return real_builder.build_scene(**kwargs)

    builder = FailOnceBuilder()
    service = SceneGenerationService(
        provider=None,
        store=store,
        builder=builder,
        template_text="tmpl",
        template_sha=hashlib.sha256(b"tmpl").hexdigest(),
    )

    first = service.build_one(scene, attempt.ordinal, source_pkg)
    assert first["outcome"] == "failed"
    first_manifest = attempt.attempt_dir / "builds/build-0001/manifest.json"
    assert not first_manifest.exists()

    second = service.build_one(scene, attempt.ordinal, source_pkg)
    assert second["outcome"] == "complete"
    assert (attempt.attempt_dir / "builds/build-0002/dist/index.html").is_file()
    assert not first_manifest.exists()

    checkpoint = store.load_checkpoint()
    item = checkpoint["items"][0]
    assert item["status"] == "generated"
    assert item["successful_build"] == 2
    assert [row["ordinal"] for row in item["attempts"][0]["builds"]] == [1, 2]
    assert item["attempts"][0]["builds"][0]["status"] == "failed"
    assert item["attempts"][0]["builds"][1]["status"] == "complete"
    # The only provider evidence is the fixture receipt created before either build.
    assert builder.build_calls == 2

    store.close()


def test_real_ast_validator_rejects_sibling_prefix_path_escape(tmp_path: Path) -> None:
    store = SceneArtifactStore(tmp_path)
    store.acquire_lock()
    scene = create_test_scene()
    snap = InputSnapshot(0, "in.json", "in.json", hashlib.sha256(b"{}").hexdigest(), 2, "snapshot/inputs/0001.json")
    inv = HandoffInventory("test-nb", "test-conv", [scene], [], [snap], 1)
    cfg = load_provider_config("gemini", model="gemini-2.5-flash", env={"GEMINI_API_KEY": "k"})
    store.initialize_run(inv, cfg, "tmpl", "tmpl-sha", [b"{}"])
    attempt = store.start_attempt(scene)
    escaped_code = 'import leaked from "../../project-private/secret"; export default () => <div>{leaked}</div>;'
    package = ReactSceneSourceV1(1, [], [GeneratedFile("src/Scene.tsx", escaped_code, len(escaped_code.encode()), hashlib.sha256(escaped_code.encode()).hexdigest())])
    builder = SceneBuilder()
    store.save_source_package(scene, attempt.ordinal, package, builder.get_scaffold_files([]))
    record_provider_evidence(store, scene, attempt, cfg, package)
    result = builder.build_scene(attempt.attempt_dir, scene, package, build_ordinal=1)
    validation_log = (attempt.attempt_dir / "builds/build-0001/validation.log").read_text(encoding="utf-8")
    store.close()
    assert result["outcome"] == "failed"
    assert result["safe_code"] == "source_import_invalid"
    assert "escapes project directory" in validation_log
