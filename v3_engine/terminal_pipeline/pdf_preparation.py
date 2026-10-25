"""Local PDF preparation, inspection, recursive splitting, and verification (Spec 020)."""

from __future__ import annotations

import fcntl
import hashlib
import json
import logging
import os
import re
import shutil
import subprocess
import uuid
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Sequence

from v3_engine.terminal_pipeline.contracts import (
    ArtifactRef,
    SafeCode,
    validate_sha256,
)
from v3_engine.terminal_pipeline.manifest import (
    canonical_json_dumps,
    compute_digest,
    compute_file_sha256,
)

logger = logging.getLogger("v3_engine.terminal_pipeline.pdf_preparation")

QPDF_INSPECTION_TIMEOUT_SECONDS = 60
QPDF_EXTRACTION_TIMEOUT_SECONDS = 120


class PreparationError(Exception):
    def __init__(self, message: str, safe_code: SafeCode = SafeCode.input_invalid):
        super().__init__(message)
        self.safe_code = safe_code


@dataclass(frozen=True)
class PreparationPolicy:
    version: int = 1
    split_trigger_pages: int = 100
    max_part_pages: int = 50
    algorithm: str = "recursive_halving"
    odd_rule: str = "smaller_first"

    def __post_init__(self) -> None:
        triggers = {1: 100, 2: 50}
        if self.version not in triggers or self.split_trigger_pages != triggers[self.version]:
            raise ValueError("Preparation policy version/trigger mismatch")
        if self.max_part_pages != 50:
            raise ValueError(f"max_part_pages must be 50, got {self.max_part_pages}")
        if self.algorithm != "recursive_halving":
            raise ValueError(f"algorithm must be recursive_halving, got {self.algorithm}")
        if self.odd_rule != "smaller_first":
            raise ValueError(f"odd_rule must be smaller_first, got {self.odd_rule}")

    def to_dict(self) -> dict[str, Any]:
        return {
            "version": self.version,
            "split_trigger_pages": self.split_trigger_pages,
            "max_part_pages": self.max_part_pages,
            "algorithm": self.algorithm,
            "odd_rule": self.odd_rule,
        }

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> PreparationPolicy:
        return cls(
            version=int(data.get("version", 1)),
            split_trigger_pages=int(data.get("split_trigger_pages", 100)),
            max_part_pages=int(data.get("max_part_pages", 50)),
            algorithm=str(data.get("algorithm", "recursive_halving")),
            odd_rule=str(data.get("odd_rule", "smaller_first")),
        )


@dataclass(frozen=True)
class OriginalSource:
    source_ordinal: int
    original_path: str
    original_filename: str
    sha256: str
    bytes: int
    page_count: int
    retained_original_ref: ArtifactRef
    split_applied: bool
    policy_version: int = 1

    def __post_init__(self) -> None:
        if self.source_ordinal < 1:
            raise ValueError("source_ordinal must be >= 1")
        if not self.original_path:
            raise ValueError("original_path cannot be empty")
        if not self.original_filename:
            raise ValueError("original_filename cannot be empty")
        validate_sha256(self.sha256, "OriginalSource.sha256")
        if self.bytes < 0:
            raise ValueError("bytes cannot be negative")
        if self.page_count < 1:
            raise ValueError("page_count must be >= 1")
        if self.policy_version not in (1, 2) or self.split_applied != (self.page_count > {1: 100, 2: 50}[self.policy_version]):
            raise ValueError("Original split decision differs from its policy version")

    def to_dict(self) -> dict[str, Any]:
        return {
            "source_ordinal": self.source_ordinal,
            "original_path": self.original_path,
            "original_filename": self.original_filename,
            "sha256": self.sha256,
            "bytes": self.bytes,
            "page_count": self.page_count,
            "retained_original_ref": self.retained_original_ref.to_dict(),
            "split_applied": self.split_applied,
            **({"policy_version": self.policy_version} if self.policy_version != 1 else {}),
        }

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> OriginalSource:
        return cls(
            source_ordinal=int(data["source_ordinal"]),
            original_path=str(data["original_path"]),
            original_filename=str(data["original_filename"]),
            sha256=str(data["sha256"]),
            bytes=int(data["bytes"]),
            page_count=int(data["page_count"]),
            retained_original_ref=ArtifactRef.from_dict(data["retained_original_ref"]),
            split_applied=bool(data["split_applied"]),
            policy_version=int(data.get("policy_version", 1)),
        )


