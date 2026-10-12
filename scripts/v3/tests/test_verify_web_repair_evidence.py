"""Reject an unsupported green status even when every label says passed."""

import importlib.util
import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[3]
MODULE = ROOT / "scripts/v3/verify_web_repair_evidence.py"
spec = importlib.util.spec_from_file_location("spec019_evidence_validator", MODULE)
validator = importlib.util.module_from_spec(spec)
spec.loader.exec_module(validator)


def test_rejects_forged_complete_claim_without_artifacts(tmp_path):
    source = ROOT / "specs/019-v3-web-integration/repair-evidence/manifest.json"
    data = json.loads(source.read_text())
    data["source_snapshot_sha256"] = "0" * 64
    data["gates"]["G06"]["status"] = "passed"
    data["executions"] = [{
        "id": "fake-run", "command": "never executed", "started_at": "2026-10-12T00:00:00Z",
        "ended_at": "2026-10-12T00:00:01Z", "exit_code": 0,
        "passed_count": 1, "failed_count": 0, "skipped_count": 0,
        "deselected_count": 0, "doubles": [], "trace_ids": {},
        "observations": {}, "artifacts": [],
    }]
    path = tmp_path / "forged.json"
    path.write_text(json.dumps(data))
    valid, errors = validator.validate_manifest(path, "complete")
    assert valid is False
    assert any("source snapshot" in error for error in errors)
    assert any("no runtime evidence artifacts" in error for error in errors)
    assert any("no execution references" in error for error in errors)
    assert any("exact test IDs" in error for error in errors)


def test_rejects_narrative_markdown_as_runtime_artifact(tmp_path):
    source = ROOT / "specs/019-v3-web-integration/repair-evidence/manifest.json"
    data = json.loads(source.read_text())
    data["source_snapshot_sha256"] = "0" * 64
    data["executions"] = [{
        "id": "reported-run", "command": "pytest test_example.py", "started_at": "2026-10-12T00:00:00Z",
        "ended_at": "2026-10-12T00:00:01Z", "exit_code": 0,
        "passed_count": 1, "failed_count": 0, "skipped_count": 0,
        "deselected_count": 0, "doubles": [], "trace_ids": {},
        "observations": {"assertion": True}, "test_ids": ["test_example.py::test_case"],
        "artifacts": [],
    }]
    artifact = tmp_path / "runtime.md"
    artifact.write_text("A narrative claiming that a run passed.")
    data["executions"][0]["artifacts"] = [{
        "path": str(artifact), "sha256": validator.file_sha256(artifact), "kind": "test_log",
    }]
    path = tmp_path / "manifest.json"
    path.write_text(json.dumps(data))
    valid, errors = validator.validate_manifest(path, "schema", check_source_digest=False)
    assert valid is False
    assert any("narrative Markdown" in error for error in errors)
