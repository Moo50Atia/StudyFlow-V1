"""Command-line interface for terminal scene generation (Spec 017)."""

from __future__ import annotations

import argparse
import asyncio
import hashlib
import json
from dataclasses import replace
from pathlib import Path
import sys
from typing import Any, Optional, Sequence
import httpx

from v3_engine.scene_generation.artifact_store import (
    LockBusyError,
    SceneArtifactStore,
)
from v3_engine.scene_generation.builder import SceneBuilder
from v3_engine.scene_generation.config import (
    ConfigurationError,
    load_provider_config,
    resolve_template,
)
from v3_engine.scene_generation.contracts import SceneInput
from v3_engine.scene_generation.handoff import InputError, load_handoff_inventory
from v3_engine.scene_generation.providers import create_provider
from v3_engine.scene_generation.service import SceneGenerationService
from v3_engine.scene_generation.source_package import GeneratedFile, HtmlSceneSourceV1


def _restore_captured_prompt(run_doc: dict[str, Any], scene_row: dict[str, Any]) -> str:
    """Return the exact prompt from the immutable input snapshot after digest validation."""
    source_index = scene_row.get("source_index")
    inputs = run_doc.get("inputs")
    if isinstance(source_index, bool) or not isinstance(source_index, int) or not isinstance(inputs, list) or source_index < 0 or source_index >= len(inputs):
        raise ValueError("captured_scene_input_missing")
    input_row = inputs[source_index]
    if not isinstance(input_row, dict) or input_row.get("source_index") != source_index:
        raise ValueError("captured_scene_input_missing")
    snapshot_path = input_row.get("snapshot_path")
    if not isinstance(snapshot_path, str):
        raise ValueError("captured_scene_input_missing")
    snapshot_file = Path(run_doc["_output_dir"]) / snapshot_path
    raw_bytes = snapshot_file.read_bytes()
    if len(raw_bytes) != input_row.get("bytes") or hashlib.sha256(raw_bytes).hexdigest() != input_row.get("sha256"):
        raise ValueError("captured_scene_input_corrupt")
    document = json.loads(raw_bytes.decode("utf-8"))
    pointer = scene_row.get("source_json_pointer")
    if not isinstance(pointer, str) or not pointer.startswith("/"):
        raise ValueError("captured_scene_pointer_invalid")
    current: Any = document
    for raw_part in pointer.split("/")[1:]:
        part = raw_part.replace("~1", "/").replace("~0", "~")
        if isinstance(current, list):
            if not part.isdigit() or int(part) >= len(current):
                raise ValueError("captured_scene_pointer_invalid")
            current = current[int(part)]
        elif isinstance(current, dict) and part in current:
            current = current[part]
        else:
            raise ValueError("captured_scene_pointer_invalid")
    prompt = current.get("generated_prompt") if isinstance(current, dict) else None
    if not isinstance(prompt, str) or hashlib.sha256(prompt.encode("utf-8")).hexdigest() != scene_row.get("prompt_sha256"):
        raise ValueError("captured_scene_prompt_mismatch")
    return prompt


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="generate_scenes.py",
        description="V3 Terminal Scene Generation CLI",
    )
    subparsers = parser.add_subparsers(dest="subcommand", required=True)

    # Subcommand: check
    check_p = subparsers.add_parser("check", help="Check AI provider connectivity")
    check_p.add_argument("--provider", required=True, help="AI provider name")
    check_p.add_argument("--model", help="Model identifier")

    # Subcommand: run
    run_p = subparsers.add_parser("run", help="Generate scenes from NotebookLM handoff")
    run_p.add_argument("--input", required=True, help="Input handoff file or directory")
    run_p.add_argument("--output-dir", required=True, help="Output run directory")
    run_p.add_argument("--provider", required=True, help="AI provider name")
    run_p.add_argument("--model", help="Model identifier")
    run_p.add_argument("--template", help="Custom template file path")
    run_p.add_argument("--template-sha256", help="SHA256 digest of custom template")
    run_p.add_argument("--request-timeout", type=int, default=300, help="Request timeout in seconds")
    run_p.add_argument("--limit", type=int, help="Limit new provider requests in this invocation")
    run_p.add_argument("--scene-key", action="append", default=[], help="Generate only this captured scene (repeatable)")
    run_p.add_argument("--retry-scene", action="append", default=[], help="Explicitly retry a scene")

    # Subcommand: build
    build_p = subparsers.add_parser("build", help="Rebuild local scene browser artifacts")
    build_p.add_argument("--output-dir", required=True, help="Run output directory")
    build_p.add_argument("--scene-key", action="append", default=[], help="Specific scene key(s) to build")

    return parser


