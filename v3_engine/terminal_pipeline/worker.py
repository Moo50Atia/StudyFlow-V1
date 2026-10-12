"""Temporal Worker architecture managing control, IO, and build queues with process exclusivity (Spec 018 / T013)."""

from __future__ import annotations

import asyncio
from concurrent.futures import ThreadPoolExecutor
import fcntl
import logging
import os
import signal
from pathlib import Path
from typing import Sequence
from temporalio.client import Client
from temporalio.worker import Worker

from v3_engine.terminal_pipeline.activities import (
    build_terminal_scene,
    admit_terminal_recovery,
    admit_terminal_submission,
    archive_terminal_pdf,
    reset_terminal_conversation,
    capture_terminal_turn,
    configure_terminal_chat,
    create_terminal_notebook,
    finalize_terminal_run,
    freeze_terminal_handoffs,
    export_terminal_scene_prompts,
    generate_terminal_scene,
    initialize_terminal_run,
    materialize_terminal_scene,
    poll_terminal_sources,
    page_terminal_scenes,
    reconcile_terminal_operation,
    register_terminal_child,
    send_terminal_turn,
    settle_terminal_child,
    upload_terminal_source,
    verify_notebook_session,
)
from v3_engine.terminal_pipeline.subject_workflow import TerminalSubjectWorkflow
from v3_engine.terminal_pipeline.supervisor import TerminalSupervisorWorkflow

logger = logging.getLogger("v3_engine.terminal_pipeline.worker")


class TerminalWorkerLockError(RuntimeError):
    pass


