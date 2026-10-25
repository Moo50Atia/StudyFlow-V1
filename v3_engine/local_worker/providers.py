"""NotebookLM provider adapter for the outbound worker."""
from __future__ import annotations

import hashlib
import json
import time
from pathlib import Path
from typing import Any, Dict, List, Optional, Protocol

from v3_engine.notebooklm.ports import AuthenticationRequiredError, ProviderError


class NotebookLMProviderAdapter:
    """Provider-backed operations using the locally installed NotebookLM session."""

    def __init__(
        self,
        credentials_path: Optional[Path] = None,
        transport_double: Optional[Any] = None,
        terminal_service: Optional[Any] = None,
    ):
        self.credentials_path = credentials_path
        self.transport_double = transport_double
        self.terminal_service = terminal_service

    def _service(self, cred_file: Path) -> Any:
        if self.terminal_service is not None:
            return self.terminal_service
        from v3_engine.notebooklm.terminal_service import NotebookTerminalService
        from v3_engine.notebooklm.sdk_provider import SdkSessionProvider

        provider = SdkSessionProvider(storage_state_path=cred_file)
        return NotebookTerminalService(session_provider=provider)

    @staticmethod
    def _run(coro: Any) -> Any:
        import asyncio

        try:
            asyncio.get_running_loop()
        except RuntimeError:
            return asyncio.run(coro)
        coro.close()
        raise ProviderError("provider_loop_conflict", "NotebookLM operation cannot run inside an active event loop.")

    def execute_operation(
        self,
        operation_kind: str,
        request_payload: Dict[str, Any],
        inputs: Dict[str, Any],
    ) -> str:
        if self.transport_double is not None:
            if hasattr(self.transport_double, "execute_operation"):
                return self.transport_double.execute_operation(operation_kind, request_payload, inputs)
            elif hasattr(self.transport_double, "call_notebooklm"):
                res = self.transport_double.call_notebooklm(operation_kind, {"payload": request_payload, "inputs": inputs})
                if isinstance(res, str):
                    return res
                if isinstance(res, dict) and "raw_text" in res:
                    return res["raw_text"]
                return json.dumps(res)

        cred_file = self._validated_credentials_path()

        service = self._service(Path(cred_file))
        if operation_kind == "notebook_create":
            ref = self._run(service.create_notebook(str(request_payload["title"]), storage_path=Path(cred_file)))
            return json.dumps({"notebook_id": ref.id, "title": ref.title}, ensure_ascii=False)

        if operation_kind == "source_upload":
            source_path = Path(str(inputs["source_path"])).expanduser().resolve()
            digest = hashlib.sha256()
            with source_path.open("rb") as source_stream:
                for chunk in iter(lambda: source_stream.read(1024 * 1024), b""):
                    digest.update(chunk)
            if digest.hexdigest() != str(request_payload["artifact_sha256"]):
                raise ProviderError("source_changed", "Prepared source bytes differ from the sealed artifact digest.")
            receipt = self._run(service.upload_source(
                notebook_id=str(request_payload["notebook_id"]),
                local_path=source_path,
                mime_type=str(request_payload["mime_type"]),
                title=str(request_payload["title"]),
                storage_path=Path(cred_file),
            ))
            return json.dumps({
                "source_id": receipt.source_id,
                "title": receipt.title,
                "status": receipt.status,
                "mime_type": receipt.mime_type,
            }, ensure_ascii=False)

        if operation_kind == "chat_configure":
            self._run(service.configure_chat(
                str(request_payload["notebook_id"]),
                str(request_payload["format_text"]),
                storage_path=Path(cred_file),
            ))
            return "System Ready"

        if operation_kind in {"divide_map", "execution_ready", "lesson_bundle"}:
            question = str(request_payload["prompt"])
            answer = self._run(service.send_turn(
                notebook_id=str(request_payload["notebook_id"]),
                question=question,
                source_ids=[str(value) for value in request_payload["source_ids"]],
                conversation_id=(str(request_payload["conversation_id"])
                                 if request_payload.get("conversation_id") else None),
                storage_path=Path(cred_file),
            ))
            return answer.text

        raise ProviderError("unsupported_operation", f"Unsupported NotebookLM operation '{operation_kind}'.")

    def wait_sources_ready(
        self,
        notebook_id: str,
        source_ids: list[str],
        *,
        timeout_seconds: float = 600.0,
        poll_interval_seconds: float = 5.0,
    ) -> None:
        """Wait on provider indexing with read-only calls; mutations remain individually fenced."""
        cred_file = self._validated_credentials_path()
        service = self._service(cred_file)

        import time as _time
        deadline = _time.monotonic() + timeout_seconds
        while True:
            receipts = self._run(service.poll_sources(notebook_id, storage_path=cred_file))
            by_id = {receipt.source_id: receipt for receipt in receipts}
            missing = [source_id for source_id in source_ids if source_id not in by_id]
            if missing:
                raise ProviderError("source_missing", "NotebookLM did not return all uploaded sources.")
            errors = [source_id for source_id in source_ids if by_id[source_id].status == "error"]
            if errors:
                raise ProviderError("source_processing_failed", "NotebookLM rejected an uploaded source.")
            if all(by_id[source_id].status == "ready" for source_id in source_ids):
                return
            if _time.monotonic() >= deadline:
                raise ProviderError("source_readiness_timeout", "NotebookLM source processing did not finish before timeout.")
            _time.sleep(poll_interval_seconds)

    def current_conversation_id(self, notebook_id: str) -> Optional[str]:
        cred_file = self._validated_credentials_path()
        service = self._service(cred_file)
        return self._run(service.get_current_conversation_id(notebook_id, storage_path=cred_file))

    def _validated_credentials_path(self) -> Path:
        cred_file = self.credentials_path
        if cred_file is None:
            from v3_engine.notebooklm.credentials import get_storage_state_path
            cred_file = get_storage_state_path()
        cred_file = Path(cred_file).expanduser().resolve()
        if not cred_file.is_file():
            raise AuthenticationRequiredError(
                f"NotebookLM credentials are missing from the configured profile ({cred_file.name}). Run credential setup to authenticate."
            )
        from v3_engine.notebooklm.credentials import validate_storage_state
        try:
            validate_storage_state(cred_file)
        except Exception as exc:
            raise AuthenticationRequiredError(f"NotebookLM credentials invalid at {cred_file}: {exc}") from exc
        return cred_file


