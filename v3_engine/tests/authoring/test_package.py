import pytest
import json
from v3_engine.authoring.package import parse_scene_package
from v3_engine.authoring.contracts import OUTPUT_CONTRACT


def test_valid_scene_package():
    raw = json.dumps({
        "schema_version": 1,
        "files": [
            {
                "path": "index.html",
                "content": "<!DOCTYPE html><html><head><title>Wave</title></head><body><canvas id='c'></canvas><script src='scene.js'></script></body></html>"
            },
            {
                "path": "scene.js",
                "content": "console.log('Wave simulation initialized');"
            },
            {
                "path": "style.css",
                "content": "body { margin: 0; }"
            },
            {
                "path": "assets/data.json",
                "content": "{\"particles\": 100}"
            }
        ]
    })

    pkg = parse_scene_package(raw)
    assert pkg.schema_version == 1
    assert len(pkg.files) == 4
    assert pkg.files[0].path == "index.html"
    assert pkg.total_bytes > 0


def test_package_rejects_non_json():
    with pytest.raises(ValueError, match="JSON"):
        parse_scene_package("```json\n{\"schema_version\": 1}\n```")


def test_package_rejects_extra_top_level_keys():
    raw = json.dumps({
        "schema_version": 1,
        "files": [{"path": "index.html", "content": "<!DOCTYPE html><html></html>"}],
        "metadata": "forbidden"
    })
    with pytest.raises(ValueError, match="top-level keys"):
        parse_scene_package(raw)


def test_package_rejects_missing_index_html():
    raw = json.dumps({
        "schema_version": 1,
        "files": [{"path": "scene.js", "content": "console.log('no index');"}]
    })
    with pytest.raises(ValueError, match="index.html"):
        parse_scene_package(raw)


def test_package_rejects_invalid_index_html_doctype():
    raw = json.dumps({
        "schema_version": 1,
        "files": [{"path": "index.html", "content": "<div>not valid html document</div>"}]
    })
    with pytest.raises(ValueError, match="doctype"):
        parse_scene_package(raw)


def test_package_rejects_duplicate_paths_case_folded():
    raw = json.dumps({
        "schema_version": 1,
        "files": [
            {"path": "index.html", "content": "<!DOCTYPE html><html></html>"},
            {"path": "scene.js", "content": "let a = 1;"},
            {"path": "SCENE.JS", "content": "let b = 2;"}
        ]
    })
    with pytest.raises(ValueError, match="Duplicate"):
        parse_scene_package(raw)


def test_package_rejects_path_traversal():
    raw = json.dumps({
        "schema_version": 1,
        "files": [
            {"path": "index.html", "content": "<!DOCTYPE html><html></html>"},
            {"path": "../secret.js", "content": "let a = 1;"}
        ]
    })
    with pytest.raises(ValueError, match="Invalid"):
        parse_scene_package(raw)


def test_package_rejects_disallowed_extension():
    raw = json.dumps({
        "schema_version": 1,
        "files": [
            {"path": "index.html", "content": "<!DOCTYPE html><html></html>"},
            {"path": "script.py", "content": "print('hello')"}
        ]
    })
    with pytest.raises(ValueError, match="extension"):
        parse_scene_package(raw)


def test_package_rejects_oversize_file():
    large_content = "A" * (262144 + 1)
    raw = json.dumps({
        "schema_version": 1,
        "files": [
            {"path": "index.html", "content": "<!DOCTYPE html><html></html>"},
            {"path": "large.js", "content": large_content}
        ]
    })
    with pytest.raises(ValueError, match="size"):
        parse_scene_package(raw)


def test_package_rejects_more_than_32_files():
    files = [{"path": "index.html", "content": "<!DOCTYPE html><html></html>"}]
    for i in range(33):
        files.append({"path": f"f_{i}.js", "content": "let x = 1;"})
    raw = json.dumps({
        "schema_version": 1,
        "files": files
    })
    with pytest.raises(ValueError, match="files count"):
        parse_scene_package(raw)
