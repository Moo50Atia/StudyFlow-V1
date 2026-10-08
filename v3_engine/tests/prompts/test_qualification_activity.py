import hashlib
import json
import pytest
import httpx
from temporalio.exceptions import ApplicationError

from v3_engine.prompts.activities import qualify_prompts, resolve_qualified_prompt
from v3_engine.prompts.policy import compute_section_fields_sha256, ELEVEN_FIELDS


def test_compute_section_fields_sha256_canonical():
    """Verify compute_section_fields_sha256 produces exact 010 hash."""
    fields = {
        "section_identity": "Sec1",
        "core_understanding": "Core1",
        "visual_first_explanation": "Expl1",
        "visual_scene_script": "Script1",
        "dynamic_view_blueprint": "Blueprint1",
        "visual_learning_outcome": "Outcome1",
        "concept_check": "Check1",
        "conceptual_summary": "Summary1",
        "colloquial_egyptian_explanation": "Colloquial1",
        "laws_if_any": "",
        "real_life_example": "Example1",
    }
    digest = compute_section_fields_sha256(fields)
    assert len(digest) == 64

    # Verify manual reconstruction
    buffer = "010-section-v1\n"
    for fn in ELEVEN_FIELDS:
        val = fields.get(fn, "")
        val_bytes = val.encode("utf-8")
        buffer += f"{fn}\n{len(val_bytes)}:{val}\n"
    expected = hashlib.sha256(buffer.encode("utf-8")).hexdigest()
    assert digest == expected


@pytest.mark.asyncio
async def test_resolve_qualified_prompt_rejects_mock_mode():
    """Verify mock_mode is removed and cannot bypass network resolution."""
    with pytest.raises(ApplicationError) as exc_info:
        await resolve_qualified_prompt({
            "case_id": "00000000-0000-0000-0000-000000000001",
            "prompt_version_ref": "00000000-0000-0000-0000-000000000002",
            "mock_mode": True,
        })
    assert "Network error" in str(exc_info.value) or "Server error" in str(exc_info.value) or "Prompt unavailable" in str(exc_info.value)


@pytest.mark.asyncio
async def test_qualify_prompts_pass_lifecycle(monkeypatch):
    """Verify qualify_prompts runs full pass lifecycle against mock services."""
    monkeypatch.setenv("V3_CALLBACK_HMAC_KEY", "test-key")
    monkeypatch.setenv("TYPESAFE_API_KEY", "test-typesafe-key")
    monkeypatch.setenv("TYPESAFE_BASE_URL", "https://api.typesafe.ai")
    monkeypatch.setenv("JEV_MODEL", "typesafe-systemone-v1")

    pv1 = "11111111-1111-1111-1111-111111111111"
    case_id = "00000000-0000-0000-0000-000000000001"

    sample_fields = {fn: f"val_{fn}" for fn in ELEVEN_FIELDS}

    def transport_handler(request: httpx.Request) -> httpx.Response:
        url_str = str(request.url)
        # TypeSafe JEV endpoint
        if "typesafe.ai/v1/systemone" in url_str:
            return httpx.Response(
                200,
                json={
                    "model": "typesafe-systemone-v1",
                    "answers": {
                        "choice": {"type": "choice", "choice": "pass", "confidence": 0.9},
                        "score": {"type": "score", "score": 90.0, "confidence": 0.85},
                        "noul": {"type": "noul", "noul": 0.95},
                    },
                },
            )
        # Laravel qualification-context
        if f"/api/internal/v1/cases/{case_id}/prompt-versions/{pv1}/qualification-context" in url_str:
            return httpx.Response(
                200,
                json={
                    "data": {
                        "prompt": "Process: Wave animation.",
                        "fields": sample_fields,
                        "coverage_pages": [1, 2],
                        "settings_checksum": "a" * 64,
                    }
                },
            )
        # Laravel claim JEV attempt
        if f"/api/internal/v1/cases/{case_id}/prompt-versions/{pv1}/jev-attempts/claim" in url_str:
            return httpx.Response(200, json={"data": {"attempt_ref": "att-1", "disposition": "send_now"}})
        # Laravel record JEV result
        if f"/api/internal/v1/cases/{case_id}/prompt-jev-attempts/att-1/result" in url_str:
            return httpx.Response(201, json={"data": {"qualification_ref": "qual-1", "decision": "pass", "failed_checks": []}})

        return httpx.Response(404, json={"error": "not found"})

    # Patch httpx.AsyncClient to use mock transport
    orig_client_init = httpx.AsyncClient.__init__

    def mock_client_init(self, *args, **kwargs):
        kwargs["transport"] = httpx.MockTransport(transport_handler)
        orig_client_init(self, *args, **kwargs)

    monkeypatch.setattr(httpx.AsyncClient, "__init__", mock_client_init)

    res = await qualify_prompts({
        "case_id": case_id,
        "work_ordinal": 1,
        "prompt_version_refs": [pv1],
    })

    assert res["status"] == "completed"
    assert res["qualified_count"] == 1
    assert res["blocked_count"] == 0
    assert res["passing_prompt_refs"] == [pv1]


