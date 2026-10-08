"""Activity: prepare_lesson per Spec 009."""
from temporalio import activity
from typing import Dict, Any

@activity.defn(name="prepare_lesson")
async def prepare_lesson(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Execute First prompt for target work ordinal, parse 11 fields per Section, and accept in Laravel."""
    from v3_engine.lessons.lesson_processor import LessonProcessor
    from v3_engine.lessons.client import HttpLaravelLessonClient
    from v3_engine.sources.notebooklm_adapter import KnowledgeSourceAdapter

    case_id = payload.get("case_id")
    activation_id = payload.get("activation_id")
    binding_ref = payload.get("binding_ref")
    work_ordinal = payload.get("work_ordinal")
    if (
        not all(isinstance(value, str) and value for value in (case_id, activation_id, binding_ref))
        or not isinstance(work_ordinal, int)
        or isinstance(work_ordinal, bool)
        or work_ordinal < 1
    ):
        return {"status": "blocked", "error_code": "validation"}

    processor = LessonProcessor(KnowledgeSourceAdapter(), HttpLaravelLessonClient())
    return await processor.prepare_lesson(
        case_id=case_id,
        activation_id=activation_id,
        binding_ref=binding_ref,
        work_ordinal=work_ordinal,
    )