async def _run_check_async(
    provider_name: str,
    model: Optional[str] = None,
    client: Optional[httpx.AsyncClient] = None,
) -> int:
    try:
        cfg = load_provider_config(provider=provider_name, model=model)
    except ConfigurationError as exc:
        print(f"configuration_error {exc}", file=sys.stderr)
        return 2

    provider = create_provider(cfg, client=client)
    try:
        result = await provider.check_connection()
        status_word = "passed" if result.passed else "failed"
        model_name = result.returned_model or result.requested_model
        print(f"provider_check {status_word} {cfg.provider_id} {model_name} {result.safe_code}")
        return 0 if result.passed else 1
    finally:
        await provider.aclose()


async def _run_generation_async(
    args: argparse.Namespace,
    client: Optional[httpx.AsyncClient] = None,
) -> int:
    # 1. Path overlap check
    input_supplied = Path(args.input).expanduser()
    output_supplied = Path(args.output_dir).expanduser()
    if input_supplied.is_symlink() or output_supplied.is_symlink():
        print("input_error symbolic_link_root_rejected", file=sys.stderr)
        return 2
    if args.limit is not None and args.limit <= 0:
        print("configuration_error --limit must be a positive integer", file=sys.stderr)
        return 2
    input_path = input_supplied.resolve()
    output_dir = output_supplied.resolve()

    if input_path == output_dir:
        print("error: input and output paths must not be the same directory", file=sys.stderr)
        return 2

    try:
        output_dir.relative_to(input_path)
        print("error: input and output paths overlap (output inside input)", file=sys.stderr)
        return 2
    except ValueError:
        pass

    try:
        input_path.relative_to(output_dir)
        print("error: input and output paths overlap (input inside output)", file=sys.stderr)
        return 2
    except ValueError:
        pass

    # 2. Acquire lock on output directory
    store = SceneArtifactStore(output_dir)
    try:
        store.acquire_lock()
    except LockBusyError as exc:
        print(f"lock_busy {exc}", file=sys.stderr)
        return 3

    # 3. Load input inventory
    try:
        inventory = load_handoff_inventory(input_path)
    except InputError as exc:
        print(f"input_error {exc}", file=sys.stderr)
        store.close()
        return 2

    if args.scene_key and args.retry_scene:
        print("configuration_error use --scene-key for a selected new run or --retry-scene for an existing run, not both", file=sys.stderr)
        store.close()
        return 2
    if args.scene_key:
        requested = set(args.scene_key)
        if len(requested) != len(args.scene_key):
            print("input_error duplicate_scene_selector", file=sys.stderr)
            store.close()
            return 2
        by_key = {scene.scene_key: scene for scene in inventory.scene_inputs}
        unknown = requested - set(by_key)
        if unknown:
            print("input_error unknown_scene_selector", file=sys.stderr)
            store.close()
            return 2
        selected = [scene for scene in inventory.scene_inputs if scene.scene_key in requested]
        selected_pairs = {(scene.lesson_ordinal, scene.scene_ordinal) for scene in selected}
        selected_lessons = {lesson for lesson, _ in selected_pairs}
        selected_issues = [
            issue for issue in inventory.input_issues
            if issue.lesson_ordinal in selected_lessons
            and (issue.scene_ordinal is None or (issue.lesson_ordinal, issue.scene_ordinal) in selected_pairs)
        ]
        inventory = replace(inventory, scene_inputs=selected, input_issues=selected_issues)

    print(
        f"inputs_loaded lessons {len(inventory.input_snapshots)} scenes {len(inventory.scene_inputs)} input_issues {len(inventory.input_issues)}"
    )

    # 4. Load provider configuration
    try:
        cfg = load_provider_config(
            provider=args.provider,
            model=args.model,
            timeout=args.request_timeout,
        )
    except ConfigurationError as exc:
        print(f"configuration_error {exc}", file=sys.stderr)
        store.close()
        return 2

    # 5. Resolve template
    try:
        template_text, template_sha = resolve_template(
            template_path=Path(args.template) if args.template else None,
            expected_sha256=args.template_sha256,
        )
    except ConfigurationError as exc:
        print(f"configuration_error {exc}", file=sys.stderr)
        store.close()
        return 2

    from v3_engine.scene_generation.builder import get_host_toolchain_info, toolchain_prerequisite_error
    toolchain = get_host_toolchain_info()
    toolchain_error = toolchain_prerequisite_error(toolchain)
    if toolchain_error:
        print(f"configuration_error {toolchain_error}", file=sys.stderr)
        store.close()
        return 2

    # 6. Read raw input bytes and initialize run
    raw_bytes_list: list[bytes] = []
    for snap in inventory.input_snapshots:
        current_bytes = Path(snap.original_path).read_bytes()
        if len(current_bytes) != snap.bytes or hashlib.sha256(current_bytes).hexdigest() != snap.sha256:
            print("input_error input_changed_during_run_initialization", file=sys.stderr)
            store.close()
            return 2
        raw_bytes_list.append(current_bytes)

    try:
        store.initialize_run(
            inventory=inventory,
            config=cfg,
            template_text=template_text,
            template_sha=template_sha,
            input_raw_bytes=raw_bytes_list,
        )
    except ConfigurationError as exc:
        print(f"configuration_error {exc}", file=sys.stderr)
        store.close()
        return 2

    initial_ckpt = store.load_checkpoint()
    print(f"run_opened {initial_ckpt['run_id']}")

    if args.retry_scene:
        ckpt_items_by_key = {it["scene_key"]: it for it in initial_ckpt.get("items", [])}
        for r_key in args.retry_scene:
            if r_key not in ckpt_items_by_key:
                print(f"error: unknown scene selector for retry: {r_key}", file=sys.stderr)
                store.close()
                return 2
            st = ckpt_items_by_key[r_key].get("status")
            if st == "generated":
                print(f"error: scene {r_key} is already generated; cannot retry model send", file=sys.stderr)
                store.close()
                return 2
            if st in ("source_saved", "building") or (st == "failed" and ckpt_items_by_key[r_key].get("error_stage") == "build"):
                print(f"error: scene {r_key} has retained build source; use build recovery, not --retry-scene", file=sys.stderr)
                store.close()
                return 2
            if st == "failed" and ckpt_items_by_key[r_key].get("error_stage") not in {"provider", "package"}:
                print(f"error: scene {r_key} is not eligible for provider retry", file=sys.stderr)
                store.close()
                return 2

    # 7. Instantiate provider, builder, and service
    provider = create_provider(cfg, client=client)
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
            limit=args.limit,
            retry_scenes=args.retry_scene,
            progress_cb=print,
        )
    finally:
        await provider.aclose()
        store.close()

    counts = summary["counts"]
    status = summary["status"]
    print(
        f"run_summary generated {counts['generated']} failed {counts['failed']} uncertain {counts['uncertain']} queued {counts['queued']} input_issues {counts['input_issues']} status {status}"
    )
    print(f"checkpoint {output_dir}/checkpoint.json")

    # 8. Determine exit code
    if status == "complete":
        return 0
    if (
        args.limit is not None
        and counts["queued"] > 0
        and counts["failed"] == 0
        and counts["uncertain"] == 0
        and counts["input_issues"] == 0
    ):
        return 4
    if counts["failed"] > 0 or counts["uncertain"] > 0 or counts["input_issues"] > 0:
        return 1
    return 1


