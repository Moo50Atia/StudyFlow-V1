"""Disposable joined runtime harness for V3 Web Pipeline (T119).

Starts:
1. Real Laravel HTTP server (on disposable on-disk SQLite DB, dynamic port).
2. Real FastAPI WebPipelineBridge (on dynamic port).
3. Authenticated browser/HTTP client with session and CSRF handling.
4. Fault injection hooks for boundary sensitivity testing.
5. Strict process tracking and cleanup.
"""

from __future__ import annotations

import asyncio
import os
import re
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

import httpx


def find_free_port(start_port: int = 8015, exclude: Optional[set[int]] = None) -> int:
    """Find an available TCP port on localhost, strictly avoiding excluded ports like 8000."""
    excluded = exclude or {8000}
    for port in range(start_port, start_port + 200):
        if port in excluded:
            continue
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
            try:
                s.bind(("127.0.0.1", port))
                return port
            except OSError:
                continue
def make_minimal_pdf(page_count: int = 1, text: str = "Educational Concept Material") -> bytes:
    """Generate a strictly valid PDF 1.4 byte sequence that passes qpdf validation."""
    objects = {1: "<< /Type /Catalog /Pages 2 0 R >>"}
    kid_refs = [f"{2 + i * 2 - 1} 0 R" for i in range(1, page_count + 1)]
    kids_str = " ".join(kid_refs)
    objects[2] = f"<< /Type /Pages /Kids [{kids_str}] /Count {page_count} >>"
    next_obj = 3
    for i in range(1, page_count + 1):
        p_id = next_obj
        next_obj += 1
        c_id = next_obj
        next_obj += 1
        stream_content = f"BT /F1 12 Tf 72 712 Td ({text} Page {i}) Tj ET"
        length = len(stream_content)
        objects[p_id] = (
            f"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] "
            f"/Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> "
            f"/Contents {c_id} 0 R >>"
        )
        objects[c_id] = f"<< /Length {length} >>\nstream\n{stream_content}\nendstream"
    out = "%PDF-1.4\n"
    offsets = {}
    for obj_id in sorted(objects.keys()):
        offsets[obj_id] = len(out.encode("latin1"))
        out += f"{obj_id} 0 obj\n{objects[obj_id]}\nendobj\n"
    xref_offset = len(out.encode("latin1"))
    max_obj = max(objects.keys())
    total_objs = max_obj + 1
    out += f"xref\n0 {total_objs}\n0000000000 65535 f \n"
    for i in range(1, max_obj + 1):
        if i in offsets:
            out += f"{offsets[i]:010d} 00000 n \n"
        else:
            out += "0000000000 65535 f \n"
    out += f"trailer\n<< /Size {total_objs} /Root 1 0 R >>\nstartxref\n{xref_offset}\n%%EOF\n"
    return out.encode("latin1")


