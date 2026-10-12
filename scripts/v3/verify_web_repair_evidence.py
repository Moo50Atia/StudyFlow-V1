#!/usr/bin/env python3
"""Machine-checkable evidence ledger validator for V3 Web Pipeline Repair (T120).

Validates specs/019-v3-web-integration/repair-evidence/manifest.json against
the acceptance contract defined in repair-acceptance-v2.md.
"""

from __future__ import annotations

import argparse
from datetime import datetime
import hashlib
import json
import sys
from pathlib import Path
from typing import Any, Dict, List, Set, Tuple

REQUIRED_GATES = [f"G0{i}" for i in range(1, 7)]
REQUIRED_VIEWS = [f"V{i:02d}" for i in range(1, 13)]
REQUIRED_ACCEPTANCE = [f"A{i:02d}" for i in range(1, 29)]
VALID_STATUSES = {"passed", "failed", "blocked", "not_run"}
VALID_LANES = {"component", "joined_offline", "retained_archive", "recovery_replay", "live"}
RUNTIME_ARTIFACT_KINDS = {
    "test_log", "runtime_trace", "browser_report", "database_snapshot",
    "provider_journal", "temporal_history", "archive_hashes",
}


def compute_source_snapshot_digest(repo_root: Path) -> str:
    """Hash the runtime and governing tests without hashing this mutable ledger."""
    roots = [
        "app/Services/V3", "app/Models/V3", "app/Http/Controllers/V3",
        "app/Console/Commands/V3", "v3_engine/web_pipeline",
        "v3_engine/terminal_pipeline", "v3_engine/authoring",
        "v3_engine/review", "v3_engine/tests/web_pipeline",
        "v3_engine/tests/review", "scripts/v3/tests",
        "tests/Feature/V3/WebPipeline", "tests/Integration/V3/WebPipeline",
        "tests/Support/V3", "resources/views/v3",
        "database/migrations",
    ]
    files = []
    for root in roots:
        path = repo_root / root
        if path.is_dir():
            files.extend(p for p in path.rglob("*") if p.is_file() and "__pycache__" not in p.parts and p.suffix not in {".pyc", ".pyo"})
    for name in ["routes/v3.php", "routes/api.php", "config/filesystems.php", "scripts/v3/verify_web_repair_evidence.py"]:
        path = repo_root / name
        if path.is_file():
            files.append(path)
    digest = hashlib.sha256()
    for path in sorted(set(files)):
        digest.update(path.relative_to(repo_root).as_posix().encode() + b"\0")
        digest.update(file_sha256(path).encode() + b"\n")
    return digest.hexdigest()


