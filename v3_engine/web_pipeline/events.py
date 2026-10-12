"""Safe required-event delivery and sanitization for Web Pipeline (T058 / Spec 019).

Enforces:
- Strict sanitization of all event payloads before delivery (zero secrets, prompts, or raw traces)
- Synchronous or reliable event persistence before the next workflow mutation
- Event persistence failure blocks next dispatch
- Sanitized safe error codes and bounded count integers
"""

from __future__ import annotations

import logging
from typing import Any, Optional
from uuid import uuid4

from v3_engine.web_pipeline.contracts import (
    DISALLOWED_RESULT_KEYS,
    SAFE_CODES,
    compute_canonical_digest,
)

logger = logging.getLogger(__name__)


class EventDeliveryError(Exception):
    """Raised when required event persistence fails, blocking subsequent mutations."""


def sanitize_pipeline_event(data: dict[str, Any]) -> dict[str, Any]:
    """
    Sanitize an operational event payload:
    - Strips all disallowed sensitive keys
    - Normalizes kind and safe_code
    - Bounds counts as non-negative integers
    """
    clean: dict[str, Any] = {}
    for k, v in data.items():
        key_lower = str(k).lower().strip()
        if key_lower in DISALLOWED_RESULT_KEYS:
            continue
        clean[k] = v

    # Normalize safe code
    code = str(clean.get("safe_code", "ok")).lower().strip()
    clean["safe_code"] = code if code in SAFE_CODES else "unknown"

    # Normalize kind
    clean["kind"] = str(clean.get("kind", "unknown_event")).lower().strip()

    # Bounded counts
    counts = clean.get("counts")
    if isinstance(counts, dict):
        clean_counts = {}
        for ck, cv in counts.items():
            ck_lower = str(ck).lower().strip()
            if ck_lower in DISALLOWED_RESULT_KEYS:
                continue
            if isinstance(cv, int) and cv >= 0:
                clean_counts[ck] = cv
        clean["counts"] = clean_counts if clean_counts else None
    else:
        clean["counts"] = None

    if "id" not in clean or not clean["id"]:
        clean["id"] = str(uuid4())

    return clean


class PipelineEventDelivery:
    """
    Handles reliable, sanitized event delivery to application storage.
    If event delivery fails, it blocks downstream mutation.
    """

    def __init__(self, bridge_client: Any | None = None) -> None:
        self.bridge_client = bridge_client

    def emit_event(
        self,
        run_id: str,
        kind: str,
        operation_id: Optional[str] = None,
        phase: Optional[str] = None,
        outcome: Optional[str] = "completed",
        safe_code: str = "ok",
        counts: Optional[dict[str, int]] = None,
        expected_version: int = 1,
    ) -> dict[str, Any]:
        """
        Sanitize and deliver a required operational event.
        Throws EventDeliveryError on delivery failure to prevent unrecorded mutations.
        """
        raw_event = {
            "run_id": run_id,
            "operation_id": operation_id,
            "kind": kind,
            "phase": phase,
            "outcome": outcome,
            "safe_code": safe_code,
            "counts": counts,
            "expected_version": expected_version,
        }

        sanitized = sanitize_pipeline_event(raw_event)

        if self.bridge_client:
            try:
                resp = self.bridge_client.post_event(sanitized)
                if resp.get("status") not in (200, 201, "ok"):
                    raise EventDeliveryError(f"Event delivery returned non-ok status: {resp}")
            except Exception as e:
                logger.error(f"Event delivery failed for run {run_id}: {e}")
                raise EventDeliveryError(f"Event delivery failed: {e}") from e

        return sanitized
