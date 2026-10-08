import json
import re
import hashlib
from typing import Dict, Any, List, Set
from v3_engine.authoring.contracts import ScenePackageV1, ScenePackageFile

ALLOWED_EXTENSIONS = {".html", ".js", ".css", ".svg", ".json"}
SEGMENT_REGEX = re.compile(r"^[A-Za-z0-9._-]+$")


class PackageValidationError(ValueError):
    """Raised when scene package fails validation."""
    pass


def parse_scene_package(raw_text: str) -> ScenePackageV1:
    raw_bytes = raw_text.encode("utf-8")
    if len(raw_bytes) > 2097152:
        raise ValueError("Response JSON exceeds maximum size of 2097152 bytes")

    try:
        data = json.loads(raw_text)
    except Exception as e:
        raise ValueError(f"Failed to parse response as JSON: {e}")

    if not isinstance(data, dict):
        raise ValueError("Package must be a JSON object")

    allowed_top_keys = {"schema_version", "files"}
    if set(data.keys()) != allowed_top_keys:
        raise ValueError(f"Package contains invalid top-level keys: {set(data.keys()) - allowed_top_keys}")

    if data.get("schema_version") != 1:
        raise ValueError("Package schema_version must be 1")

    files_list = data.get("files")
    if not isinstance(files_list, list):
        raise ValueError("Package 'files' must be a list")

    if len(files_list) < 1 or len(files_list) > 32:
        raise ValueError(f"Package files count must be between 1 and 32, got {len(files_list)}")

    has_index = False
    total_bytes = 0
    seen_paths_exact: Set[str] = set()
    seen_paths_folded: Set[str] = set()
    package_files: List[ScenePackageFile] = []

    for file_obj in files_list:
        if not isinstance(file_obj, dict):
            raise ValueError("Each file item must be an object")

        if set(file_obj.keys()) != {"path", "content"}:
            raise ValueError("Each file must contain exactly 'path' and 'content' keys")

        path = file_obj.get("path")
        content = file_obj.get("content")

        if not isinstance(path, str) or not isinstance(content, str):
            raise ValueError("File path and content must be strings")

        path_bytes = path.encode("utf-8")
        if len(path_bytes) < 1 or len(path_bytes) > 160:
            raise ValueError(f"File path length must be 1-160 bytes: {path}")

        # Check path characters: no backslash, no colon, no absolute path
        if "\\" in path or ":" in path or path.startswith("/"):
            raise ValueError(f"Invalid path characters in '{path}'")

        segments = path.split("/")
        if len(segments) < 1 or len(segments) > 4:
            raise ValueError(f"Path must have 1-4 segments: '{path}'")

        for segment in segments:
            if not segment or segment in {".", ".."} or segment.startswith("."):
                raise ValueError(f"Invalid segment '{segment}' in path '{path}'")
            if not SEGMENT_REGEX.match(segment):
                raise ValueError(f"Invalid segment characters in '{segment}' in path '{path}'")

        # Check extension
        dot_idx = path.rfind(".")
        if dot_idx == -1:
            raise ValueError(f"File path missing extension: '{path}'")
        ext = path[dot_idx:].lower()
        if ext not in ALLOWED_EXTENSIONS:
            raise ValueError(f"Disallowed file extension '{ext}' in path '{path}'")

        # Check duplicate paths
        if path in seen_paths_exact:
            raise ValueError(f"Duplicate path: '{path}'")
        folded = path.lower()
        if folded in seen_paths_folded:
            raise ValueError(f"Duplicate path (case-folded): '{path}'")

        seen_paths_exact.add(path)
        seen_paths_folded.add(folded)

        content_bytes = content.encode("utf-8")
        content_len = len(content_bytes)
        if content_len < 1 or content_len > 262144:
            raise ValueError(f"File content size must be 1-262144 bytes, got {content_len} for '{path}'")

        total_bytes += content_len

        # Check index.html requirements
        if path == "index.html":
            has_index = True
            stripped = content.lstrip()
            doctype_valid = (
                stripped.lower().startswith("<!doctype") or
                stripped.lower().startswith("<html")
            )
            if not doctype_valid:
                raise ValueError("index.html must begin with an HTML doctype or <html")
        else:
            if path.endswith(".html"):
                raise ValueError(f"Only index.html may have .html extension, got '{path}'")

        file_sha256 = hashlib.sha256(content_bytes).hexdigest()
        package_files.append(ScenePackageFile(
            path=path,
            content=content,
            bytes=content_len,
            sha256=file_sha256,
        ))

    if not has_index:
        raise ValueError("Package must contain 'index.html'")

    if total_bytes > 1048576:
        raise ValueError(f"Total package content size {total_bytes} exceeds limit of 1048576 bytes")

    return ScenePackageV1(
        schema_version=1,
        files=package_files,
        total_bytes=total_bytes,
    )