@dataclass(frozen=True)
class PreparedPDF:
    upload_ordinal: int
    source_ordinal: int
    part_ordinal: int
    start_page: int
    end_page: int
    page_count: int
    filename: str
    artifact_ref: ArtifactRef

    def __post_init__(self) -> None:
        if self.upload_ordinal < 1:
            raise ValueError("upload_ordinal must be >= 1")
        if self.source_ordinal < 1:
            raise ValueError("source_ordinal must be >= 1")
        if self.part_ordinal < 1:
            raise ValueError("part_ordinal must be >= 1")
        if self.start_page < 1:
            raise ValueError("start_page must be >= 1")
        if self.end_page < self.start_page:
            raise ValueError("end_page must be >= start_page")
        expected_count = self.end_page - self.start_page + 1
        if self.page_count != expected_count:
            raise ValueError(f"page_count mismatch: expected {expected_count}, got {self.page_count}")
        if not self.filename:
            raise ValueError("filename cannot be empty")

    def to_dict(self) -> dict[str, Any]:
        return {
            "upload_ordinal": self.upload_ordinal,
            "source_ordinal": self.source_ordinal,
            "part_ordinal": self.part_ordinal,
            "start_page": self.start_page,
            "end_page": self.end_page,
            "page_count": self.page_count,
            "filename": self.filename,
            "artifact_ref": self.artifact_ref.to_dict(),
        }

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> PreparedPDF:
        return cls(
            upload_ordinal=int(data["upload_ordinal"]),
            source_ordinal=int(data["source_ordinal"]),
            part_ordinal=int(data["part_ordinal"]),
            start_page=int(data["start_page"]),
            end_page=int(data["end_page"]),
            page_count=int(data["page_count"]),
            filename=str(data["filename"]),
            artifact_ref=ArtifactRef.from_dict(data["artifact_ref"]),
        )


@dataclass(frozen=True)
class PreparationInventory:
    schema_version: int
    kind: str
    state: str
    policy: PreparationPolicy
    originals: list[OriginalSource]
    prepared_pdfs: list[PreparedPDF]
    qpdf_version: str
    verification: dict[str, Any]
    inventory_sha256: str

    def __post_init__(self) -> None:
        if self.schema_version != 1:
            raise ValueError(f"schema_version must be 1, got {self.schema_version}")
        if self.kind != "v3_pdf_preparation":
            raise ValueError(f"kind must be v3_pdf_preparation, got {self.kind}")
        if self.state != "complete":
            raise ValueError(f"state must be complete, got {self.state}")
        validate_sha256(self.inventory_sha256, "PreparationInventory.inventory_sha256")
        if not self.originals:
            raise ValueError("originals cannot be empty")
        if not self.prepared_pdfs:
            raise ValueError("prepared_pdfs cannot be empty")

    def to_dict(self) -> dict[str, Any]:
        return {
            "schema_version": self.schema_version,
            "kind": self.kind,
            "state": self.state,
            "policy": self.policy.to_dict(),
            "originals": [o.to_dict() for o in self.originals],
            "prepared_pdfs": [p.to_dict() for p in self.prepared_pdfs],
            "qpdf_version": self.qpdf_version,
            "verification": self.verification,
            "inventory_sha256": self.inventory_sha256,
        }

    def verify_digest(self) -> bool:
        clean = {k: v for k, v in self.to_dict().items() if k != "inventory_sha256"}
        expected = hashlib.sha256(canonical_json_dumps(clean).encode("utf-8")).hexdigest()
        return expected == self.inventory_sha256

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> PreparationInventory:
        return cls(
            schema_version=int(data["schema_version"]),
            kind=str(data["kind"]),
            state=str(data["state"]),
            policy=PreparationPolicy.from_dict(data["policy"]),
            originals=[OriginalSource.from_dict(o) for o in data["originals"]],
            prepared_pdfs=[PreparedPDF.from_dict(p) for p in data["prepared_pdfs"]],
            qpdf_version=str(data["qpdf_version"]),
            verification=dict(data.get("verification", {})),
            inventory_sha256=str(data["inventory_sha256"]),
        )


