"""Durable operation store and receipt ledger for Web Pipeline (T029, Spec 019)."""

from __future__ import annotations

import base64
import hashlib
import json
import os
import tempfile
import uuid
from dataclasses import dataclass, asdict
from pathlib import Path
from typing import Any

from v3_engine.web_pipeline.contracts import (
    ContractValidationError,
    compute_canonical_digest,
    sanitize_activity_result,
)


@dataclass
class OperationRecord:
    operation_id: str
    run_id: str
    kind: str
    item_id: str | None
    attempt: int
    request_sha256: str
    status: str  # pending, completed, failed, uncertain
    receipt_ref: str | None = None
    counts: dict[str, int] | None = None

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


class WebOperationStore:
    def __init__(self, storage_path: Path | str | None = None) -> None:
        self.storage_path = Path(storage_path) if storage_path else None
        self._operations: dict[str, OperationRecord] = {}
        self._receipts: dict[str, dict[str, Any]] = {}
        self._artifacts: dict[str, bytes] = {}

        if self.storage_path and self.storage_path.exists():
            self._load()

    def _load(self) -> None:
        if not self.storage_path or not self.storage_path.exists():
            return
        try:
            raw = self.storage_path.read_text(encoding="utf-8")
            data = json.loads(raw)
            for op_id, op_data in data.get("operations", {}).items():
                self._operations[op_id] = OperationRecord(**op_data)
            self._receipts = data.get("receipts", {})
            raw_artifacts = data.get("artifacts", {})
            self._artifacts = {
                k: base64.b64decode(v) for k, v in raw_artifacts.items()
            }
        except Exception:
            pass

    def _save(self) -> None:
        if not self.storage_path:
            return
        self.storage_path.parent.mkdir(parents=True, exist_ok=True)
        dump = {
            "operations": {k: v.to_dict() for k, v in self._operations.items()},
            "receipts": self._receipts,
            "artifacts": {k: base64.b64encode(v).decode("ascii") for k, v in self._artifacts.items()},
        }
        with tempfile.NamedTemporaryFile("w", dir=self.storage_path.parent, delete=False, encoding="utf-8") as tmp:
            json.dump(dump, tmp, separators=(",", ":"))
            tmp.flush()
            os.fsync(tmp.fileno())
            temp_name = tmp.name
        os.replace(temp_name, self.storage_path)

    def get_operation(self, operation_id: str) -> OperationRecord | None:
        return self._operations.get(operation_id)

    def get_receipt(self, receipt_ref: str) -> dict[str, Any] | None:
        return self._receipts.get(receipt_ref)

    def claim_operation(
        self,
        operation_id: str,
        run_id: str,
        kind: str,
        item_id: str | None,
        request_sha256: str,
        max_attempts: int = 1,
    ) -> OperationRecord:
        """Atomically claim operation, enforcing max mutation attempts."""
        if operation_id in self._operations:
            existing = self._operations[operation_id]
            if existing.request_sha256 != request_sha256:
                raise ContractValidationError(
                    f"Operation {operation_id} conflict: request_sha256 mismatch"
                )
            if existing.status in ("completed", "uncertain", "pending"):
                return existing
            if existing.attempt >= max_attempts:
                existing.status = "failed"
                self._save()
                raise ContractValidationError(
                    f"Operation {operation_id} exceeded maximum attempt limit ({max_attempts})"
                )
            existing.attempt += 1
            self._save()
            return existing

        record = OperationRecord(
            operation_id=operation_id,
            run_id=run_id,
            kind=kind,
            item_id=item_id,
            attempt=1,
            request_sha256=request_sha256,
            status="pending",
        )
        self._operations[operation_id] = record
        self._save()
        return record

    def commit_receipt(
        self,
        operation_id: str,
        outcome: str,
        safe_code: str,
        receipt_data: dict[str, Any] | None = None,
        counts: dict[str, int] | None = None,
    ) -> dict[str, Any]:
        """Commit durable receipt for an operation."""
        record = self._operations.get(operation_id)
        receipt_ref = str(uuid.uuid4())

        clean_result = sanitize_activity_result({
            "outcome": outcome,
            "safe_code": safe_code,
            "receipt_ref": receipt_ref,
            "counts": counts,
        })

        if record:
            record.status = clean_result["outcome"]
            record.receipt_ref = receipt_ref
            record.counts = clean_result.get("counts")

        self._receipts[receipt_ref] = {
            "receipt_ref": receipt_ref,
            "operation_id": operation_id,
            "result": clean_result,
            "data": receipt_data or {},
        }
        self._save()
        return clean_result

    def store_artifact(self, artifact_ref: str, content: bytes) -> str:
        self._artifacts[artifact_ref] = content
        self._save()
        return hashlib.sha256(content).hexdigest()

    def get_artifact(self, artifact_ref: str) -> bytes | None:
        return self._artifacts.get(artifact_ref)
