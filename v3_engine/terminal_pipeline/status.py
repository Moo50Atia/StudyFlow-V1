"""Progress projection, status queries, and offline last-known fallback (Spec 018 / T025)."""

from __future__ import annotations

import asyncio
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
import os
from pathlib import Path
from typing import Any, Optional
from temporalio.client import Client

from v3_engine.terminal_pipeline.contracts import SafeCode
from v3_engine.terminal_pipeline.store import TerminalIndexStore


@dataclass(frozen=True)
class SubjectProgressProjection:
    subject_run_id: str
    workflow_id: Optional[str] = None
    lifecycle: Optional[str] = None
    status: Optional[str] = None
    phase: Optional[str] = None
    freshness: str = "last_known"  # 'realtime' | 'last_known'
    as_of: Optional[str] = None
    lessons_saved: Optional[int] = None
    scenes_generated: Optional[int] = None
    scenes_built: Optional[int] = None
    state_version: Optional[int] = None
    active_recovery_id: Optional[str] = None
    safe_code: Optional[str] = None
    pdf_processing: Optional[str] = None
    current_pdf_ordinal: Optional[int] = None
    pdfs_completed: Optional[int] = None
    total_pdfs: Optional[int] = None

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


def get_subject_status(
    run_id: str,
    client: Optional[Client] = None,
    index_store: Optional[TerminalIndexStore] = None,
    timeout: float = 5.0,
) -> SubjectProgressProjection:
    """Retrieve progress projection with 5-second deadline; falls back to index store as last-known."""
    now = datetime.now(timezone.utc).isoformat()
    wf_id = f"v3-terminal-subject-{run_id}"

    # 1. Attempt online Temporal workflow query
    if client is not None:
        try:
            async def _query() -> dict[str, Any]:
                handle = client.get_workflow_handle(wf_id)
                return await handle.query("progress_v1")

            try:
                loop = asyncio.get_running_loop()
            except RuntimeError:
                loop = None

            if loop and loop.is_running():
                # In active async loop, create task or wait_for
                future = asyncio.wait_for(_query(), timeout=timeout)
                # If already in async test, run via new event loop or task
                prog = asyncio.run(_query())
            else:
                prog = asyncio.run(asyncio.wait_for(_query(), timeout=timeout))

            return SubjectProgressProjection(
                subject_run_id=run_id,
                workflow_id=wf_id,
                lifecycle=prog.get("status", "running"),
                status='paused' if prog.get('paused') else prog.get("status", "running"),
                phase=prog.get("phase"),
                freshness="realtime",
                as_of=now,
                lessons_saved=prog.get("lessons_saved"),
                scenes_generated=prog.get("scenes_generated"),
                scenes_built=prog.get("scenes_built"),
                state_version=prog.get("state_version"),
                active_recovery_id=prog.get("active_recovery_id"),
                safe_code=SafeCode.ok.value,
                pdf_processing=prog.get("pdf_processing"),
                current_pdf_ordinal=prog.get("current_pdf_ordinal"),
                pdfs_completed=prog.get("pdfs_completed"),
                total_pdfs=prog.get("total_pdfs"),
            )
        except Exception:
            # Query timed out or failed -> fall back to offline index
            pass

    # 2. Offline fallback from persisted index store
    if index_store is None:
        term_root = Path(os.environ.get("V3_TERMINAL_ROOT") or "storage/app/private/v3/terminal")
        index_store = TerminalIndexStore(term_root / "index.sqlite")

    try:
        subj = index_store.get_subject(run_id)
    except Exception:
        subj = None

    if subj is not None:
        safe_code = (
            SafeCode.ok.value
            if subj.get("lifecycle") == "settled"
            else (SafeCode.worker_unavailable.value if client else SafeCode.temporal_unavailable.value)
        )
        return SubjectProgressProjection(
            subject_run_id=run_id,
            workflow_id=subj.get("workflow_id", wf_id),
            lifecycle=subj.get("lifecycle"),
            status=subj.get("lifecycle"),
            phase=None,
            freshness="last_known",
            as_of=subj.get("updated_at", now),
            lessons_saved=None,
            scenes_generated=None,
            scenes_built=None,
            state_version=None,
            active_recovery_id=None,
            safe_code=safe_code,
        )

    # 3. Completely unknown subject
    return SubjectProgressProjection(
        subject_run_id=run_id,
        workflow_id=wf_id,
        lifecycle=None,
        status=None,
        phase=None,
        freshness="last_known",
        as_of=now,
        lessons_saved=None,
        scenes_generated=None,
        scenes_built=None,
        state_version=None,
        active_recovery_id=None,
        safe_code=SafeCode.input_invalid.value,
    )