def plan_ranges(page_count: int, policy: PreparationPolicy | None = None) -> list[tuple[int, int]]:
    """Plan page ranges for an original PDF using policy v1.

    Rules:
    - 1 <= page_count <= 100: remains intact -> [(1, page_count)]
    - page_count > 100: recursively halve both branches until every range has <= 50 pages.
    - For range [start, end] of length L = end - start + 1:
        if L <= 50: terminal leaf
        else:
            left_len = L // 2  # floor(L / 2), smaller first for odd L
            left_end = start + left_len - 1
            recurse on [start, left_end] then [left_end + 1, end]
    """
    if page_count < 1:
        raise ValueError(f"page_count must be positive, got {page_count}")
    policy = policy or PreparationPolicy()
    if page_count <= policy.split_trigger_pages:
        return [(1, page_count)]

    def _split(start: int, end: int) -> list[tuple[int, int]]:
        length = end - start + 1
        if length <= 50:
            return [(start, end)]
        left_len = length // 2
        left_end = start + left_len - 1
        return _split(start, left_end) + _split(left_end + 1, end)

    return _split(1, page_count)


def sanitize_stem(name: str) -> str:
    """Sanitize original stem for filename while preserving Unicode and bound length."""
    stem = Path(name).stem
    cleaned = re.sub(r'[^\w\.-]+', '-', stem, flags=re.UNICODE).strip('-.')
    if not cleaned:
        cleaned = "doc"
    return cleaned[:64]


def check_qpdf_available() -> str:
    """Verify qpdf CLI is available on PATH and return version string."""
    qpdf_bin = shutil.which("qpdf")
    if not qpdf_bin:
        raise PreparationError("qpdf CLI tool is not available on PATH", SafeCode.source_error)
    try:
        proc = subprocess.run([qpdf_bin, "--version"], capture_output=True, text=True, timeout=10)
        if proc.returncode != 0:
            raise PreparationError("Failed to query qpdf version", SafeCode.source_error)
        first_line = proc.stdout.strip().splitlines()[0] if proc.stdout else "qpdf"
        return first_line
    except subprocess.TimeoutExpired:
        raise PreparationError("qpdf --version query timed out", SafeCode.source_timeout)
    except Exception as exc:
        raise PreparationError(f"qpdf check failed: {exc}", SafeCode.source_error) from exc


def inspect_pdf_strict(pdf_path: Path) -> int:
    """Strict inspection using bounded argv subprocesses.

    Verifies:
    - unencrypted: qpdf --is-encrypted exits 2
    - clean structure: qpdf --check exits 0
    - positive page count: qpdf --show-npages returns integer >= 1
    Returns physical page count.
    """
    if not pdf_path.is_file():
        raise PreparationError(f"File not found: {pdf_path}", SafeCode.input_invalid)

    # 1. Encryption check
    try:
        enc_proc = subprocess.run(
            ["qpdf", "--is-encrypted", str(pdf_path)],
            capture_output=True,
            text=True,
            timeout=QPDF_INSPECTION_TIMEOUT_SECONDS,
        )
    except subprocess.TimeoutExpired:
        raise PreparationError(f"Inspection timeout on encryption check for {pdf_path.name}", SafeCode.source_timeout)
    except Exception as exc:
        raise PreparationError(f"Error checking encryption for {pdf_path.name}: {exc}", SafeCode.source_error)

    if enc_proc.returncode == 0:
        raise PreparationError(f"PDF is encrypted: {pdf_path.name}", SafeCode.input_invalid)
    if enc_proc.returncode != 2:
        raise PreparationError(f"Malformed or corrupt PDF during encryption check: {pdf_path.name}", SafeCode.input_invalid)

    # 2. Structural check
    try:
        chk_proc = subprocess.run(
            ["qpdf", "--check", str(pdf_path)],
            capture_output=True,
            text=True,
            timeout=QPDF_INSPECTION_TIMEOUT_SECONDS,
        )
    except subprocess.TimeoutExpired:
        raise PreparationError(f"Inspection timeout on structural check for {pdf_path.name}", SafeCode.source_timeout)
    except Exception as exc:
        raise PreparationError(f"Error checking structure for {pdf_path.name}: {exc}", SafeCode.source_error)

    if chk_proc.returncode == 3:
        raise PreparationError(f"PDF check returned warnings: {pdf_path.name}", SafeCode.input_invalid)
    if chk_proc.returncode != 0:
        raise PreparationError(f"PDF check failed with exit code {chk_proc.returncode}: {pdf_path.name}", SafeCode.input_invalid)

    # 3. Page count
    try:
        pages_proc = subprocess.run(
            ["qpdf", "--show-npages", str(pdf_path)],
            capture_output=True,
            text=True,
            timeout=QPDF_INSPECTION_TIMEOUT_SECONDS,
        )
    except subprocess.TimeoutExpired:
        raise PreparationError(f"Inspection timeout on page count check for {pdf_path.name}", SafeCode.source_timeout)
    except Exception as exc:
        raise PreparationError(f"Error reading page count for {pdf_path.name}: {exc}", SafeCode.source_error)

    if pages_proc.returncode != 0:
        raise PreparationError(f"Failed to read page count for: {pdf_path.name}", SafeCode.input_invalid)

    try:
        page_count = int(pages_proc.stdout.strip())
    except (ValueError, TypeError):
        raise PreparationError(f"Invalid page count output for: {pdf_path.name}", SafeCode.input_invalid)

    if page_count < 1:
        raise PreparationError(f"PDF has non-positive page count ({page_count}): {pdf_path.name}", SafeCode.input_invalid)

    return page_count


