"""ApplicationSubjectWorkflow implementation for Web Pipeline (Spec 019 / T028)."""

from __future__ import annotations

import asyncio
from datetime import timedelta
from typing import Any, Callable, Coroutine
from temporalio import workflow

with workflow.unsafe.imports_passed_through():
    from v3_engine.web_pipeline.contracts import SAFE_CODES
    from temporalio.common import RetryPolicy


@workflow.defn(name="ApplicationSubjectWorkflow")
class ApplicationSubjectWorkflow:
    def __init__(self) -> None:
        self.phase: str = "preflight"
        self.status: str = "staging"
        self.generation_phase: str = "initialized"
        self.lessons_saved: int = 0
        self.prompts_saved: int = 0
        self.scenes_generated: int = 0
        self.scenes_built: int = 0
        self.pdfs_completed: int = 0
        self.pdfs_failed: int = 0
        self.total_pdfs: int = 0
        self.current_pdf_ordinal: int | None = None
        self.paused: bool = False
        self.cancelled: bool = False
        self.state_version: int = 1
        self.command_receipts: dict[str, dict[str, Any]] = {}
        self.mode: str = "export_manual"
        self._activity_executor: Callable[[str, dict[str, Any]], Coroutine[Any, Any, dict[str, Any]]] | None = None

    @workflow.query(name="progress_v1")
    def progress_v1(self) -> dict[str, Any]:
        """Bounded current progress projection."""
        return {
            "phase": self.phase,
            "status": self.status,
            "generation_phase": self.generation_phase,
            "lessons_saved": self.lessons_saved,
            "prompts_saved": self.prompts_saved,
            "scenes_generated": self.scenes_generated,
            "scenes_built": self.scenes_built,
            "pdfs_completed": self.pdfs_completed,
            "pdfs_failed": self.pdfs_failed,
            "total_pdfs": self.total_pdfs,
            "current_pdf_ordinal": self.current_pdf_ordinal,
            "paused": self.paused,
            "cancelled": self.cancelled,
            "state_version": self.state_version,
            "mode": self.mode,
        }

    @workflow.query(name="command_v1")
    def command_v1(self, command_id: str) -> dict[str, Any] | None:
        return self.command_receipts.get(command_id)

    @workflow.update(name="control_v1")
    def control_v1(self, cmd: dict[str, Any]) -> dict[str, Any]:
        action = cmd.get("action", "")
        cmd_id = cmd.get("command_id", "")
        cmd_digest = cmd.get("command_digest", "")

        # Duplicate check
        if cmd_id in self.command_receipts:
            existing = self.command_receipts[cmd_id]
            if existing.get("digest") == cmd_digest:
                return existing
            return {
                "outcome": "rejected",
                "safe_code": "control_conflict",
                "command_id": cmd_id,
            }

        if ((action == "pause" and self.paused) or (action == "resume" and not self.paused)
                or (action == "cancel" and self.cancelled)
                or action not in {"pause", "resume", "cancel"}):
            return {
                "outcome": "rejected",
                "safe_code": "control_conflict",
                "command_id": cmd_id,
            }

        receipt = {
            "outcome": "applied",
            "command_id": cmd_id,
            "action": action,
            "digest": cmd_digest,
            "state_version": self.state_version + 1,
        }
        self.state_version += 1

        if action == "pause":
            self.paused = True
            self.status = "paused"
        elif action == "resume":
            self.paused = False
            self.status = "running"
        elif action == "cancel":
            self.cancelled = True
            self.status = "cancelled"

        self.command_receipts[cmd_id] = receipt
        return receipt

    async def _wait_for_control(self) -> bool:
        """Stop at workflow boundaries until a pause is resumed or cancelled."""
        if self.cancelled:
            self.status = "cancelled"
            return False
        if self.paused:
            self.status = "paused"
            await workflow.wait_condition(lambda: not self.paused or self.cancelled)
        if self.cancelled:
            self.status = "cancelled"
            return False
        self.status = "running"
        return True

    async def _execute_act(self, name: str, args: dict[str, Any]) -> dict[str, Any]:
        if self._activity_executor is not None:
            return await self._activity_executor(name, args)
        control_activities = {
            "resolve_application_run_activity",
            "prepare_application_item_activity",
            "prepare_application_framework_activity",
            "reset_conversation_activity",
            "export_prompts_activity",
        }
        task_queue = "v3-terminal-control" if name in control_activities else "v3-terminal-io"
        return await workflow.execute_activity(
            name,
            args,
            task_queue=task_queue,
            start_to_close_timeout=timedelta(seconds=120),
            retry_policy=RetryPolicy(maximum_attempts=1),
        )

    @workflow.run
    async def run(self, manifest: dict[str, Any]) -> dict[str, Any]:
        self.status = "running"
        self.phase = "source_setup"
        run_id = manifest.get("run_id")
        if manifest.get("manifest_ref"):
            resolved = await self._execute_act("resolve_application_run_activity", {
                "run_id": run_id,
                "manifest_ref": manifest.get("manifest_ref"),
            })
            if resolved.get("outcome") != "completed":
                self.status = "failed"
                return {"outcome": resolved.get("outcome", "failed"), "safe_code": resolved.get("safe_code", "artifact_missing")}
            items = resolved.get("items", [])
            manifest = {
                "run_id": run_id,
                "manifest_ref": manifest.get("manifest_ref"),
                "mode": resolved.get("mode", "export_manual"),
                "framework_version_id": resolved.get("framework_version_id"),
                "items": items,
            }
        else:
            # Direct input is retained for focused workflow unit tests. Bridge
            # production admission always sends only manifest_ref/run_id.
            items = manifest.get("items", [])
        self.mode = manifest.get("mode", "export_manual")
        self.total_pdfs = len(items)

        if self.cancelled:
            self.status = "cancelled"
            return {"outcome": "cancelled", "safe_code": "cancelled"}

        private_manifest = bool(manifest.get("manifest_ref"))
        if private_manifest:
            prep = await self._execute_act("prepare_application_framework_activity", {"run_id": run_id})
            if prep.get("outcome") != "completed":
                self.status = "failed"
                return {"outcome": prep.get("outcome", "failed"), "safe_code": prep.get("safe_code", "artifact_missing")}
            setup = await self._execute_act("ensure_web_notebook_activity", {"run_id": run_id})
            if setup.get("outcome") != "completed":
                self.status = "paused_auth" if setup.get("safe_code") == "authentication_required" else "failed"
                return {"outcome": setup.get("outcome", "failed"), "safe_code": setup.get("safe_code", "unknown")}
            upload_framework = await self._execute_act("upload_web_source_activity", {
                "run_id": run_id,
                "item_id": None,
            })
            if upload_framework.get("outcome") != "completed":
                self.status = "failed"
                return {"outcome": upload_framework.get("outcome", "failed"), "safe_code": upload_framework.get("safe_code", "source_error")}
            configure = await self._execute_act("configure_web_notebook_activity", {"run_id": run_id})
            if configure.get("outcome") != "completed":
                self.status = "failed"
                return {"outcome": configure.get("outcome", "failed"), "safe_code": configure.get("safe_code", "configuration_drift")}

        # 1. Process each PDF in order
        for item in items:
            if not await self._wait_for_control():
                return {"outcome": "cancelled", "safe_code": "cancelled"}

            ordinal = item.get("pdf_ordinal", 1)
            self.current_pdf_ordinal = ordinal

            if private_manifest:
                item_id = str(item.get("item_id", ""))
                intake = await self._execute_act("prepare_application_item_activity", {
                    "run_id": run_id,
                    "item_id": item_id,
                })
                if intake.get("outcome") != "completed":
                    self.pdfs_failed += 1
                    self.status = "partial"
                    continue
                upload_pdf = await self._execute_act("upload_web_source_activity", {
                    "run_id": run_id,
                    "item_id": item_id,
                })
                if upload_pdf.get("outcome") != "completed":
                    self.pdfs_failed += 1
                    self.status = "partial"
                    continue
                ready = False
                for check_no in range(60):
                    ready_res = await self._execute_act("check_web_sources_activity", {
                        "run_id": run_id,
                        "item_id": item_id,
                    })
                    if ready_res.get("outcome") == "completed":
                        ready = True
                        break
                    if ready_res.get("outcome") != "pending":
                        self.pdfs_failed += 1
                        self.status = "partial"
                        break
                    if check_no < 59:
                        await workflow.sleep(5)
                if not ready:
                    if self.pdfs_failed == 0:
                        self.pdfs_failed += 1
                    self.status = "partial"
                    continue
                pdf_source_id = None
                framework_source_id = None
            else:
                pdf_source_id = item.get("source_ref")
                framework_source_id = manifest.get("framework_ref")

            if not await self._wait_for_control():
                return {"outcome": "cancelled", "safe_code": "cancelled"}

            # Divide turn: uses that PDF ALONE, excluding framework
            self.phase = "mapping"
            divide_res = await self._execute_act("execute_divide_turn", {
                "run_id": manifest.get("run_id"),
                "item_id": item.get("item_id"),
                "pdf_ordinal": ordinal,
                **({} if private_manifest else {"source_ids": [pdf_source_id]}),
            })

            # A control accepted during the Divide call takes effect before any
            # lesson prompt is sent for this PDF.
            if not await self._wait_for_control():
                return {"outcome": "cancelled", "safe_code": "cancelled"}

            # Check for shared errors (auth, quota, uncertainty, storage)
            safe_code = divide_res.get("safe_code", "ok")
            if safe_code in ("authentication_required", "account_mismatch"):
                self.status = "paused_auth"
                return {"outcome": "paused_auth", "safe_code": safe_code}
            if safe_code in ("provider_rate_limit", "provider_unavailable"):
                self.status = "paused_quota"
                return {"outcome": "paused_quota", "safe_code": safe_code}
            if safe_code in ("delivery_uncertain", "storage_unavailable") or divide_res.get("outcome") == "uncertain":
                self.status = "held_uncertain" if ("uncertain" in safe_code or divide_res.get("outcome") == "uncertain") else "held_persistence"
                return {"outcome": divide_res.get("outcome", "failed"), "safe_code": safe_code}

            # Check PDF-local failure (map_unreadable, source_error)
            if divide_res.get("outcome") != "completed":
                self.pdfs_failed += 1
                # A PDF-local failure may advance only after the isolation
                # barrier has actually been verified.
                reset = await self._execute_act("reset_conversation_activity", {
                    "run_id": manifest.get("run_id"),
                    "pdf_ordinal": ordinal,
                })
                if reset.get("outcome") != "completed":
                    self.status = "failed"
                    return {"outcome": "failed", "safe_code": reset.get("safe_code", "reset_uncertain")}
                continue

            # Lesson turns: use that PDF + framework
            self.phase = "lesson_loop"
            if not await self._wait_for_control():
                return {"outcome": "cancelled", "safe_code": "cancelled"}
            lesson_res = await self._execute_act("execute_lesson_turns", {
                "run_id": manifest.get("run_id"),
                "item_id": item.get("item_id"),
                "pdf_ordinal": ordinal,
                **({} if private_manifest else {
                    "source_ids": [pdf_source_id, framework_source_id],
                    "map": divide_res.get("map"),
                    "conversation_id": divide_res.get("conversation_id"),
                }),
            })

            if lesson_res.get("outcome") != "completed":
                safe_code = lesson_res.get("safe_code", "ok")
                if safe_code in ("authentication_required", "account_mismatch"):
                    self.status = "paused_auth"
                    return {"outcome": "paused_auth", "safe_code": safe_code}
                if safe_code in ("provider_rate_limit", "provider_unavailable"):
                    self.status = "paused_quota"
                    return {"outcome": "paused_quota", "safe_code": safe_code}
                if safe_code in ("delivery_uncertain", "storage_unavailable") or lesson_res.get("outcome") == "uncertain":
                    self.status = "held_uncertain" if ("uncertain" in safe_code or lesson_res.get("outcome") == "uncertain") else "held_persistence"
                    return {"outcome": lesson_res.get("outcome", "failed"), "safe_code": safe_code}

                self.pdfs_failed += 1
                reset = await self._execute_act("reset_conversation_activity", {
                    "run_id": manifest.get("run_id"),
                    "pdf_ordinal": ordinal,
                })
                if reset.get("outcome") != "completed":
                    self.status = "failed"
                    return {"outcome": "failed", "safe_code": reset.get("safe_code", "reset_uncertain")}
                continue

            counts = lesson_res.get("counts") or {}
            self.lessons_saved += counts.get("lessons_saved", lesson_res.get("lessons_saved", 0))
            self.prompts_saved += counts.get("prompts_saved", lesson_res.get("prompts_saved", 0))

            # Archive PDF and reset conversation barrier
            self.phase = "archiving"
            reset = await self._execute_act("reset_conversation_activity", {
                "run_id": manifest.get("run_id"),
                "pdf_ordinal": ordinal,
                **({} if private_manifest else {
                    "conversation_id": lesson_res.get("conversation_id") or divide_res.get("conversation_id"),
                }),
            })
            if reset.get("outcome") != "completed":
                self.status = "failed"
                return {"outcome": "failed", "safe_code": reset.get("safe_code", "reset_uncertain")}
            self.pdfs_completed += 1

        # 2. Finalization & Prompt Export
        self.phase = "prompts_ready"
        self.generation_phase = "prompts_exported"
        export_res = await self._execute_act("export_prompts_activity", {
            "run_id": manifest.get("run_id"),
        })
        if export_res.get("outcome") != "completed":
            self.status = "partial" if self.pdfs_completed else "failed"
            return {
                "outcome": "partial" if self.pdfs_completed else "failed",
                "safe_code": export_res.get("safe_code", "context_incomplete"),
                "pdfs_completed": self.pdfs_completed,
                "pdfs_failed": self.pdfs_failed,
                "lessons_saved": self.lessons_saved,
                "prompts_saved": self.prompts_saved,
            }

        if self.pdfs_completed == 0:
            self.status = "failed"
            return {
                "outcome": "failed",
                "safe_code": "all_inputs_failed",
                "pdfs_completed": 0,
                "pdfs_failed": self.pdfs_failed,
                "prompts_saved": 0,
                "lessons_saved": 0,
            }

        if self.mode == "export_manual":
            # Manual mode stops at export with zero scene API requests
            self.status = "partial" if self.pdfs_failed > 0 else "completed"
            return {
                "outcome": "partial" if self.pdfs_failed > 0 else "prompts_exported",
                "safe_code": "ok",
                "pdfs_completed": self.pdfs_completed,
                "pdfs_failed": self.pdfs_failed,
                "prompts_saved": self.prompts_saved,
                "lessons_saved": self.lessons_saved,
            }

        # API generate mode: handle scene generation if selected
        self.phase = "scene_processing"
        self.status = "partial" if self.pdfs_failed > 0 else "completed"
        return {
            "outcome": "partial" if self.pdfs_failed > 0 else "completed",
            "safe_code": "ok",
            "pdfs_completed": self.pdfs_completed,
            "pdfs_failed": self.pdfs_failed,
            "prompts_saved": self.prompts_saved,
            "lessons_saved": self.lessons_saved,
        }
