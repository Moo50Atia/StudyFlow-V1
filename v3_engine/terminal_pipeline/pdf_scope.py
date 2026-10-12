"""Private per-PDF archives and routing, independent of provider transport."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any

from v3_engine.notebooklm.terminal_service import atomic_json
from v3_engine.terminal_pipeline.contracts import ArtifactRef
from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT, EXECUTION_PROMPT


def pdf_run_dir(run_dir: Path, ordinal: int) -> Path:
    if isinstance(ordinal, bool) or not isinstance(ordinal, int) or ordinal < 1:
        raise ValueError("pdf_ordinal_invalid")
    return run_dir / "pdfs" / f"pdf-{ordinal:04d}"


def terminal_scene_key(ordinal: int | None, provider_scene_key: str) -> str:
    """Provider conversation IDs can survive a reset; PDF scope is part of identity."""
    return f"pdf-{ordinal:04d}:{provider_scene_key}" if ordinal is not None else provider_scene_key


def read_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def retain_json(path: Path, document: Any) -> None:
    """A replay may reuse an identical capture; it cannot replace evidence."""
    if path.exists():
        if read_json(path) != document:
            raise ValueError("pdf_capture_conflict")
    else:
        atomic_json(path, document)


def artifact_ref(run_dir: Path, path: Path) -> dict[str, Any]:
    content = path.read_bytes()
    return {"path": str(path.relative_to(run_dir)), "bytes": len(content),
            "sha256": hashlib.sha256(content).hexdigest()}


def verify_pdf_archive(run_dir: Path, ordinal: int) -> dict[str, Any]:
    archive = read_json(pdf_run_dir(run_dir, ordinal) / "private" / "archive.json")
    if archive.get("state") != "archived" or archive.get("pdf_ordinal") != ordinal or not archive.get("files"):
        raise ValueError("pdf_archive_invalid")
    for row in archive["files"]:
        if not ArtifactRef.from_dict(row).verify_on_disk(run_dir):
            raise ValueError("pdf_archive_corrupt")
    if not ArtifactRef.from_dict(archive["accepted_pdf_ref"]).verify_on_disk(run_dir.parent.parent):
        raise ValueError("pdf_snapshot_changed")
    if archive.get("accepted_steps_ref") and not ArtifactRef.from_dict(archive["accepted_steps_ref"]).verify_on_disk(run_dir.parent.parent):
        raise ValueError("framework_snapshot_changed")
    if archive.get("pdf_preparation_ref"):
        if not ArtifactRef.from_dict(archive["pdf_preparation_ref"]).verify_on_disk(run_dir.parent.parent):
            raise ValueError("pdf_preparation_ref_corrupt")
        config_path = run_dir / "subject-config.json"
        if config_path.exists():
            cfg = read_json(config_path)
            src = next((row for row in cfg.get("inputs", []) if row.get("kind") == "pdf" and row.get("ordinal") == ordinal), None)
            if src and src.get("pdf_lineage") and archive.get("pdf_lineage") != src.get("pdf_lineage"):
                raise ValueError("pdf_archive_lineage_mismatch")
    return archive


def archive_pdf(run_dir: Path, ordinal: int) -> tuple[Path, dict[str, Any]]:
    """Seal maps, exact receipts, handoffs, and the accepted PDF before any reset."""
    scope = pdf_run_dir(run_dir, ordinal)
    archive_path = scope / "private" / "archive.json"
    if archive_path.exists():
        return archive_path, verify_pdf_archive(run_dir, ordinal)
    capture_dir = scope / "private" / "turn-captures"
    divide = read_json(capture_dir / "divide.json")
    execution = read_json(capture_dir / "execution.json")
    count = divide["captured"]["work_count"]
    if isinstance(count, bool) or not isinstance(count, int) or count < 1:
        raise ValueError("pdf_map_invalid")
    if execution["captured"]["raw_answer"].strip() != "System Ready":
        raise ValueError("pdf_execution_not_ready")
    notebook = divide["notebook_id"]
    conversation = divide["conversation_id"]
    source_ids = divide["pdf_source_ids"]
    if len(source_ids) != 1 or not notebook or not conversation:
        raise ValueError("pdf_lineage_invalid")
    config = read_json(run_dir / "subject-config.json")
    source = next(row for row in config["inputs"] if row["kind"] == "pdf" and row["ordinal"] == ordinal)
    terminal_root = run_dir.parent.parent
    accepted_ref = ArtifactRef.from_dict(source["snapshot_ref"])
    if not accepted_ref.verify_on_disk(terminal_root):
        raise ValueError("pdf_snapshot_changed")
    framework = next(row for row in config["inputs"] if row["kind"] == "steps")
    if not ArtifactRef.from_dict(framework["snapshot_ref"]).verify_on_disk(terminal_root):
        raise ValueError("framework_snapshot_changed")
    # The snapshot lives outside this run; retain its reference separately.
    paths: list[Path] = []
    scene_count = 0
    names = ["divide", "execution"] + [f"lesson-{i:03d}" for i in range(1, count + 1)]
    for name in names:
        capture_path = capture_dir / f"{name}.json"
        capture = read_json(capture_path)
        if (capture["notebook_id"] != notebook or capture["conversation_id"] != conversation
                or capture["pdf_source_ids"] != source_ids):
            raise ValueError("pdf_lineage_conflict")
        receipt_path = run_dir / "private" / "turn-receipts" / f'{capture["receipt_ref"]}.json'
        receipt = read_json(receipt_path)
        if (receipt.get("state") != "complete" or receipt.get("conversation_id") != conversation
                or hashlib.sha256(receipt["raw_answer"].encode("utf-8")).hexdigest() != capture["raw_answer_sha256"]):
            raise ValueError("pdf_receipt_invalid")
        selected_sources = source_ids if name == "divide" else source_ids + [execution["framework_source_id"]]
        expected_question = (DIVIDE_PROMPT if name == "divide" else EXECUTION_PROMPT if name == "execution"
                             else "Start with First lesson" if name == "lesson-001" else "Continue")
        request = {"notebook_id": notebook, "question": expected_question,
                   "source_ids": selected_sources, "conversation_id": None if name == "divide" else conversation}
        if name == "divide" and ordinal > 1:
            previous = verify_pdf_archive(run_dir, ordinal - 1)
            request["fresh_after_conversation_id"] = previous["conversation_id"]
            if receipt.get("fresh_after_conversation_id") != previous["conversation_id"]:
                raise ValueError("pdf_fresh_conversation_invalid")
        expected_digest = hashlib.sha256(json.dumps(request, sort_keys=True, separators=(",", ":")).encode("utf-8")).hexdigest()
        if receipt.get("request_digest") != expected_digest or receipt.get("source_ids") != selected_sources:
            raise ValueError("pdf_receipt_scope_invalid")
        paths.extend([capture_path, receipt_path])
        if name.startswith("lesson-"):
            local_ordinal = int(name.split("-")[1])
            handoff_path = scope / "lesson_handoffs" / f"{name}.json"
            handoff = read_json(handoff_path)
            if (handoff.get("lesson_ordinal") != local_ordinal or handoff.get("conversation_id") != conversation
                    or handoff.get("notebook_id") != notebook or handoff.get("pdf_source_ids") != source_ids
                    or handoff.get("raw_answer_sha256") != capture["raw_answer_sha256"]):
                raise ValueError("pdf_handoff_invalid")
            scene_count += len(handoff["scenes"])
            paths.append(handoff_path)
    if len(list((scope / "lesson_handoffs").glob("lesson-*.json"))) != count:
        raise ValueError("pdf_lesson_count_conflict")
    document = {
        "schema_version": 1,
        "kind": "v3_notebooklm_pdf_archive",
        "state": "archived",
        "pdf_ordinal": ordinal,
        "pdf_sha256": source["sha256"],
        "original_filename": Path(source["original_path"]).name,
        "accepted_pdf_ref": source["snapshot_ref"],
        "notebook_id": notebook,
        "accepted_steps_ref": framework["snapshot_ref"],
        "steps_sha256": framework["sha256"],
        "conversation_id": conversation,
        "pdf_source_id": source_ids[0],
        "framework_source_id": execution["framework_source_id"],
        "work_count": count,
        "scene_count": scene_count,
        "files": [artifact_ref(run_dir, path) for path in paths],
    }
    if config.get("pdf_preparation_ref"):
        document["pdf_preparation_ref"] = config["pdf_preparation_ref"]
        prep_inv_path = terminal_root / config["pdf_preparation_ref"]["path"]
        if prep_inv_path.exists():
            inv = read_json(prep_inv_path)
            document["policy"] = inv.get("policy")
    if source.get("pdf_lineage"):
        lineage = source["pdf_lineage"]
        document["pdf_lineage"] = lineage
        document["source_ordinal"] = lineage.get("source_ordinal")
        document["part_ordinal"] = lineage.get("part_ordinal")
        document["start_page"] = lineage.get("start_page")
        document["end_page"] = lineage.get("end_page")
        if config.get("pdf_preparation_ref"):
            prep_inv_path = terminal_root / config["pdf_preparation_ref"]["path"]
            if prep_inv_path.exists():
                inv = read_json(prep_inv_path)
                src_ord = lineage.get("source_ordinal")
                orig = next((o for o in inv.get("originals", []) if o.get("source_ordinal") == src_ord), None)
                if orig:
                    document["original_source_ordinal"] = src_ord
                    document["original_source_filename"] = orig.get("original_filename")
                    document["original_source_sha256"] = orig.get("sha256")
                    document["original_source_page_count"] = orig.get("page_count")
                    document["split_applied"] = orig.get("split_applied")
    retain_json(archive_path, document)
    return archive_path, verify_pdf_archive(run_dir, ordinal)


def scoped_inventories(run_dir: Path):
    """Keep each conversation in its own unchanged Spec 017 inventory."""
    from v3_engine.scene_generation.handoff import load_handoff_inventory

    config_path = run_dir / "subject-config.json"
    config = read_json(config_path) if config_path.exists() else {}
    if config.get("pdf_processing", "joint") == "per_pdf":
        for source in config["inputs"]:
            if source["kind"] == "pdf":
                ordinal = source["ordinal"]
                archive = verify_pdf_archive(run_dir, ordinal)
                scope = pdf_run_dir(run_dir, ordinal)
                yield ordinal, scope, load_handoff_inventory(scope / "lesson_handoffs"), archive
    else:
        yield None, run_dir, load_handoff_inventory(run_dir / "lesson_handoffs"), None