class JoinedRuntimeHarness:
    def __init__(
        self,
        test_root: Optional[Path] = None,
        signing_secret: str = "v3-test-joined-harness-secret-key",
    ):
        self.repo_root = Path("/home/moahmmed/college_project").resolve()
        self.test_root = test_root or (self.repo_root / "storage/testing" / f"harness_{os.getpid()}_{int(time.time())}")
        self.test_root.mkdir(parents=True, exist_ok=True)

        self.db_path = self.test_root / "test_pipeline.sqlite"
        self.storage_path = self.test_root / "storage"
        self.storage_path.mkdir(parents=True, exist_ok=True)

        self.signing_secret = signing_secret
        self.private_root = self.test_root / "v3-private"
        self.private_root.mkdir(parents=True, exist_ok=True)
        self.laravel_port = find_free_port(8015, exclude={8000})
        self.bridge_port = find_free_port(self.laravel_port + 1, exclude={8000})

        self.laravel_url = f"http://127.0.0.1:{self.laravel_port}"
        self.bridge_url = f"http://127.0.0.1:{self.bridge_port}"

        self.laravel_proc: Optional[subprocess.Popen] = None
        self.bridge_proc: Optional[subprocess.Popen] = None
        self.temporal_address: Optional[str] = None
        self.seeded_data: Dict[str, Any] = {}

        # Fault injection flags
        self.wrong_signing_key_active: bool = False
        self.callback_failure_active: bool = False
        self.callback_failure_status: int = 500
        self.browser_check_failure_active: bool = False

    def setup_database_and_prerequisites(self) -> Dict[str, Any]:
        """Create disposable on-disk SQLite database, run migrations, and seed prerequisites."""
        if self.db_path.exists():
            self.db_path.unlink()
        self.db_path.touch()

        env = os.environ.copy()
        env["APP_ENV"] = "testing"
        env["APP_KEY"] = "base64:pAeRvprsT7xvgtJnI7RY2CtfRhhwt5HflVxit0LZEH0="
        env["DB_CONNECTION"] = "sqlite"
        env["DB_DATABASE"] = str(self.db_path)
        env["V3_PRIVATE_ROOT"] = str(self.private_root)

        # 1. Migrate
        res_mig = subprocess.run(
            ["php", "artisan", "migrate", "--force"],
            cwd=str(self.repo_root),
            env=env,
            capture_output=True,
            text=True,
        )
        if res_mig.returncode != 0:
            raise RuntimeError(f"Database migration failed: {res_mig.stderr}\n{res_mig.stdout}")

        # 2. Seed prerequisites
        res_seed = subprocess.run(
            ["php", "artisan", "v3:seed-harness", "--json"],
            cwd=str(self.repo_root),
            env=env,
            capture_output=True,
            text=True,
        )
        if res_seed.returncode != 0:
            raise RuntimeError(f"Prerequisite seeding failed: {res_seed.stderr}\n{res_seed.stdout}")

        import json
        try:
            self.seeded_data = json.loads(res_seed.stdout.strip())
        except Exception:
            self.seeded_data = {"raw": res_seed.stdout}

        return self.seeded_data

    def start_laravel(self) -> None:
        """Start Laravel HTTP server on dedicated dynamic port with isolated environment."""
        if self.laravel_proc is not None:
            return

        env = os.environ.copy()
        env["APP_ENV"] = "testing"
        env["APP_KEY"] = "base64:pAeRvprsT7xvgtJnI7RY2CtfRhhwt5HflVxit0LZEH0="
        env["APP_DEBUG"] = "true"
        env["DB_CONNECTION"] = "sqlite"
        env["DB_DATABASE"] = str(self.db_path)
        env["V3_PRIVATE_ROOT"] = str(self.private_root)
        env["APP_URL"] = self.laravel_url
        env["V3_BRIDGE_URL"] = self.bridge_url
        env["V3_BRIDGE_HMAC_KEY"] = self.signing_secret
        env["V3_WORKER_HMAC_KEY"] = self.signing_secret
        env["V3_CALLBACK_HMAC_KEY"] = self.signing_secret

        self.laravel_proc = subprocess.Popen(
            [
                "php",
                "artisan",
                "serve",
                "--host=127.0.0.1",
                f"--port={self.laravel_port}",
                "--env=testing",
            ],
            cwd=str(self.repo_root),
            env=env,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            preexec_fn=os.setsid,
        )

        # Poll until server is ready
        deadline = time.time() + 15.0
        ready = False
        while time.time() < deadline:
            try:
                resp = httpx.get(f"{self.laravel_url}/up", timeout=1.0)
                if resp.status_code in (200, 302, 404):
                    ready = True
                    break
            except Exception:
                time.sleep(0.2)

        if not ready:
            self.stop_laravel()
            raise RuntimeError(f"Laravel server failed to respond on {self.laravel_url} within deadline")

    def stop_laravel(self) -> None:
        """Stop Laravel server cleanly."""
        if self.laravel_proc:
            try:
                os.killpg(os.getpgid(self.laravel_proc.pid), signal.SIGTERM)
                self.laravel_proc.wait(timeout=3.0)
            except Exception:
                try:
                    os.killpg(os.getpgid(self.laravel_proc.pid), signal.SIGKILL)
                except Exception:
                    pass
            self.laravel_proc = None

    def start_bridge(self) -> None:
        """Start FastAPI Bridge server on dedicated dynamic port."""
        if self.bridge_proc is not None:
            return

        env = os.environ.copy()
        # Laravel writes manifests and accepted PDFs to this exact disk root.
        # The bridge must resolve the same opaque refs, not a parallel test dir.
        env["V3_STORAGE_ROOT"] = str(self.private_root)
        env["V3_PRIVATE_ROOT"] = str(self.private_root)
        env["V3_BRIDGE_HMAC_KEY"] = (
            "wrong-key-fault" if self.wrong_signing_key_active else self.signing_secret
        )
        env["V3_WORKER_HMAC_KEY"] = self.signing_secret
        if self.temporal_address:
            env["V3_TEMPORAL_ADDRESS"] = self.temporal_address
            env["V3_TERMINAL_TEMPORAL_ADDRESS"] = self.temporal_address
            env["V3_TERMINAL_TEMPORAL_NAMESPACE"] = "v3-terminal"

        python_bin = self.repo_root / ".venv/bin/python"

        self.bridge_proc = subprocess.Popen(
            [
                str(python_bin),
                "-m",
                "uvicorn",
                "v3_engine.bridge:app",
                "--host",
                "127.0.0.1",
                "--port",
                str(self.bridge_port),
                "--log-level",
                "warning",
            ],
            cwd=str(self.repo_root),
            env=env,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            preexec_fn=os.setsid,
        )

        # Poll until bridge responds
        deadline = time.time() + 15.0
        ready = False
        while time.time() < deadline:
            try:
                resp = httpx.get(f"{self.bridge_url}/health", timeout=1.0)
                if resp.status_code == 200:
                    ready = True
                    break
            except Exception:
                time.sleep(0.2)

        if not ready:
            self.stop_bridge()
            raise RuntimeError(f"FastAPI bridge failed to respond on {self.bridge_url}/health within deadline")

    def stop_bridge(self) -> None:
        """Stop FastAPI bridge cleanly."""
        if self.bridge_proc:
            try:
                os.killpg(os.getpgid(self.bridge_proc.pid), signal.SIGTERM)
                self.bridge_proc.wait(timeout=3.0)
            except Exception:
                try:
                    os.killpg(os.getpgid(self.bridge_proc.pid), signal.SIGKILL)
                except Exception:
                    pass
            self.bridge_proc = None

    def create_browser_client(self) -> httpx.AsyncClient:
        """Create an async HTTP client with cookies enabled targeting Laravel."""
        return httpx.AsyncClient(
            base_url=self.laravel_url,
            follow_redirects=True,
            timeout=30.0,
        )

    def dispatch_outbox(self) -> dict[str, Any]:
        """Run the production Laravel outbox dispatcher against disposable state."""
        import json

        env = os.environ.copy()
        env.update({
            "APP_ENV": "testing",
            "APP_KEY": "base64:pAeRvprsT7xvgtJnI7RY2CtfRhhwt5HflVxit0LZEH0=",
            "DB_CONNECTION": "sqlite",
            "DB_DATABASE": str(self.db_path),
            "V3_PRIVATE_ROOT": str(self.private_root),
            "V3_BRIDGE_URL": self.bridge_url,
            "V3_BRIDGE_HMAC_KEY": self.signing_secret,
            "V3_WORKER_HMAC_KEY": self.signing_secret,
            "V3_CALLBACK_HMAC_KEY": self.signing_secret,
        })
        result = subprocess.run(
            ["php", "artisan", "v3:dispatch-web-pipeline", "--json"],
            cwd=str(self.repo_root), env=env, capture_output=True, text=True,
            timeout=45,
        )
        if result.returncode:
            raise RuntimeError(f"Outbox dispatch failed: {result.stdout}\n{result.stderr}")
        return json.loads(result.stdout.strip())

    def dispatch_review_effects(self) -> str:
        """Deliver committed teacher decisions through the production review outbox."""
        env = os.environ.copy()
        env.update({
            "APP_ENV": "testing",
            "APP_KEY": "base64:pAeRvprsT7xvgtJnI7RY2CtfRhhwt5HflVxit0LZEH0=",
            "DB_CONNECTION": "sqlite",
            "DB_DATABASE": str(self.db_path),
            "V3_PRIVATE_ROOT": str(self.private_root),
            "V3_BRIDGE_URL": self.bridge_url,
            "V3_BRIDGE_HMAC_KEY": self.signing_secret,
        })
        result = subprocess.run(
            ["php", "artisan", "v3:dispatch-review-effects"],
            cwd=str(self.repo_root), env=env, capture_output=True, text=True,
            timeout=45,
        )
        if result.returncode:
            raise RuntimeError(f"Review dispatch failed: {result.stdout}\n{result.stderr}")
        return result.stdout.strip()

    async def login_teacher(
        self,
        client: httpx.AsyncClient,
        email: str = "teacher_a@example.com",
        password: str = "password",
    ) -> bool:
        """Authenticate as teacher via browser web login form with CSRF extraction."""
        # 1. GET /login to extract CSRF token
        resp = await client.get("/login")
        if resp.status_code != 200:
            return False

        # Extract _token from HTML
        match = re.search(r'name="_token"\s+value="([^"]+)"', resp.text)
        csrf_token = match.group(1) if match else None
        if not csrf_token:
            # Try XSRF-TOKEN cookie
            xsrf = client.cookies.get("XSRF-TOKEN")
            csrf_token = xsrf

        if not csrf_token:
            return False

        # 2. POST /login
        login_resp = await client.post(
            "/login",
            data={
                "_token": csrf_token,
                "email": email,
                "password": password,
            },
        )
        # Login is successful if status is 200 and client was not redirected back to /login
        return login_resp.status_code == 200 and not str(login_resp.url).rstrip("/").endswith("/login")

    def cleanup(self) -> None:
        """Terminate all owned processes and remove disposable root."""
        self.stop_laravel()
        self.stop_bridge()
        if os.getenv("V3_WEB_LIVE_PRESERVE_ARTIFACTS") == "1":
            print(f"Joined runtime artifacts preserved for inspection: {self.test_root}")
            return
        if self.test_root.exists():
            try:
                shutil.rmtree(self.test_root)
            except Exception:
                pass

    def __enter__(self):
        self.setup_database_and_prerequisites()
        self.start_laravel()
        self.start_bridge()
        return self

    def __exit__(self, exc_type, exc_val, exc_tb):
        self.cleanup()


