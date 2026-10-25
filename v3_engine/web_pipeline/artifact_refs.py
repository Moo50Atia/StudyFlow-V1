"""Opaque artifact reference management and boundary protection."""

from __future__ import annotations

import os
import re
import tempfile
import uuid
from pathlib import Path

UUID_PATTERN = re.compile(
    r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$",
    re.IGNORECASE,
)

MAX_METADATA_BYTES = 32768  # 32 KiB
MAX_PREPARED_MANIFEST_BYTES = 1048576
MAX_STATIC_PACKAGE_BYTES = 4194304  # 4 MiB
MAX_RAW_TURN_BYTES = 8388608  # 8 MiB
MAX_ACCEPTED_PDF_BYTES = 104857600  # 100 MiB


class ArtifactError(Exception):
    """Base artifact operation error."""


class ArtifactSecurityError(ArtifactError):
    """Artifact path security or symlink violation."""


class ArtifactSizeError(ArtifactError):
    """Artifact payload size violation."""


def is_valid_uuid_ref(ref: str) -> bool:
    """Validate that ref is strictly an opaque UUID string."""
    if not isinstance(ref, str):
        return False
    if "/" in ref or "\\" in ref or ".." in ref:
        return False
    return bool(UUID_PATTERN.match(ref))


def parse_uuid_ref(ref: str) -> uuid.UUID:
    """Parse ref into UUID or raise ValueError."""
    if not is_valid_uuid_ref(ref):
        raise ValueError(f"Invalid opaque artifact ref: {ref!r}")
    return uuid.UUID(ref)


def resolve_artifact_path(
    root: Path,
    artifact_ref: str,
    category: str = "artifacts",
    ext: str = ".bin",
) -> Path:
    """
    Resolve and verify that the target path is strictly contained within root,
    with no symlink traversal.
    """
    parse_uuid_ref(artifact_ref)

    if "/" in category or "\\" in category or ".." in category:
        raise ArtifactSecurityError(f"Invalid artifact category: {category!r}")

    root_resolved = root.resolve()
    target_dir = root_resolved / category
    target_file = target_dir / f"{artifact_ref.lower()}{ext}"

    # Verify containment
    try:
        target_file.resolve().relative_to(root_resolved)
    except ValueError:
        raise ArtifactSecurityError(f"Path traversal detected: {target_file}")

    # Check for symlink components along the path
    curr = target_file
    while curr != root_resolved and curr != curr.parent:
        if curr.is_symlink():
            raise ArtifactSecurityError(f"Symlink rejected: {curr}")
        curr = curr.parent

    return target_file


def store_artifact(
    root: Path,
    artifact_ref: str,
    data: bytes,
    max_bytes: int = MAX_METADATA_BYTES,
    category: str = "artifacts",
    ext: str = ".bin",
) -> Path:
    """
    Atomically store raw bytes under the captured root via tempfile + flush + rename.
    Enforces payload size ceiling and symlink checks.
    """
    if len(data) > max_bytes:
        raise ArtifactSizeError(
            f"Artifact size {len(data)} exceeds maximum allowed limit {max_bytes} bytes"
        )

    target_path = resolve_artifact_path(root, artifact_ref, category=category, ext=ext)
    target_path.parent.mkdir(parents=True, exist_ok=True)

    if target_path.exists() and target_path.is_symlink():
        raise ArtifactSecurityError(f"Cannot overwrite symlink: {target_path}")

    # Atomic write
    temp_dir = target_path.parent
    with tempfile.NamedTemporaryFile(dir=temp_dir, delete=False) as tmp:
        tmp.write(data)
        tmp.flush()
        os.fsync(tmp.fileno())
        temp_name = tmp.name

    os.replace(temp_name, target_path)
    return target_path


def load_artifact(
    root: Path,
    artifact_ref: str,
    max_bytes: int = MAX_METADATA_BYTES,
    category: str = "artifacts",
    ext: str = ".bin",
) -> bytes:
    """
    Safely read artifact bytes enforcing maximum byte count and symlink rejection.
    """
    target_path = resolve_artifact_path(root, artifact_ref, category=category, ext=ext)

    if not target_path.exists():
        raise FileNotFoundError(f"Artifact {artifact_ref} not found at {target_path}")

    if target_path.is_symlink():
        raise ArtifactSecurityError(f"Refusing to read symlink: {target_path}")

    size = target_path.stat().st_size
    if size > max_bytes:
        raise ArtifactSizeError(
            f"Artifact file size {size} exceeds limit {max_bytes} bytes"
        )

    return target_path.read_bytes()


def resolve_manifest_path(root: Path, manifest_ref: str) -> Path:
    """Resolve and verify path to private manifest file."""
    return resolve_artifact_path(root, manifest_ref, category="manifests", ext=".json")


def store_manifest(
    root: Path,
    manifest_ref: str,
    manifest_data: dict,
) -> Path:
    """Store manifest JSON atomically enforcing metadata size ceiling."""
    import json
    data = json.dumps(manifest_data, separators=(",", ":")).encode("utf-8")
    return store_artifact(
        root=root,
        artifact_ref=manifest_ref,
        data=data,
        max_bytes=MAX_PREPARED_MANIFEST_BYTES if manifest_data.get("protocol") == "notebooklm_per_pdf_web_v3" else MAX_METADATA_BYTES,
        category="manifests",
        ext=".json",
    )


def load_manifest(
    root: Path,
    manifest_ref: str,
) -> dict:
    """Safely load and parse manifest JSON from private root."""
    import json
    raw = load_artifact(
        root=root,
        artifact_ref=manifest_ref,
        max_bytes=MAX_PREPARED_MANIFEST_BYTES,
        category="manifests",
        ext=".json",
    )
    try:
        data = json.loads(raw.decode("utf-8"))
        if not isinstance(data, dict):
            raise ArtifactError("Manifest payload must be a JSON object")
        if data.get("protocol") != "notebooklm_per_pdf_web_v3" and len(raw) > MAX_METADATA_BYTES:
            raise ArtifactSizeError("Legacy manifest exceeds metadata bound")
        return data
    except Exception as e:
        raise ArtifactError(f"Corrupted manifest JSON: {e}") from e

