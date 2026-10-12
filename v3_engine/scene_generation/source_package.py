"""ReactSceneSourceV1 parser and validation rules (Spec 017)."""

from __future__ import annotations

from dataclasses import dataclass, field
import hashlib
import json
import re
from typing import Any

ALLOWED_OPTIONAL_DEPENDENCIES = frozenset(
    {
        "d3",
        "katex",
        "lucide-react",
        "three",
        "@react-three/fiber",
        "@react-three/drei",
        "@react-three/postprocessing",
        "react-konva",
        "zustand",
        "motion",
        "gsap",
    }
)

RESERVED_PATHS = frozenset(
    {
        "src/main.tsx",
        "src/vite-env.d.ts",
        "index.html",
        "tsconfig.json",
        "vite.config.mjs",
        "package.json",
        "package-lock.json",
        "validate-source.mjs",
    }
)

PATH_SEGMENT_PATTERN = re.compile(r"^[A-Za-z0-9._-]+$")
ALLOWED_SRC_EXTENSIONS = (".ts", ".tsx", ".css", ".svg", ".json")
ALLOWED_PUBLIC_EXTENSIONS = (".svg", ".json")

MAX_FILE_BYTES = 1024 * 1024  # 1 MiB
MAX_TOTAL_FILE_BYTES = 4 * 1024 * 1024  # 4 MiB
MAX_FILES_COUNT = 64


class SourcePackageError(Exception):
    """Raised when model source output violates ReactSceneSourceV1 contract."""

    def __init__(self, code: str, message: str) -> None:
        super().__init__(message)
        self.code = code


@dataclass(frozen=True)
class GeneratedFile:
    path: str
    content: str
    bytes: int
    sha256: str


SourceFile = GeneratedFile


@dataclass(frozen=True)
class ReactSceneSourceV1:
    schema_version: int
    dependencies: list[str]
    files: list[GeneratedFile]


@dataclass(frozen=True)
class HtmlSceneSourceV1:
    """Plain browser scene files, with no framework or package build step."""

    schema_version: int
    files: list[GeneratedFile]
    dependencies: list[str] = field(default_factory=list)


def parse_html_scene_source(raw_text: str) -> HtmlSceneSourceV1:
    """Parse an HTML/CSS/JavaScript bundle without imposing educational gates."""
    cleaned = _strip_markdown_fence(raw_text)
    try:
        data = json.loads(cleaned)
    except (TypeError, json.JSONDecodeError) as exc:
        raise SourcePackageError("package_invalid", "Response is not a JSON scene bundle.") from exc
    if not isinstance(data, dict) or set(data) != {"schema_version", "files"}:
        raise SourcePackageError("package_invalid", "Scene bundle must contain schema_version and files only.")
    if data.get("schema_version") != 1:
        raise SourcePackageError("package_invalid", "Unsupported HTML scene bundle version.")
    raw_files = data.get("files")
    expected = {"index.html", "styles.css", "script.js"}
    if not isinstance(raw_files, list) or len(raw_files) != len(expected):
        raise SourcePackageError("package_invalid", "Scene bundle must contain index.html, styles.css, and script.js.")
    files: list[GeneratedFile] = []
    seen: set[str] = set()
    total = 0
    for row in raw_files:
        if not isinstance(row, dict) or set(row) != {"path", "content"}:
            raise SourcePackageError("package_invalid", "Each scene file must contain path and content only.")
        path, content = row.get("path"), row.get("content")
        if not isinstance(path, str) or path not in expected or path in seen:
            raise SourcePackageError("package_invalid", "Scene file paths must be unique index.html, styles.css, or script.js.")
        if not isinstance(content, str) or not content.strip():
            raise SourcePackageError("package_invalid", f"Scene file {path} must contain text.")
        encoded = content.encode("utf-8")
        if len(encoded) > MAX_FILE_BYTES:
            raise SourcePackageError("package_invalid", f"Scene file {path} exceeds the per-file size limit.")
        total += len(encoded)
        if total > MAX_TOTAL_FILE_BYTES:
            raise SourcePackageError("package_invalid", "Scene bundle exceeds the total size limit.")
        seen.add(path)
        files.append(GeneratedFile(path, content, len(encoded), hashlib.sha256(encoded).hexdigest()))
    if seen != expected:
        raise SourcePackageError("package_invalid", "Scene bundle is missing a required HTML/CSS/JavaScript file.")
    files.sort(key=lambda item: item.path)
    return HtmlSceneSourceV1(schema_version=1, files=files)


def _strip_markdown_fence(raw_text: str) -> str:
    cleaned = raw_text.strip()
    if cleaned.startswith("```"):
        lines = cleaned.splitlines()
        first_line = lines[0].strip()
        last_line = lines[-1].strip()
        if first_line.startswith("```") and last_line == "```" and len(lines) >= 2:
            return "\n".join(lines[1:-1]).strip()
    return cleaned


