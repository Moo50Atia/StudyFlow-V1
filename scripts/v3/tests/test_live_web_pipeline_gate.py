"""The live gate must remain inert until it has every explicit prerequisite."""

import pytest

from v3_engine.tests.web_pipeline import test_live_web_pipeline as gate


def test_live_gate_skips_with_named_missing_prerequisites(monkeypatch, tmp_path):
    monkeypatch.setenv("RUN_V3_LIVE_APPLICATION_GATE", "1")
    monkeypatch.delenv("V3_LIVE_GATE_ENABLED", raising=False)
    for name in (
        "V3_WEB_LIVE_PROOF_NOTEBOOK_ID", "V3_PROOF_NOTEBOOK_ID",
        "QWEN_API_KEY", "QWEN_MODEL", "V3_LIVE_SPENDING_ACK",
        "V3_WEB_LIVE_PDF1", "V3_WEB_LIVE_PDF2",
    ):
        monkeypatch.delenv(name, raising=False)
    monkeypatch.setattr(gate, "validate_storage_state", lambda _path: None)

    with pytest.raises(pytest.skip.Exception) as exc:
        gate.test_live_web_pipeline_gate(tmp_path)

    message = str(exc.value)
    assert "QWEN_API_KEY" in message
    assert "QWEN_MODEL" in message
    assert "V3_LIVE_SPENDING_ACK" in message
    assert "V3_WEB_LIVE_PDF1" in message
    assert "V3_WEB_LIVE_PDF2" in message
    assert "V3_WEB_LIVE_PROOF_NOTEBOOK_ID" in message


def test_live_pdf_preflight_rejects_missing_or_non_pdf(tmp_path):
    assert gate._live_pdf_problem(None, "PDF1")[1] == "PDF1 is unset"

    bad_pdf = tmp_path / "bad.pdf"
    bad_pdf.write_bytes(b"not a pdf")
    _, problem = gate._live_pdf_problem(str(bad_pdf), "PDF1")
    assert problem == "PDF1 is not a readable PDF"