def make_valid_combined_lesson(
    work_ordinal: int,
    lecture_title: str,
    sections: list[tuple[str, str, str]],
) -> str:
    """Generate a fully valid combined 3-artifact lesson string adhering to CombinedLessonValidator."""
    lines = []
    lines.append("=== FIRST ===")
    lines.append(f"Lecture: W{work_ordinal} | {lecture_title}")
    lines.append("")
    for i, (identity, scene_type, desc) in enumerate(sections, 1):
        lines.append(f"Section {i}:")
        lines.append(f"Section Identity: {identity}")
        lines.append(f"Core Understanding: Fundamental mechanical laws governing {identity.lower()}.")
        lines.append(f"Visual-First Explanation: Interactive simulation of {desc}.")
        lines.append(f"Visual Scene Script: Simulation canvas rendering visual elements for {scene_type}.")
        lines.append(f"Dynamic View Blueprint: Multi-axis coordinate space with real-time vector overlay.")
        lines.append(f"Visual Learning Outcome: Differentiate state changes across parameter variations.")
        lines.append(f"Concept Check: How does parameter variation alter equilibrium?")
        lines.append(f"Conceptual Summary: Principles of {identity.lower()} under classical mechanics.")
        lines.append(f"• شرح للجزء بالعامية المصرية: شرح مبسط ومباشر لمفهوم {identity} وكيفية تطبيقه عملياً.")
        lines.append(f"القوانين (إن وُجدت): F = m * a")
        lines.append(f"إسقاط على مثال أو موقف من الحياة اليومية لتثبيت الفهم: مثال واقعي يوضح {identity} في الحياة اليومية.")
        lines.append("")

    lines.append("=== SECOND ===")
    lines.append(f"Lecture: W{work_ordinal} | {lecture_title}")
    lines.append("")
    for i, (identity, scene_type, desc) in enumerate(sections, 1):
        lines.append(f"Lec{work_ordinal} (Sec{i}):")
        lines.append(f'"{scene_type}: Interactive visual scene exploring {desc}."')
        lines.append("Prepared Perspective Map:")
        lines.append(f"- Primary learning objective: Comprehend {identity} dynamics.")
        lines.append(f"- Scene Type: Process")
        lines.append(f"- Baseline objects and state: Simulation canvas with coordinate axes and objects.")
        lines.append(f"- Time Perspective: Not applicable")
        lines.append(f"- Custom Perspectives: Dual viewpoint comparison.")
        lines.append(f"- Governing Behavior: Equations of motion update positions continuously.")
        lines.append(f"- Learner Investigations: Adjust control parameters to explore state boundaries.")
        lines.append(f"- Controls: Sliders for primary mechanical variables.")
        lines.append(f"- Linked Views: Synchronized dynamic plot and readout values.")
        lines.append(f"- Visual Learning Goal: Build intuition for {identity}.")
        lines.append(f"- Coverage: Comprehensive coverage of {desc}.")
        lines.append(f"- Acceptance Checks: Dynamic readouts update without latency.")
        lines.append(f"- Evidence Gaps: Not applicable")
        lines.append("")

    lines.append("=== THIRD ===")
    lines.append(f"Lecture: W{work_ordinal} | {lecture_title}")
    lines.append("")
    for i, (identity, scene_type, desc) in enumerate(sections, 1):
        lines.append(f"Lec{work_ordinal} (Sec{i}):")
        lines.append(f'"Scene Type: {scene_type}')
        lines.append(f'Learning Target: Master principles of {identity}.')
        lines.append(f'Scene Objects and Initial State: Simulation canvas configured with primary components for {desc}.')
        lines.append(f'Governing Rules: Physical governing equations applied to visual models.')
        lines.append(f'Learner Investigations and Controls: Sliders and interactive handles for parameter variation.')
        lines.append(f'Linked Representations: Numerical formulas and graphical telemetry.')
        lines.append(f'Visual Outcome and Misconception Contrast: Demonstrates physical intuition and corrects misconceptions.')
        lines.append(f'Acceptance Requirements: Animation loops at 60fps with responsive interaction."')
        lines.append("")

    lines.append("=== END ===")
    lines.append(f"✅ Lesson [{work_ordinal}] Completed. Awaiting 'Continue' for the next lesson.")
    lines.append("")
    return "\n".join(lines)


