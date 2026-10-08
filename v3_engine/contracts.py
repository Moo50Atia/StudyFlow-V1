import hashlib
import hmac
import json
import time
from typing import Any, Dict, Optional
from pydantic import BaseModel, Field

def serialize_request_json(data: Any) -> bytes:
    """Canonical JSON byte serialization for signed HTTP requests."""
    return json.dumps(data, ensure_ascii=False, separators=(",", ":")).encode("utf-8")

class RetryPolicyInput(BaseModel):
    technical_retries: int = Field(ge=0, le=10)
    retry_delay_seconds: int = Field(ge=1, le=3600)

class WorkflowStartRequest(BaseModel):
    schema_version: int = 1
    case_id: str
    settings_version_id: int
    settings_checksum: str
    workflow_id: str
    retry_policy: RetryPolicyInput

class WorkflowStartResponseData(BaseModel):
    workflow_id: str
    run_id: str
    temporal_status: str

class WorkflowStartResponse(BaseModel):
    data: WorkflowStartResponseData

class WorkflowDescribeResponseData(BaseModel):
    found: bool
    workflow_id: str
    run_id: Optional[str] = None
    temporal_status: Optional[str] = None
    case_id: Optional[str] = None
    settings_version_id: Optional[int] = None
    settings_checksum: Optional[str] = None

class WorkflowDescribeResponse(BaseModel):
    data: WorkflowDescribeResponseData

class WorkerEventV1(BaseModel):
    schema_version: int = 1
    event_key: str
    case_id: str
    workflow_id: str
    run_id: str
    expected_state_version: int
    event_type: str
    stage_code: str
    status_code: str
    error_code: Optional[str] = None

def compute_payload_digest(event: WorkerEventV1) -> str:
    """
    Computes normalized SHA-256 payload digest per data-model.md:
    fields in fixed order separated by LF with null error represented by '-':
    schema_version,event_key,case_id,workflow_id,run_id,expected_state_version,event_type,stage_code,status_code,error_code
    """
    error_rep = event.error_code if event.error_code is not None else "-"
    parts = [
        str(event.schema_version),
        event.event_key,
        event.case_id,
        event.workflow_id,
        event.run_id,
        str(event.expected_state_version),
        event.event_type,
        event.stage_code,
        event.status_code,
        error_rep,
    ]
    raw = "\n".join(parts)
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()

def compute_hmac_signature(
    method: str,
    path: str,
    timestamp: int,
    raw_body: bytes,
    secret_key: str,
) -> str:
    body_sha256 = hashlib.sha256(raw_body).hexdigest().lower()
    string_to_sign = f"{method.upper()}\n{path}\n{timestamp}\n{body_sha256}"
    return hmac.new(
        secret_key.encode("utf-8"),
        string_to_sign.encode("utf-8"),
        hashlib.sha256
    ).hexdigest().lower()

def verify_hmac_signature(
    method: str,
    path: str,
    timestamp_header: Optional[str],
    signature_header: Optional[str],
    raw_body: bytes,
    secret_key: str,
    now: Optional[int] = None,
    tolerance_seconds: int = 300,
) -> bool:
    if not timestamp_header or not signature_header or not secret_key:
        return False
    try:
        ts = int(timestamp_header)
    except ValueError:
        return False
    current_ts = int(time.time()) if now is None else now
    if abs(current_ts - ts) > tolerance_seconds:
        return False
    expected = compute_hmac_signature(method, path, ts, raw_body, secret_key)
    return hmac.compare_digest(expected, signature_header.lower())
