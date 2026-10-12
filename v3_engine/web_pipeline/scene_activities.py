"""Scene generation and build Temporal activities (Spec 019 / T041)."""

from __future__ import annotations

import asyncio
import hashlib
import json
import logging
from pathlib import Path
from typing import Any
from temporalio import activity

from v3_engine.scene_generation.artifact_store import SceneArtifactStore
from v3_engine.scene_generation.builder import SceneBuilder
from v3_engine.scene_generation.contracts import (
    HandoffInventory,
    SceneInput,
)
from v3_engine.scene_generation.providers import create_provider
from v3_engine.scene_generation.service import SceneGenerationService
from v3_engine.web_pipeline.scene_context import build_provider_config, is_export_manual_mode
from v3_engine.web_pipeline.settings import get_settings

logger = logging.getLogger("v3_engine.web_pipeline.scene_activities")


def _get_scene_store(run_id: str) -> SceneArtifactStore:
    settings = get_settings()
    output_dir = settings.terminal_root / "runs" / run_id / "scenes"
    output_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
    return SceneArtifactStore(output_dir)


@activity.defn(name="resolve_scene_context_activity")
async def resolve_scene_context_activity(args: dict[str, Any]) -> dict[str, Any]:
    """Resolve scene context and check if manual or selected API mode."""
    run_id = args.get("run_id", "")
    scene_key = args.get("scene_key", "")
    captured_settings = args.get("captured_settings", {})

    if is_export_manual_mode(captured_settings):
        return {
            "outcome": "completed",
            "safe_code": "ok",
            "mode": "export_manual",
            "scene_key": scene_key,
        }

    return {
        "outcome": "completed",
        "safe_code": "ok",
        "mode": "selected_api",
        "scene_key": scene_key,
    }


@activity.defn(name="execute_scene_generation_activity")
async def execute_scene_generation_activity(args: dict[str, Any]) -> dict[str, Any]:
    """Generate scene source via SceneGenerationService or recover retained source."""
    run_id = args.get("run_id", "")
    lesson_ordinal = int(args.get("lesson_ordinal", 1))
    scene_ordinal = int(args.get("scene_ordinal", 1))
    notebook_id = args.get("notebook_id", "nb-app")
    conversation_id = args.get("conversation_id", "conv-app")
    prompt_text = args.get("prompt", "")
    prompt_sha = args.get("prompt_sha256") or hashlib.sha256(prompt_text.encode("utf-8")).hexdigest()
    captured_settings = args.get("captured_settings", {})

    if is_export_manual_mode(captured_settings):
        return {
            "outcome": "completed",
            "safe_code": "ok",
            "skipped": True,
            "mode": "export_manual",
        }

    scene = SceneInput(
        scene_key=f"{notebook_id}:{conversation_id}:W{lesson_ordinal}:S{scene_ordinal}",
        notebook_id=notebook_id,
        conversation_id=conversation_id,
        lesson_ordinal=lesson_ordinal,
        scene_ordinal=scene_ordinal,
        lesson_title=args.get("lesson_title", f"Lesson {lesson_ordinal}"),
        section_title=args.get("section_title", f"Scene {scene_ordinal}"),
        scene_type=args.get("scene_type", "Interactive 2D Canvas"),
        prompt=prompt_text,
        prompt_sha256=prompt_sha,
        input_error=None,
        source_index=0,
        source_json_pointer=f"/lessons/{lesson_ordinal}/scenes/{scene_ordinal}",
    )

    store = _get_scene_store(run_id)
    store.acquire_lock()
    try:
        # Check if source package is already verified on disk (no regeneration)
        if store.verify_source_package(scene, 1):
            return {
                "outcome": "completed",
                "safe_code": "ok",
                "skipped": True,
                "attempt_ordinal": 1,
            }

        # Initialize run metadata if not already done
        run_file = store.output_dir / "run.json"
        if not run_file.exists():
            inventory = HandoffInventory(
                notebook_id=notebook_id,
                conversation_id=conversation_id,
                scene_inputs=[scene],
                input_issues=[],
                input_snapshots=[],
                expected_work_count=1,
            )
            store.initialize_run(
                inventory=inventory,
                config=captured_settings.get("provider"),
                template_text="Static HTML/CSS/JS simulation.",
                template_sha=hashlib.sha256(b"Static HTML/CSS/JS simulation.").hexdigest(),
            )

        provider_config = build_provider_config(captured_settings)
        if provider_config is None:
            return {"outcome": "completed", "safe_code": "ok", "skipped": True}

        provider = create_provider(provider_config)
        builder = SceneBuilder()
        template_text = "Static HTML/CSS/JS simulation."
        template_sha = hashlib.sha256(template_text.encode("utf-8")).hexdigest()

        service = SceneGenerationService(
            provider=provider,
            store=store,
            builder=builder,
            template_text=template_text,
            template_sha=template_sha,
        )

        gen_res = await service.generate_one(scene)
        if gen_res.get("outcome") != "complete":
            return {
                "outcome": "failed",
                "safe_code": gen_res.get("safe_code", "provider_error"),
            }

        service.materialize_source(scene, gen_res["attempt_ordinal"])

        return {
            "outcome": "completed",
            "safe_code": "ok",
            "attempt_ordinal": gen_res["attempt_ordinal"],
            "raw_receipt_ref": gen_res.get("raw_receipt_ref"),
        }
    finally:
        store.close()