@pytest.mark.asyncio
async def test_qualify_prompts_choice_block_lifecycle(monkeypatch):
    """Verify qualify_prompts records choice block without continuing revision."""
    monkeypatch.setenv("V3_CALLBACK_HMAC_KEY", "test-key")
    monkeypatch.setenv("TYPESAFE_API_KEY", "test-typesafe-key")
    monkeypatch.setenv("TYPESAFE_BASE_URL", "https://api.typesafe.ai")
    monkeypatch.setenv("JEV_MODEL", "typesafe-systemone-v1")

    pv1 = "11111111-1111-1111-1111-111111111111"
    case_id = "00000000-0000-0000-0000-000000000001"
    sample_fields = {fn: f"val_{fn}" for fn in ELEVEN_FIELDS}

    def transport_handler(request: httpx.Request) -> httpx.Response:
        url_str = str(request.url)
        if "typesafe.ai/v1/systemone" in url_str:
            return httpx.Response(
                200,
                json={
                    "model": "typesafe-systemone-v1",
                    "answers": {
                        "choice": {"type": "choice", "choice": "block", "confidence": 0.99},
                        "score": {"type": "score", "score": 90.0, "confidence": 0.85},
                        "noul": {"type": "noul", "noul": 0.95},
                    },
                },
            )
        if f"/api/internal/v1/cases/{case_id}/prompt-versions/{pv1}/qualification-context" in url_str:
            return httpx.Response(
                200,
                json={
                    "data": {
                        "prompt": "Process: Wave animation.",
                        "fields": sample_fields,
                        "coverage_pages": [1],
                        "settings_checksum": "a" * 64,
                    }
                },
            )
        if f"/api/internal/v1/cases/{case_id}/prompt-versions/{pv1}/jev-attempts/claim" in url_str:
            return httpx.Response(200, json={"data": {"attempt_ref": "att-1", "disposition": "send_now"}})
        if f"/api/internal/v1/cases/{case_id}/prompt-jev-attempts/att-1/result" in url_str:
            return httpx.Response(201, json={"data": {"qualification_ref": "qual-1", "decision": "block", "failed_checks": ["choice"]}})

        return httpx.Response(404, json={"error": "not found"})

    orig_client_init = httpx.AsyncClient.__init__

    def mock_client_init(self, *args, **kwargs):
        kwargs["transport"] = httpx.MockTransport(transport_handler)
        orig_client_init(self, *args, **kwargs)

    monkeypatch.setattr(httpx.AsyncClient, "__init__", mock_client_init)

    res = await qualify_prompts({
        "case_id": case_id,
        "work_ordinal": 1,
        "prompt_version_refs": [pv1],
    })

    assert res["status"] == "completed"
    assert res["qualified_count"] == 0
    assert res["blocked_count"] == 1
    assert res["passing_prompt_refs"] == []