class TerminalWorkerProcessLock:
    def __init__(self, lock_path: Path):
        self.lock_path = lock_path
        self._fd: int | None = None

    def acquire(self) -> None:
        self.lock_path.parent.mkdir(parents=True, exist_ok=True)
        self._fd = os.open(str(self.lock_path), os.O_CREAT | os.O_RDWR, 0o600)
        try:
            fcntl.flock(self._fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except (BlockingIOError, OSError) as exc:
            os.close(self._fd)
            self._fd = None
            raise TerminalWorkerLockError(
                f"Another terminal worker instance is already running on {self.lock_path}"
            ) from exc

    def release(self) -> None:
        if self._fd is not None:
            try:
                fcntl.flock(self._fd, fcntl.LOCK_UN)
                os.close(self._fd)
            except OSError:
                pass
            self._fd = None


async def run_workers(
    client: Client,
    lock_path: Path | None = None,
    stop_event: asyncio.Event | None = None,
) -> None:
    """Run the 3 worker instances (control, IO, build) with concurrency bounds and process lock."""
    if lock_path is None:
        lock_path = Path("/home/moahmmed/college_project/storage/app/private/v3/temporal/.worker.lock")

    lock = TerminalWorkerProcessLock(lock_path)
    lock.acquire()

    # A few filesystem/import activities in the shared control queue are sync
    # functions. Temporal refuses to construct a worker that registers sync
    # activities without an executor; keep them off the event loop while still
    # running the existing async terminal activities normally.
    activity_executor = ThreadPoolExecutor(
        max_workers=4,
        thread_name_prefix="v3-terminal-control",
    )

    try:
        # Control Worker (workflows & metadata/local activities)
        control_activities = [
            initialize_terminal_run,
            admit_terminal_recovery,
            admit_terminal_submission,
            register_terminal_child,
            settle_terminal_child,
            capture_terminal_turn,
            archive_terminal_pdf,
            freeze_terminal_handoffs,
            export_terminal_scene_prompts,
            page_terminal_scenes,
            materialize_terminal_scene,
            finalize_terminal_run,
        ]
        # Declare the queues before registering optional web-pipeline activities.
        # Those activities are routed to the IO/build workers below.
        io_activities = [
            verify_notebook_session,
            create_terminal_notebook,
            upload_terminal_source,
            poll_terminal_sources,
            configure_terminal_chat,
            send_terminal_turn,
            reset_terminal_conversation,
            reconcile_terminal_operation,
            generate_terminal_scene,
        ]
        build_activities = [build_terminal_scene]

        # Register web pipeline workflows and activities
        control_workflows = [TerminalSupervisorWorkflow, TerminalSubjectWorkflow]
        try:
            from v3_engine.web_pipeline.import_workflow import ApplicationImportWorkflow
            from v3_engine.web_pipeline.import_activities import (
                execute_terminal_import_activity,
            )
            control_workflows.append(ApplicationImportWorkflow)
            control_activities.append(execute_terminal_import_activity)
        except ImportError as e:
            raise RuntimeError(f"Failed to register ApplicationImportWorkflow: {e}") from e

        try:
            from v3_engine.web_pipeline.subject_workflow import ApplicationSubjectWorkflow
            from v3_engine.web_pipeline.review_workflow import WebReviewWorkflow
            from v3_engine.web_pipeline.activities import (
                resolve_application_run_activity,
                prepare_application_item_activity,
                prepare_application_framework_activity,
                ensure_web_notebook_activity,
                upload_web_source_activity,
                configure_web_notebook_activity,
                check_web_sources_activity,
                execute_divide_turn,
                execute_lesson_turns,
                reset_conversation_activity,
                export_prompts_activity,
            )
            control_workflows.append(ApplicationSubjectWorkflow)
            control_workflows.append(WebReviewWorkflow)
            control_activities.extend([
                resolve_application_run_activity,
                prepare_application_item_activity,
                prepare_application_framework_activity,
                reset_conversation_activity,
                export_prompts_activity,
            ])
            io_activities.extend([
                ensure_web_notebook_activity,
                upload_web_source_activity,
                configure_web_notebook_activity,
                check_web_sources_activity,
                execute_divide_turn,
                execute_lesson_turns,
            ])
        except ImportError as e:
            raise RuntimeError(f"Failed to register ApplicationSubjectWorkflow: {e}") from e

        try:
            from v3_engine.web_pipeline.scene_workflow import ApplicationSceneWorkflow
            from v3_engine.web_pipeline.scene_activities import (
                resolve_scene_context_activity,
                execute_scene_generation_activity,
                execute_scene_build_activity,
            )
            from v3_engine.web_pipeline.check_activities import (
                execute_browser_checks_activity,
                qualify_authoring_candidate_activity,
            )
            control_workflows.append(ApplicationSceneWorkflow)
            control_activities.append(resolve_scene_context_activity)
            io_activities.append(execute_scene_generation_activity)
            build_activities.extend([
                execute_scene_build_activity,
                execute_browser_checks_activity,
                qualify_authoring_candidate_activity,
            ])
        except ImportError as e:
            raise RuntimeError(f"Failed to register ApplicationSceneWorkflow: {e}") from e

        try:
            from v3_engine.web_pipeline.authoring_workflow import ApplicationAuthoringWorkflow
            from v3_engine.web_pipeline.authoring_activities import resolve_web_authoring_manifest_activity
            from v3_engine.authoring.activities import authoring_execute_attempts
            control_workflows.append(ApplicationAuthoringWorkflow)
            control_activities.append(resolve_web_authoring_manifest_activity)
            io_activities.append(authoring_execute_attempts)
        except ImportError as e:
            raise RuntimeError(f"Failed to register ApplicationAuthoringWorkflow: {e}") from e

        # Post-approval teacher corrections are started by the bridge on the
        # shared control queue. Register their workflow and every activity it
        # can call on that queue so accepted corrections cannot sit on the
        # legacy v3-cases queue without a poller.
        try:
            from v3_engine.review.correction_workflow import CorrectionWorkflow
            from v3_engine.review.activity import (
                claim_teacher_scene_repair_activity,
                claim_teacher_prompt_correction_activity,
                get_teacher_prompt_correction_context_activity,
                record_teacher_prompt_correction_result_activity,
                record_teacher_prompt_correction_uncertain_activity,
                revise_teacher_prompt_correction_activity,
                execute_teacher_scene_repair_activity,
            )
            from v3_engine.prompts.activities import qualify_prompts
            from v3_engine.authoring.activities import (
                authoring_list_eligible_prompts,
                authoring_claim_request,
                authoring_execute_attempts,
            )
            from v3_engine.qualification.activity import (
                qualify_scene_activity,
                repair_scene_activity,
                ensure_review_ready_activity,
            )
            control_workflows.append(CorrectionWorkflow)
            control_activities.extend([
                claim_teacher_scene_repair_activity,
                claim_teacher_prompt_correction_activity,
                get_teacher_prompt_correction_context_activity,
                record_teacher_prompt_correction_result_activity,
                record_teacher_prompt_correction_uncertain_activity,
                revise_teacher_prompt_correction_activity,
                execute_teacher_scene_repair_activity,
                qualify_prompts,
                authoring_list_eligible_prompts,
                authoring_claim_request,
                authoring_execute_attempts,
                qualify_scene_activity,
                repair_scene_activity,
                ensure_review_ready_activity,
            ])
        except ImportError as e:
            raise RuntimeError(f"Failed to register CorrectionWorkflow: {e}") from e

        control_worker = Worker(
            client,
            task_queue="v3-terminal-control",
            workflows=control_workflows,
            activities=control_activities,
            activity_executor=activity_executor,
        )

        # IO Worker (external NotebookLM & scene API requests, max 4 concurrent)
        io_worker = Worker(
            client,
            task_queue="v3-terminal-io",
            max_concurrent_activities=4,
            activities=io_activities,
        )

        # Build Worker (local builds with subprocess groups, max 2 concurrent)
        build_worker = Worker(
            client,
            task_queue="v3-terminal-build",
            max_concurrent_activities=2,
            activities=build_activities,
        )

        workers = [control_worker, io_worker, build_worker]

        async def _run_all() -> None:
            await asyncio.gather(*(w.run() for w in workers))

        worker_task = asyncio.create_task(_run_all())

        if stop_event is None:
            stop_event = asyncio.Event()
            loop = asyncio.get_running_loop()
            for sig in (signal.SIGTERM, signal.SIGINT):
                try:
                    loop.add_signal_handler(sig, stop_event.set)
                except NotImplementedError:
                    pass

        await stop_event.wait()
        logger.info("Stopping terminal workers cooperatively...")
        worker_task.cancel()
        try:
            await worker_task
        except asyncio.CancelledError:
            pass

    finally:
        activity_executor.shutdown(wait=True, cancel_futures=True)
        lock.release()
