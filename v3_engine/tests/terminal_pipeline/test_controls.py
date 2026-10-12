"""Tests for operator controls, version fences, and queued cancellation (Spec 018 / T018)."""

from __future__ import annotations

import asyncio
import json
import uuid
from pathlib import Path
import pytest
from temporalio import activity, workflow
from temporalio.client import Client
from temporalio.worker import Worker

from v3_engine.terminal_pipeline.contracts import (
    SafeCode,
    SafeErrorCode,
)
from v3_engine.terminal_pipeline.controls import (
    CommandIntent,
    CommandReceipt,
    validate_and_apply_control,
)
from v3_engine.terminal_pipeline.supervisor import TerminalSupervisorWorkflow
from v3_engine.terminal_pipeline.subject_workflow import TerminalSubjectWorkflow
from v3_engine.terminal_pipeline.cli import run_cli


@activity.defn(name="admit_terminal_submission")
async def _admit_terminal_submission_for_test(inp: dict) -> dict:
    return {"outcome": "complete", "safe_code": "ok", "counts": {"duplicate": False}}


def test_offline_control_version_fence_and_idempotency() -> None:
    receipts: dict[str, dict] = {}
    current_state = {
        "status": "running",
        "paused": False,
        "cancelled": False,
        "state_version": 1,
    }

    cmd1 = CommandIntent(
        command_id="cmd-001",
        action="pause",
        subject_run_id="run-001",
        expected_version=1,
    )

    # 1. Matching version succeeds
    res1 = validate_and_apply_control(cmd1, current_state, receipts)
    assert res1.outcome == "applied"
    assert res1.status == "paused"
    assert current_state["paused"] is True
    assert current_state["state_version"] == 2

    # 2. Resend same command_id with same action returns recorded receipt immediately (idempotent)
    res1_dup = validate_and_apply_control(cmd1, current_state, receipts)
    assert res1_dup.outcome == "applied"
    assert res1_dup.command_id == "cmd-001"

    # 3. Resend same command_id with conflicting action returns control_conflict
    cmd1_conflict = CommandIntent(
        command_id="cmd-001",
        action="resume",
        subject_run_id="run-001",
        expected_version=2,
    )
    res_conflict = validate_and_apply_control(cmd1_conflict, current_state, receipts)
    assert res_conflict.outcome == "rejected"
    assert res_conflict.safe_code == SafeCode.control_conflict.value

    # 4. New command with stale expected_version (e.g. expected 1, current is 2) returns control_conflict
    cmd2_stale = CommandIntent(
        command_id="cmd-002",
        action="resume",
        subject_run_id="run-001",
        expected_version=1,
    )
    res_stale = validate_and_apply_control(cmd2_stale, current_state, receipts)
    assert res_stale.outcome == "rejected"
    assert res_stale.safe_code == SafeCode.control_conflict.value
    assert current_state["paused"] is True

    # 5. Matching expected_version 2 resumes
    cmd3_ok = CommandIntent(
        command_id="cmd-003",
        action="resume",
        subject_run_id="run-001",
        expected_version=2,
    )
    res_resume = validate_and_apply_control(cmd3_ok, current_state, receipts)
    assert res_resume.outcome == "applied"
    assert res_resume.status == "running"
    assert current_state["paused"] is False
    assert current_state["state_version"] == 3


def test_offline_cancel_and_terminal_rejection() -> None:
    receipts: dict[str, dict] = {}
    current_state = {
        "status": "running",
        "paused": False,
        "cancelled": False,
        "state_version": 3,
    }

    cmd_cancel = CommandIntent(
        command_id="cmd-cancel",
        action="cancel",
        subject_run_id="run-001",
        expected_version=3,
    )
    res_cancel = validate_and_apply_control(cmd_cancel, current_state, receipts)
    assert res_cancel.outcome == "applied"
    assert res_cancel.status == "cancelled"
    assert current_state["cancelled"] is True
    assert current_state["state_version"] == 4

    # Subsequent pause or resume on cancelled subject is rejected
    cmd_pause = CommandIntent(
        command_id="cmd-pause-after-cancel",
        action="pause",
        subject_run_id="run-001",
        expected_version=4,
    )
    res_pause = validate_and_apply_control(cmd_pause, current_state, receipts)
    assert res_pause.outcome == "rejected"
    assert res_pause.safe_code == SafeCode.control_conflict.value


def test_pause_during_remote_operation_is_reported_as_requested() -> None:
    state = {"status": "running", "paused": False, "cancelled": False,
             "state_version": 5, "operation_in_flight": True}
    receipt = validate_and_apply_control(
        CommandIntent(command_id="pause-running-op", action="pause", subject_run_id="run-001", expected_version=5),
        state, {},
    )
    assert receipt.outcome == "requested"
    assert state["paused"] is True
    assert state["state_version"] == 6


