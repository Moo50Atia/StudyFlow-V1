"""Privacy and failure cause tests (T054 / Spec 019).

Verifies that injected secrets, educational content, remote IDs, and raw exception
tracebacks are strictly stripped from activity results, admission payloads,
and operational outputs.
"""

import json
import pytest
from uuid import uuid4

from v3_engine.web_pipeline.contracts import (
    ContractValidationError,
    sanitize_activity_result,
    validate_admission_payload,
)


def test_sanitize_activity_result_strips_secrets_and_exception_details():
    """Injected credentials, traces, and prompt bodies must not survive serialization."""
    injected_result = {
        "outcome": "completed",
        "safe_code": "ok",
        "receipt_ref": str(uuid4()),
        "result_ref": str(uuid4()),
        "counts": {
            "saved_lessons": 3,
            "secret": 12345,  # Forbidden key in counts
        },
        # Injected sensitive keys
        "body": "Tell me everything about quantum physics",
        "prompt": "Create 3D scene showing wave-particle duality",
        "secret": "sk-proj-super-secret-api-key",
        "token": "bearer-eyJh...",
        "password": "db_password_123",
        "exception": "ConnectionError: failed to connect to api.anthropic.com with key sk-proj-super-secret",
        "traceback": "Traceback (most recent call last):\n  File 'engine.py', line 12",
        "student": "Student Name",
        "email": "student@college.edu",
    }

    sanitized = sanitize_activity_result(injected_result)
    serialized = json.dumps(sanitized)

    assert "sk-proj" not in serialized
    assert "super-secret" not in serialized
    assert "quantum physics" not in serialized
    assert "wave-particle" not in serialized
    assert "Traceback" not in serialized
    assert "student@college.edu" not in serialized
    assert "bearer-eyJh" not in serialized

    assert sanitized["outcome"] == "completed"
    assert sanitized["safe_code"] == "ok"
    assert "receipt_ref" in sanitized
    assert "result_ref" in sanitized
    assert sanitized["counts"] == {"saved_lessons": 3}


def test_sanitize_activity_result_normalizes_unknown_codes_and_outcomes():
    """Invalid outcomes and safe codes must be sanitized to safe defaults."""
    bad_result = {
        "outcome": "HACKED_OUTCOME",
        "safe_code": "SQL_INJECTION_CODE; DROP TABLE users;",
    }

    sanitized = sanitize_activity_result(bad_result)
    assert sanitized["outcome"] == "failed"
    assert sanitized["safe_code"] == "unknown"


def test_sanitize_activity_result_rejects_malicious_refs():
    """Non-UUID or path-traversal references must be dropped."""
    malicious_result = {
        "outcome": "completed",
        "safe_code": "ok",
        "receipt_ref": "../../etc/passwd",
        "result_ref": "javascript:alert(1)",
    }

    sanitized = sanitize_activity_result(malicious_result)
    assert "receipt_ref" not in sanitized
    assert "result_ref" not in sanitized


def test_admission_payload_rejects_injected_secrets_and_content():
    """Admission schema strictly allows only 5 specific UUIDs/hashes, rejecting injected fields."""
    valid_payload = {
        "submission_id": str(uuid4()),
        "submission_digest": "a" * 64,
        "run_id": str(uuid4()),
        "manifest_ref": str(uuid4()),
        "workflow_kind": "web_subject_v1",
    }
    # Should validate cleanly
    validate_admission_payload(valid_payload)

    # Injected prompt / educational text must raise ContractValidationError
    with pytest.raises(ContractValidationError, match="Unknown keys"):
        validate_admission_payload({
            **valid_payload,
            "prompt_content": "Newton's laws of motion",
        })

    # Injected credential must raise ContractValidationError
    with pytest.raises(ContractValidationError, match="Unknown keys"):
        validate_admission_payload({
            **valid_payload,
            "api_key": "secret_key_12345",
        })

    # Injected teacher title must raise ContractValidationError
    with pytest.raises(ContractValidationError, match="Unknown keys"):
        validate_admission_payload({
            **valid_payload,
            "teacher_title": "Physics 101 Lecture",
        })


def test_admission_payload_enforces_size_ceiling():
    """Payloads exceeding 32 KiB must be rejected before Temporal scheduling."""
    oversized = {
        "submission_id": str(uuid4()),
        "submission_digest": "a" * 64,
        "run_id": str(uuid4()),
        "manifest_ref": str(uuid4()),
        "workflow_kind": "web_subject_v1",
        "junk": "x" * 35000,
    }
    with pytest.raises(ContractValidationError, match="ceiling"):
        validate_admission_payload(oversized)


def test_event_delivery_sanitizes_and_blocks_on_failure():
    """Events must be sanitized and delivery failure must block next dispatch."""
    from v3_engine.web_pipeline.events import (
        EventDeliveryError,
        PipelineEventDelivery,
        sanitize_pipeline_event,
    )

    raw_event = {
        "run_id": str(uuid4()),
        "kind": "turn_completed",
        "secret": "sk-injected-token",
        "prompt": "forbidden prompt text",
        "safe_code": "ok",
        "counts": {"saved_lessons": 2, "secret": 123},
    }

    sanitized = sanitize_pipeline_event(raw_event)
    assert "secret" not in sanitized
    assert "prompt" not in sanitized
    assert sanitized["counts"] == {"saved_lessons": 2}

    # Mock client failure
    class FailingBridge:
        def post_event(self, event):
            raise ConnectionError("Network down")

    delivery = PipelineEventDelivery(bridge_client=FailingBridge())
    with pytest.raises(EventDeliveryError, match="Network down"):
        delivery.emit_event(
            run_id=str(uuid4()),
            kind="test_kind",
        )