@pytest.mark.asyncio
async def test_qualify_prompts_revision_then_pass_lifecycle(monkeypatch):
    """Verify qualify_prompts executes revision loop and then qualifies successor version."""
    monkeypatch.setenv("V3_CALLBACK_HMAC_KEY", "test-key")
    monkeypatch.setenv("TYPESAFE_API_KEY", "test-typesafe-key")
    monkeypatch.setenv("TYPESAFE_BASE_URL", "https://api.typesafe.ai")
    monkeypatch.setenv("JEV_MODEL", "typesafe-systemone-v1")
    monkeypatch.setenv("GEMINI_API_KEY", "test-gemini-key")

    pv1 = "11111111-1111-1111-1111-111111111111"
    pv2 = "22222222-2222-2222-2222-222222222222"
    case_id = "00000000-0000-0000-0000-000000000001"
    sample_fields = {fn: f"val_{fn}" for fn in ELEVEN_FIELDS}

    jev_call_count = 0

    def transport_handler(request: httpx.Request) -> httpx.Response:
        nonlocal jev_call_count
        url_str = str(request.url)

        # TypeSafe JEV
        if "typesafe.ai/v1/systemone" in url_str:
            jev_call_count += 1
            if jev_call_count == 1:
                return httpx.Response(
                    200,
                    json={
                        "model": "typesafe-systemone-v1",
                        "answers": {
                            "choice": {"type": "choice", "choice": "revise", "confidence": 0.8},
                            "score": {"type": "score", "score": 60.0, "confidence": 0.8},
                            "noul": {"type": "noul", "noul": 0.8},
                        },
                    },
                )
            else:
                return httpx.Response(
                    200,
                    json={
                        "model": "typesafe-systemone-v1",
                        "answers": {
                            "choice": {"type": "choice", "choice": "pass", "confidence": 0.9},
                            "score": {"type": "score", "score": 90.0, "confidence": 0.9},
                            "noul": {"type": "noul", "noul": 0.95},
                        },
                    },
                )

        # Gemini feedback call
        if "generativelanguage.googleapis.com" in url_str:
            return httpx.Response(
                200,
                json={
                    "candidates": [
                        {
                            "finishReason": "STOP",
                            "content": {"parts": [{"text": "Process: Revised wave animation."}]},
                        }
                    ],
                    "modelVersion": "gemini-2.5-flash-001",
                    "responseId": "resp-fb-1",
                },
            )

        # Laravel endpoints
        if f"/api/internal/v1/cases/{case_id}/prompt-versions/{pv1}/qualification-context" in url_str:
            return httpx.Response(
                200,
                json={"data": {"prompt": "Process: Old wave.", "fields": sample_fields, "coverage_pages": [1], "settings_checksum": "a" * 64}},
            )
        if f"/api/internal/v1/cases/{case_id}/prompt-versions/{pv1}/jev-attempts/claim" in url_str:
            return httpx.Response(200, json={"data": {"attempt_ref": "att-1", "disposition": "send_now"}})
        if f"/api/internal/v1/cases/{case_id}/prompt-jev-attempts/att-1/result" in url_str:
            return httpx.Response(201, json={"data": {"qualification_ref": "qual-1", "decision": "revise", "failed_checks": ["score"]}})

        # Feedback endpoints
        if f"/api/internal/v1/cases/{case_id}/prompt-versions/{pv1}/feedback-context" in url_str:
            return httpx.Response(
                200,
                json={
                    "data": {
                        "case_id": case_id,
                        "section_ref": "sec-1",
                        "prompt_version_ref": pv1,
                        "previous_prompt": "Process: Old wave.",
                        "fields": sample_fields,
                        "qualification_ref": "qual-1",
                        "qualification_answer_sha256": "b" * 64,
                        "failed_checks": ["score"],
                        "typed_values": {"score": 60.0},
                        "template_id": "tpl-1",
                        "template_sha256": "c" * 64,
                        "template_bytes": "Edit prompt.",
                        "provider": {"id": "gemini_api", "model": "gemini-2.5-flash", "credential_ref": "GEMINI_API_KEY"},
                    }
                },
            )
        if f"/api/internal/v1/cases/{case_id}/prompt-versions/{pv1}/feedback/claim" in url_str:
            return httpx.Response(200, json={"data": {"effect_ref": "eff-1", "disposition": "send_now"}})
        if f"/api/internal/v1/cases/{case_id}/prompt-feedback-effects/eff-1/result" in url_str:
            return httpx.Response(201, json={"data": {"prompt_version_ref": pv2, "version_no": 2, "state": "awaiting_qualification"}})

        # Successor pv2 qualification
        if f"/api/internal/v1/cases/{case_id}/prompt-versions/{pv2}/qualification-context" in url_str:
            return httpx.Response(
                200,
                json={"data": {"prompt": "Process: Revised wave animation.", "fields": sample_fields, "coverage_pages": [1], "settings_checksum": "a" * 64}},
            )
        if f"/api/internal/v1/cases/{case_id}/prompt-versions/{pv2}/jev-attempts/claim" in url_str:
            return httpx.Response(200, json={"data": {"attempt_ref": "att-2", "disposition": "send_now"}})
        if f"/api/internal/v1/cases/{case_id}/prompt-jev-attempts/att-2/result" in url_str:
            return httpx.Response(201, json={"data": {"qualification_ref": "qual-2", "decision": "pass", "failed_checks": []}})

        return httpx.Response(404, json={"error": "not found"})

    orig_client_init = httpx.AsyncClient.__init__

    def mock_client_init(self, *args, **kwargs):
        kwargs["transport"] = httpx.MockTransport(transport_handler)
        orig_client_init(self, *args, **kwargs)

    monkeypatch.setattr(httpx.AsyncClient, "__init__", mock_client_init)

    res = await qualify_prompts({
        "case_id": case_id,
        "work_ordinal": 1,
        "prompt_version_refs": [pv1],
    })

    assert res["status"] == "completed"
    assert res["qualified_count"] == 1
    assert res["blocked_count"] == 0
    # The passing ref is the successor pv2!
    assert res["passing_prompt_refs"] == [pv2]