def extract_range_direct(retained_pdf: Path, start: int, end: int, output_path: Path) -> None:
    """Extract page range [start, end] inclusive directly from retained original using qpdf."""
    cmd = ["qpdf", str(retained_pdf), "--pages", ".", f"{start}-{end}", "--", str(output_path)]
    try:
        proc = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=QPDF_EXTRACTION_TIMEOUT_SECONDS,
        )
    except subprocess.TimeoutExpired:
        raise PreparationError(f"Extraction timeout on range {start}-{end} for {retained_pdf.name}", SafeCode.source_timeout)
    except Exception as exc:
        raise PreparationError(f"Extraction failed for range {start}-{end}: {exc}", SafeCode.source_error)

    if proc.returncode != 0:
        raise PreparationError(f"qpdf extraction failed for range {start}-{end} on {retained_pdf.name}: {proc.stderr}", SafeCode.source_error)


def verify_preparation(package_or_inventory_path: Path) -> PreparationInventory:
    """Verify complete preparation package on disk.

    Validates:
    - inventory.json exists, parses, digest is valid.
    - Policy version 1 matches.
    - Originals exist in originals/, hashes match, qpdf check and counts match.
    - Final PDFs exist in final/, hashes match, qpdf check and counts match.
    - No extra or missing files in final/.
    - Per-original gap-free partition covering 1..N.
    - Split files have part count <= 50.
    - Intact files have byte-identical hashes to originals.
    - Ascending filename sorting matches upload_ordinal order.
    """
    if package_or_inventory_path.is_file():
        inventory_path = package_or_inventory_path.resolve()
        package_dir = inventory_path.parent
    else:
        package_dir = package_or_inventory_path.resolve()
        inventory_path = package_dir / "inventory.json"

    if not inventory_path.is_file():
        raise PreparationError(f"inventory.json not found: {inventory_path}", SafeCode.artifact_missing)

    try:
        raw = json.loads(inventory_path.read_text(encoding="utf-8"))
        inventory = PreparationInventory.from_dict(raw)
    except Exception as exc:
        raise PreparationError(f"Failed to parse inventory: {exc}", SafeCode.input_invalid) from exc

    if not inventory.verify_digest():
        raise PreparationError("inventory.json digest mismatch", SafeCode.input_changed)

    # Check policy
    if (inventory.policy.version not in (1, 2)
            or inventory.policy.split_trigger_pages != {1: 100, 2: 50}[inventory.policy.version]
            or inventory.policy.max_part_pages != 50
            or inventory.policy.algorithm != "recursive_halving"
            or inventory.policy.odd_rule != "smaller_first"):
        raise PreparationError("Preparation policy violates policy v1 invariants", SafeCode.input_invalid)

    originals_dir = package_dir / "originals"
    final_dir = package_dir / "final"
    if not originals_dir.is_dir():
        raise PreparationError(f"originals directory missing: {originals_dir}", SafeCode.artifact_missing)
    if not final_dir.is_dir():
        raise PreparationError(f"final directory missing: {final_dir}", SafeCode.artifact_missing)

    # Verify each original
    for orig in inventory.originals:
        if orig.policy_version != inventory.policy.version or orig.split_applied != (orig.page_count > inventory.policy.split_trigger_pages):
            raise PreparationError("Original split decision differs from captured policy", SafeCode.input_invalid)
        rel_path = Path(orig.retained_original_ref.path)
        if rel_path.is_absolute() or ".." in rel_path.parts or not str(rel_path).startswith("originals/"):
            raise PreparationError(f"Invalid retained original ref path: {rel_path}", SafeCode.input_invalid)
        retained_path = package_dir / rel_path
        if not retained_path.is_file():
            raise PreparationError(f"Retained original missing: {retained_path}", SafeCode.artifact_missing)
        file_sha, file_bytes = compute_file_sha256(retained_path)
        if file_sha != orig.sha256 or file_bytes != orig.bytes:
            raise PreparationError(f"Retained original hash/size mismatch: {orig.original_filename}", SafeCode.input_changed)
        actual_pages = inspect_pdf_strict(retained_path)
        if actual_pages != orig.page_count:
            raise PreparationError(f"Retained original page count mismatch for {orig.original_filename}: expected {orig.page_count}, got {actual_pages}", SafeCode.input_changed)

    # Verify each prepared PDF
    seen_upload_ordinals: set[int] = set()
    for prep in inventory.prepared_pdfs:
        rel_path = Path(prep.artifact_ref.path)
        if rel_path.is_absolute() or ".." in rel_path.parts or not str(rel_path).startswith("final/"):
            raise PreparationError(f"Invalid prepared PDF ref path: {rel_path}", SafeCode.input_invalid)
        final_path = package_dir / rel_path
        if not final_path.is_file():
            raise PreparationError(f"Prepared PDF file missing: {final_path}", SafeCode.artifact_missing)
        file_sha, file_bytes = compute_file_sha256(final_path)
        if file_sha != prep.artifact_ref.sha256 or file_bytes != prep.artifact_ref.bytes:
            raise PreparationError(f"Prepared PDF hash/size mismatch: {prep.filename}", SafeCode.input_changed)
        actual_pages = inspect_pdf_strict(final_path)
        if actual_pages != prep.page_count:
            raise PreparationError(f"Prepared PDF page count mismatch for {prep.filename}: expected {prep.page_count}, got {actual_pages}", SafeCode.input_changed)
        if prep.upload_ordinal in seen_upload_ordinals:
            raise PreparationError(f"Duplicate upload ordinal in prepared_pdfs: {prep.upload_ordinal}", SafeCode.input_invalid)
        seen_upload_ordinals.add(prep.upload_ordinal)

    # Check upload ordinals are contiguous 1..N
    expected_ordinals = set(range(1, len(inventory.prepared_pdfs) + 1))
    if seen_upload_ordinals != expected_ordinals:
        raise PreparationError(f"Upload ordinals are not contiguous 1..{len(inventory.prepared_pdfs)}", SafeCode.input_invalid)

    # Check no extra or missing files in final/
    disk_final_files = {p.name for p in final_dir.iterdir() if p.is_file()}
    inventory_final_files = {prep.filename for prep in inventory.prepared_pdfs}
    if disk_final_files != inventory_final_files:
        raise PreparationError(f"Final directory contents mismatch: extra={disk_final_files - inventory_final_files}, missing={inventory_final_files - disk_final_files}", SafeCode.input_invalid)

    # Check filename sorting agrees with upload_ordinal order
    sorted_by_filename = sorted(inventory.prepared_pdfs, key=lambda p: p.filename)
    if [p.upload_ordinal for p in sorted_by_filename] != list(range(1, len(inventory.prepared_pdfs) + 1)):
        raise PreparationError("Prepared PDF filenames do not sort in upload_ordinal reading order", SafeCode.input_invalid)

    # Partition validation per original
    by_source: dict[int, list[PreparedPDF]] = {}
    for prep in inventory.prepared_pdfs:
        by_source.setdefault(prep.source_ordinal, []).append(prep)

    for orig in inventory.originals:
        parts = by_source.get(orig.source_ordinal, [])
        if not parts:
            raise PreparationError(f"No prepared PDFs for original {orig.source_ordinal}", SafeCode.input_invalid)
        parts.sort(key=lambda p: p.part_ordinal)
        if [(p.start_page, p.end_page) for p in parts] != plan_ranges(orig.page_count, inventory.policy):
            raise PreparationError("Prepared ranges differ from captured halving policy", SafeCode.input_invalid)
        expected_part_ordinals = list(range(1, len(parts) + 1))
        if [p.part_ordinal for p in parts] != expected_part_ordinals:
            raise PreparationError(f"Part ordinals for original {orig.source_ordinal} are not contiguous 1..{len(parts)}", SafeCode.input_invalid)

        if not orig.split_applied:
            # Intact original
            if len(parts) != 1:
                raise PreparationError(f"Intact original {orig.source_ordinal} must have exactly 1 part, got {len(parts)}", SafeCode.input_invalid)
            part = parts[0]
            if part.start_page != 1 or part.end_page != orig.page_count:
                raise PreparationError(f"Intact original {orig.source_ordinal} range must be 1..{orig.page_count}", SafeCode.input_invalid)
            if part.artifact_ref.sha256 != orig.sha256 or part.artifact_ref.bytes != orig.bytes:
                raise PreparationError(f"Intact copy is not byte-identical for original {orig.original_filename}", SafeCode.input_changed)
        else:
            # Split original
            for part in parts:
                if part.page_count > 50:
                    raise PreparationError(f"Split part {part.filename} exceeds 50 pages: {part.page_count}", SafeCode.input_invalid)
            if parts[0].start_page != 1:
                raise PreparationError(f"First part for original {orig.source_ordinal} does not start at page 1", SafeCode.input_invalid)
            for i in range(1, len(parts)):
                if parts[i].start_page != parts[i - 1].end_page + 1:
                    raise PreparationError(f"Page range gap or overlap between part {i} and {i + 1} for original {orig.source_ordinal}", SafeCode.input_invalid)
            if parts[-1].end_page != orig.page_count:
                raise PreparationError(f"Last part for original {orig.source_ordinal} does not end at {orig.page_count}", SafeCode.input_invalid)
            if sum(p.page_count for p in parts) != orig.page_count:
                raise PreparationError(f"Total part pages do not match original {orig.source_ordinal} page count", SafeCode.input_invalid)

    return inventory


