"""Opt-in live website-to-NotebookLM-to-scene-generation acceptance gate.

The gate validates prerequisites before starting the child run. Once started,
any browser, Laravel, bridge, Temporal, NotebookLM, Qwen, qualification or review
failure is a test failure and is never converted into a skip.
"""

from __future__ import annotations

import hashlib
import os
from pathlib import Path
import subprocess
import sys

import pytest

from v3_engine.notebooklm.credentials import (
    CredentialsValidationError,
    get_storage_state_path,
    validate_storage_state,
)


SPEND_ACK = "I_AUTHORIZE_ONE_QWEN_SCENE_REQUEST"
JOINED_TEST = (
    "v3_engine/tests/web_pipeline/test_joined_runtime.py::"
    "test_browser_intake_and_sequential_service_execution"
)


def _live_pdf_problem(path_value: str | None, key: str) -> tuple[str | None, str | None]:
    if not path_value:
        return None, f"{key} is unset"
    path = Path(path_value).expanduser()
    if not path.is_file():
        return None, f"{key} does not point to a readable file"
    try:
        if path.stat().st_size <= 0 or path.stat().st_size > 100 * 1024 * 1024:
            return None, f"{key} must be a nonempty PDF no larger than 100 MiB"
        from pypdf import PdfReader

        reader = PdfReader(str(path), strict=True)
        if reader.is_encrypted or len(reader.pages) < 1:
            return None, f"{key} must be a readable, unencrypted PDF with at least one page"
    except Exception:
        return None, f"{key} is not a readable PDF"
    digest = hashlib.sha256()
    with path.open("rb") as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest(), None


@pytest.mark.live
def test_live_web_pipeline_gate(tmp_path: Path) -> None:
    """Run the full joined Chromium journey with real NotebookLM and one Qwen call."""
    enabled = (
        os.getenv("RUN_V3_LIVE_APPLICATION_GATE") == "1"
        or os.getenv("V3_LIVE_GATE_ENABLED") == "1"
    )
    if not enabled:
        pytest.skip(
            "Live web gate is opt-in: set RUN_V3_LIVE_APPLICATION_GATE=1 "
            "or V3_LIVE_GATE_ENABLED=1"
        )

    missing: list[str] = []
    proof_notebook_id = (
        os.getenv("V3_WEB_LIVE_PROOF_NOTEBOOK_ID")
        or os.getenv("V3_PROOF_NOTEBOOK_ID")
    )
    if not proof_notebook_id:
        missing.append("V3_WEB_LIVE_PROOF_NOTEBOOK_ID (approved account proof notebook)")

    try:
        validate_storage_state(get_storage_state_path())
    except CredentialsValidationError as exc:
        missing.append(f"valid NotebookLM storage state ({exc.code})")

    qwen_api_key = os.getenv("QWEN_API_KEY")
    qwen_model = os.getenv("QWEN_MODEL")
    if not qwen_api_key:
        missing.append("QWEN_API_KEY for the selected live scene provider")
    if not qwen_model:
        missing.append("QWEN_MODEL for the selected live scene provider")
    if os.getenv("V3_LIVE_SPENDING_ACK") != SPEND_ACK:
        missing.append(f"V3_LIVE_SPENDING_ACK={SPEND_ACK} for exactly one Qwen scene request")

    pdf1_digest, pdf1_problem = _live_pdf_problem(os.getenv("V3_WEB_LIVE_PDF1"), "V3_WEB_LIVE_PDF1")
    pdf2_digest, pdf2_problem = _live_pdf_problem(os.getenv("V3_WEB_LIVE_PDF2"), "V3_WEB_LIVE_PDF2")
    if pdf1_problem:
        missing.append(pdf1_problem)
    if pdf2_problem:
        missing.append(pdf2_problem)
    if pdf1_digest and pdf2_digest and pdf1_digest == pdf2_digest:
        missing.append("V3_WEB_LIVE_PDF1 and V3_WEB_LIVE_PDF2 must be distinct PDFs")

    if missing:
        pytest.skip("Live web gate prerequisites absent: " + "; ".join(missing))

    child_env = os.environ.copy()
    child_env.update({
        "V3_WEB_LIVE_NOTEBOOKLM_TEST": "1",
        "V3_WEB_LIVE_QWEN_TEST": "1",
        "V3_WEB_LIVE_PROOF_NOTEBOOK_ID": proof_notebook_id or "",
    })
    command = [
        sys.executable,
        "-m",
        "pytest",
        "--import-mode=importlib",
        JOINED_TEST,
        "-q",
        "-ra",
        "--tb=short",
    ]
    stdout_path = tmp_path / "live-gate.stdout.log"
    stderr_path = tmp_path / "live-gate.stderr.log"
    try:
        result = subprocess.run(
            command,
            cwd=Path(__file__).resolve().parents[3],
            env=child_env,
            capture_output=True,
            text=True,
            timeout=1800,
            check=False,
        )
    except subprocess.TimeoutExpired:
        pytest.fail(
            "Live full-chain browser test exceeded 30 minutes; private child logs "
            f"are retained at {tmp_path}"
        )

    stdout_path.write_text(result.stdout, encoding="utf-8")
    stderr_path.write_text(result.stderr, encoding="utf-8")
    stdout_path.chmod(0o600)
    stderr_path.chmod(0o600)
    if result.returncode != 0:
        pytest.fail(
            "Live full-chain browser test failed after startup with exit code "
            f"{result.returncode}; inspect private logs under {tmp_path}. "
            "The failure was not skipped or converted into a pass."
        )
    assert "1 passed" in result.stdout, "The joined live test did not report one completed passing test."
