"""ApplicationSceneWorkflow implementation for Web Pipeline (Spec 019 / T041)."""

from __future__ import annotations

import asyncio
import hashlib
import json
from datetime import timedelta
from pathlib import Path
from typing import Any, Callable, Coroutine
from temporalio import workflow

with workflow.unsafe.imports_passed_through():
    from temporalio.common import RetryPolicy


@workflow.defn(name="ApplicationSceneWorkflow")
class ApplicationSceneWorkflow:
    def __init__(self) -> None:
        self.phase: str = "scene_generation"
        self.status: str = "running"
        self.scenes_generated: int = 0
        self.scenes_built: int = 0
        self.scenes_failed: int = 0
        self.paused: bool = False
        self.cancelled: bool = False
        self.state_version: int = 1
        self.command_receipts: dict[str, dict[str, Any]] = {}
        self.mode: str = "selected_api"
        self._activity_executor: Callable[[str, dict[str, Any]], Coroutine[Any, Any, dict[str, Any]]] | None = None

    @workflow.query(name="progress_v1")
    def progress_v1(self) -> dict[str, Any]:
        """Bounded scene progress query."""
        return {
            "phase": self.phase,
            "status": self.status,
            "scenes_generated": self.scenes_generated,
            "scenes_built": self.scenes_built,
            "scenes_failed": self.scenes_failed,
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

        if cmd_id in self.command_receipts:
            existing = self.command_receipts[cmd_id]
            if existing.get("digest") == cmd_digest:
                return existing

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

    async def _execute_act(self, name: str, args: dict[str, Any], task_queue: str = "v3-terminal-control") -> dict[str, Any]:
        if self._activity_executor is not None:
            return await self._activity_executor(name, args)
        return await workflow.execute_activity(
            name,
            args,
            task_queue=task_queue,
            start_to_close_timeout=timedelta(minutes=10),
            retry_policy=RetryPolicy(
                maximum_attempts=1,
            ),
        )

    @workflow.run
    async def run(self, input_doc: dict[str, Any]) -> dict[str, Any]:
        run_id = input_doc.get("run_id", "")
        scenes = input_doc.get("scenes", [])
        captured_settings = input_doc.get("captured_settings", {})
        self.mode = captured_settings.get("mode", "selected_api")

        if self.mode == "export_manual":
            self.phase = "export_complete"
            self.status = "prompts_ready"
            return {
                "outcome": "completed",
                "safe_code": "ok",
                "run_id": run_id,
                "scenes_generated": 0,
                "scenes_built": 0,
            }

        # Bounded page of scenes
        for scene_info in scenes:
            if self.cancelled:
                self.status = "cancelled"
                return {"outcome": "cancelled", "safe_code": "cancelled", "run_id": run_id}

            while self.paused and not self.cancelled:
                await workflow.wait_condition(lambda: not self.paused or self.cancelled)

            if self.cancelled:
                self.status = "cancelled"
                return {"outcome": "cancelled", "safe_code": "cancelled", "run_id": run_id}

            scene_args = {
                "run_id": run_id,
                "lesson_ordinal": scene_info.get("lesson_ordinal", 1),
                "scene_ordinal": scene_info.get("scene_ordinal", 1),
                "notebook_id": scene_info.get("notebook_id", "nb-app"),
                "conversation_id": scene_info.get("conversation_id", "conv-app"),
                "prompt": scene_info.get("prompt", ""),
                "prompt_sha256": scene_info.get("prompt_sha256"),
                "lesson_title": scene_info.get("lesson_title", "Lesson"),
                "section_title": scene_info.get("section_title", "Scene"),
                "captured_settings": captured_settings,
            }

            # 1. Generate scene source (IO queue)
            gen_res = await self._execute_act(
                "execute_scene_generation_activity",
                scene_args,
                task_queue="v3-terminal-io",
            )

            if gen_res.get("outcome") != "completed":
                self.scenes_failed += 1
                self.state_version += 1
                continue

            self.scenes_generated += 1
            self.state_version += 1

            # 2. Build scene (Build queue)
            build_args = dict(scene_args)
            build_args["attempt_ordinal"] = gen_res.get("attempt_ordinal", 1)

            build_res = await self._execute_act(
                "execute_scene_build_activity",
                build_args,
                task_queue="v3-terminal-build",
            )

            if build_res.get("outcome") != "completed":
                self.scenes_failed += 1
            else:
                self.scenes_built += 1
                manifest_path = build_res.get("manifest_path")
                if manifest_path:
                    try:
                        p = Path(manifest_path)
                        if p.exists():
                            manifest_data = json.loads(p.read_text(encoding="utf-8"))
                            manifest_sha = hashlib.sha256(p.read_bytes()).hexdigest()
                            await self._execute_act(
                                "execute_browser_checks_activity",
                                {
                                    "candidate_dir": str(p.parent),
                                    "candidate_ref": f"{scene_args.get('notebook_id')}:{scene_args.get('conversation_id')}:W{scene_args.get('lesson_ordinal')}:S{scene_args.get('scene_ordinal')}",
                                    "manifest": manifest_data,
                                    "manifest_sha256": manifest_sha,
                                },
                                task_queue="v3-terminal-build",
                            )
                    except Exception:
                        pass
            self.state_version += 1

        self.phase = "complete"
        self.status = "scenes_ready"
        return {
            "outcome": "completed",
            "safe_code": "ok",
            "run_id": run_id,
            "scenes_generated": self.scenes_generated,
            "scenes_built": self.scenes_built,
            "scenes_failed": self.scenes_failed,
        }
