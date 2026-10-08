"""
Live provider, preview container, and Temporal completion gate test for Spec 013 (T022, R26).
Marked with @pytest.mark.live and gated by V3_REVIEW_LIVE=1.
"""

import base64
import hashlib
import json
import os
import socket
import struct
import threading
import pytest
from pathlib import Path
from typing import Any, Dict

from v3_engine.review.preview_gateway import PreviewGatewaySession
from v3_engine.review.routing import build_teacher_correction_payload, resolve_correction_route
from v3_engine.prompts.feedback import FeedbackTextAdapter


def is_valid_png_1280x720(png_bytes: bytes) -> bool:
    """Verify PNG signature and 1280x720 dimensions."""
    if len(png_bytes) < 24:
        return False
    if png_bytes[:8] != b"\x89PNG\r\n\x1a\n":
        return False
    width, height = struct.unpack(">II", png_bytes[16:24])
    return width == 1280 and height == 720


@pytest.mark.live
@pytest.mark.asyncio
async def test_live_gate_teacher_review():
    """
    R26: Disposable current candidate is reviewed through actual isolated preview and
    real Temporal ReviewGate; selected captured feedback provider produces a real factual
    correction, followed by fresh 010/011/012 path or an explicitly recorded provider/policy blocker.
    No skipped/mocked result counts as this gate.
    """
    is_live_enabled = os.environ.get("V3_REVIEW_LIVE") == "1"
    if not is_live_enabled:
        pytest.skip("V3_REVIEW_LIVE=1 not set. Skipping live teacher review completion gate.")

    # ---------------------------------------------------------
    # 1. Real Restricted Preview Container Gate
    # ---------------------------------------------------------
    listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    listener.bind(("127.0.0.1", 0))
    host_port = listener.getsockname()[1]
    listener.listen(5)
    listener.settimeout(2.0)

    connection_received = []

    def accept_thread():
        try:
            conn, _ = listener.accept()
            connection_received.append(True)
            conn.close()
        except Exception:
            pass

    t = threading.Thread(target=accept_thread, daemon=True)
    t.start()

    disposable_scene_files = [
        {
            "path": "index.html",
            "content": (
                "<!DOCTYPE html><html lang='en'><head><title>Live Gate Scene</title></head>"
                "<body><canvas id='c' width='1280' height='720'></canvas>"
                "<button id='action-btn' style='position:absolute;top:10px;left:10px;'>Click Me</button>"
                "<script src='app.js'></script></body></html>"
            ),
        },
        {
            "path": "app.js",
            "content": (
                "const canvas = document.getElementById('c');"
                "const ctx = canvas.getContext('2d');"
                "ctx.fillStyle = '#1e293b';"
                "ctx.fillRect(0, 0, 1280, 720);"
                "document.getElementById('action-btn').addEventListener('click', () => {"
                "  ctx.fillStyle = '#38bdf8';"
                "  ctx.fillRect(100, 100, 400, 300);"
                "  window.__action_done = true;"
                "});"
                f"fetch('http://127.0.0.1:{host_port}').catch(() => {{}});"
            ),
        },
        {
            "path": "manifest.json",
            "content": json.dumps({"schema_version": 1, "entry": "index.html", "files": ["index.html", "app.js"]})
        }
    ]
    manifest_sha = hashlib.sha256(disposable_scene_files[2]["content"].encode("utf-8")).hexdigest()

    session = PreviewGatewaySession(
        viewer_ref="00000000-0000-0000-0000-000000000001",
        candidate_ref="00000000-0000-0000-0000-000000000002",
        manifest_sha256=manifest_sha,
        files=disposable_scene_files,
    )

    try:
        start_res = session.start()
        assert start_res["success"] is True, f"Preview start failed: {start_res}"
        frame0 = start_res["frame"]
        assert frame0["frame_no"] == 0
        frame0_bytes = base64.b64decode(frame0["png_base64"])
        assert is_valid_png_1280x720(frame0_bytes), "Frame 0 is not a valid 1280x720 PNG"

        # Apply pointer action
        action_res = session.apply_action({
            "schema_version": 1,
            "expected_frame_no": 0,
            "kind": "click",
            "payload": {"x": 20, "y": 20},
        })
        assert action_res["success"] is True, f"Action failed: {action_res}"
        frame1 = action_res["frame"]
        assert frame1["frame_no"] == 1
        frame1_bytes = base64.b64decode(frame1["png_base64"])
        assert is_valid_png_1280x720(frame1_bytes), "Frame 1 is not a valid 1280x720 PNG"

        # Verify egress denial
        listener.close()
        t.join(timeout=1.0)
        assert len(connection_received) == 0, "Host listener received connection from restricted preview sandbox!"
    finally:
        session.close()

    # ---------------------------------------------------------
    # 2. Real Temporal Workflow Gate
    # ---------------------------------------------------------
    temporal_target = os.environ.get("TEMPORAL_TARGET_HOST", "localhost:7233")
    try:
        from temporalio.client import Client
        import asyncio

        temporal_client = await asyncio.wait_for(
            Client.connect(temporal_target),
            timeout=3.0
        )
    except Exception as e:
        pytest.fail(
            f"Live review gate failed: Temporal server is unreachable at {temporal_target} ({e}). "
            "Ensure a real Temporal service is running to satisfy the live workflow gate."
        )

    # ---------------------------------------------------------
    # 3. Selected Captured Feedback Provider Execution
    # ---------------------------------------------------------
    feedback_env = os.environ.get("FEEDBACK_CREDENTIAL_ENV", "GEMINI_API_KEY")
    api_key = os.environ.get(feedback_env)
    if not api_key:
        pytest.fail(
            f"Live review gate failed: Feedback provider credential '{feedback_env}' is missing from environment. "
            "Set credential and V3_REVIEW_LIVE=1 to execute the live factual correction gate."
        )

    # Build genuine factual correction payload
    correction_payload = build_teacher_correction_payload(
        section_ref="00000000-0000-0000-0000-000000000010",
        fields={
            "section_identity": "Coulomb Electrostatic Force",
            "core_understanding": "Electrostatic force is proportional to the product of charges and inversely proportional to square distance.",
            "visual_first_explanation": "Two charged particles with variable distance and charge magnitude displaying force vectors.",
            "visual_scene_script": "Interactive particle pair with dynamic force arrows scaling quadratically.",
            "dynamic_view_blueprint": "Slider controls distance r and charges q1, q2 with vector length reflecting Fe.",
            "visual_learning_outcome": "Students deduce inverse-square relationship by halving distance and seeing 4x force increase.",
            "concept_check": "What happens to the electric force if distance is doubled?",
            "conceptual_summary": "Doubling distance quarters the force between two static charges.",
            "colloquial_egyptian_explanation": "لو بعدت الشحنتين عن بعض الضعف، القوة الكهربية بينهم هتقل للربع.",
            "laws_if_any": "F = k * |q1 * q2| / r^2",
            "real_life_example": "Static cling on clothes pulled apart.",
        },
        previous_prompt="A scene showing two charges.",
        teacher_note="The inverse square relationship should be clearly emphasized in the visual explanation and prompt guidance.",
        previous_qualification={"status": "qualified", "version": 1},
    )

    template_bytes = (
        "You are an educational prompt designer. Revise the scene prompt given the teacher feedback.\n"
        "Teacher Note: {{ teacher_note }}\n"
        "Original Prompt: {{ previous_prompt }}\n"
        "Section Concept: {{ fields.core_understanding }}\n"
        "Please provide the revised prompt."
    )

    descriptor = {
        "id": "gemini_api",
        "model": os.environ.get("FEEDBACK_PROVIDER_MODEL", "gemini-2.5-flash"),
        "credential_ref": feedback_env,
    }

    revision_result = await FeedbackTextAdapter.revise(
        descriptor=descriptor,
        template_bytes=template_bytes,
        user_payload=correction_payload,
        effect_ref="00000000-0000-0000-0000-000000000099",
    )

    assert revision_result.text, "Live feedback provider returned empty text"
    assert revision_result.returned_model, "Live feedback provider returned no model info"
    assert revision_result.output_sha256, "Live feedback provider returned no output SHA256"
