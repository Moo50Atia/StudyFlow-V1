from dataclasses import dataclass
from typing import List, Optional, Dict, Any


OUTPUT_CONTRACT = "Return one JSON object only with schema_version 1 and a files array of path/content strings. Include a nonblank index.html. Other files may be local .js, .css, .svg or .json text. Use only relative local paths. Do not include remote URLs, build commands, Markdown fences or tool calls."


@dataclass
class ScenePackageFile:
    path: str
    content: str
    bytes: int
    sha256: str


@dataclass
class ScenePackageV1:
    schema_version: int
    files: List[ScenePackageFile]
    total_bytes: int