@pytest.mark.temporal
async def test_temporal_subject_workflow_control_updates(
    temporal_local_env, tmp_path: Path
) -> None:
    client = temporal_local_env.client

    # Dummy tracking activities
    @activity.defn(name="initialize_terminal_run")
    async def init_act(inp: dict) -> dict:
        return {"outcome": "complete", "safe_code": "ok"}

    @activity.defn(name="register_terminal_child")
    async def reg_act(inp: dict) -> dict:
        return {"outcome": "complete", "safe_code": "ok"}

    @activity.defn(name="verify_notebook_session")
    async def ver_act(inp: dict) -> dict:
        return {"outcome": "complete", "safe_code": "ok"}

    async with Worker(
        client,
        task_queue="v3-terminal-control",
        workflows=[TerminalSubjectWorkflow],
        activities=[init_act, reg_act, ver_act],
    ):
        subj_run_id = str(uuid.uuid4())
        wf_id = f"v3-terminal-subject-{subj_run_id}"

        # Start subject workflow with dummy sources
        handle = await client.start_workflow(
            TerminalSubjectWorkflow.run,
            {
                "schema_version": 1,
                "subject_run_id": subj_run_id,
                "sources": [],
            },
            id=wf_id,
            task_queue="v3-terminal-control",
        )

        # 1. Send pause update with expected_version 1
        cmd_pause = {
            "command_id": "cmd-pause-1",
            "action": "pause",
            "expected_version": 1,
        }
        res_pause = await handle.execute_update("control_v1", cmd_pause)
        assert res_pause.get("status") == "applied" or res_pause.get("outcome") == "applied"

        prog = await handle.query("progress_v1")
        assert prog["paused"] is True
        assert prog["state_version"] == 2

        # 2. Resend same pause update (idempotent)
        res_pause_dup = await handle.execute_update("control_v1", cmd_pause)
        assert res_pause_dup.get("status") == "applied" or res_pause_dup.get("outcome") == "applied"

        # 3. Send stale expected_version 1
        cmd_stale = {
            "command_id": "cmd-resume-stale",
            "action": "resume",
            "expected_version": 1,
        }
        res_stale = await handle.execute_update("control_v1", cmd_stale)
        assert res_stale.get("safe_code") == SafeCode.control_conflict.value

        # 4. Send resume with matching expected_version 2
        cmd_resume = {
            "command_id": "cmd-resume-ok",
            "action": "resume",
            "expected_version": 2,
        }
        res_resume = await handle.execute_update("control_v1", cmd_resume)
        assert res_resume.get("status") == "applied" or res_resume.get("outcome") == "applied"

        prog2 = await handle.query("progress_v1")
        assert prog2["paused"] is False
        assert prog2["state_version"] == 3

        # 5. Cancel workflow with expected_version 3
        cmd_cancel = {
            "command_id": "cmd-cancel-ok",
            "action": "cancel",
            "expected_version": 3,
        }
        res_cancel = await handle.execute_update("control_v1", cmd_cancel)
        assert res_cancel.get("status") == "applied" or res_cancel.get("outcome") == "applied"


@pytest.mark.temporal
async def test_queued_cancellation_and_bootstrap_exclusion(
    temporal_local_env, tmp_path: Path
) -> None:
    client = temporal_local_env.client

    launched_subjects: list[str] = []

    @activity.defn(name="mock_child_execute")
    async def mock_child_execute(inp: dict) -> dict:
        subj_id = inp.get("subject_run_id", "")
        launched_subjects.append(subj_id)
        return {"outcome": "complete", "safe_code": "ok", "subject_run_id": subj_id}

    async with Worker(
        client,
        task_queue="v3-terminal-control",
        workflows=[TerminalSupervisorWorkflow, TerminalSubjectWorkflow],
        activities=[_admit_terminal_submission_for_test, mock_child_execute],
    ):
        supervisor_handle = await client.start_workflow(
            TerminalSupervisorWorkflow.run,
            id="v3-terminal-supervisor",
            task_queue="v3-terminal-control",
        )

        sub_id = str(uuid.uuid4())
        subjects = [str(uuid.uuid4()) for _ in range(6)]
        # subjects[0..3] will be launched immediately (max 4 concurrent)
        # subjects[4..5] will remain queued in self.queue

        sub_payload = {
            "schema_version": 1,
            "submission_id": sub_id,
            "submission_digest": "c" * 64,
            "manifest_ref": "manifests/sub.json",
            "subjects": [
                {"subject_run_id": s, "run_ref": f"runs/{s}", "title": f"Subj {s[:4]}", "mock": True}
                for s in subjects
            ],
        }

        # Submit all 6 subjects
        await supervisor_handle.execute_update(
            TerminalSupervisorWorkflow.submit_v1,
            sub_payload,
        )

        # 1. Cancel subject[4] while still queued in self.queue
        cancel_cmd_q = {
            "command_id": "cmd-cancel-q4",
            "subject_run_id": subjects[4],
            "expected_version": 1,
        }
        cancel_res = await supervisor_handle.execute_update(
            TerminalSupervisorWorkflow.cancel_queued_v1,
            cancel_cmd_q,
        )
        assert cancel_res["outcome"] == "applied"
        assert cancel_res["status"] == "cancelled_before_start"

        # 2. Duplicate cancel_queued_v1 returns identical applied receipt
        cancel_res_dup = await supervisor_handle.execute_update(
            TerminalSupervisorWorkflow.cancel_queued_v1,
            cancel_cmd_q,
        )
        assert cancel_res_dup["outcome"] == "applied"
        assert cancel_res_dup["status"] == "cancelled_before_start"

        # 3. Attempting to cancel_queued on an active launched subject (subjects[0]) rejects with control_conflict
        cancel_cmd_active = {
            "command_id": "cmd-cancel-act",
            "subject_run_id": subjects[0],
            "expected_version": 1,
        }
        res_active_reject = await supervisor_handle.execute_update(
            TerminalSupervisorWorkflow.cancel_queued_v1,
            cancel_cmd_active,
        )
        assert res_active_reject["outcome"] == "rejected"
        assert res_active_reject["safe_code"] == SafeCode.control_conflict.value

        # Wait for workflows to process
        await asyncio.sleep(0.8)

        # subjects[0..3] and subjects[5] ran, but subjects[4] was NEVER launched!
        for s in subjects[:4]:
            assert s in launched_subjects
        assert subjects[5] in launched_subjects
        assert subjects[4] not in launched_subjects