async def _run_build_async(args: argparse.Namespace) -> int:
    supplied_output = Path(args.output_dir).expanduser()
    if supplied_output.is_symlink():
        print("input_error symbolic_link_root_rejected", file=sys.stderr)
        return 2
    output_dir = supplied_output.resolve()
    store = SceneArtifactStore(output_dir)
    try:
        store.acquire_lock()
    except LockBusyError as exc:
        print(f"lock_busy {exc}", file=sys.stderr)
        return 3

    run_file = output_dir / "run.json"
    if not run_file.exists():
        print("error: missing run.json in output directory", file=sys.stderr)
        store.close()
        return 2

    run_doc = json.loads(run_file.read_text(encoding="utf-8"))
    from v3_engine.scene_generation.builder import check_toolchain_drift, get_host_toolchain_info, toolchain_prerequisite_error
    prereq_error = toolchain_prerequisite_error(get_host_toolchain_info())
    if prereq_error:
        print(f"configuration_error {prereq_error}", file=sys.stderr)
        store.close()
        return 2
    drift_err = check_toolchain_drift(run_doc.get("toolchain", {}))
    if drift_err:
        print(f"toolchain_drift: {drift_err}", file=sys.stderr)
        store.close()
        return 2

    ckpt = store.load_checkpoint()
    try:
        run_doc = json.loads((output_dir / "run.json").read_text(encoding="utf-8"))
        run_doc["_output_dir"] = str(output_dir)
        scene_rows = [row for row in run_doc.get("scene_inputs", []) if isinstance(row, dict)]
        captured_scenes = {row.get("scene_key"): row for row in scene_rows}
        duplicate_scene_rows = len(captured_scenes) != len(scene_rows)
    except (OSError, ValueError, TypeError):
        captured_scenes = {}
        duplicate_scene_rows = True
    builder = SceneBuilder()
    selected_keys = set(args.scene_key)
    known_keys = {item.get("scene_key") for item in ckpt.get("items", [])}
    unknown = selected_keys - known_keys
    if unknown:
        print("error: unknown scene selector for build", file=sys.stderr)
        store.close()
        return 2

    all_keys = {item["scene_key"] for item in ckpt.get("items", [])}
    if selected_keys and not selected_keys.issubset(all_keys):
        unknown = selected_keys - all_keys
        print(f"error: unknown scene selectors: {unknown}", file=sys.stderr)
        store.close()
        return 2

    build_failures = 0
    build_successes = 0

    try:
        for item in ckpt.get("items", []):
            scene_key = item["scene_key"]
            if selected_keys and scene_key not in selected_keys:
                continue

            attempts = item.get("attempts", [])
            if not attempts:
                if scene_key in selected_keys:
                    build_failures += 1
                    store.update_item_status(scene_key, "failed", "artifact", "source_manifest_missing")
                    print(f"scene_build_failed {scene_key} source_manifest_missing")
                continue
            latest_attempt = attempts[-1]
            captured = captured_scenes.get(scene_key)
            if duplicate_scene_rows or not isinstance(captured, dict):
                build_failures += 1
                store.update_item_status(scene_key, "failed", "artifact", "scene_inventory_missing")
                print(f"scene_build_failed {scene_key} scene_inventory_missing")
                continue
            try:
                captured_prompt = _restore_captured_prompt(run_doc, captured)
            except (OSError, ValueError, TypeError, KeyError, json.JSONDecodeError):
                build_failures += 1
                store.update_item_status(scene_key, "failed", "artifact", "scene_inventory_corrupt")
                print(f"scene_build_failed {scene_key} scene_inventory_corrupt")
                continue
            parts = scene_key.split(":")
            lesson_ord = int(parts[2][1:])
            scene_ord = int(parts[3][1:])
            dummy_scene = SceneInput(
                scene_key=scene_key,
                notebook_id=parts[0],
                conversation_id=parts[1],
                lesson_ordinal=lesson_ord,
                scene_ordinal=scene_ord,
                lesson_title=captured.get("lesson_title", f"Lesson {lesson_ord}"),
                section_title=captured.get("section_title", f"Scene {scene_ord}"),
                scene_type=captured.get("scene_type"),
                prompt=captured_prompt,
                prompt_sha256=captured.get("prompt_sha256"),
                input_error=captured.get("input_error"),
                source_index=captured.get("source_index", 0),
                source_json_pointer=captured.get("source_json_pointer", "/scenes/0"),
            )
            source_manifest_rel = latest_attempt.get("source_manifest_path")
            if not source_manifest_rel or not (output_dir / source_manifest_rel).exists():
                if scene_key in selected_keys:
                    build_failures += 1
                    store.update_item_status(scene_key, "failed", "artifact", "source_manifest_missing")
                    print(f"scene_build_failed {scene_key} source_manifest_missing")
                continue

            attempt_dir = (output_dir / source_manifest_rel).parent

            if item.get("status") == "generated" and store.verify_current_build(dummy_scene, item):
                print(f"scene_build_skipped {scene_key}")
                continue
            if not store.verify_source_package(dummy_scene, latest_attempt["ordinal"]):
                build_failures += 1
                store.update_item_status(scene_key, "failed", "artifact", "source_artifact_invalid")
                print(f"scene_build_failed {scene_key} source_artifact_invalid")
                continue

            pkg_file = attempt_dir / "source-package.json"
            if not pkg_file.exists():
                if scene_key in selected_keys:
                    build_failures += 1
                    store.update_item_status(scene_key, "failed", "artifact", "source_package_missing")
                continue

            pkg_data = json.loads(pkg_file.read_text(encoding="utf-8"))
            gen_files = [
                GeneratedFile(
                    path=f["path"],
                    content=(attempt_dir / "project" / f["path"]).read_text(encoding="utf-8"),
                    bytes=f["bytes"],
                    sha256=f["sha256"],
                )
                for f in pkg_data.get("files", [])
            ]
            source_pkg = HtmlSceneSourceV1(
                schema_version=pkg_data.get("schema_version", 1),
                files=gen_files,
            )

            next_build_ordinal = store.reserve_build(dummy_scene, latest_attempt["ordinal"])
            store.mark_build_running(dummy_scene, latest_attempt["ordinal"], next_build_ordinal)
            build_res = builder.build_scene(
                attempt_dir=attempt_dir,
                scene=dummy_scene,
                source_pkg=source_pkg,
                build_ordinal=next_build_ordinal,
            )

            if build_res.get("outcome") == "complete":
                store.finish_build(dummy_scene, latest_attempt["ordinal"], next_build_ordinal, "complete", build_res.get("manifest_path"))
                build_successes += 1
                store.update_item_status(
                    scene_key,
                    "generated",
                    successful_build=next_build_ordinal,
                    manifest_path=build_res.get("manifest_path"),
                )
                print(f"scene_built {scene_key} build {next_build_ordinal}")
            else:
                store.finish_build(dummy_scene, latest_attempt["ordinal"], next_build_ordinal, "failed")
                build_failures += 1
                store.update_item_status(
                    scene_key,
                    "failed",
                    error_stage="build",
                    error_code=build_res.get("safe_code"),
                )
                print(f"scene_build_failed {scene_key} {build_res.get('safe_code')}")
    finally:
        store.close()

    print(f"build_summary succeeded {build_successes} failed {build_failures}")
    return 0 if build_failures == 0 else 1


def main(args: Optional[Sequence[str]] = None, client: Optional[httpx.AsyncClient] = None) -> int:
    parser = build_parser()
    try:
        parsed_args = parser.parse_args(args)
    except SystemExit as exc:
        return exc.code if isinstance(exc.code, int) else 2

    if parsed_args.subcommand == "check":
        return asyncio.run(
            _run_check_async(
                provider_name=parsed_args.provider,
                model=parsed_args.model,
                client=client,
            )
        )

    if parsed_args.subcommand == "run":
        return asyncio.run(_run_generation_async(parsed_args, client=client))

    if parsed_args.subcommand == "build":
        return asyncio.run(_run_build_async(parsed_args))

    print(f"unknown_subcommand {parsed_args.subcommand}", file=sys.stderr)
    return 2
