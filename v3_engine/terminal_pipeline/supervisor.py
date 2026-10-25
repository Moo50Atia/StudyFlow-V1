"""Temporal Supervisor Workflow managing global capacity, admission, and queueing (Spec 018 / T013)."""

from __future__ import annotations

import asyncio
import json
from datetime import timedelta
from typing import Any
from temporalio import workflow

with workflow.unsafe.imports_passed_through():
    from v3_engine.terminal_pipeline.contracts import (
        SafeCode,
        SafeErrorCode,
        SubjectLifecycle,
    )
    from v3_engine.terminal_pipeline.store import TerminalIndexStore
    from v3_engine.terminal_pipeline.subject_workflow import TerminalSubjectWorkflow
    from temporalio.common import RetryPolicy


class SupervisorBootstrapError(Exception):
    def __init__(self, message: str, safe_code: SafeCode = SafeCode.supervisor_recovery_required):
        super().__init__(message)
        self.safe_code = safe_code


def bootstrap_supervisor_state(
    index_store: TerminalIndexStore,
    client: Any | None = None,
    describe_fn: Any | None = None,
) -> dict[str, Any]:
    """Reconstruct supervisor state from SQLite index and verify unresolved chains."""
    unsettled = index_store.list_unsettled_subjects()
    queue: list[dict[str, Any]] = []
    cancelled_queued_runs: set[str] = set()
    cancelled_queued_receipts: dict[str, dict[str, Any]] = {}
    completed_runs: set[str] = set()
    accepted_submissions: dict[str, dict[str, Any]] = {}

    for row in unsettled:
        s_id = row["subject_run_id"]
        lifecycle = row["lifecycle"]
        if lifecycle == SubjectLifecycle.cancelled_before_start.value:
            cancelled_queued_runs.add(s_id)
            if row.get("cancellation_receipt_ref"):
                cancelled_queued_receipts[s_id] = {
                    "outcome": "applied",
                    "safe_code": SafeCode.ok.value,
                    "subject_run_id": s_id,
                    "status": "cancelled_before_start",
                }
        elif lifecycle == SubjectLifecycle.queued.value:
            queue.append({
                "subject_run_id": s_id,
                "submission_id": row["submission_id"],
                "subject_key": row["subject_key"],
                "acceptance_seq": row["acceptance_seq"],
                "run_ref": row["run_ref"],
                "manifest_ref": row["manifest_ref"],
            })
        elif lifecycle in (SubjectLifecycle.launch_pending.value, SubjectLifecycle.started.value):
            close_proof = row.get("close_confirmation_ref")
            if close_proof:
                completed_runs.add(s_id)
            else:
                wf_id = row.get("workflow_id") or f"v3-terminal-subject-{s_id}"
                status_known = False
                if describe_fn is not None:
                    try:
                        desc = describe_fn(wf_id)
                        status_known = True
                    except Exception:
                        status_known = False
                elif client is not None:
                    try:
                        handle = client.get_workflow_handle(wf_id)
                        status_known = False
                    except Exception:
                        status_known = False

                if not status_known:
                    raise SupervisorBootstrapError(
                        f"Unresolved started chain {s_id} without close proof blocks bootstrap",
                        safe_code=SafeCode.supervisor_recovery_required,
                    )

    # Load settled runs and accepted submissions
    with index_store._get_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT subject_run_id FROM subjects WHERE lifecycle = 'settled';")
        for r in cursor.fetchall():
            completed_runs.add(r["subject_run_id"])
        cursor.execute("SELECT submission_id, accepted_receipt_json FROM submissions WHERE state = 'accepted';")
        for r in cursor.fetchall():
            receipt_json = r["accepted_receipt_json"]
            accepted_submissions[r["submission_id"]] = json.loads(receipt_json) if receipt_json else {"outcome": "accepted"}

    return {
        "queue": queue,
        "accepted_submissions": accepted_submissions,
        "cancelled_queued_runs": list(cancelled_queued_runs),
        "cancelled_queued_receipts": cancelled_queued_receipts,
        "completed_runs": list(completed_runs),
        "active_recoveries": {},
        "draining": False,
    }


