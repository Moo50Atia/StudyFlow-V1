"""Live scene generation and build gate test against real external model API (Spec 017)."""

from __future__ import annotations

import json
import hashlib
import os
from pathlib import Path
import pytest

from v3_engine.local_env import load_local_env
from v3_engine.scene_generation.artifact_store import SceneArtifactStore
from v3_engine.scene_generation.builder import SceneBuilder
from v3_engine.scene_generation.config import ConfigurationError, load_provider_config, resolve_template
from v3_engine.scene_generation.contracts import (
    HandoffInventory,
    InputSnapshot,
    SceneInput,
)
from v3_engine.scene_generation.providers import create_provider
from v3_engine.scene_generation.service import SceneGenerationService


@pytest.mark.live
@pytest.mark.asyncio
async def test_live_scene_generation_and_build(tmp_path: Path, repo_root: Path) -> None:
    """Execute live model request, store response/source, and compile locally."""
    if os.environ.get("V3_SCENE_LIVE") != "1":
        pytest.skip("Live test requires explicit V3_SCENE_LIVE=1 environment variable")

    load_local_env(repo_root / ".env")

    provider_name = os.environ.get("V3_LIVE_PROVIDER", "gemini")
    model_override = os.environ.get("V3_LIVE_MODEL")

    try:
        cfg = load_provider_config(provider_name, model=model_override)
    except ConfigurationError as exc:
        pytest.skip(f"Provider '{provider_name}' configuration/key not available for live test: {exc}")

    template_text, template_sha = resolve_template(repo_root=repo_root)

    # Simple prompt designed for educational scene generation
    test_prompt = "Create an interactive 2D balance scale comparing weights using pure SVG and React."
    dummy_scene = SceneInput(
        scene_key="live-nb:live-conv:W1:S1",
        notebook_id="live-nb",
        conversation_id="live-conv",
        lesson_ordinal=1,
        scene_ordinal=1,
        lesson_title="Physics Balance",
        section_title="Interactive Scale",
        scene_type="Interactive Visualization",
        prompt=test_prompt,
        prompt_sha256="live-prompt-sha",
        input_error=None,
        source_index=0,
        source_json_pointer="/scenes/0",
    )

    output_dir = tmp_path / "live_scene_output"
    store = SceneArtifactStore(output_dir)
    store.acquire_lock()

    try:
        prompt_bytes = test_prompt.encode("utf-8")
        snap = InputSnapshot(0, "live_handoff.json", "live_handoff.json", hashlib.sha256(prompt_bytes).hexdigest(), len(prompt_bytes), "snapshot/inputs/0001.json")
        inventory = HandoffInventory("live-nb", "live-conv", [dummy_scene], [], [snap], 1)
        store.initialize_run(
            inventory=inventory,
            config=cfg,
            template_text=template_text,
            template_sha=template_sha,
            input_raw_bytes=[prompt_bytes],
        )

        provider = create_provider(cfg)
        builder = SceneBuilder()
        service = SceneGenerationService(
            provider=provider,
            store=store,
            builder=builder,
            template_text=template_text,
            template_sha=template_sha,
        )

        try:
            summary = await service.execute_run(
                inventory=inventory,
                limit=1,
                progress_cb=print,
            )
        finally:
            await provider.aclose()

        assert summary["status"] == "complete"
        assert summary["counts"]["generated"] == 1
        assert summary["counts"]["failed"] == 0

        ckpt = store.load_checkpoint()
        item = ckpt["items"][0]
        assert item["status"] == "generated"
        assert item["successful_build"] == 1

        attempts = item["attempts"]
        assert len(attempts) >= 1
        latest_attempt = attempts[-1]
        dist_manifest_rel = latest_attempt.get("dist_manifest_path")
        assert dist_manifest_rel is not None
        manifest_file = output_dir / dist_manifest_rel
        assert manifest_file.exists()

        manifest_data = json.loads(manifest_file.read_text(encoding="utf-8"))
        assert manifest_data["qualification"]["mode"] == "owner_waived_terminal_demo"
        assert manifest_data["qualification"]["jev_called"] is False

        # Verify compiled files exist on disk
        dist_dir = manifest_file.parent / "dist"
        assert (dist_dir / "index.html").exists()

        # Log provenance details for report
        print(f"\n[LIVE PROVENANCE EVIDENCE]")
        print(f"Provider: {cfg.provider_id}")
        print(f"Model: {manifest_data.get('returned_model') or cfg.requested_model}")
        print(f"Manifest SHA256: {manifest_file.name}")
        print(f"Files compiled: {len(manifest_data.get('files', []))}")
    finally:
        store.close()
