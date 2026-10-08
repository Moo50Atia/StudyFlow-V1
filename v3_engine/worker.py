import asyncio
import signal
import sys
from temporalio.worker import Worker

from v3_engine.activities.publish_milestone import publish_workflow_started, publish_case_event
from v3_engine.activities.extract_case_source import extract_case_source
from v3_engine.activities.ingest_case_source import ingest_case_source
from v3_engine.activities.map_case import map_case
from v3_engine.activities.prepare_lesson import prepare_lesson
from v3_engine.activities.process_notebook_lesson import process_notebook_lesson
from v3_engine.activities.reconcile_notebook_query import reconcile_notebook_query
from v3_engine.prompts.activities import generate_prompts, qualify_prompts, resolve_qualified_prompt
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
from v3_engine.review.activity import (
    claim_teacher_scene_repair_activity,
    claim_teacher_prompt_correction_activity,
    get_teacher_prompt_correction_context_activity,
    record_teacher_prompt_correction_result_activity,
    record_teacher_prompt_correction_uncertain_activity,
    revise_teacher_prompt_correction_activity,
    execute_teacher_scene_repair_activity,
)
from v3_engine.review.correction_workflow import CorrectionWorkflow
from v3_engine.client import get_temporal_client
from v3_engine.settings import get_settings
from v3_engine.workflows.case_workflow import CaseWorkflow

async def run_worker() -> None:
    settings = get_settings()
    client = await get_temporal_client()

    worker = Worker(
        client,
        task_queue=settings.task_queue,
        workflows=[CaseWorkflow, CorrectionWorkflow],
        activities=[
            publish_workflow_started,
            publish_case_event,
            extract_case_source,
            ingest_case_source,
            map_case,
            prepare_lesson,
            process_notebook_lesson,
            reconcile_notebook_query,
            generate_prompts,
            qualify_prompts,
            resolve_qualified_prompt,
            authoring_list_eligible_prompts,
            authoring_claim_request,
            authoring_execute_attempts,
            qualify_scene_activity,
            repair_scene_activity,
            ensure_review_ready_activity,
            claim_teacher_scene_repair_activity,
            claim_teacher_prompt_correction_activity,
            get_teacher_prompt_correction_context_activity,
            record_teacher_prompt_correction_result_activity,
            record_teacher_prompt_correction_uncertain_activity,
            revise_teacher_prompt_correction_activity,
            execute_teacher_scene_repair_activity,
        ],
    )


    print(f"Starting V3 Temporal worker on queue '{settings.task_queue}'...")
    await worker.run()

def main() -> None:
    try:
        asyncio.run(run_worker())
    except KeyboardInterrupt:
        print("Worker stopped.")
        sys.exit(0)

if __name__ == "__main__":
    main()