def file_sha256(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        while chunk := f.read(65536):
            h.update(chunk)
    return h.hexdigest()


def validate_manifest(
    manifest_path: Path,
    requirement_mode: str = "offline",
    check_source_digest: bool = True,
) -> Tuple[bool, List[str]]:
    errors: List[str] = []

    if not manifest_path.exists():
        return False, [f"Manifest file not found: {manifest_path}"]

    try:
        with open(manifest_path, "r", encoding="utf-8") as f:
            data = json.load(f)
    except Exception as e:
        return False, [f"Failed to parse manifest JSON: {e}"]

    # 1. Top-level metadata and current runtime/test source snapshot.
    for field in ["schema_version", "capture_timestamp", "git_revision", "source_snapshot_sha256", "environment"]:
        if field not in data:
            errors.append(f"Missing required top-level field: '{field}'")

    if data.get("schema_version") != 1:
        errors.append(f"Expected schema_version 1, got: {data.get('schema_version')}")
    repo_root = manifest_path.resolve().parents[3]
    if check_source_digest and data.get("source_snapshot_sha256") != compute_source_snapshot_digest(repo_root):
        errors.append("Runtime/test source snapshot differs from the evidence manifest")
    source_digest = data.get("source_snapshot_sha256")
    if not isinstance(source_digest, str) or len(source_digest) != 64 or any(c not in "0123456789abcdef" for c in source_digest):
        errors.append("source_snapshot_sha256 must be a lowercase SHA-256 digest")

    gates = data.get("gates", {})
    views = data.get("views", {})
    acceptance = data.get("acceptance", {})
    executions = data.get("executions", [])

    # 2. Check Gates G01-G06
    for gid in REQUIRED_GATES:
        if gid not in gates:
            errors.append(f"Missing gate entry: {gid}")
            continue
        g = gates[gid]
        status = g.get("status")
        if status not in VALID_STATUSES:
            errors.append(f"Gate {gid} has invalid status: '{status}'")
        lane = g.get("lane")
        if lane and lane not in VALID_LANES:
            errors.append(f"Gate {gid} has invalid lane: '{lane}'")

        if gid == "G06":
            # Live gate
            if requirement_mode == "offline":
                if status not in ("passed", "blocked"):
                    errors.append(f"G06 live gate status in offline mode must be 'blocked' or 'passed', got '{status}'")
                if status == "blocked" and not g.get("reason"):
                    errors.append("G06 is blocked but no explicit reason was documented")
            elif requirement_mode == "complete":
                if status != "passed":
                    errors.append(f"Complete acceptance requires G06 to be 'passed', got '{status}' (reason: {g.get('reason')})")
        else:
            if requirement_mode in ("offline", "complete") and status != "passed":
                errors.append(f"Gate {gid} must be 'passed', got '{status}' (reason: {g.get('reason')})")

    # 3. Check Views V01-V12
    for vid in REQUIRED_VIEWS:
        if vid not in views:
            errors.append(f"Missing website view entry: {vid}")
            continue
        v = views[vid]
        status = v.get("status")
        if status not in VALID_STATUSES:
            errors.append(f"View {vid} has invalid status: '{status}'")
        if requirement_mode in ("offline", "complete") and status != "passed":
            errors.append(f"View {vid} must be 'passed', got '{status}' (reason: {v.get('reason')})")

    # 4. Check Acceptance A01-A28
    for aid in REQUIRED_ACCEPTANCE:
        if aid not in acceptance:
            errors.append(f"Missing acceptance entry: {aid}")
            continue
        a = acceptance[aid]
        status = a.get("status")
        if status not in VALID_STATUSES:
            errors.append(f"Acceptance row {aid} has invalid status: '{status}'")
        if requirement_mode in ("offline", "complete") and status != "passed":
            errors.append(f"Acceptance row {aid} must be 'passed', got '{status}' (reason: {a.get('reason')})")

    # 5. Check Executions & Artifact Digests
    if not executions and requirement_mode in ("offline", "complete"):
        errors.append("Executions log is empty; no execution records found")

    executions_by_id = {}
    for idx, ex in enumerate(executions):
        for req_ex_field in ["id", "command", "started_at", "ended_at", "exit_code", "passed_count", "failed_count", "skipped_count", "deselected_count", "doubles", "trace_ids", "observations", "artifacts"]:
            if req_ex_field not in ex:
                errors.append(f"Execution #{idx} missing required field '{req_ex_field}'")
        execution_id = ex.get("id")
        if not isinstance(execution_id, str) or not execution_id or execution_id in executions_by_id:
            errors.append(f"Execution #{idx} has missing or duplicate id")
        else:
            executions_by_id[execution_id] = ex
        if ex.get("exit_code") != 0:
            errors.append(f"Execution #{idx} ({ex.get('command')}) failed with exit code {ex.get('exit_code')}")
        if ex.get("failed_count") != 0 or not isinstance(ex.get("passed_count"), int) or ex.get("passed_count", 0) < 1:
            errors.append(f"Execution #{idx} has no passing test or has failures")
        for count_field in ("passed_count", "failed_count", "skipped_count", "deselected_count"):
            count = ex.get(count_field)
            if not isinstance(count, int) or isinstance(count, bool) or count < 0:
                errors.append(f"Execution #{idx} has invalid {count_field}")
        try:
            started = datetime.fromisoformat(str(ex.get("started_at", "")).replace("Z", "+00:00"))
            ended = datetime.fromisoformat(str(ex.get("ended_at", "")).replace("Z", "+00:00"))
            if started.tzinfo is None or ended.tzinfo is None or ended < started:
                raise ValueError("timestamps must be timezone-aware and ordered")
        except (TypeError, ValueError):
            errors.append(f"Execution #{idx} has invalid or unordered timestamps")
        if not isinstance(ex.get("doubles"), list) or not isinstance(ex.get("trace_ids"), dict) or not isinstance(ex.get("observations"), dict):
            errors.append(f"Execution #{idx} lacks transport/trace/observation details")
        if not isinstance(ex.get("test_ids"), list) or not ex.get("test_ids") or any(not isinstance(t, str) or not t for t in ex.get("test_ids", [])):
            errors.append(f"Execution #{idx} lacks exact test IDs")
        if not isinstance(ex.get("artifacts"), list) or not ex["artifacts"]:
            errors.append(f"Execution #{idx} has no runtime evidence artifacts")

        for art in ex.get("artifacts", []):
            if art.get("kind") not in RUNTIME_ARTIFACT_KINDS:
                errors.append(f"Execution #{idx} artifact lacks a runtime evidence kind")
            art_path = Path(art.get("path", ""))
            art_sha = art.get("sha256")
            if not art_path.is_absolute():
                art_path = manifest_path.parent.parent.parent / art_path
            evidence_root = manifest_path.parent.resolve()
            try:
                art_path.resolve().relative_to(evidence_root)
            except ValueError:
                errors.append(f"Execution #{idx} artifact escapes the evidence directory: {art_path}")
            if art_path.suffix.lower() == ".md":
                errors.append(f"Execution #{idx} artifact is narrative Markdown, not runtime evidence: {art_path}")

            if not isinstance(art_sha, str) or len(art_sha) != 64:
                errors.append(f"Execution #{idx} artifact lacks a SHA-256 digest")
            if not art_path.is_file():
                errors.append(f"Execution #{idx} references non-existent artifact: {art_path}")
            elif art_sha:
                real_sha = file_sha256(art_path)
                if real_sha != art_sha:
                    errors.append(f"Artifact {art_path} digest mismatch: expected {art_sha}, got {real_sha}")

    # A narrative status does not cover a requirement. Each passed row needs
    # the exact successful execution(s) and measured assertions it cites.
    for kind, rows in [("gate", gates), ("view", views), ("acceptance", acceptance)]:
        for row_id, row in rows.items():
            if row.get("status") != "passed":
                continue
            refs = row.get("execution_ids")
            assertions = row.get("assertions")
            if not isinstance(refs, list) or not refs:
                errors.append(f"Passed {kind} {row_id} has no execution references")
                continue
            if not isinstance(assertions, list) or not assertions:
                errors.append(f"Passed {kind} {row_id} has no assertions")
            for ref in refs:
                ex = executions_by_id.get(ref)
                if ex is None:
                    errors.append(f"Passed {kind} {row_id} references absent execution {ref}")
                    continue
                if ex.get("exit_code") != 0 or ex.get("failed_count") != 0:
                    errors.append(f"Passed {kind} {row_id} references failed execution {ref}")
                if kind == "gate" and row_id != "G06" and (ex.get("skipped_count", 0) or ex.get("deselected_count", 0)):
                    errors.append(f"Passed gate {row_id} relies on skipped/deselected execution {ref}")
                observed = ex.get("observations", {})
                if isinstance(observed, dict) and isinstance(assertions, list):
                    for assertion in assertions:
                        if assertion not in observed:
                            errors.append(f"Passed {kind} {row_id} assertion {assertion} has no measured observation in execution {ref}")
                if requirement_mode == "offline" and kind != "gate" and row.get("lane") == "live":
                    errors.append(f"Passed {kind} {row_id} relies on live-only evidence in offline mode")

    return len(errors) == 0, errors


def main() -> int:
    parser = argparse.ArgumentParser(description="Validate Spec 019 repair evidence manifest")
    parser.add_argument(
        "--manifest",
        type=Path,
        default=Path("specs/019-v3-web-integration/repair-evidence/manifest.json"),
        help="Path to manifest.json",
    )
    parser.add_argument(
        "--require",
        choices=["offline", "complete", "schema"],
        default="offline",
        help="Acceptance requirement level to enforce",
    )
    parser.add_argument(
        "--no-digest-check",
        action="store_true",
        help="Skip source digest validation",
    )

    args = parser.parse_args()
    manifest_path = args.manifest.resolve()

    print(f"Validating evidence manifest: {manifest_path}")
    print(f"Requirement mode: --require {args.require}")

    valid, errors = validate_manifest(
        manifest_path=manifest_path,
        requirement_mode=args.require,
        check_source_digest=not args.no_digest_check,
    )

    if valid:
        print(f"SUCCESS: Evidence manifest satisfies all criteria for mode '{args.require}'.")
        return 0
    else:
        print(f"FAILED: Evidence manifest has {len(errors)} error(s):")
        for err in errors:
            print(f"  - {err}")
        return 1


if __name__ == "__main__":
    sys.exit(main())
