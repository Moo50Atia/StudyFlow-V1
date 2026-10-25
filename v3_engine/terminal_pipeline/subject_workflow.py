"""Temporal Subject Workflow implementation (Spec 018 / T013)."""

from __future__ import annotations

import json
from datetime import timedelta
from typing import Any
from temporalio import workflow

with workflow.unsafe.imports_passed_through():
    from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT, EXECUTION_PROMPT
    from v3_engine.notebooklm.protocol import COMBINED_RESPONSE_FORMAT_TEXT
    from v3_engine.terminal_pipeline.contracts import (
        ActivityOutcome,
        SafeCode,
    )
    from v3_engine.terminal_pipeline.controls import (
        CommandIntent,
        validate_and_apply_control,
    )
    from temporalio.common import RetryPolicy


class _ControlCancelled(Exception):
    """Internal deterministic signal used to settle cancellation at a safe boundary."""


@workflow.defn(name="TerminalSubjectWorkflow")
class TerminalSubjectWorkflow:
    def __init__(self) -> None:
        self.phase: str = "preflight"
        self.status: str = "running"
        self.lessons_saved: int = 0
        self.scenes_generated: int = 0
        self.scenes_built: int = 0
        self.scene_prompts_exported: int = 0
        self.scene_generation_mode: str = "generate"
        self.paused: bool = False
        self.cancelled: bool = False
        self.command_receipts: dict[str, dict[str, Any]] = {}
        self.state_version: int = 1
        self.is_recovery: bool = False
        self.operation_in_flight: bool = False
        self.pdf_processing: str = "joint"
        self.current_pdf_ordinal: int | None = None
        self.pdfs_completed: int = 0
        self.total_pdfs: int = 0
        self.divide_prompt: str = DIVIDE_PROMPT
        self.execution_prompt: str = EXECUTION_PROMPT
        self.lesson_start_prompt: str = "Start with First lesson"
        self.lesson_continue_prompt: str = "Continue"
        self.combined_response_format: str = COMBINED_RESPONSE_FORMAT_TEXT

    @workflow.query(name="progress_v1")
    def progress_v1(self) -> dict[str, Any]:
        """Bounded current progress projection."""
        return {
            "phase": self.phase,
            "status": self.status,
            "lessons_saved": self.lessons_saved,
            "scenes_generated": self.scenes_generated,
            "scenes_built": self.scenes_built,
            "scene_prompts_exported": self.scene_prompts_exported,
            "scene_generation_mode": self.scene_generation_mode,
            "state_version": self.state_version,
            "paused": self.paused,
            "cancelled": self.cancelled,
            "operation_in_flight": self.operation_in_flight,
            "pdf_processing": self.pdf_processing,
            "current_pdf_ordinal": self.current_pdf_ordinal,
            "pdfs_completed": self.pdfs_completed,
            "total_pdfs": self.total_pdfs,
        }

    @workflow.query(name="command_v1")
    def command_v1(self, command_id: str) -> dict[str, Any] | None:
        """Query receipt of a previously received command."""
        return self.command_receipts.get(command_id)

    @workflow.update(name="control_v1")
    async def control_v1(self, cmd: dict[str, Any]) -> dict[str, Any]:
        """Process operator controls: pause, resume, cancel with version fencing."""
        current_state = {
            "status": self.status,
            "paused": self.paused,
            "cancelled": self.cancelled,
            "state_version": self.state_version,
            "operation_in_flight": self.operation_in_flight,
        }
        intent = CommandIntent(
            command_id=cmd.get("command_id", ""),
            action=cmd.get("action", ""),
            subject_run_id=cmd.get("subject_run_id", ""),
            expected_version=cmd.get("expected_version"),
            command_digest=cmd.get("command_digest"),
            phase=cmd.get("phase"),
            scene_keys=cmd.get("scene_keys"),
            accept_duplicate_risk=cmd.get("accept_duplicate_risk", False),
        )
        receipt = validate_and_apply_control(intent, current_state, self.command_receipts)
        self.paused = current_state["paused"]
        self.cancelled = current_state["cancelled"]
        self.status = current_state["status"]
        self.state_version = current_state["state_version"]
        return receipt.to_dict()

    async def _run_activity(
        self,
        name: str,
        payload: dict[str, Any],
        timeout: timedelta | None = None,
        *,
        task_queue: str | None = None,
        start_to_close_timeout: timedelta | None = None,
        allow_pending: bool = False,
        allow_uncertain: bool = False,
    ) -> dict[str, Any]:
        """Dispatch to the registered queue and reject nonterminal Activity outcomes."""
        if name in {
            "verify_notebook_session", "create_terminal_notebook", "upload_terminal_source",
            "poll_terminal_sources", "configure_terminal_chat", "send_terminal_turn",
            "reconcile_terminal_operation", "generate_terminal_scene",
            "reset_terminal_conversation",
        }:
            queue = "v3-terminal-io"
        elif name == "build_terminal_scene":
            queue = "v3-terminal-build"
        else:
            queue = "v3-terminal-control"
        mutations = {
            "create_terminal_notebook", "upload_terminal_source", "configure_terminal_chat",
            "send_terminal_turn", "generate_terminal_scene", "materialize_terminal_scene",
            "build_terminal_scene",
            "reset_terminal_conversation",
        }
        actual_timeout = timeout or start_to_close_timeout or timedelta(seconds=60)
        if name in mutations:
            await workflow.wait_condition(lambda: not self.paused or self.cancelled)
            if self.cancelled:
                raise _ControlCancelled()
            self.operation_in_flight = True
            try:
                result = await workflow.execute_activity(
                    name, payload, task_queue=queue, start_to_close_timeout=actual_timeout,
                    retry_policy=RetryPolicy(maximum_attempts=1),
                )
            finally:
                self.operation_in_flight = False
        else:
            result = await workflow.execute_activity(
                name, payload, task_queue=queue, start_to_close_timeout=actual_timeout,
            )
        if (name == 'export_terminal_scene_prompts' and isinstance(result, dict)
                and result.get('outcome') != 'complete'
                and result.get('safe_code') in {'input_invalid', 'storage_unavailable'}):
            # Export reads retained data and writes local artifacts only. It
            # cannot issue NotebookLM turns or scene-generation requests.
            # A patch marker preserves histories that already entered a hold.
            automatic_retry = workflow.patched('local-export-retry-v1')
            if automatic_retry:
                for delay in (5, 15, 30):
                    await workflow.sleep(delay)
                    await workflow.wait_condition(lambda: not self.paused or self.cancelled)
                    if self.cancelled:
                        raise _ControlCancelled()
                    result = await workflow.execute_activity(name, payload, task_queue=queue,
                        start_to_close_timeout=actual_timeout, retry_policy=RetryPolicy(maximum_attempts=1))
                    if result.get('outcome') == 'complete':
                        break
        if name == 'send_terminal_turn' and isinstance(result, dict) and result.get('outcome') == 'uncertain' and not allow_uncertain:
            # The Activity retains its dispatch intent. Repeating this SAME
            # operation only inspects anchored history; it cannot send again.
            for _ in range(3):
                await workflow.sleep(2)
                result = await workflow.execute_activity(name, payload, task_queue=queue,
                    start_to_close_timeout=actual_timeout, retry_policy=RetryPolicy(maximum_attempts=1))
                if result.get('outcome') == 'complete':
                    return result
        if (name in {'verify_notebook_session', 'create_terminal_notebook', 'upload_terminal_source',
                     'configure_terminal_chat', 'send_terminal_turn', 'reset_terminal_conversation', 'export_terminal_scene_prompts'} and isinstance(result, dict)
                and result.get('outcome') not in ('complete', 'pending') and not allow_uncertain):
            # A known provider hold must remain queryable. Raising RuntimeError
            # here makes Temporal replay a failing workflow task forever.
            previous_phase = self.phase
            self.phase = 'held_uncertain'
            self.status = 'paused'
            self.paused = True
            self.state_version += 1
            await workflow.wait_condition(lambda: not self.paused or self.cancelled)
            if self.cancelled:
                raise _ControlCancelled()
            self.phase = previous_phase
            return await self._run_activity(name, payload, timeout, task_queue=queue,
                start_to_close_timeout=start_to_close_timeout, allow_pending=allow_pending, allow_uncertain=allow_uncertain)
        if not isinstance(result, dict) or (
            result.get("outcome") != ActivityOutcome.complete.value
            and not (allow_pending and result.get("outcome") == ActivityOutcome.pending.value)
            and not (allow_uncertain and result.get("outcome") == ActivityOutcome.uncertain.value)
        ):
            code = result.get("safe_code", "activity_outcome_invalid") if isinstance(result, dict) else "activity_outcome_invalid"
            raise RuntimeError(f"{name} did not complete: {code}")
        return result

    async def _list_scenes(self, subject_run_id: str, timeout: timedelta) -> tuple[list[str], int]:
        keys: list[str] = []
        offset = 0
        total = 0
        while True:
            page = await self._run_activity(
                "page_terminal_scenes",
                {"schema_version": 1, "subject_run_id": subject_run_id, "offset": offset, "limit": 50},
                timeout,
            )
            keys.extend(page.get("scene_keys", []))
            total = (page.get("counts") or {}).get("total", total)
            next_offset = page.get("next_offset")
            if next_offset is None:
                break
            offset = int(next_offset)
        return keys, total

    async def _run_per_pdf_notebook(
        self, inp: dict[str, Any], notebook_id: str, control_timeout: timedelta, io_timeout: timedelta,
    ) -> dict[str, Any]:
        """One notebook; complete, archive and reset each PDF before the next upload."""
        subject_id = inp["subject_run_id"]
        sources = inp["sources"]
        pdfs = [source for source in sources if source.get("role") == "educational_pdf"]
        frameworks = [source for source in sources if source.get("role") == "framework_steps"]
        if not pdfs or len(frameworks) != 1:
            raise RuntimeError("per_pdf_sources_invalid")
        self.total_pdfs = len(pdfs)

        async def upload(source):
            self.phase = "uploading_sources"
            result = await self._run_activity("upload_terminal_source", {
                "schema_version": 1, "subject_run_id": subject_id, "notebook_id": notebook_id,
                "source_path": source["path"], "mime_type": source["mime_type"], "title": source["title"],
                "expected_sha256": source["sha256"],
            }, io_timeout)
            if not result.get("result_ref"):
                raise RuntimeError("source_identity_missing")
            return result["result_ref"]

        framework_id = await upload(frameworks[0])
        self.phase = "configuring_chat"
        await self._run_activity("configure_terminal_chat", {
            "schema_version": 1, "subject_run_id": subject_id, "notebook_id": notebook_id,
            "format_text": self.combined_response_format,
        }, io_timeout)

        previous_conversation_id = None
        for source in pdfs:
            ordinal = source["ordinal"]
            self.current_pdf_ordinal = ordinal
            pdf_id = await upload(source)
            self.phase = "polling_sources"
            poll = 0
            while True:
                poll += 1
                ready = await self._run_activity("poll_terminal_sources", {
                    "schema_version": 1, "subject_run_id": subject_id, "notebook_id": notebook_id,
                    "pdf_ordinal": ordinal, "poll_ordinal": poll, "source_ids": [pdf_id, framework_id],
                }, io_timeout, allow_pending=True)
                if ready["outcome"] == "complete":
                    break
                # NotebookLM source ingestion time varies with file size and
                # provider load. Keep the durable workflow in its observable
                # polling phase instead of failing the entire Temporal task on
                # an arbitrary five-minute deadline. Polls are read-only and
                # the activity wait remains cancelable through workflow control.
                delay = min(5 * (2 ** min(poll - 1, 3)), 30)
                await workflow.sleep(delay)

            common = {"schema_version": 1, "subject_run_id": subject_id, "notebook_id": notebook_id,
                      "pdf_ordinal": ordinal, "pdf_source_ids": [pdf_id], "framework_source_id": framework_id}
            self.phase = "dividing"
            for inspection in range(3):
                divide = await self._run_activity("send_terminal_turn", {
                    **common, "question": self.divide_prompt, "source_ids": [pdf_id],
                    "operation_id": f"pdf-{ordinal:04d}:divide",
                    **({"fresh_after_conversation_id": previous_conversation_id}
                       if previous_conversation_id is not None else {}),
                }, io_timeout)
                if divide["outcome"] == "complete":
                    break
                if inspection == 2:
                    raise RuntimeError("pdf_divide_uncertain")
                # The retained intent makes this a history inspection only.
                await workflow.sleep(2)
            mapped = await self._run_activity("capture_terminal_turn", {
                **common, "turn_kind": "divide", "receipt_ref": divide["receipt_ref"],
                "conversation_id": divide["result_ref"],
            }, control_timeout)
            count = (mapped.get("counts") or {}).get("work_count")
            if not isinstance(count, int) or isinstance(count, bool) or count < 1:
                raise RuntimeError("map_unreadable")

            self.phase = "executing"
            execution = await self._run_activity("send_terminal_turn", {
                **common, "question": self.execution_prompt, "source_ids": [pdf_id, framework_id],
                "operation_id": f"pdf-{ordinal:04d}:execution", "conversation_id": divide["result_ref"],
            }, io_timeout)
            await self._run_activity("capture_terminal_turn", {
                **common, "turn_kind": "execution", "receipt_ref": execution["receipt_ref"],
                "conversation_id": execution["result_ref"],
            }, control_timeout)
            conversation_id = execution["result_ref"]
            self.phase = "generating_lessons"
            for lesson in range(1, count + 1):
                answer = await self._run_activity("send_terminal_turn", {
                    **common, "question": self.lesson_start_prompt if lesson == 1 else self.lesson_continue_prompt,
                    "source_ids": [pdf_id, framework_id], "conversation_id": conversation_id,
                    "operation_id": f"pdf-{ordinal:04d}:lesson-{lesson:03d}",
                }, io_timeout)
                await self._run_activity("capture_terminal_turn", {
                    **common, "turn_kind": "lesson", "lesson_ordinal": lesson,
                    "receipt_ref": answer["receipt_ref"], "conversation_id": answer["result_ref"],
                }, control_timeout)
                conversation_id = answer["result_ref"]
                self.lessons_saved += 1

            self.phase = "archiving_pdf"
            archived = await self._run_activity("archive_terminal_pdf", {
                "schema_version": 1, "subject_run_id": subject_id, "pdf_ordinal": ordinal,
            }, control_timeout)
            if archived["counts"]["lesson_count"] != count:
                raise RuntimeError("pdf_archive_incomplete")
            self.pdfs_completed += 1
            if self.pdfs_completed < self.total_pdfs:
                self.phase = "resetting_conversation"
                # A reset retry only inspects its durable fence; it cannot
                # issue the delete mutation again. This also repairs a crash
                # after successful deletion before receipt publication.
                for inspection in range(3):
                    reset = await self._run_activity("reset_terminal_conversation", {
                        "schema_version": 1, "subject_run_id": subject_id, "pdf_ordinal": ordinal,
                        "notebook_id": notebook_id, "conversation_id": conversation_id,
                    }, io_timeout)
                    if reset["outcome"] == "complete":
                        break
                    if inspection == 2:
                        raise RuntimeError("conversation_reset_uncertain")
                    await workflow.sleep(2)
                previous_conversation_id = conversation_id

        self.phase = "freezing_handoffs"
        return await self._run_activity("freeze_terminal_handoffs", {
            "schema_version": 1, "subject_run_id": subject_id, "pdf_processing": "per_pdf",
            "expected_pdf_count": self.total_pdfs, "expected_lesson_count": self.lessons_saved,
        }, control_timeout)

    async def _export_prompts_and_settle(
        self, subject_run_id: str, scene_keys: list[str], issue_count: int, control_timeout: timedelta
    ) -> dict[str, Any]:
        """Finish a NotebookLM-only run with a private prompt export and no scene-provider calls."""
        self.phase = "exporting_scene_prompts"
        exported = await self._run_activity(
            "export_terminal_scene_prompts",
            {"schema_version": 1, "subject_run_id": subject_run_id},
            control_timeout,
        )
        counts = exported.get("counts") or {}
        exported_count = int(counts.get("scene_count", -1))
        # The exporter revalidates every retained lesson and prompt. A parser
        # correction can recover scenes absent from the older frozen index.
        if exported_count < 1 or int(counts.get('lesson_count', -1)) != self.lessons_saved:
            raise RuntimeError("scene_prompt_export_incomplete")

        self.scene_prompts_exported = exported_count
        self.phase = "finalizing"
        self.status = "prompts_exported"
        final_report = await self._run_activity(
            "finalize_terminal_run",
            {"schema_version": 1, "subject_run_id": subject_run_id,
             "terminal_status": self.status, "scene_generation_mode": "export_prompts",
             "prompt_export_ref": exported.get("result_ref"), "prompt_export_count": exported_count},
            control_timeout,
        )
        await self._run_activity(
            "settle_terminal_child",
            {"schema_version": 1, "subject_run_id": subject_run_id, "terminal_status": self.status,
             "final_report_ref": final_report.get("result_ref")},
            control_timeout,
        )
        return {
            "schema_version": 1, "outcome": ActivityOutcome.complete.value, "safe_code": SafeCode.ok.value,
            "subject_run_id": subject_run_id, "status": self.status,
            "lessons_saved": self.lessons_saved, "scenes_generated": 0, "scenes_built": 0,
            "scene_prompts_exported": self.scene_prompts_exported,
            "prompt_export_ref": exported.get("result_ref"),
        }

    @workflow.run
    async def run(self, inp: dict[str, Any]) -> dict[str, Any]:
        try:
            return await self._run_impl(inp)
        except _ControlCancelled:
            return await self._cancelled_result(inp["subject_run_id"])

    async def _run_impl(self, inp: dict[str, Any]) -> dict[str, Any]:
        subject_run_id = inp["subject_run_id"]
        self.is_recovery = bool(inp.get("is_recovery"))
        subject_config = inp.get("subject_config") if isinstance(inp.get("subject_config"), dict) else {}
        protocol_text = subject_config.get("captured_notebooklm_protocol_json")
        protocol_sha = subject_config.get("captured_notebooklm_protocol_sha256")
        if protocol_text is not None:
            import hashlib
            if (not isinstance(protocol_text, str) or not isinstance(protocol_sha, str)
                    or hashlib.sha256(protocol_text.encode("utf-8")).hexdigest() != protocol_sha):
                raise RuntimeError("captured_notebooklm_protocol_invalid")
            try:
                protocol_bundle = json.loads(protocol_text)
            except ValueError:
                raise RuntimeError("captured_notebooklm_protocol_invalid") from None
            fields = {"divide_prompt", "execution_prompt", "lesson_start_prompt",
                      "lesson_continue_prompt", "combined_response_format"}
            if not isinstance(protocol_bundle, dict) or not fields.issubset(protocol_bundle):
                raise RuntimeError("captured_notebooklm_protocol_invalid")
            self.divide_prompt = protocol_bundle["divide_prompt"]
            self.execution_prompt = protocol_bundle["execution_prompt"]
            self.lesson_start_prompt = protocol_bundle["lesson_start_prompt"]
            self.lesson_continue_prompt = protocol_bundle["lesson_continue_prompt"]
            self.combined_response_format = protocol_bundle["combined_response_format"]
        self.scene_generation_mode = str(subject_config.get("scene_generation_mode", "generate"))
        self.pdf_processing = str(subject_config.get("pdf_processing", "joint"))
        run_ref = inp.get("run_ref", f"runs/{subject_run_id}")
        proof_notebook_id = inp.get("proof_notebook_id", "")
        sources = inp.get("sources", [])

        # Timeouts per contract
        control_timeout = timedelta(seconds=60)
        io_timeout = timedelta(seconds=360)
        build_timeout = timedelta(seconds=1500)

        # 1. Preflight initialization
        self.phase = "preflight"
        await self._run_activity(
            "initialize_terminal_run",
            {"schema_version": 1, "subject_run_id": subject_run_id, "run_ref": run_ref,
             "subject_config": inp.get("subject_config"), "mode": inp.get("mode", "new"),
             "is_recovery": bool(inp.get("is_recovery"))},
            control_timeout,
        )

        await self._run_activity(
            "register_terminal_child",
            {"schema_version": 1, "subject_run_id": subject_run_id, "run_ref": run_ref},
            control_timeout,
        )

        if inp.get("is_recovery"):
            original_run_id = inp.get("source_subject_run_id")
            scene_keys = inp.get("selected_scene_keys") or []
            recovery_mode = inp.get("mode")
            if not original_run_id or recovery_mode not in {"build", "generate"} or not scene_keys:
                raise RuntimeError("recovery input is incomplete")
            failure_code: str | None = None
            for scene_key in scene_keys:
                await workflow.wait_condition(lambda: not self.paused or self.cancelled)
                if self.cancelled:
                    return await self._cancelled_result(subject_run_id)
                try:
                    if recovery_mode == "generate":
                        generated = await self._run_activity(
                            "generate_terminal_scene",
                            {"schema_version": 1, "subject_run_id": subject_run_id, "scene_root_subject_id": original_run_id, "scene_key": scene_key},
                            io_timeout,
                        )
                        attempt = (generated.get("counts") or {}).get("attempt_ordinal", 1)
                        await self._run_activity(
                            "materialize_terminal_scene",
                            {"schema_version": 1, "subject_run_id": subject_run_id, "scene_root_subject_id": original_run_id, "scene_key": scene_key, "attempt_ordinal": attempt},
                            control_timeout,
                        )
                    else:
                        # Build recovery may start from a complete retained
                        # provider receipt whose source package was never
                        # materialized (for example, a crash after response
                        # persistence). Parse that receipt locally; never
                        # resend a prompt during a build-only recovery.
                        attempt = 1
                        await self._run_activity(
                            "materialize_terminal_scene",
                            {"schema_version": 1, "subject_run_id": subject_run_id,
                             "scene_root_subject_id": original_run_id, "scene_key": scene_key,
                             "attempt_ordinal": attempt},
                            control_timeout,
                        )
                    built = await self._run_activity(
                        "build_terminal_scene",
                        {"schema_version": 1, "subject_run_id": subject_run_id, "scene_root_subject_id": original_run_id, "scene_key": scene_key, "attempt_ordinal": attempt, "mode": "build"},
                        build_timeout,
                    )
                    self.scenes_built += 1
                except _ControlCancelled:
                    raise
                except Exception as exc:
                    # A recovery child must settle partial work instead of
                    # leaving the supervisor's recovery reservation active.
                    # Keep only a short, code-shaped identifier in workflow
                    # history; Activity/provider details remain in private logs.
                    candidate = str(exc).rsplit(": ", 1)[-1]
                    failure_code = candidate if candidate.replace("_", "").isalnum() else "recovery_operation_failed"
                    break

            self.status = "partial" if failure_code else "completed"
            final_report = await self._run_activity(
                "finalize_terminal_run",
                {"schema_version": 1, "subject_run_id": subject_run_id, "terminal_status": self.status},
                control_timeout,
            )
            await self._run_activity(
                "settle_terminal_child",
                {"schema_version": 1, "subject_run_id": subject_run_id, "terminal_status": self.status, "final_report_ref": final_report.get("result_ref"), "recovery_id": inp.get("recovery_id")},
                control_timeout,
            )
            return {
                "schema_version": 1,
                "outcome": ActivityOutcome.complete.value,
                "safe_code": failure_code or SafeCode.ok.value,
                "subject_run_id": subject_run_id,
                "status": self.status,
                "scenes_built": self.scenes_built,
            }

        mode = inp.get("mode", "new")
        if mode == "saved":
            # Direct saved path: skips all NotebookLM operations (FR-021 / A25)
            self.phase = "freezing_handoffs"
            handoff_path = inp.get("handoff_path") or inp.get("handoff_ref") or f"{run_ref}/handoff"
            freeze_res = await self._run_activity(
                "freeze_terminal_handoffs",
                {
                    "schema_version": 1,
                    "subject_run_id": subject_run_id,
                    "handoff_path": handoff_path,
                    "mode": "saved",
                },
                control_timeout,
            )
            scene_keys, _ = await self._list_scenes(subject_run_id, control_timeout)
            issue_count = freeze_res.get("counts", {}).get("issue_count", 0)

            if self.scene_generation_mode == "export_prompts":
                return await self._export_prompts_and_settle(subject_run_id, scene_keys, issue_count, control_timeout)

            # Scene generation & build loop
            self.phase = "generating_scenes"
            for sk in scene_keys:
                await workflow.wait_condition(lambda: not self.paused or self.cancelled)
                if self.cancelled:
                    return await self._cancelled_result(subject_run_id)

                try:
                    generated = await self._run_activity(
                        "generate_terminal_scene",
                        {"schema_version": 1, "subject_run_id": subject_run_id, "scene_key": sk},
                        io_timeout,
                    )
                    self.scenes_generated += 1

                    await self._run_activity(
                        "materialize_terminal_scene",
                        {"schema_version": 1, "subject_run_id": subject_run_id, "scene_key": sk, "attempt_ordinal": (generated.get("counts") or {}).get("attempt_ordinal", 1)},
                        control_timeout,
                    )

                    await self._run_activity(
                        "build_terminal_scene",
                        {"schema_version": 1, "subject_run_id": subject_run_id, "scene_key": sk, "attempt_ordinal": (generated.get("counts") or {}).get("attempt_ordinal", 1)},
                        build_timeout,
                    )
                    self.scenes_built += 1
                except _ControlCancelled:
                    raise
                except Exception:
                    self.status = "partial"

            # Finalize & Settle
            self.phase = "finalizing"
            terminal_status = (
                "completed"
                if (scene_keys and self.scenes_built == len(scene_keys) and issue_count == 0)
                else "partial"
            )
            final_report = await self._run_activity(
                "finalize_terminal_run",
                {"schema_version": 1, "subject_run_id": subject_run_id, "terminal_status": terminal_status},
                control_timeout,
            )
            await self._run_activity(
                "settle_terminal_child",
                {"schema_version": 1, "subject_run_id": subject_run_id, "terminal_status": terminal_status, "final_report_ref": final_report.get("result_ref")},
                control_timeout,
            )

            self.status = terminal_status
            return {
                "schema_version": 1,
                "outcome": ActivityOutcome.complete.value,
                "safe_code": SafeCode.ok.value,
                "subject_run_id": subject_run_id,
                "status": terminal_status,
                "lessons_saved": self.lessons_saved,
                "scenes_built": self.scenes_built,
            }

        # Pause fence check
        await workflow.wait_condition(lambda: not self.paused or self.cancelled)
        if self.cancelled:
            return await self._cancelled_result(subject_run_id)

        # 2. Verify Session
        self.phase = "verifying_session"
        await self._run_activity(
            "verify_notebook_session",
            {"schema_version": 1, "subject_run_id": subject_run_id, "proof_notebook_id": proof_notebook_id},
            task_queue="v3-terminal-control",
            start_to_close_timeout=io_timeout,
        )

        # 3. Create Notebook
        self.phase = "creating_notebook"
        nb_res = await self._run_activity(
            "create_terminal_notebook",
            {"schema_version": 1, "subject_run_id": subject_run_id, "title": f"V3 Mechanics {subject_run_id}"},
            task_queue="v3-terminal-control",
            start_to_close_timeout=io_timeout,
        )
        notebook_id = nb_res.get("result_ref")
        if not notebook_id:
            raise RuntimeError("create_terminal_notebook returned no notebook identity")

        if self.pdf_processing == "per_pdf":
            freeze_res = await self._run_per_pdf_notebook(inp, notebook_id, control_timeout, io_timeout)
        else:
            # 4. Upload Sources
            self.phase = "uploading_sources"
            uploaded_source_ids: list[str] = []
            pdf_ids: list[str] = []
            framework_id: str | None = None

            for src in sources:
                up_res = await self._run_activity(
                    "upload_terminal_source",
                    {
                        "schema_version": 1,
                        "subject_run_id": subject_run_id,
                        "notebook_id": notebook_id,
                        "source_path": src.get("path", ""),
                        "mime_type": src.get("mime_type", "application/pdf"),
                        "title": src.get("title", "source"),
                    },
                    task_queue="v3-terminal-control",
                    start_to_close_timeout=io_timeout,
                )
                src_id = up_res.get("result_ref", f"src-{len(uploaded_source_ids)+1}")
                uploaded_source_ids.append(src_id)
                if src.get("role") == "educational_pdf":
                    pdf_ids.append(src_id)
                else:
                    framework_id = src_id

            # 5. Poll Sources until ready
            self.phase = "polling_sources"
            poll_res = None
            for poll_ordinal in range(1, 41):
                poll_res = await self._run_activity(
                    "poll_terminal_sources",
                    {
                        "schema_version": 1,
                        "subject_run_id": subject_run_id,
                        "notebook_id": notebook_id,
                        "poll_ordinal": poll_ordinal,
                    },
                    io_timeout,
                    allow_pending=True,
                )
                if poll_res.get("outcome") == ActivityOutcome.complete.value:
                    break
                await workflow.sleep(timedelta(seconds=15))
            if not poll_res or poll_res.get("outcome") != ActivityOutcome.complete.value:
                raise RuntimeError("NotebookLM source readiness deadline exceeded")

            # 6. Configure Chat
            self.phase = "configuring_chat"
            await self._run_activity(
                "configure_terminal_chat",
                {
                    "schema_version": 1,
                    "subject_run_id": subject_run_id,
                    "notebook_id": notebook_id,
                    "format_text": self.combined_response_format,
                },
                task_queue="v3-terminal-control",
                start_to_close_timeout=io_timeout,
            )

            # Pause fence check
            await workflow.wait_condition(lambda: not self.paused or self.cancelled)
            if self.cancelled:
                return await self._cancelled_result(subject_run_id)
            if self.cancelled:
                return await self._cancelled_result(subject_run_id)

            # 7. Divide Turn (PDFs only)
            self.phase = "dividing"
            divide_turn = await self._run_activity(
                "send_terminal_turn",
                {
                    "schema_version": 1,
                    "subject_run_id": subject_run_id,
                    "notebook_id": notebook_id,
                    "question": self.divide_prompt,
                    "source_ids": pdf_ids or uploaded_source_ids,
                    "operation_id": "divide",
                },
                task_queue="v3-terminal-control",
                start_to_close_timeout=io_timeout,
            )
            divide_captured = await self._run_activity(
                "capture_terminal_turn",
                {
                    "schema_version": 1,
                    "subject_run_id": subject_run_id,
                    "turn_kind": "divide",
                    "receipt_ref": divide_turn.get("receipt_ref"),
                    "notebook_id": notebook_id,
                    "conversation_id": divide_turn.get("result_ref"),
                },
                task_queue="v3-terminal-control",
                start_to_close_timeout=control_timeout,
            )
            work_count = (divide_captured.get("counts") or {}).get("work_count", 2)

            # 8. Execution Turn (All sources)
            self.phase = "executing"
            exec_turn = await self._run_activity(
                "send_terminal_turn",
                {
                    "schema_version": 1,
                    "subject_run_id": subject_run_id,
                    "notebook_id": notebook_id,
                    "question": self.execution_prompt,
                    "source_ids": uploaded_source_ids,
                    "operation_id": "execution",
                    "conversation_id": divide_turn.get("result_ref"),
                },
                task_queue="v3-terminal-control",
                start_to_close_timeout=io_timeout,
            )
            await self._run_activity(
                "capture_terminal_turn",
                {
                    "schema_version": 1,
                    "subject_run_id": subject_run_id,
                    "turn_kind": "execution",
                    "receipt_ref": exec_turn.get("receipt_ref"),
                    "notebook_id": notebook_id,
                    "conversation_id": exec_turn.get("result_ref"),
                },
                task_queue="v3-terminal-control",
                start_to_close_timeout=control_timeout,
            )

            # 9. Lessons loop (1..work_count)
            self.phase = "generating_lessons"
            for ordinal in range(1, work_count + 1):
                await workflow.wait_condition(lambda: not self.paused or self.cancelled)
                if self.cancelled:
                    return await self._cancelled_result(subject_run_id)
                if self.cancelled:
                    return await self._cancelled_result(subject_run_id)

                question = self.lesson_start_prompt if ordinal == 1 else self.lesson_continue_prompt
                les_turn = await self._run_activity(
                    "send_terminal_turn",
                    {
                        "schema_version": 1,
                        "subject_run_id": subject_run_id,
                        "notebook_id": notebook_id,
                        "question": question,
                        "source_ids": uploaded_source_ids,
                        "operation_id": f"lesson-{ordinal}",
                        "conversation_id": exec_turn.get("result_ref"),
                    },
                    task_queue="v3-terminal-control",
                    start_to_close_timeout=io_timeout,
                )
                # CRITICAL: lesson turn captured and saved before next send!
                await self._run_activity(
                    "capture_terminal_turn",
                    {
                        "schema_version": 1,
                        "subject_run_id": subject_run_id,
                        "turn_kind": "lesson",
                        "lesson_ordinal": ordinal,
                        "mapped_title": f"Lesson {ordinal}",
                        "receipt_ref": les_turn.get("receipt_ref"),
                        "notebook_id": notebook_id,
                        "conversation_id": les_turn.get("result_ref"),
                        "pdf_source_ids": pdf_ids,
                        "framework_source_id": framework_id,
                    },
                    task_queue="v3-terminal-control",
                    start_to_close_timeout=control_timeout,
                )
                self.lessons_saved += 1

            # 10. Freeze handoffs barrier
            self.phase = "freezing_handoffs"
            freeze_res = await self._run_activity(
                "freeze_terminal_handoffs",
                {
                    "schema_version": 1,
                    "subject_run_id": subject_run_id,
                    "handoff_dir": f"{run_ref}/lesson_handoffs",
                    "expected_lesson_count": work_count,
                },
                task_queue="v3-terminal-control",
                start_to_close_timeout=control_timeout,
            )

        # Discovered scene keys
        scene_keys, _ = await self._list_scenes(subject_run_id, control_timeout)
        issue_count = freeze_res.get("counts", {}).get("issue_count", 0)

        if self.scene_generation_mode == "export_prompts":
            return await self._export_prompts_and_settle(subject_run_id, scene_keys, issue_count, control_timeout)

        # 11. Scene generation & build loop
        self.phase = "generating_scenes"
        for sk in scene_keys:
            await workflow.wait_condition(lambda: not self.paused or self.cancelled)
            if self.cancelled:
                return await self._cancelled_result(subject_run_id)
            if self.cancelled:
                return await self._cancelled_result(subject_run_id)

            try:
                generated = await self._run_activity(
                    "generate_terminal_scene",
                    {"schema_version": 1, "subject_run_id": subject_run_id, "scene_key": sk},
                    task_queue="v3-terminal-control",
                    start_to_close_timeout=io_timeout,
                )
                self.scenes_generated += 1

                await self._run_activity(
                    "materialize_terminal_scene",
                    {"schema_version": 1, "subject_run_id": subject_run_id, "scene_key": sk, "attempt_ordinal": (generated.get("counts") or {}).get("attempt_ordinal", 1)},
                    task_queue="v3-terminal-control",
                    start_to_close_timeout=control_timeout,
                )

                await self._run_activity(
                    "build_terminal_scene",
                    {"schema_version": 1, "subject_run_id": subject_run_id, "scene_key": sk, "attempt_ordinal": (generated.get("counts") or {}).get("attempt_ordinal", 1)},
                    task_queue="v3-terminal-control",
                    start_to_close_timeout=build_timeout,
                )
                self.scenes_built += 1
            except _ControlCancelled:
                raise
            except Exception:
                self.status = "partial"

        # 12. Finalize & Settle
        self.phase = "finalizing"
        terminal_status = (
            "completed"
            if (scene_keys and self.scenes_built == len(scene_keys) and issue_count == 0)
            else "partial"
        )
        final_report = await self._run_activity(
            "finalize_terminal_run",
            {"schema_version": 1, "subject_run_id": subject_run_id, "terminal_status": terminal_status},
            task_queue="v3-terminal-control",
            start_to_close_timeout=control_timeout,
        )

        await self._run_activity(
            "settle_terminal_child",
            {"schema_version": 1, "subject_run_id": subject_run_id, "terminal_status": terminal_status, "final_report_ref": final_report.get("result_ref")},
            task_queue="v3-terminal-control",
            start_to_close_timeout=control_timeout,
        )

        self.status = terminal_status
        return {
            "schema_version": 1,
            "outcome": ActivityOutcome.complete.value,
            "safe_code": SafeCode.ok.value,
            "subject_run_id": subject_run_id,
            "status": terminal_status,
            "lessons_saved": self.lessons_saved,
            "scenes_built": self.scenes_built,
        }

    async def _cancelled_result(self, subject_run_id: str) -> dict[str, Any]:
        self.status = "cancelled"
        if not self.is_recovery:
            timeout = timedelta(seconds=60)
            await self._run_activity("finalize_terminal_run", {
                "schema_version": 1, "subject_run_id": subject_run_id, "terminal_status": "cancelled",
            }, timeout)
            await self._run_activity("settle_terminal_child", {
                "schema_version": 1, "subject_run_id": subject_run_id, "terminal_status": "cancelled",
            }, timeout)
        return {
            "schema_version": 1,
            "outcome": ActivityOutcome.failed.value,
            "safe_code": SafeCode.build_cancelled.value,
            "subject_run_id": subject_run_id,
            "lessons_saved": self.lessons_saved,
            "scenes_built": self.scenes_built,
        }
