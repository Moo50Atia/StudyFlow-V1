"""Tests for ReactSceneSourceV1 parser and validation rules (Spec 017)."""

from __future__ import annotations

import json
import pytest

from v3_engine.scene_generation.source_package import (
    HtmlSceneSourceV1,
    ReactSceneSourceV1,
    SourcePackageError,
    parse_html_scene_source,
    parse_react_scene_source,
)


def test_valid_react_scene_source_with_markdown_fences() -> None:
    raw = """```json
{
  "schema_version": 1,
  "dependencies": ["lucide-react", "motion"],
  "files": [
    {
      "path": "src/Scene.tsx",
      "content": "import React from 'react';\\nexport default function Scene() { return <div>Hello</div>; }"
    },
    {
      "path": "src/components/Particle.tsx",
      "content": "export const Particle = () => null;"
    },
    {
      "path": "public/data.json",
      "content": "{\\"count\\": 42}"
    }
  ]
}
```"""
    parsed = parse_react_scene_source(raw)
    assert parsed.schema_version == 1
    assert parsed.dependencies == ["lucide-react", "motion"]
    assert len(parsed.files) == 3
    assert parsed.files[0].path == "src/Scene.tsx"
    assert parsed.files[0].sha256 is not None
    assert parsed.files[0].bytes > 0


def test_missing_scene_tsx_rejected() -> None:
    doc = {
        "schema_version": 1,
        "dependencies": [],
        "files": [{"path": "src/Other.tsx", "content": "export default () => null;"}],
    }
    with pytest.raises(SourcePackageError) as exc:
        parse_react_scene_source(json.dumps(doc))
    assert exc.value.code == "package_invalid"
    assert "src/Scene.tsx" in str(exc.value)


@pytest.mark.parametrize(
    "bad_path",
    [
        "index.html",
        "package.json",
        "src/main.tsx",
        "src/vite-env.d.ts",
        "src/../escaped.tsx",
        "src/sub/../../escaped.tsx",
        "src\\Scene.tsx",
        "src/.hidden.tsx",
        "src/dist/loader.ts",
        "src/node_modules/loader.ts",
        "src/script.py",
        "public/script.js",
        "public/Scene.tsx",
        "src/a/b/c/d/e/f/too_deep.tsx",
    ],
)
def test_disallowed_file_paths(bad_path: str) -> None:
    doc = {
        "schema_version": 1,
        "dependencies": [],
        "files": [
            {"path": "src/Scene.tsx", "content": "export default () => null;"},
            {"path": bad_path, "content": "content"},
        ],
    }
    with pytest.raises(SourcePackageError) as exc:
        parse_react_scene_source(json.dumps(doc))
    assert exc.value.code == "package_invalid"


def test_disallowed_dependency_rejected() -> None:
    doc = {
        "schema_version": 1,
        "dependencies": ["axios"],  # not in allowed catalog!
        "files": [{"path": "src/Scene.tsx", "content": "export default () => null;"}],
    }
    with pytest.raises(SourcePackageError) as exc:
        parse_react_scene_source(json.dumps(doc))
    assert exc.value.code == "package_invalid"
    assert "axios" in str(exc.value)


def test_duplicate_dependencies_rejected() -> None:
    doc = {
        "schema_version": 1,
        "dependencies": ["three", "three"],
        "files": [{"path": "src/Scene.tsx", "content": "export default () => null;"}],
    }
    with pytest.raises(SourcePackageError) as exc:
        parse_react_scene_source(json.dumps(doc))
    assert exc.value.code == "package_invalid"


def test_file_size_limit_exceeded() -> None:
    large_content = "x" * (1024 * 1024 + 1)  # > 1 MiB
    doc = {
        "schema_version": 1,
        "dependencies": [],
        "files": [{"path": "src/Scene.tsx", "content": large_content}],
    }
    with pytest.raises(SourcePackageError) as exc:
        parse_react_scene_source(json.dumps(doc))
    assert exc.value.code == "package_invalid"
    assert "1048576" in str(exc.value) or "exceeds" in str(exc.value)


def test_html_scene_contract_accepts_exact_browser_files() -> None:
    package = {
        "schema_version": 1,
        "files": [
            {"path": "index.html", "content": '<link rel="stylesheet" href="./styles.css"><script src="./script.js"></script>'},
            {"path": "styles.css", "content": "body{color:#111}"},
            {"path": "script.js", "content": "document.body.dataset.ready='true'"},
        ],
    }
    parsed = parse_html_scene_source(json.dumps(package))
    assert isinstance(parsed, HtmlSceneSourceV1)
    assert [item.path for item in parsed.files] == ["index.html", "script.js", "styles.css"]
    assert parsed.dependencies == []


@pytest.mark.parametrize("package", [
    {"schema_version": 1, "dependencies": [], "files": []},
    {"schema_version": 1, "files": [{"path": "src/Scene.tsx", "content": "export default () => null"}]},
    {"schema_version": 1, "files": [
        {"path": "index.html", "content": "html"},
        {"path": "styles.css", "content": "css"},
        {"path": "script.js", "content": "js"},
        {"path": "package.json", "content": "{}"},
    ]},
])
def test_html_scene_contract_rejects_other_formats_and_extra_files(package: dict) -> None:
    with pytest.raises(SourcePackageError):
        parse_html_scene_source(json.dumps(package))
