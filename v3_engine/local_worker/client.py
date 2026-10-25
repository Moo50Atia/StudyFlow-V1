"""HTTP client for outbound communication with the hosted Local Worker API."""

from __future__ import annotations

import hashlib
import ipaddress
from pathlib import Path
from typing import Any, Dict, List, Optional
from urllib.parse import urlsplit
import httpx


class LocalWorkerClientError(Exception):
    def __init__(self, message: str, status_code: int = 500, error_code: str = "client_error", response_data: Any = None):
        super().__init__(f"[{status_code}] {error_code}: {message}")
        self.status_code = status_code
        self.error_code = error_code
        self.response_data = response_data


def validate_server_origin(origin: str) -> str:
    """Keep device credentials on one TLS origin; permit HTTP for loopback only."""
    parsed = urlsplit(origin)
    if (parsed.scheme not in ('http', 'https') or not parsed.hostname
            or parsed.username is not None or parsed.password is not None
            or parsed.path not in ('', '/') or parsed.query or parsed.fragment):
        raise ValueError('Worker server must be an origin URL without credentials, path, query or fragment.')
    parsed.port  # Reject malformed or out-of-range ports before saving credentials.
    loopback = parsed.hostname.lower() == 'localhost'
    try:
        loopback = loopback or ipaddress.ip_address(parsed.hostname).is_loopback
    except ValueError:
        pass
    if parsed.scheme != 'https' and not loopback:
        raise ValueError('Remote worker servers require HTTPS. HTTP is allowed only on loopback.')
    return origin.rstrip('/')


