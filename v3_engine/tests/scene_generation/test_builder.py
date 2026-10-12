"""Unit and behavioral tests for SceneBuilder (Spec 017)."""

from __future__ import annotations

import json
from pathlib import Path
import pytest

from v3_engine.scene_generation.builder import (
    SceneBuilder,
    synthesize_package_json,
    sanitize_child_env,
    check_toolchain_drift,
    _atomic_json_write,
)
from v3_engine.scene_generation.contracts import SceneInput
from v3_engine.scene_generation.source_package import GeneratedFile, ReactSceneSourceV1


def test_sanitize_child_env() -> None:
    env_in = {
        "PATH": "/usr/bin:/bin",
        "HOME": "/home/user",
        "GEMINI_API_KEY": "super-secret-key",
        "V3_BRIDGE_HMAC_KEY": "bridge-secret",
        "NODE_OPTIONS": "--inspect",
        "USER": "user",
    }
    env_out = sanitize_child_env(env_in)
    assert env_out["PATH"] == "/usr/bin:/bin"
    assert env_out["HOME"] == "/home/user"
    assert env_out["USER"] == "user"
    assert env_out["CI"] == "true"
    # Secrets and untrusted options stripped
    assert "GEMINI_API_KEY" not in env_out
    assert "V3_BRIDGE_HMAC_KEY" not in env_out
    assert "NODE_OPTIONS" not in env_out


def test_synthesize_package_json() -> None:
    # Selected: motion and lucide-react
    pkg_json = synthesize_package_json(["lucide-react", "motion"])
    deps = pkg_json.get("dependencies", {})
    dev_deps = pkg_json.get("devDependencies", {})

    # Core runtime in dependencies
    assert deps.get("react") == "19.1.1"
    assert deps.get("react-dom") == "19.1.1"
    assert deps.get("lucide-react") == "0.546.0"
    assert deps.get("motion") == "12.23.12"

    # Core build tools in devDependencies
    assert dev_deps.get("vite") == "7.0.7"
    assert dev_deps.get("typescript") == "5.9.2"
    assert dev_deps.get("@types/react") == "19.1.10"
    assert dev_deps.get("@types/react-dom") == "19.1.9"

    # No model-generated scripts
    assert pkg_json.get("scripts") == {}


def test_synthesize_package_json_with_peer_closures() -> None:
    # Selected: @react-three/postprocessing
    # Requires fiber, three, postprocessing, types/three
    pkg_json = synthesize_package_json(["@react-three/postprocessing"])
    deps = pkg_json.get("dependencies", {})
    dev_deps = pkg_json.get("devDependencies", {})

    assert deps.get("@react-three/postprocessing") == "3.0.4"
    assert deps.get("@react-three/fiber") == "9.3.0"
    assert deps.get("three") == "0.179.1"
    assert deps.get("postprocessing") == "6.37.7"
    assert dev_deps.get("@types/three") == "0.179.0"

    # Selected: react-konva adds konva 9.3.18
    pkg_konva = synthesize_package_json(["react-konva"])
    k_deps = pkg_konva.get("dependencies", {})
    assert k_deps.get("react-konva") == "19.0.7"
    assert k_deps.get("konva") == "9.3.18"


def test_toolchain_drift_compares_npm_and_complete_scaffold_identity(monkeypatch: pytest.MonkeyPatch) -> None:
    import v3_engine.scene_generation.builder as builder_module
    current = {"sha256": "new", "node_version": "v22", "npm_version": "10", "catalog_sha256": "cat"}
    monkeypatch.setattr(builder_module, "get_host_toolchain_info", lambda scaffold_dir=None: current)
    assert check_toolchain_drift({"sha256": "old", "node_version": "v22", "npm_version": "10", "catalog_sha256": "cat"})
    assert check_toolchain_drift({"sha256": "new", "node_version": "v22", "npm_version": "9", "catalog_sha256": "cat"})
    assert check_toolchain_drift({"sha256": "new", "node_version": "v22", "npm_version": "10", "catalog_sha256": "cat"}) is None


def test_atomic_pointer_write_preserves_previous_json_on_publish_failure(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    import v3_engine.scene_generation.builder as builder_module
    target = tmp_path / "current.json"
    target.write_text('{"old":true}', encoding="utf-8")
    def fail_replace(source, destination):
        raise OSError("simulated publication failure")
    monkeypatch.setattr(builder_module.os, "replace", fail_replace)
    with pytest.raises(OSError):
        _atomic_json_write(target, {"new": True})
    assert target.read_text(encoding="utf-8") == '{"old":true}'