@workflow.defn(name="TerminalSupervisorWorkflow")
class TerminalSupervisorWorkflow:
    def __init__(self) -> None:
        self.active_tasks: dict[str, asyncio.Task] = {}
        self.queue: list[dict[str, Any]] = []
        self.accepted_submissions: dict[str, dict[str, Any]] = {}
        self.cancelled_queued_runs: set[str] = set()
        self.cancelled_queued_receipts: dict[str, dict[str, Any]] = {}
        self.completed_runs: set[str] = set()
        self.active_recoveries: dict[str, dict[str, Any]] = {}
        self.draining: bool = False
        self.rotation_requested: bool = False

    def export_state(self) -> dict[str, Any]:
        settled_ids = sorted(self.completed_runs)[-128:]
        accepted_ids = sorted(self.accepted_submissions)[-128:]
        cancelled_ids = sorted(self.cancelled_queued_receipts)[-128:]
        return {
            "queue": list(self.queue),
            "accepted_submissions": {key: self.accepted_submissions[key] for key in accepted_ids},
            "cancelled_queued_runs": sorted(self.cancelled_queued_runs)[-128:],
            "cancelled_queued_receipts": {key: self.cancelled_queued_receipts[key] for key in cancelled_ids},
            "completed_runs": settled_ids,
            "active_recoveries": dict(self.active_recoveries),
            "draining": False if self.rotation_requested else self.draining,
        }

    def load_state(self, state: dict[str, Any]) -> None:
        self.queue = list(state.get("queue", []))
        self.accepted_submissions = dict(state.get("accepted_submissions", {}))
        self.cancelled_queued_runs = set(state.get("cancelled_queued_runs", []))
        self.cancelled_queued_receipts = dict(state.get("cancelled_queued_receipts", {}))
        self.completed_runs = set(state.get("completed_runs", []))
        self.active_recoveries = dict(state.get("active_recoveries", {}))
        self.draining = bool(state.get("draining", False))

    @workflow.update(name="submit_v1")
    async def submit_v1(self, payload: dict[str, Any]) -> dict[str, Any]:
        """Atomically admit and queue submission subjects."""
        sub_id = payload["submission_id"]
        # Idempotent return if already accepted
        if sub_id in self.accepted_submissions:
            return self.accepted_submissions[sub_id]

        wf_kind = payload.get("workflow_kind", "terminal_subject_v1")
        if wf_kind not in ("terminal_subject_v1", "web_subject_v1", "web_import_v1", "web_authoring_v1"):
            return {
                "outcome": "rejected",
                "safe_code": SafeCode.configuration_invalid.value,
                "submission_id": sub_id,
            }

        try:
            hist_len = workflow.info().get_current_history_length()
        except Exception:
            hist_len = 0

        if hist_len >= 12000:
            return {
                "outcome": "rejected",
                "safe_code": SafeCode.scheduler_draining.value,
                "submission_id": sub_id,
            }

        if hist_len >= 8000:
            self.rotation_requested = True

        new_subjects = payload.get("subjects", [])
        if len(self.queue) + len(new_subjects) > 64:
            return {
                "outcome": "rejected",
                "safe_code": SafeCode.queue_full.value,
                "submission_id": sub_id,
            }

        if self.draining or hist_len >= 12000:
            return {
                "outcome": "rejected",
                "safe_code": SafeCode.scheduler_draining.value,
                "submission_id": sub_id,
            }

        persisted = await workflow.execute_activity(
            "admit_terminal_submission",
            payload,
            task_queue="v3-terminal-control",
            start_to_close_timeout=timedelta(seconds=60),
            retry_policy=RetryPolicy(maximum_attempts=3),
        )
        if not isinstance(persisted, dict) or persisted.get("outcome") != "complete":
            return {"outcome": "rejected", "safe_code": SafeCode.storage_unavailable.value, "submission_id": sub_id}
        if (persisted.get("counts") or {}).get("duplicate"):
            receipt = {"outcome": "accepted", "submission_id": sub_id, "subject_count": len(new_subjects)}
            self.accepted_submissions[sub_id] = receipt
            if payload.get("retry"):
                self.queue.extend(new_subjects)
            return receipt

        for s in new_subjects:
            self.queue.append(s)

        receipt = {
            "outcome": "accepted",
            "submission_id": sub_id,
            "subject_count": len(new_subjects),
        }
        self.accepted_submissions[sub_id] = receipt
        return receipt

    @workflow.update(name="retry_scenes_v1")
    async def retry_scenes_v1(self, payload: dict[str, Any]) -> dict[str, Any]:
        """Atomically admit and queue linked recovery subject."""
        recovery_id = payload["recovery_id"]
        orig_gen_ref = payload["original_generation_ref"]
        cmd_digest = payload.get("command_digest", "")
        mode = payload.get("mode", "build")
        rec_subj_id = payload.get("recovery_subject_id") or str(recovery_id)

        # 1. Check if already active or duplicate for this original gen ref
        if orig_gen_ref in self.active_recoveries:
            existing = self.active_recoveries[orig_gen_ref]
            if existing["recovery_id"] == recovery_id and existing["command_digest"] == cmd_digest:
                return existing["receipt"]
            return {
                "outcome": "rejected",
                "safe_code": SafeCode.recovery_busy.value,
                "recovery_id": recovery_id,
            }

        if len(self.queue) >= 64:
            return {
                "outcome": "rejected",
                "safe_code": SafeCode.queue_full.value,
                "recovery_id": recovery_id,
            }

        if self.draining:
            return {
                "outcome": "rejected",
                "safe_code": SafeCode.scheduler_draining.value,
                "recovery_id": recovery_id,
            }

        persisted = await workflow.execute_activity(
            "admit_terminal_recovery",
            {
                "recovery_id": recovery_id,
                "recovery_subject_id": rec_subj_id,
                "original_generation_ref": orig_gen_ref,
                "mode": mode,
                "command_digest": cmd_digest,
            },
            task_queue="v3-terminal-control",
            start_to_close_timeout=timedelta(seconds=60),
            retry_policy=RetryPolicy(maximum_attempts=3),
        )
        if not isinstance(persisted, dict) or persisted.get("outcome") != "complete":
            return {
                "outcome": "rejected",
                "safe_code": persisted.get("safe_code", SafeCode.storage_unavailable.value)
                if isinstance(persisted, dict) else SafeCode.storage_unavailable.value,
                "recovery_id": recovery_id,
            }

        receipt = {
            "outcome": "accepted",
            "recovery_id": recovery_id,
            "recovery_subject_id": rec_subj_id,
            "original_generation_ref": orig_gen_ref,
            "mode": mode,
        }
        self.active_recoveries[orig_gen_ref] = {
            "recovery_id": recovery_id,
            "command_digest": cmd_digest,
            "receipt": receipt,
            "recovery_subject_id": rec_subj_id,
        }
        self.queue.append({
            "subject_run_id": rec_subj_id,
            "run_ref": orig_gen_ref,
            "source_subject_run_id": orig_gen_ref.rstrip("/").split("/")[-1],
            "is_recovery": True,
            "recovery_id": recovery_id,
            "mode": mode,
            "selected_scene_keys": payload.get("selected_scene_keys", []),
        })
        return receipt

    @workflow.update(name="cancel_queued_v1")
    async def cancel_queued_v1(self, cmd: dict[str, Any]) -> dict[str, Any]:
        """Atomically cancel a queued run before child workflow launch."""
        run_id = cmd.get("subject_run_id")
        cmd_id = cmd.get("command_id", str(run_id))

        # 1. Idempotency: if already cancelled before start, return existing receipt
        if run_id in self.cancelled_queued_runs:
            receipt = self.cancelled_queued_receipts.get(run_id) or {
                "outcome": "applied",
                "safe_code": SafeCode.ok.value,
                "subject_run_id": run_id,
                "status": "cancelled_before_start",
                "command_id": cmd_id,
            }
            return receipt

        # 2. Check if already launched: launch reservation won, cancel conflicts here
        if run_id in self.active_tasks:
            return {
                "outcome": "rejected",
                "safe_code": SafeCode.control_conflict.value,
                "subject_run_id": run_id,
                "status": "already_launched",
                "command_id": cmd_id,
            }

        # 3. Check if subject is currently queued
        is_queued = any(s.get("subject_run_id") == run_id for s in self.queue)
        if not is_queued:
            return {
                "outcome": "rejected",
                "safe_code": SafeCode.control_conflict.value,
                "subject_run_id": run_id,
                "status": "not_queued",
                "command_id": cmd_id,
            }

        self.cancelled_queued_runs.add(run_id)
        self.queue = [s for s in self.queue if s.get("subject_run_id") != run_id]
        receipt = {
            "outcome": "applied",
            "safe_code": SafeCode.ok.value,
            "subject_run_id": run_id,
            "status": "cancelled_before_start",
            "command_id": cmd_id,
        }
        self.cancelled_queued_receipts[run_id] = receipt
        return receipt

    @workflow.query(name="progress_v1")
    def progress_v1(self) -> dict[str, Any]:
        """Bounded current progress projection."""
        return {
            "active_count": len(self.active_tasks),
            "queued_count": len(self.queue),
            "draining": self.draining,
        }

    @workflow.query(name="get_subject_admission_v1")
    def get_subject_admission_v1(self, run_id: str) -> str:
        """Returns subject admission state: 'queued' | 'active' | 'completed' | 'cancelled_before_start' | 'unknown'."""
        if any(s.get("subject_run_id") == run_id for s in self.queue):
            return "queued"
        if run_id in self.active_tasks:
            return "active"
        if run_id in self.completed_runs:
            return "completed"
        if run_id in self.cancelled_queued_runs:
            return "cancelled_before_start"
        return "unknown"

    @workflow.query(name="get_submission_receipt_v1")
    def get_submission_receipt_v1(self, submission_id: str) -> dict[str, Any] | None:
        """Return the durable receipt used to reconcile retried admission calls."""
        return self.accepted_submissions.get(submission_id)

    @workflow.update(name="set_draining_v1")
    async def set_draining_v1(self) -> dict[str, Any]:
        """Operator command to place scheduler in drain mode."""
        self.draining = True
        return {"draining": True}

    @workflow.update(name="rotate_v1")
    async def rotate_v1(self) -> dict[str, Any]:
        """Operator or system command to request Continue-As-New rotation."""
        self.rotation_requested = True
        return {"outcome": "rotation_requested"}

    async def _run_child(self, subject: dict[str, Any]) -> None:
        subject_id = subject["subject_run_id"]
        if subject.get("mock"):
            act_name = subject.get("activity_name", "mock_child_execute")
            await workflow.execute_activity(
                act_name,
                subject,
                task_queue="v3-terminal-control",
                start_to_close_timeout=timedelta(seconds=60),
            )
            return

        wf_kind = subject.get("workflow_kind") or subject.get("workflow_type")
        if wf_kind in ("web_import_v1", "ApplicationImportWorkflow"):
            await workflow.execute_child_workflow(
                "ApplicationImportWorkflow",
                subject,
                id=f"v3-import-{subject_id}",
                parent_close_policy=workflow.ParentClosePolicy.REQUEST_CANCEL,
                task_queue="v3-terminal-control",
            )
        elif wf_kind in ("web_authoring_v1", "ApplicationAuthoringWorkflow"):
            await workflow.execute_child_workflow(
                "ApplicationAuthoringWorkflow",
                subject,
                id=f"v3-web-authoring-{subject_id}",
                parent_close_policy=workflow.ParentClosePolicy.REQUEST_CANCEL,
                task_queue="v3-terminal-control",
            )
        elif wf_kind in ("web_subject_v1", "ApplicationSubjectWorkflow"):
            await workflow.execute_child_workflow(
                "ApplicationSubjectWorkflow",
                subject,
                id=f"v3-web-subject-{subject_id}",
                parent_close_policy=workflow.ParentClosePolicy.REQUEST_CANCEL,
                task_queue="v3-terminal-control",
            )
        elif wf_kind in ("web_scene_v1", "ApplicationSceneWorkflow"):
            await workflow.execute_child_workflow(
                "ApplicationSceneWorkflow",
                subject,
                id=f"v3-scene-{subject_id}",
                parent_close_policy=workflow.ParentClosePolicy.REQUEST_CANCEL,
                task_queue="v3-terminal-control",
            )
        elif subject.get("workflow_type"):
            await workflow.execute_child_workflow(
                subject["workflow_type"],
                subject.get("workflow_arg", 1),
                id=f"v3-terminal-subject-{subject_id}",
                parent_close_policy=workflow.ParentClosePolicy.REQUEST_CANCEL,
                task_queue="v3-terminal-control",
            )
        else:
            await workflow.execute_child_workflow(
                TerminalSubjectWorkflow.run,
                subject,
                id=f"v3-terminal-subject-{subject_id}",
                parent_close_policy=workflow.ParentClosePolicy.REQUEST_CANCEL,
                task_queue="v3-terminal-control",
            )

    @workflow.run
    async def run(self, initial_state: dict[str, Any] | None = None) -> dict[str, Any]:
        if initial_state:
            self.load_state(initial_state)

        while True:
            # Prune completed child tasks
            done_ids = [sid for sid, task in self.active_tasks.items() if task.done()]
            for sid in done_ids:
                del self.active_tasks[sid]
                self.completed_runs.add(sid)
                for generation_ref, recovery in list(self.active_recoveries.items()):
                    if recovery.get("recovery_subject_id") == sid:
                        del self.active_recoveries[generation_ref]

            # Admit up to 4 concurrent child executions globally
            while len(self.active_tasks) < 4 and len(self.queue) > 0:
                subject = self.queue.pop(0)
                subject_id = subject["subject_run_id"]
                if subject_id in self.cancelled_queued_runs:
                    continue
                task = asyncio.create_task(self._run_child(subject))
                self.active_tasks[subject_id] = task

            if (self.rotation_requested or workflow.info().is_continue_as_new_suggested()) and len(self.active_tasks) == 0 and len(self.queue) == 0:
                workflow.continue_as_new(self.export_state())

            if self.draining and len(self.active_tasks) == 0 and len(self.queue) == 0:
                break

            await workflow.wait_condition(
                lambda: (
                    any(t.done() for t in self.active_tasks.values())
                    or (len(self.active_tasks) < 4 and len(self.queue) > 0)
                    or (self.draining and len(self.active_tasks) == 0 and len(self.queue) == 0)
                    or ((self.rotation_requested or workflow.info().is_continue_as_new_suggested()) and len(self.active_tasks) == 0)
                )
            )

        return {"outcome": "complete", "safe_code": SafeCode.ok.value}