@activity.defn(name="execute_scene_build_activity")
async def execute_scene_build_activity(args: dict[str, Any]) -> dict[str, Any]:
    """Execute local build for a scene or skip if current build is already healthy."""
    run_id = args.get("run_id", "")
    lesson_ordinal = int(args.get("lesson_ordinal", 1))
    scene_ordinal = int(args.get("scene_ordinal", 1))
    notebook_id = args.get("notebook_id", "nb-app")
    conversation_id = args.get("conversation_id", "conv-app")
    attempt_ordinal = int(args.get("attempt_ordinal", 1))
    captured_settings = args.get("captured_settings", {})

    if is_export_manual_mode(captured_settings):
        return {
            "outcome": "completed",
            "safe_code": "ok",
            "skipped": True,
            "mode": "export_manual",
        }

    scene = SceneInput(
        scene_key=f"{notebook_id}:{conversation_id}:W{lesson_ordinal}:S{scene_ordinal}",
        notebook_id=notebook_id,
        conversation_id=conversation_id,
        lesson_ordinal=lesson_ordinal,
        scene_ordinal=scene_ordinal,
        lesson_title=args.get("lesson_title", f"Lesson {lesson_ordinal}"),
        section_title=args.get("section_title", f"Scene {scene_ordinal}"),
        scene_type=args.get("scene_type", "Interactive 2D Canvas"),
        prompt=args.get("prompt", ""),
        prompt_sha256=args.get("prompt_sha256"),
        input_error=None,
        source_index=0,
        source_json_pointer=f"/lessons/{lesson_ordinal}/scenes/{scene_ordinal}",
    )

    store = _get_scene_store(run_id)
    store.acquire_lock()
    try:
        # Check if already healthy
        ckpt = store.load_checkpoint()
        item = next((it for it in ckpt.get("items", []) if it.get("scene_key") == scene.scene_key), None)
        if item is not None and store.verify_current_build(scene, item):
            return {
                "outcome": "completed",
                "safe_code": "ok",
                "skipped": True,
                "manifest_path": item.get("manifest_path"),
            }

        builder = SceneBuilder()
        service = SceneGenerationService(
            provider=None,  # Provider not needed for build
            store=store,
            builder=builder,
            template_text="",
            template_sha="",
        )

        build_res = service.build_one(scene, attempt_ordinal)
        if build_res.get("outcome") != "complete":
            return {
                "outcome": "failed",
                "safe_code": build_res.get("safe_code", "build_failed"),
            }

        return {
            "outcome": "completed",
            "safe_code": "ok",
            "manifest_path": build_res.get("manifest_path"),
        }
    finally:
        store.close()
