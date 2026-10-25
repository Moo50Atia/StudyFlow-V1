"""Local-only web preparation. No provider calls or arbitrary caller paths."""
from pathlib import Path
from typing import Any
from v3_engine.web_pipeline.artifact_refs import is_valid_uuid_ref
from v3_engine.terminal_pipeline.pdf_preparation import (
    PreparationPolicy, PreparationError, prepare_pdf_sources, compute_file_sha256, verify_preparation,
)

MAX_PREPARED_PDFS = 640  # 20 originals of at most 1000 pages, recursively halved.
WEB_POLICY = PreparationPolicy(version=2, split_trigger_pages=50)


def _reject_symlinks(path: Path, root: Path) -> None:
    if not path.resolve().is_relative_to(root.resolve()):
        raise ValueError("Preparation path escapes storage")
    current = path
    while current != root:
        if current.is_symlink():
            raise ValueError("Symlink in preparation package")
        current = current.parent


def prepare_web_pdfs(storage_root: Path, payload: dict[str, Any]) -> dict[str, Any]:
    if not isinstance(payload, dict):
        raise ValueError("Invalid preparation payload")
    owner = payload.get("owner_user_id")
    key = payload.get("submission_key")
    originals = payload.get("originals")
    if (type(owner) is not int or owner < 1 or not is_valid_uuid_ref(key)
            or not isinstance(originals, list) or not 1 <= len(originals) <= 20):
        raise ValueError("Invalid preparation identity or originals")
    root = storage_root.resolve()
    base = root / "web-preparations" / str(owner) / key.lower()
    paths = []
    for ordinal, record in enumerate(originals, start=1):
        if not isinstance(record, dict):
            raise ValueError("Invalid original descriptor")
        name = record.get("filename")
        if (not isinstance(name, str) or not name.lower().endswith(".pdf")
                or Path(name).name != name or "\\" in name or len(name.encode()) > 200):
            raise ValueError("Invalid original filename")
        source = base / "input" / f"{ordinal:04d}" / name
        current = source
        while current != root:
            if current.is_symlink():
                raise ValueError("Symlink in preparation path")
            current = current.parent
        if not source.resolve().is_relative_to(root) or not source.is_file():
            raise ValueError("Original is unavailable")
        sha, size = compute_file_sha256(source)
        if sha != record.get("sha256") or size != record.get("bytes") or not 0 < size <= 104857600:
            raise ValueError("Original fingerprint differs")
        paths.append(source)
    package = base / "package"
    _reject_symlinks(package, root)
    inventory = prepare_pdf_sources(paths, package, policy=WEB_POLICY)
    if any(o.page_count > 1000 for o in inventory.originals) or len(inventory.prepared_pdfs) > MAX_PREPARED_PDFS:
        raise ValueError("Preparation exceeds supported page/part limits")
    file_sha, _ = compute_file_sha256(package / "inventory.json")
    return {
        "inventory_ref": str((package / "inventory.json").relative_to(root)),
        "inventory_file_sha256": file_sha,
        "policy": inventory.policy.to_dict(),
        "originals": [o.to_dict() for o in inventory.originals],
        "prepared_pdfs": [p.to_dict() for p in inventory.prepared_pdfs],
    }


def verify_web_manifest_preparation(storage_root: Path, manifest: dict[str, Any]) -> None:
    capture = manifest.get("pdf_preparation")
    if not isinstance(capture, dict):
        raise ValueError("Missing captured preparation")
    owner, key = manifest.get("owner_user_id"), manifest.get("submission_key")
    if type(owner) is not int or owner < 1 or not is_valid_uuid_ref(key):
        raise ValueError("Invalid captured preparation owner")
    root = storage_root.resolve()
    expected_ref = f"web-preparations/{owner}/{key.lower()}/package/inventory.json"
    if capture.get("inventory_ref") != expected_ref or capture.get("policy") != WEB_POLICY.to_dict():
        raise ValueError("Captured preparation identity differs")
    path = root / expected_ref
    _reject_symlinks(path, root)
    if compute_file_sha256(path)[0] != capture.get("inventory_file_sha256"):
        raise ValueError("Captured preparation fingerprint differs")
    inventory = verify_preparation(path)
    if inventory.policy != WEB_POLICY:
        raise ValueError("Captured preparation policy differs")
    items = manifest.get("items")
    if not isinstance(items, list) or len(items) != len(inventory.prepared_pdfs):
        raise ValueError("Captured part count differs")
    for source in inventory.originals:
        _reject_symlinks(path.parent / source.retained_original_ref.path, root)
    for ordinal, (item, part) in enumerate(zip(items, inventory.prepared_pdfs), start=1):
        _reject_symlinks(path.parent / part.artifact_ref.path, root)
        source = inventory.originals[part.source_ordinal - 1]
        expected_lineage = {
            "source_ordinal": part.source_ordinal, "part_ordinal": part.part_ordinal,
            "original_filename": source.original_filename, "original_sha256": source.sha256,
            "original_page_count": source.page_count, "start_page": part.start_page,
            "end_page": part.end_page, "page_count": part.page_count,
        }
        if (item.get("pdf_ordinal") != ordinal or item.get("original_filename") != part.filename
                or item.get("input_sha256") != part.artifact_ref.sha256
                or item.get("input_bytes") != part.artifact_ref.bytes
                or item.get("pdf_lineage") != expected_lineage):
            raise ValueError("Captured part ordering, lineage or fingerprint differs")