class ProviderAdapter(Protocol):
    def execute_operation(
        self,
        operation_kind: str,
        request_payload: Dict[str, Any],
        inputs: Dict[str, Any],
    ) -> str:
        """Executes a provider operation and returns the raw text response."""
        ...


class OfflineProviderDouble:
    """Deterministic offline provider double for testing, reproduction, and dry runs."""

    def __init__(self, lessons_per_item: int = 2):
        self.lessons_per_item = lessons_per_item

    def execute_operation(
        self,
        operation_kind: str,
        request_payload: Dict[str, Any],
        inputs: Dict[str, Any],
    ) -> str:
        if operation_kind == "notebook_create":
            return json.dumps({"notebook_id": f"offline-{request_payload['item_id']}"})

        if operation_kind == "source_upload":
            return json.dumps({"source_id": f"offline-{request_payload['artifact_id']}", "status": "ready"})

        if operation_kind == "chat_configure":
            return "System Ready"

        if operation_kind == "execution_ready":
            return "System Ready"

        if operation_kind == "source_wait":
            return "Sources Ready"

        if operation_kind == "divide_map":
            lines = ["Chapter 1: Fluid Mechanics Principles"]
            for i in range(1, self.lessons_per_item + 1):
                if i == 1:
                    lines.append("  Lesson 1: Introduction to Viscosity and Laminar Flow")
                elif i == 2:
                    lines.append("  Lesson 2: Navier-Stokes Momentum Equations")
                else:
                    lines.append(f"  Lesson {i}: Advanced Fluid Dynamics Topic {i}")
            return "\n".join(lines) + "\n"

        if operation_kind == "lesson_bundle":
            work_ord = int(request_payload.get("lesson_ordinal", 1))
            lesson_title = request_payload.get("lesson_title", f"Fundamental Concept {work_ord}")

            return f"""=== FIRST ===
Lecture: W{work_ord} | {lesson_title}

Section 1:
Section Identity: Core Principles of {lesson_title}
Core Understanding: Foundational physical principles and dynamic behaviors of {lesson_title}.
Visual-First Explanation: Interactive 2D visualization demonstrating primary variables and equilibrium states.
Visual Scene Script: Simulation stage renders reference boundaries, active particles, and control vectors.
Dynamic View Blueprint: Multi-parameter viewport with real-time vector overlay and responsive feedback.
Visual Learning Outcome: Student grasps equilibrium conditions and parameter sensitivity.
Concept Check: What happens when the primary driver parameter increases past threshold?
Conceptual Summary: Analytical formulation connects physical laws to observed simulation dynamics.
• شرح للجزء بالعامية المصرية: بنوضح هنا المبدأ الأساسي بشكل مرئي تفاعلي عشان نقدر نستوعب التأثير المباشر لكل متغير.
القوانين (إن وُجدت): \\frac{{\\partial u}}{{\\partial t}} + u \\frac{{\\partial u}}{{\\partial x}} = -\\frac{{1}}{{\\rho}} \\frac{{\\partial p}}{{\\partial x}}
إسقاط على مثال أو موقف من الحياة اليومية لتثبيت الفهم: زي حركة المية في الأنابيب وسريان السوائل بسرعات مختلفة.

=== SECOND ===
Lecture: W{work_ord} | {lesson_title}

Lec{work_ord} (Sec1):
"Interactive simulation exploring {lesson_title} dynamics."
Prepared Perspective Map:
- Primary learning objective: Grasp foundational mechanics of {lesson_title}.
- Scene Type: Simulation
- Baseline objects and state: Channel boundary walls and fluid tracer particles.
- Time Perspective: Real-time dynamic simulation.
- Custom Perspectives: Velocity profile overlay.
- Governing Behavior: First-principles solver with conservation constraints.
- Learner Investigations: Adjusting control sliders and observing response curves.
- Controls: Slider controls for viscosity and velocity.
- Linked Views: Synchronized shear stress graph panel.
- Visual Learning Goal: Clarify boundary behavior and dynamic transitions.
- Coverage: Core principles and boundary conditions.
- Acceptance Checks: Stable numerical integration across slider parameter ranges.
- Evidence Gaps: Not applicable

=== THIRD ===
Lecture: W{work_ord} | {lesson_title}

Lec{work_ord} (Sec1):
"Simulation: Interactive Demonstration of {lesson_title}
Learning Target: Contrast laminar and turbulent regimes under variable shear.
Scene Objects and Initial State: Fluid channel with boundary plates and tracer particles.
Governing Rules: Navier-Stokes momentum equations with no-slip boundary conditions.
Learner Investigations and Controls: Sliders for viscosity, velocity, and particle density.
Linked Representations: Live velocity profile graph and Reynolds number display.
Visual Outcome and Misconception Contrast: Confirms zero velocity at solid boundaries.
Acceptance Requirements: Real-time particle advection responding to slider adjustments."

=== END ===
✅ Lesson [{work_ord}] Completed. Awaiting 'Continue' for the next lesson.
"""

        raise ValueError(f"Unknown operation_kind '{operation_kind}' in offline provider double")

    def wait_sources_ready(self, notebook_id: str, source_ids: List[str], **_: Any) -> None:
        return None

    def current_conversation_id(self, notebook_id: str) -> str:
        return f"offline-conversation-{notebook_id}"