class VariableNotebookLMTransport:
    """Scripted external transport double for NotebookLM multi-PDF runs (T130).

    Simulates:
    - PDF 1 mapping 2 lessons with unequal scene counts (Lesson 1: 2 scenes, Lesson 2: 1 scene).
    - PDF 2 mapping 3 lessons with unequal scene counts (Lesson 1: 2 scenes, Lesson 2: 1 scene, Lesson 3: 3 scenes).
    - Arbitrary scene labels ('Phase Space Explorer', 'Dynamic Vector Plot', 'Osculating Circle Demo', etc.).
    - Invariant tracking: Framework uploads, active source IDs, scope (PDF only for divide, PDF+framework for lessons), and reset order.
    """

    def __init__(self):
        from v3_engine.notebooklm.ports import NotebookRef, SourceReceipt, TurnAnswer

        self.notebooks: dict[str, Any] = {}
        self.framework_uploads: list[dict[str, Any]] = []
        self.pdf_uploads: list[dict[str, Any]] = []
        self.all_uploads: list[dict[str, Any]] = []
        self.sources: dict[str, list[Any]] = {}

        self.turns: list[dict[str, Any]] = []
        self.resets: list[dict[str, Any]] = []
        self.format_calls: list[dict[str, Any]] = []
        self.divide_requests: list[dict[str, Any]] = []
        self.execution_requests: list[dict[str, Any]] = []
        self.start_requests: list[dict[str, Any]] = []
        self.continue_requests: list[dict[str, Any]] = []
        self.conversation_histories: dict[str, list[tuple[str, str]]] = {}

        self.current_pdf_ordinal = 1
        self.current_lesson_ordinal = 0
        self.conversation_id = "conv-var-transport-1"

    # Files port methods
    async def create_notebook(self, title: str):
        from v3_engine.notebooklm.ports import NotebookRef
        nb_id = f"nb-var-{len(self.notebooks) + 1}"
        ref = NotebookRef(id=nb_id, title=title)
        self.notebooks[nb_id] = ref
        self.sources[nb_id] = []
        return ref

    async def upload_file(self, notebook_id: str, local_path: Path, mime_type: str, title: str):
        from v3_engine.notebooklm.ports import SourceReceipt
        src_id = f"src-upload-{len(self.all_uploads) + 1}"
        upload_rec = {
            "notebook_id": notebook_id,
            "path": local_path,
            "mime_type": mime_type,
            "title": title,
            "source_id": src_id,
            "is_framework": "framework" in title.lower() or mime_type == "text/plain",
        }
        self.all_uploads.append(upload_rec)
        if upload_rec["is_framework"]:
            self.framework_uploads.append(upload_rec)
        else:
            self.pdf_uploads.append(upload_rec)

        receipt = SourceReceipt(
            source_id=src_id,
            title=title,
            status="ready",
            mime_type=mime_type,
        )
        self.sources.setdefault(notebook_id, []).append(receipt)
        return receipt

    async def list_sources(self, notebook_id: str):
        return list(self.sources.get(notebook_id, []))

    async def verify_owner(self, proof_notebook_id: str) -> str:
        return "owner"

    # Conversation port methods
    async def configure_format(self, notebook_id: str, format_text: str) -> None:
        self.format_calls.append({"notebook_id": notebook_id, "format": format_text})

    async def history(self, notebook_id: str, conversation_id: str, limit: int = 1000):
        return list(self.conversation_histories.get(conversation_id, []))

    async def ask_once(self, notebook_id: str, question: str, source_ids, conversation_id=None, **kwargs):
        from v3_engine.notebooklm.ports import TurnAnswer
        from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT, EXECUTION_PROMPT

        active_conv_id = conversation_id or self.conversation_id
        turn_rec = {
            "notebook_id": notebook_id,
            "question": question,
            "source_ids": list(source_ids),
            "conversation_id": active_conv_id,
            "time": time.time(),
        }
        self.turns.append(turn_rec)

        # 1. Divide turn
        if question == DIVIDE_PROMPT or "divide" in question.lower():
            turn_rec["type"] = "divide"
            self.divide_requests.append(turn_rec)
            # Divide Scope Invariant: Must contain ONLY the current PDF source ID, not the framework!
            fw_ids = {u["source_id"] for u in self.framework_uploads}
            assert not any(sid in fw_ids for sid in source_ids), "Divide query scope must NOT include framework source IDs!"

            if self.current_pdf_ordinal == 1:
                answer_text = "Chapter 1: Kinematics\nLesson 1: Rectilinear Particle Motion\nLesson 2: Curvilinear Trajectory\n"
            else:
                answer_text = "Chapter 2: Kinetics\nLesson 1: Newton Second Law\nLesson 2: Work and Energy\nLesson 3: Impulse and Momentum\n"

            self.conversation_histories.setdefault(active_conv_id, []).append((question, answer_text))
            return TurnAnswer(text=answer_text, conversation_id=active_conv_id, turn_number=len(self.turns))

        # 2. Execution acknowledgement turn
        if question == EXECUTION_PROMPT:
            turn_rec["type"] = "execution"
            self.execution_requests.append(turn_rec)
            fw_ids = {u["source_id"] for u in self.framework_uploads}
            if fw_ids:
                assert any(sid in fw_ids for sid in source_ids), "Execution scope MUST include framework source ID!"

            answer_text = "System Ready"
            self.conversation_histories.setdefault(active_conv_id, []).append((question, answer_text))
            return TurnAnswer(text=answer_text, conversation_id=active_conv_id, turn_number=len(self.turns))

        # 3. Lesson execution turns (Start or Continue)
        fw_ids = {u["source_id"] for u in self.framework_uploads}
        if fw_ids:
            assert any(sid in fw_ids for sid in source_ids), "Lesson execution scope MUST include framework source ID!"

        if question == "Start with First lesson":
            turn_rec["type"] = "start"
            self.start_requests.append(turn_rec)
            self.current_lesson_ordinal = 1
        else:
            turn_rec["type"] = "continue"
            self.continue_requests.append(turn_rec)
            self.current_lesson_ordinal += 1

        if self.current_pdf_ordinal == 1:
            if self.current_lesson_ordinal == 1:
                # PDF 1, Lesson 1: 2 scenes
                answer_text = make_valid_combined_lesson(
                    1,
                    "Rectilinear Particle Motion",
                    [
                        ("Kinematic Derivatives", "Phase Space Explorer", "phase space"),
                        ("Velocity Time Curve", "Dynamic Vector Plot", "vector plot"),
                    ],
                )
            else:
                # PDF 1, Lesson 2: 1 scene
                answer_text = make_valid_combined_lesson(
                    2,
                    "Curvilinear Trajectory",
                    [
                        ("Path Acceleration", "Osculating Circle Demo", "osculating circle"),
                    ],
                )
        else:
            # PDF 2
            if self.current_lesson_ordinal == 1:
                # PDF 2, Lesson 1: 2 scenes
                answer_text = make_valid_combined_lesson(
                    1,
                    "Newton Second Law",
                    [
                        ("Dynamic Equilibrium", "Friction Threshold Visualizer", "friction threshold"),
                        ("Normal Force Reaction", "Normal Reaction Vector", "normal reaction"),
                    ],
                )
            elif self.current_lesson_ordinal == 2:
                # PDF 2, Lesson 2: 1 scene
                answer_text = make_valid_combined_lesson(
                    2,
                    "Work and Energy",
                    [
                        ("Work Energy Principle", "Work Energy Integrator", "work energy"),
                    ],
                )
            else:
                # PDF 2, Lesson 3: 3 scenes
                answer_text = make_valid_combined_lesson(
                    3,
                    "Impulse and Momentum",
                    [
                        ("Impact Dynamics", "Collision Momentum Bar", "momentum bar"),
                        ("Restitution Coefficient", "Restitution Coefficient Gauge", "restitution gauge"),
                        ("Kinetic Energy Loss", "Kinetic Energy Loss 3D", "energy loss 3D"),
                    ],
                )

        self.conversation_histories.setdefault(active_conv_id, []).append((question, answer_text))
        return TurnAnswer(text=answer_text, conversation_id=active_conv_id, turn_number=len(self.turns))

    async def reset_conversation(self, notebook_id: str, conversation_id: str) -> None:
        self.resets.append({
            "notebook_id": notebook_id,
            "conversation_id": conversation_id,
            "pdf_ordinal_before_reset": self.current_pdf_ordinal,
            "time": time.time(),
        })
        # Empty conversation history for the reset conversation so history readback is clean
        self.conversation_histories[conversation_id] = []
        # Advance PDF ordinal and reset lesson ordinal counter for next PDF
        self.current_pdf_ordinal += 1
        self.current_lesson_ordinal = 0
        self.conversation_id = f"conv-var-transport-{self.current_pdf_ordinal}"

    # Invariant assertions
    def assert_framework_uploaded_once(self) -> None:
        assert len(self.framework_uploads) == 1, f"Framework must be uploaded exactly once, got {len(self.framework_uploads)}"

    def assert_divide_scope_pdf_only(self) -> None:
        divide_turns = [t for t in self.turns if t.get("type") == "divide"]
        assert len(divide_turns) >= 2, f"Expected at least 2 divide turns, got {len(divide_turns)}"
        fw_ids = {u["source_id"] for u in self.framework_uploads}
        for dt in divide_turns:
            assert not any(sid in fw_ids for sid in dt["source_ids"]), "Divide query scope must NOT contain framework!"

    def assert_lesson_scope_includes_framework(self) -> None:
        lesson_turns = [t for t in self.turns if t.get("type") in ("execution", "start", "continue")]
        assert len(lesson_turns) >= 5, f"Expected at least 5 lesson turns, got {len(lesson_turns)}"
        fw_ids = {u["source_id"] for u in self.framework_uploads}
        for et in lesson_turns:
            assert any(sid in fw_ids for sid in et["source_ids"]), "Execution query scope MUST contain framework!"

    def assert_reset_between_pdfs(self) -> None:
        assert len(self.resets) >= 1, f"Expected conversation reset between PDFs, got {len(self.resets)}"


class VariableSessionProvider:
    def __init__(self, transport: VariableNotebookLMTransport):
        self.transport = transport

    def open(self, storage_path=None):
        from contextlib import asynccontextmanager

        @asynccontextmanager
        async def _ctx():
            class _Session:
                def __init__(self, t):
                    self.files = t
                    self.conversation = t
            yield _Session(self.transport)

        return _ctx()