class LocalWorkerHttpClient:
    def __init__(self, server_origin: str, device_token: Optional[str] = None, timeout: float = 10.0):
        self.server_origin = validate_server_origin(server_origin)
        self.device_token = device_token
        self.timeout = timeout
        self.base_url = f"{self.server_origin}/api/v3/local-worker/v1"
        self._active_assignment_id: Optional[str] = None
        self._active_assignment_epoch: Optional[int] = None

    def _remember_assignment(self, payload: Dict[str, Any]) -> None:
        assignment_id = payload.get("assignment_id")
        assignment_epoch = payload.get("assignment_epoch")
        if assignment_id and assignment_epoch is not None:
            self._active_assignment_id = str(assignment_id)
            self._active_assignment_epoch = int(assignment_epoch)

    def _headers(
        self,
        assignment_id: Optional[str] = None,
        assignment_epoch: Optional[int] = None,
        extra: Optional[Dict[str, str]] = None,
    ) -> Dict[str, str]:
        assignment_id = assignment_id or self._active_assignment_id
        if assignment_epoch is None:
            assignment_epoch = self._active_assignment_epoch
        headers = {
            "User-Agent": "local-worker.v1",
            "Accept": "application/json",
        }
        if self.device_token:
            headers["Authorization"] = f"Bearer {self.device_token}"
        if assignment_id:
            headers["X-Assignment-ID"] = assignment_id
        if assignment_epoch is not None:
            headers["X-Assignment-Epoch"] = str(assignment_epoch)
        if extra:
            headers.update(extra)
        return headers

    def redeem_enrollment(
        self,
        pairing_secret: str,
        device_name: str,
        device_uuid: str = "device-local-1",
        os_family: str = "linux",
    ) -> Dict[str, Any]:
        url = f"{self.base_url}/enrollments/redeem"
        payload = {
            "schema_version": 1,
            "pairing_secret": pairing_secret,
            "pairing_code": pairing_secret,
            "device_name": device_name,
            "device_uuid": device_uuid,
            "os_family": os_family,
        }
        with httpx.Client(timeout=self.timeout) as client:
            resp = client.post(url, json=payload, headers=self._headers())
            if resp.status_code != 200:
                self._handle_error(resp)
            return resp.json()

    def get_device(self) -> Dict[str, Any]:
        url = f"{self.base_url}/device"
        with httpx.Client(timeout=self.timeout) as client:
            resp = client.get(url, headers=self._headers())
            if resp.status_code != 200:
                self._handle_error(resp)
            return resp.json()

    def get_provider_health_requests(self) -> List[Dict[str, Any]]:
        with httpx.Client(timeout=self.timeout) as client:
            response = client.get(f'{self.base_url}/device/provider-health/requests', headers=self._headers())
            if response.status_code != 200:
                self._handle_error(response)
            data = response.json()
            probes = data.get('probes', [])
            return probes if isinstance(probes, list) else []

    def report_provider_health(self, report: Dict[str, Any]) -> Dict[str, Any]:
        with httpx.Client(timeout=self.timeout) as client:
            response = client.post(f'{self.base_url}/device/provider-health/reports',
                                   json=report, headers=self._headers())
            if response.status_code != 200:
                self._handle_error(response)
            return response.json()

    def claim(
        self,
        claim_id: str,
        capabilities: List[str],
        available_slots: int = 1,
        task_id: Optional[str] = None,
    ) -> Optional[Dict[str, Any]]:
        url = f"{self.base_url}/claims"
        payload: Dict[str, Any] = {
            "schema_version": 1,
            "claim_id": claim_id,
            "protocol_version": 1,
            "capabilities": capabilities,
            "available_slots": available_slots,
        }
        if task_id:
            payload["task_id"] = task_id

        # Long-poll allowed up to 25s; timeout 30s
        with httpx.Client(timeout=30.0) as client:
            resp = client.post(url, json=payload, headers=self._headers())
            if resp.status_code == 204:
                return None
            if resp.status_code != 200:
                self._handle_error(resp)
            data = resp.json()
            self._remember_assignment(data)
            return data

    def get_claim(self, claim_id: str) -> Dict[str, Any]:
        url = f"{self.base_url}/claims/{claim_id}"
        with httpx.Client(timeout=self.timeout) as client:
            resp = client.get(url, headers=self._headers())
            if resp.status_code != 200:
                self._handle_error(resp)
            data = resp.json()
            self._remember_assignment(data)
            return data

    def get_assignment(
        self,
        task_id: str,
        assignment_id: Optional[str] = None,
        assignment_epoch: Optional[int] = None,
    ) -> Dict[str, Any]:
        url = f"{self.base_url}/tasks/{task_id}/assignment"
        with httpx.Client(timeout=self.timeout) as client:
            resp = client.get(url, headers=self._headers(assignment_id=assignment_id, assignment_epoch=assignment_epoch))
            if resp.status_code != 200:
                self._handle_error(resp)
            data = resp.json()
            self._remember_assignment(data)
            return data

    def submit_recovery_evidence(self, task_id: str, evidence: Dict[str, Any]) -> Dict[str, Any]:
        with httpx.Client(timeout=self.timeout) as client:
            resp = client.post(f'{self.base_url}/tasks/{task_id}/recoveries',
                               json={'schema_version': 1, 'evidence_payload': evidence}, headers=self._headers())
            if resp.status_code != 200:
                self._handle_error(resp)
            return resp.json()

    def report_progress(self, task_id: str, snapshot: Dict[str, Any]) -> Dict[str, Any]:
        with httpx.Client(timeout=self.timeout) as client:
            response = client.post(f'{self.base_url}/tasks/{task_id}/progress',
                                   json=snapshot, headers=self._headers())
            if response.status_code != 200:
                self._handle_error(response)
            return response.json()

    def heartbeat(
        self,
        task_id: str,
        observed_control_version: int,
        assignment_id: str,
        assignment_epoch: int,
    ) -> Dict[str, Any]:
        url = f"{self.base_url}/tasks/{task_id}/heartbeats"
        payload = {
            "schema_version": 1,
            "observed_control_version": observed_control_version,
        }
        with httpx.Client(timeout=self.timeout) as client:
            resp = client.post(
                url,
                json=payload,
                headers=self._headers(assignment_id=assignment_id, assignment_epoch=assignment_epoch),
            )
            if resp.status_code != 200:
                self._handle_error(resp)
            return resp.json()

    def release(
        self,
        task_id: str,
        reason: str,
        assignment_id: str,
        assignment_epoch: int,
    ) -> Dict[str, Any]:
        url = f"{self.base_url}/tasks/{task_id}/release"
        payload = {
            "schema_version": 1,
            "reason": reason,
        }
        with httpx.Client(timeout=self.timeout) as client:
            resp = client.post(
                url,
                json=payload,
                headers=self._headers(assignment_id=assignment_id, assignment_epoch=assignment_epoch),
            )
            if resp.status_code != 200:
                self._handle_error(resp)
            return resp.json()

    def get_input_manifest(self, task_id: str, assignment_id: str, assignment_epoch: int) -> bytes:
        url = f"{self.base_url}/tasks/{task_id}/input-manifest"
        with httpx.Client(timeout=self.timeout) as client:
            resp = client.get(url, headers=self._headers(assignment_id=assignment_id, assignment_epoch=assignment_epoch))
            if resp.status_code != 200:
                self._handle_error(resp)
            return resp.content

    def download_input_artifact(
        self,
        task_id: str,
        artifact_id: str,
        target_path: Path,
        assignment_id: str,
        assignment_epoch: int,
    ) -> Path:
        url = f"{self.base_url}/tasks/{task_id}/inputs/{artifact_id}"
        target_path = Path(target_path).expanduser().resolve()
        target_path.parent.mkdir(parents=True, exist_ok=True)

        with httpx.Client(timeout=60.0) as client:
            with client.stream(
                "GET",
                url,
                headers=self._headers(assignment_id=assignment_id, assignment_epoch=assignment_epoch),
            ) as resp:
                if resp.status_code != 200:
                    self._handle_error(resp)
                with open(target_path, "wb") as f:
                    for chunk in resp.iter_bytes(chunk_size=8192):
                        f.write(chunk)
        return target_path

    def create_upload_slot(
        self,
        task_id: str,
        upload_id: str,
        artifact_id: str,
        purpose: str,
        media_type: str,
        expected_bytes: int,
        expected_sha256: str,
    ) -> Dict[str, Any]:
        url = f"{self.base_url}/tasks/{task_id}/uploads"
        payload = {
            "schema_version": 1,
            "upload_id": upload_id,
            "artifact_id": artifact_id,
            "purpose": purpose,
            "media_type": media_type,
            "expected_bytes": expected_bytes,
            "expected_sha256": expected_sha256,
        }
        with httpx.Client(timeout=self.timeout) as client:
            resp = client.post(url, json=payload, headers=self._headers())
            if resp.status_code not in (200, 201):
                self._handle_error(resp)
            return resp.json()

    def upload_bytes(self, task_id: str, upload_id: str, data: bytes, media_type: str = "application/octet-stream") -> Dict[str, Any]:
        url = f"{self.base_url}/tasks/{task_id}/uploads/{upload_id}"
        headers = self._headers(extra={"Content-Type": media_type})
        with httpx.Client(timeout=60.0) as client:
            resp = client.put(url, content=data, headers=headers)
            if resp.status_code != 200:
                self._handle_error(resp)
            return resp.json()

    def reserve_effect(
        self,
        task_id: str,
        effect_id: str,
        logical_effect_key: str,
        operation_kind: str,
        request_digest: str,
        pdf_ordinal: Optional[int] = None,
        lesson_ordinal: Optional[int] = None,
        scene_ordinal: Optional[int] = None,
    ) -> Dict[str, Any]:
        url = f"{self.base_url}/tasks/{task_id}/effects/reserve"
        payload: Dict[str, Any] = {
            "schema_version": 1,
            "effect_id": effect_id,
            "logical_effect_key": logical_effect_key,
            "operation_kind": operation_kind,
            "request_digest": request_digest,
        }
        if pdf_ordinal is not None:
            payload["pdf_ordinal"] = pdf_ordinal
        if lesson_ordinal is not None:
            payload["lesson_ordinal"] = lesson_ordinal
        if scene_ordinal is not None:
            payload["scene_ordinal"] = scene_ordinal

        with httpx.Client(timeout=self.timeout) as client:
            resp = client.post(url, json=payload, headers=self._headers())
            if resp.status_code not in (200, 201):
                self._handle_error(resp)
            return resp.json()

    def dispatch_effect(self, task_id: str, effect_id: str, worker_process_id: str) -> Dict[str, Any]:
        url = f"{self.base_url}/tasks/{task_id}/effects/{effect_id}/dispatch"
        payload = {
            "schema_version": 1,
            "worker_process_id": worker_process_id,
        }
        with httpx.Client(timeout=self.timeout) as client:
            resp = client.post(url, json=payload, headers=self._headers())
            if resp.status_code != 200:
                self._handle_error(resp)
            return resp.json()

    def outcome_effect(
        self,
        task_id: str,
        effect_id: str,
        outcome_ref: str,
        status: str,
        evidence_artifact_id: Optional[str] = None,
        evidence_sha256: Optional[str] = None,
    ) -> Dict[str, Any]:
        url = f"{self.base_url}/tasks/{task_id}/effects/{effect_id}/outcome"
        payload: Dict[str, Any] = {
            "schema_version": 1,
            "outcome_ref": outcome_ref,
            "status": status,
        }
        if evidence_artifact_id:
            payload["evidence_artifact_id"] = evidence_artifact_id
        if evidence_sha256:
            payload["evidence_sha256"] = evidence_sha256

        with httpx.Client(timeout=self.timeout) as client:
            resp = client.post(url, json=payload, headers=self._headers())
            if resp.status_code != 200:
                self._handle_error(resp)
            return resp.json()

    def get_effect(self, task_id: str, effect_id: str) -> Dict[str, Any]:
        url = f"{self.base_url}/tasks/{task_id}/effects/{effect_id}"
        with httpx.Client(timeout=self.timeout) as client:
            resp = client.get(url, headers=self._headers())
            if resp.status_code != 200:
                self._handle_error(resp)
            return resp.json()

    def submit_result(
        self,
        task_id: str,
        result_payload: Dict[str, Any],
        assignment_id: str,
        assignment_epoch: int,
    ) -> Dict[str, Any]:
        url = f"{self.base_url}/tasks/{task_id}/results"
        with httpx.Client(timeout=30.0) as client:
            resp = client.post(
                url,
                json=result_payload,
                headers=self._headers(assignment_id=assignment_id, assignment_epoch=assignment_epoch),
            )
            if resp.status_code != 200:
                self._handle_error(resp)
            return resp.json()

    def _handle_error(self, resp: httpx.Response) -> None:
        try:
            data = resp.json()
            err = data.get("error", {})
            if isinstance(err, dict):
                code = err.get("code", "unknown_error")
                msg = err.get("message", resp.text)
            else:
                code = str(err)
                msg = data.get("message", resp.text)
        except Exception:
            code = "http_error"
            msg = resp.text
        raise LocalWorkerClientError(message=msg, status_code=resp.status_code, error_code=code, response_data=resp.text)