def parse_react_scene_source(raw_text: str) -> ReactSceneSourceV1:
    """Parse and validate raw model output according to ReactSceneSourceV1 contract."""
    clean_json_str = _strip_markdown_fence(raw_text)
    try:
        data = json.loads(clean_json_str)
    except Exception as exc:
        raise SourcePackageError("package_invalid", f"Raw text is not valid JSON: {exc}") from exc

    if not isinstance(data, dict):
        raise SourcePackageError("package_invalid", "Root of ReactSceneSourceV1 must be a JSON object.")

    # Validate schema_version
    schema_version = data.get("schema_version")
    if schema_version != 1:
        raise SourcePackageError("package_invalid", f"Unsupported schema_version {schema_version!r}; expected 1")

    # Validate dependencies
    raw_deps = data.get("dependencies")
    if not isinstance(raw_deps, list):
        raise SourcePackageError("package_invalid", "Field 'dependencies' must be a list of strings.")

    dependencies: list[str] = []
    seen_deps: set[str] = set()
    for dep in raw_deps:
        if not isinstance(dep, str):
            raise SourcePackageError("package_invalid", f"Dependency name must be a string, got {dep!r}")
        if dep in seen_deps:
            raise SourcePackageError("package_invalid", f"Duplicate dependency declaration: '{dep}'")
        if dep not in ALLOWED_OPTIONAL_DEPENDENCIES:
            raise SourcePackageError(
                "package_invalid",
                f"Dependency '{dep}' is not in allowed catalog: {sorted(ALLOWED_OPTIONAL_DEPENDENCIES)}",
            )
        seen_deps.add(dep)
        dependencies.append(dep)

    # Validate files
    raw_files = data.get("files")
    if not isinstance(raw_files, list) or len(raw_files) < 1 or len(raw_files) > MAX_FILES_COUNT:
        raise SourcePackageError(
            "package_invalid",
            f"Field 'files' must be a list of 1 to {MAX_FILES_COUNT} file objects.",
        )

    files: list[GeneratedFile] = []
    seen_paths_lower: set[str] = set()
    has_scene_tsx = False
    total_content_bytes = 0

    for file_obj in raw_files:
        if not isinstance(file_obj, dict):
            raise SourcePackageError("package_invalid", "File entry must be an object.")
        if set(file_obj.keys()) != {"path", "content"}:
            raise SourcePackageError("package_invalid", "File entry must contain exactly 'path' and 'content' keys.")

        path = file_obj.get("path")
        content = file_obj.get("content")

        if not isinstance(path, str) or not isinstance(content, str):
            raise SourcePackageError("package_invalid", "File 'path' and 'content' must both be strings.")

        # Path length and encoding
        path_bytes = path.encode("ascii", errors="replace")
        if len(path_bytes) < 1 or len(path_bytes) > 160 or b"\\" in path_bytes:
            raise SourcePackageError("package_invalid", f"Invalid path length or backslash in path: '{path}'")

        if path in RESERVED_PATHS:
            raise SourcePackageError("package_invalid", f"Model generated reserved scaffold path: '{path}'")

        segments = path.split("/")
        if len(segments) > 6 or any(not seg for seg in segments):
            raise SourcePackageError("package_invalid", f"Invalid path segment structure: '{path}'")

        if any(seg.lower() in {"dist", "node_modules"} for seg in segments):
            raise SourcePackageError("package_invalid", "Generated source cannot use dist/ or node_modules/ directories.")

        for seg in segments:
            if seg in (".", "..") or seg.startswith(".") or not PATH_SEGMENT_PATTERN.fullmatch(seg):
                raise SourcePackageError("package_invalid", f"Invalid or hidden path segment '{seg}' in '{path}'")

        first_seg = segments[0]
        if first_seg not in ("src", "public"):
            raise SourcePackageError(
                "package_invalid", f"Generated files must be under 'src/' or 'public/': got '{path}'"
            )

        if first_seg == "src" and not any(path.endswith(ext) for ext in ALLOWED_SRC_EXTENSIONS):
            raise SourcePackageError(
                "package_invalid",
                f"File under 'src/' must have extension in {ALLOWED_SRC_EXTENSIONS}: got '{path}'",
            )

        if first_seg == "public" and not any(path.endswith(ext) for ext in ALLOWED_PUBLIC_EXTENSIONS):
            raise SourcePackageError(
                "package_invalid",
                f"File under 'public/' must have extension in {ALLOWED_PUBLIC_EXTENSIONS}: got '{path}'",
            )

        path_lower = path.lower()
        if path_lower in seen_paths_lower:
            raise SourcePackageError("package_invalid", f"Duplicate or case-colliding path: '{path}'")
        seen_paths_lower.add(path_lower)

        # Content size checks
        content_bytes = content.encode("utf-8")
        size = len(content_bytes)
        if size < 1 or size > MAX_FILE_BYTES:
            raise SourcePackageError(
                "package_invalid", f"File '{path}' size {size} bytes exceeds limit of {MAX_FILE_BYTES} bytes."
            )

        total_content_bytes += size
        if total_content_bytes > MAX_TOTAL_FILE_BYTES:
            raise SourcePackageError(
                "package_invalid",
                f"Total package content size exceeds {MAX_TOTAL_FILE_BYTES} bytes limit.",
            )

        if path == "src/Scene.tsx":
            if not content.strip():
                raise SourcePackageError("package_invalid", "src/Scene.tsx content cannot be empty or whitespace.")
            has_scene_tsx = True

        files.append(
            GeneratedFile(
                path=path,
                content=content,
                bytes=size,
                sha256=hashlib.sha256(content_bytes).hexdigest(),
            )
        )

    if not has_scene_tsx:
        raise SourcePackageError("package_invalid", "ReactSceneSourceV1 must include required 'src/Scene.tsx'.")

    return ReactSceneSourceV1(
        schema_version=1,
        dependencies=dependencies,
        files=files,
    )