def prepare_pdf_sources(
    original_paths: Sequence[Path],
    preparation_dir: Path,
    policy: PreparationPolicy | None = None,
) -> PreparationInventory:
    """Prepare source PDFs locally: inspect, plan, extract direct leaves, verify, and publish package.

    Args:
        original_paths: Sequence of resolved paths in caller-defined order.
        preparation_dir: Target directory for the preparation package.

    Returns:
        Verified PreparationInventory instance.
    """
    policy = policy or PreparationPolicy()
    if not original_paths:
        raise PreparationError("original_paths sequence cannot be empty", SafeCode.input_invalid)

    pkg_dir = preparation_dir.resolve()

    # Reject if preparation_dir is inside any original source path or folder
    for p in original_paths:
        p_resolved = p.resolve()
        if pkg_dir == p_resolved or pkg_dir == p_resolved.parent or p_resolved.is_relative_to(pkg_dir) or pkg_dir.is_relative_to(p_resolved):
            raise PreparationError(f"Preparation output directory cannot be inside or overlap source files: {p}", SafeCode.input_invalid)

    qpdf_ver = check_qpdf_available()

    # Check for duplicate paths or duplicate content hashes across originals
    seen_paths: set[str] = set()
    seen_hashes: set[str] = set()
    initial_snapshots: list[tuple[Path, str, int]] = []
    for p in original_paths:
        p_res = p.resolve()
        if not p_res.is_file():
            raise PreparationError(f"Source PDF file does not exist: {p}", SafeCode.input_invalid)
        canon = str(p_res)
        if canon in seen_paths:
            raise PreparationError(f"Duplicate PDF path in original sources: {p.name}", SafeCode.input_invalid)
        seen_paths.add(canon)
        h, sz = compute_file_sha256(p_res)
        if h in seen_hashes:
            raise PreparationError(f"Duplicate PDF content hash in original sources: {p.name}", SafeCode.input_invalid)
        seen_hashes.add(h)
        initial_snapshots.append((p_res, h, sz))

    # Package lock
    pkg_dir.parent.mkdir(parents=True, exist_ok=True)
    lock_file = pkg_dir.parent / f".{pkg_dir.name}.lock"
    lock_fd = os.open(str(lock_file), os.O_CREAT | os.O_RDWR, 0o644)
    try:
        fcntl.flock(lock_fd, fcntl.LOCK_EX)

        # Check existing package for reuse
        if pkg_dir.is_dir() and (pkg_dir / "inventory.json").is_file():
            try:
                existing = verify_preparation(pkg_dir)
                if existing.policy != policy:
                    raise PreparationError("Existing preparation policy differs", SafeCode.input_changed)
                # Verify that existing originals match requested originals in order, hashes, and size
                if len(existing.originals) == len(initial_snapshots):
                    matches = True
                    for orig_rec, (src_p, src_h, src_sz) in zip(existing.originals, initial_snapshots):
                        if orig_rec.sha256 != src_h or orig_rec.bytes != src_sz or orig_rec.original_filename != src_p.name:
                            matches = False
                            break
                    if matches:
                        logger.info(f"Reusing verified existing preparation package at {pkg_dir}")
                        return existing
                raise PreparationError(f"Output directory exists with conflicting contents: {pkg_dir}", SafeCode.input_changed)
            except PreparationError as pe:
                if pe.safe_code == SafeCode.input_changed:
                    raise
                raise PreparationError(f"Output directory exists and failed verification: {pkg_dir}", SafeCode.input_invalid)

        # Staging directory on the same filesystem
        staging_dir = pkg_dir.parent / f".staging-{pkg_dir.name}-{os.getpid()}-{uuid.uuid4().hex[:8]}"
        staging_originals_dir = staging_dir / "originals"
        staging_final_dir = staging_dir / "final"
        staging_originals_dir.mkdir(parents=True, exist_ok=True)
        staging_final_dir.mkdir(parents=True, exist_ok=True)

        try:
            total_originals = len(initial_snapshots)
            src_digits = max(4, len(str(total_originals)))

            originals_records: list[OriginalSource] = []
            planned_parts_info: list[tuple[int, Path, str, list[tuple[int, int]]]] = []

            # 1. Retain originals, inspect strictly, and plan ranges
            for src_ord, (src_p, src_h, src_sz) in enumerate(initial_snapshots, start=1):
                # Copy original to staging/originals/
                retained_name = f"{src_ord:0{src_digits}d}--{src_p.name}"
                retained_path = staging_originals_dir / retained_name
                shutil.copy2(src_p, retained_path)

                ret_h, ret_sz = compute_file_sha256(retained_path)
                if ret_h != src_h or ret_sz != src_sz:
                    raise PreparationError(f"Snapshot copy corruption for {src_p.name}", SafeCode.storage_unavailable)

                # Re-verify source did not change during copy
                cur_h, cur_sz = compute_file_sha256(src_p)
                if cur_h != src_h or cur_sz != src_sz:
                    raise PreparationError(f"Source original changed during capture: {src_p.name}", SafeCode.input_changed)

                page_count = inspect_pdf_strict(retained_path)
                split_applied = (page_count > policy.split_trigger_pages)
                ranges = plan_ranges(page_count, policy)

                retained_ref = ArtifactRef(
                    path=f"originals/{retained_name}",
                    sha256=ret_h,
                    bytes=ret_sz,
                )
                orig_record = OriginalSource(
                    source_ordinal=src_ord,
                    original_path=str(src_p),
                    original_filename=src_p.name,
                    sha256=ret_h,
                    bytes=ret_sz,
                    page_count=page_count,
                    retained_original_ref=retained_ref,
                    split_applied=split_applied,
                    policy_version=policy.version,
                )
                originals_records.append(orig_record)
                planned_parts_info.append((src_ord, retained_path, src_p.name, ranges))

            # 2. Extract final leaves directly from retained originals
            prepared_records: list[PreparedPDF] = []
            upload_ord = 1

            for src_ord, retained_path, orig_name, ranges in planned_parts_info:
                orig_rec = originals_records[src_ord - 1]
                stem = sanitize_stem(orig_name)
                page_digits = max(4, len(str(orig_rec.page_count)))

                for part_ord, (start_p, end_p) in enumerate(ranges, start=1):
                    if not orig_rec.split_applied:
                        # Intact PDF: retains basename without range suffix
                        part_filename = f"{src_ord:0{src_digits}d}--{stem}.pdf"
                        final_part_path = staging_final_dir / part_filename
                        shutil.copy2(retained_path, final_part_path)
                    else:
                        # Split PDF: padded source and original physical ranges
                        part_filename = f"{src_ord:0{src_digits}d}--{stem}--pages-{start_p:0{page_digits}d}-{end_p:0{page_digits}d}.pdf"
                        final_part_path = staging_final_dir / part_filename
                        extract_range_direct(retained_path, start_p, end_p, final_part_path)

                    part_h, part_sz = compute_file_sha256(final_part_path)
                    part_pages = inspect_pdf_strict(final_part_path)
                    expected_pages = end_p - start_p + 1
                    if part_pages != expected_pages:
                        raise PreparationError(f"Extracted part page count mismatch for {part_filename}: expected {expected_pages}, got {part_pages}", SafeCode.source_error)

                    if not orig_rec.split_applied and (part_h != orig_rec.sha256 or part_sz != orig_rec.bytes):
                        raise PreparationError(f"Intact copy hash mismatch for {part_filename}", SafeCode.storage_unavailable)

                    part_ref = ArtifactRef(
                        path=f"final/{part_filename}",
                        sha256=part_h,
                        bytes=part_sz,
                    )
                    prep_pdf = PreparedPDF(
                        upload_ordinal=upload_ord,
                        source_ordinal=src_ord,
                        part_ordinal=part_ord,
                        start_page=start_p,
                        end_page=end_p,
                        page_count=part_pages,
                        filename=part_filename,
                        artifact_ref=part_ref,
                    )
                    prepared_records.append(prep_pdf)
                    upload_ord += 1

            # 3. Construct inventory and canonical digest
            verification_summary = {
                "verified": True,
                "original_count": len(originals_records),
                "prepared_pdf_count": len(prepared_records),
                "total_original_pages": sum(o.page_count for o in originals_records),
                "total_prepared_pages": sum(p.page_count for p in prepared_records),
            }
            inv_dict_pre = {
                "schema_version": 1,
                "kind": "v3_pdf_preparation",
                "state": "complete",
                "policy": policy.to_dict(),
                "originals": [o.to_dict() for o in originals_records],
                "prepared_pdfs": [p.to_dict() for p in prepared_records],
                "qpdf_version": qpdf_ver,
                "verification": verification_summary,
            }
            inv_sha = compute_digest(inv_dict_pre, exclude_keys=("inventory_sha256",))

            inventory = PreparationInventory(
                schema_version=1,
                kind="v3_pdf_preparation",
                state="complete",
                policy=policy,
                originals=originals_records,
                prepared_pdfs=prepared_records,
                qpdf_version=qpdf_ver,
                verification=verification_summary,
                inventory_sha256=inv_sha,
            )

            # Write inventory.json
            inv_json = canonical_json_dumps(inventory.to_dict())
            (staging_dir / "inventory.json").write_text(inv_json, encoding="utf-8")

            # 4. Verify staging package before atomic rename
            verify_preparation(staging_dir)

            # 5. Atomic publication to final pkg_dir
            if pkg_dir.exists():
                raise PreparationError(f"Destination package directory already exists: {pkg_dir}", SafeCode.input_changed)
            staging_dir.rename(pkg_dir)

            return inventory

        finally:
            if staging_dir.exists():
                shutil.rmtree(staging_dir, ignore_errors=True)

    finally:
        try:
            fcntl.flock(lock_fd, fcntl.LOCK_UN)
            os.close(lock_fd)
        except Exception:
            pass