@pytest.mark.asyncio
async def test_resolve_qualified_prompt_success(monkeypatch):
    """Verify resolve_qualified_prompt retrieves verified prompt body digest."""
    monkeypatch.setenv("V3_CALLBACK_HMAC_KEY", "test-key")

    case_id = "00000000-0000-0000-0000-000000000001"
    pv1 = "11111111-1111-1111-1111-111111111111"

    def transport_handler(request: httpx.Request) -> httpx.Response:
        url_str = str(request.url)
        if f"/api/internal/v1/cases/{case_id}/qualified-prompts/{pv1}" in url_str:
            return httpx.Response(
                200,
                json={
                    "data": {
                        "case_id": case_id,
                        "section_ref": "sec-1",
                        "prompt_version_ref": pv1,
                        "body_sha256": "f" * 64,
                    }
                },
            )
        return httpx.Response(404, json={"error": "not found"})

    orig_client_init = httpx.AsyncClient.__init__

    def mock_client_init(self, *args, **kwargs):
        kwargs["transport"] = httpx.MockTransport(transport_handler)
        orig_client_init(self, *args, **kwargs)

    monkeypatch.setattr(httpx.AsyncClient, "__init__", mock_client_init)

    res = await resolve_qualified_prompt({
        "case_id": case_id,
        "prompt_version_ref": pv1,
    })

    assert res["status"] == "resolved"
    assert res["case_id"] == case_id
    assert res["prompt_version_ref"] == pv1
    assert res["body_sha256"] == "f" * 64


@pytest.mark.asyncio
async def test_qualify_prompts_reconcile_disposition_never_resends_billable_jev(monkeypatch):
    monkeypatch.setenv("V3_CALLBACK_HMAC_KEY", "test-key")
    monkeypatch.setenv("TYPESAFE_API_KEY", "test-typesafe-key")
    monkeypatch.setenv("JEV_MODEL", "typesafe-systemone-v1")
    provider_calls = []
    case_id = "00000000-0000-0000-0000-000000000001"
    prompt_ref = "11111111-1111-1111-1111-111111111111"

    def transport_handler(request: httpx.Request) -> httpx.Response:
        if "typesafe.ai" in str(request.url):
            provider_calls.append(request)
            return httpx.Response(200, json={})
        if request.url.path.endswith(f"/prompt-versions/{prompt_ref}/qualification-context"):
            return httpx.Response(200, json={"data": {
                "prompt": "Process: Scene", "fields": {fn: fn for fn in ELEVEN_FIELDS},
                "coverage_pages": [1], "settings_checksum": "a" * 64,
            }})
        if request.url.path.endswith(f"/prompt-versions/{prompt_ref}/jev-attempts/claim"):
            return httpx.Response(200, json={"data": {"attempt_ref": "attempt-1", "disposition": "reconcile"}})
        return httpx.Response(404, json={"error": "not found"})

    orig_client_init = httpx.AsyncClient.__init__
    def mock_client_init(self, *args, **kwargs):
        kwargs["transport"] = httpx.MockTransport(transport_handler)
        orig_client_init(self, *args, **kwargs)
    monkeypatch.setattr(httpx.AsyncClient, "__init__", mock_client_init)

    result = await qualify_prompts({"case_id": case_id, "work_ordinal": 1, "prompt_version_refs": [prompt_ref]})

    assert result["status"] == "uncertain"
    assert result["qualified_count"] == 0
    assert provider_calls == []
