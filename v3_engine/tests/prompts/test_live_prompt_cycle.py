import json
import os
from pathlib import Path
import pytest

from v3_engine.prompts.jev import TypeSafeJevClient
from v3_engine.prompts.feedback import FeedbackTextAdapter

MANIFEST_PATH = Path(__file__).parent / "fixtures" / "live_manifest.json"

@pytest.mark.live
@pytest.mark.asyncio
async def test_live_prompt_cycle():
    """
    P18: Live selected-provider gate for disposable source/first+continued work,
    actual selected MCP, TypeSafe model, and captured feedback provider.
    Credentials absent means incomplete gate (failure), not pass or skip.
    """
    if not MANIFEST_PATH.exists():
        pytest.fail(f"Live manifest not found at {MANIFEST_PATH}")

    with open(MANIFEST_PATH, "r", encoding="utf-8") as f:
        manifest = json.load(f)

    is_live_enabled = os.environ.get("V3_PROMPT_LIVE") == "1"
    typesafe_key = os.environ.get("TYPESAFE_API_KEY")
    jev_model = os.environ.get("JEV_MODEL")

    feedback_info = manifest.get("feedback", {})
    feedback_env = feedback_info.get("credential_env", "GEMINI_API_KEY")
    feedback_key = os.environ.get(feedback_env)

    # Incomplete gate check
    if not is_live_enabled or not typesafe_key or not jev_model or not feedback_key:
        pytest.fail(
            "Live prompt release gate incomplete: required live credentials/environment absent. "
            "Set V3_PROMPT_LIVE=1, TYPESAFE_API_KEY, JEV_MODEL, and "
            f"{feedback_env} to run the mandatory live gate."
        )

    # 1. Verify TypeSafe JEV client against live /v1/models and evaluate prompt
    jev_client = TypeSafeJevClient(api_key=typesafe_key, base_url="https://api.typesafe.ai", model=jev_model)

    disposable_section = {
        "section_identity": "Wave Propagation Dynamics",
        "core_understanding": "Mechanical waves transmit energy across a medium without net mass displacement.",
        "visual_first_explanation": "An oscillating grid of connected particle nodes demonstrating transverse phase propagation.",
        "visual_scene_script": "Central vibrating node propagates transverse sinusoidal disturbance along horizontal lattice.",
        "dynamic_view_blueprint": "Grid nodes displace vertically under Hooke restoration while wave crest advances horizontally.",
        "visual_learning_outcome": "Identify that individual particles oscillate in place while wave phase travels through space.",
        "concept_check": "Do individual water molecules move across the ocean with a wave?",
        "conceptual_summary": "Energy propagates horizontally as local particles vibrate vertically.",
        "colloquial_egyptian_explanation": "الموجة بتنقل الطاقة عبر الجزيئات اللي بتهتز في مكانها من غير ما تمشي معاها.",
        "laws_if_any": "v = f * lambda",
        "real_life_example": "Stadium spectator wave where people stand up and sit down in fixed seats.",
    }

    test_prompt = (
        "Process: Grid of particle nodes connected by elastic bonds. Central source node initiates vertical "
        "sinusoidal oscillation. Neighboring nodes displace with progressive phase delay forming transverse "
        "traveling wave. Misconception correction overlay displays red cross over particles moving horizontally "
        "and green check over vertical in-place vibration. Final visual state displays sustained harmonic wave pattern."
    )

    jev_response = await jev_client.evaluate_prompt(
        section_fields=disposable_section,
        coverage_pages=[1, 2],
        prompt_text=test_prompt,
    )

    assert "model" in jev_response
    answers = jev_response.get("answers", {})
    assert "choice" in answers
    assert "score" in answers
    assert "noul" in answers

    # Verify score rubric range
    score_ans = answers["score"]
    score_val = score_ans["score"]
    assert 0.0 <= score_val <= 100.0

    # 2. Verify live feedback provider revision
    descriptor = {
        "id": feedback_info["provider_id"],
        "model": feedback_info["provider_model"],
        "credential_ref": "env",
    }

    template_bytes = (
        "You are an expert educational scene prompt editor. "
        "Given the section fields, the previous prompt, and the failed evaluation criteria, "
        "revise the prompt so that it strictly adheres to the visual learning scene rules. "
        "Output ONLY the revised prompt starting with one of: Process, Comparison, Accumulation, "
        "Parallel Flow, Time Evolution."
    )

    user_payload = {
        "section_ref": "sec-live-disp-1",
        "fields": disposable_section,
        "previous_prompt": test_prompt,
        "failed_checks": ["score_confidence"],
        "typed_values": {
            "choice": answers["choice"]["choice"],
            "score": score_val,
            "noul": answers["noul"]["noul"],
        },
    }

    adapter = FeedbackTextAdapter()
    revision_result = await adapter.revise(
        descriptor=descriptor,
        template_bytes=template_bytes,
        user_payload=user_payload,
        effect_ref="eff-live-test-1",
    )

    assert revision_result["text"]
    assert len(revision_result["text"]) <= 65536
    first_token = revision_result["text"].split()[0].rstrip(":")
    assert first_token in ["Process", "Comparison", "Accumulation", "Parallel Flow", "Time Evolution"]
    assert revision_result["returned_model"]
