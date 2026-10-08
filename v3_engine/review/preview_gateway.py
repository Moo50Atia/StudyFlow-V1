"""
Preview Gateway for V3 Interactive Scene Review (Spec 013).
Manages isolated container processes running preview_entry.py, action advancement,
replay caching, and timeout enforcement.
"""

import base64
import json
import logging
import os
import select
import subprocess
import threading
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional

logger = logging.getLogger(__name__)

IMAGE_TAG = "v3-scene-checker:012-v1"
# Preview initialization includes Chromium startup plus an internal 15-second
# page-load deadline. Leave enough outer time to return the fail-closed frame
# and blocked-request evidence when a candidate attempts external navigation.
PREVIEW_START_TIMEOUT_SECONDS = 30
PREVIEW_MAX_INIT_LINE_BYTES = 1024 * 1024


def _read_init_line(stream, timeout_seconds: float) -> str:
    """Read one newline terminated JSON frame without an unbounded readline."""
    deadline = time.monotonic() + timeout_seconds
    chunks = bytearray()
    while len(chunks) <= PREVIEW_MAX_INIT_LINE_BYTES:
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise TimeoutError("Preview initialization timed out")
        ready, _, _ = select.select([stream], [], [], remaining)
        if not ready:
            raise TimeoutError("Preview initialization timed out")
        part = os.read(stream.fileno(), 4096)
        if not part:
            return chunks.decode("utf-8") if chunks else ""
        chunks.extend(part)
        newline = chunks.find(b"\n")
        if newline >= 0:
            return bytes(chunks[:newline]).decode("utf-8")
    raise ValueError("Preview initialization response is too large")

class PreviewGatewaySession:
    def __init__(
        self,
        viewer_ref: str,
        candidate_ref: str,
        manifest_sha256: str,
        files: List[Dict[str, str]],
        use_docker: bool = True
    ):
        self.viewer_ref = viewer_ref
        self.candidate_ref = candidate_ref
        self.manifest_sha256 = manifest_sha256
        self.files = files
        self.use_docker = use_docker
        self.proc: Optional[subprocess.Popen] = None
        self.created_at = time.time()
        self.last_action_at = time.time()
        self.frames: Dict[int, dict] = {}
        self.action_history: Dict[int, dict] = {} # frame_no -> action_payload
        self.current_frame_no = 0
        self.closed = False
        self.lock = threading.Lock()

    def start(self) -> dict:
        with self.lock:
            if self.closed:
                return {"success": False, "error": "session_closed"}

            # Build command to launch preview container
            if self.use_docker:
                entry_path = "/app/v3_engine/review/preview_entry.py"
                cmd = [
                    "docker", "run", "-i", "--rm",
                    "--network", "none",
                    "--read-only",
                    "--cap-drop=ALL",
                    "--security-opt", "no-new-privileges",
                    "--memory=1g",
                    "--cpus=1",
                    "--pids-limit=128",
                    "--tmpfs", "/tmp:rw,nosuid,size=128m",
                    "-e", "HOME=/tmp",
                    "--user", "10001:10001",
                    "--entrypoint", "python3",
                    IMAGE_TAG,
                    entry_path
                ]
            else:
                # Local fallback execution
                entry_file = Path(__file__).parent / "preview_entry.py"
                cmd = ["python3", str(entry_file)]

            try:
                self.proc = subprocess.Popen(
                    cmd,
                    stdin=subprocess.PIPE,
                    stdout=subprocess.PIPE,
                    stderr=subprocess.PIPE,
                    text=True,
                    bufsize=1
                )
            except Exception as e:
                logger.error(f"Failed to launch preview process: {e}")
                return {"success": False, "error": "review_unavailable"}

            init_payload = {
                "command": "init",
                "viewer_ref": self.viewer_ref,
                "candidate_ref": self.candidate_ref,
                "manifest_sha256": self.manifest_sha256,
                "files": self.files
            }

            try:
                self.proc.stdin.write(json.dumps(init_payload) + "\n")
                self.proc.stdin.flush()

                response_line = _read_init_line(self.proc.stdout, PREVIEW_START_TIMEOUT_SECONDS)
                if not response_line:
                    self._close_locked()
                    return {"success": False, "error": "review_unavailable"}

                res = json.loads(response_line)
                if not res.get("success", False):
                    self._close_locked()
                    return res

                frame0 = res.get("frame", {})
                self.frames[0] = frame0
                self.current_frame_no = 0
                return res
            except Exception as e:
                logger.error(f"Error during preview init: {e}")
                self._close_locked()
                return {"success": False, "error": "review_unavailable"}

    def apply_action(self, action: dict) -> dict:
        with self.lock:
            if self.closed or not self.proc or self.proc.poll() is not None:
                return {"success": False, "error": "session_closed"}

            expected_frame_no = int(action.get("expected_frame_no", 0))

            # Idempotency / exact replay check
            if expected_frame_no in self.action_history:
                prior_action, prior_frame = self.action_history[expected_frame_no]
                # Compare canonical representations
                if json.dumps(prior_action, sort_keys=True) == json.dumps(action, sort_keys=True):
                    return {
                        "success": True,
                        "viewer_ref": self.viewer_ref,
                        "frame_no": prior_frame["frame_no"],
                        "frame": prior_frame
                    }
                else:
                    return {"success": False, "error": "review_conflict"}

            if expected_frame_no != self.current_frame_no:
                return {"success": False, "error": "review_conflict"}

            action_cmd = {
                "command": "action",
                "expected_frame_no": expected_frame_no,
                "kind": action.get("kind"),
                "payload": action.get("payload", {})
            }

            try:
                self.proc.stdin.write(json.dumps(action_cmd) + "\n")
                self.proc.stdin.flush()

                response_line = self.proc.stdout.readline()
                if not response_line:
                    return {"success": False, "error": "review_unavailable"}

                res = json.loads(response_line)
                if not res.get("success", False):
                    return res

                new_frame = res.get("frame", {})
                new_frame_no = new_frame.get("frame_no", self.current_frame_no + 1)
                self.frames[new_frame_no] = new_frame
                self.action_history[expected_frame_no] = (action, new_frame)
                self.current_frame_no = new_frame_no
                self.last_action_at = time.time()

                return res
            except Exception as e:
                logger.error(f"Error applying preview action: {e}")
                return {"success": False, "error": "review_unavailable"}

    def get_frame(self, frame_no: int) -> Optional[dict]:
        with self.lock:
            return self.frames.get(frame_no)

    def is_expired(self) -> bool:
        now = time.time()
        # 30-minute absolute lifetime, 15-minute idle kill
        if now - self.created_at > 1800:
            return True
        if now - self.last_action_at > 900:
            return True
        return False

    def close(self):
        with self.lock:
            self._close_locked()

    def _close_locked(self):
        """Close resources while the caller owns ``self.lock``."""
        if self.closed:
            return
        self.closed = True
        proc, self.proc = self.proc, None
        if proc:
            try:
                if proc.stdin:
                    proc.stdin.write(json.dumps({"command": "close"}) + "\n")
                    proc.stdin.flush()
                proc.terminate()
                proc.wait(timeout=2)
            except Exception:
                try:
                    proc.kill()
                    proc.wait(timeout=2)
                except Exception:
                    pass


class PreviewGateway:
    _instance = None

    def __init__(self):
        self.sessions: Dict[str, PreviewGatewaySession] = {}
        self.lock = threading.Lock()

    @classmethod
    def get_instance(cls) -> 'PreviewGateway':
        if cls._instance is None:
            cls._instance = PreviewGateway()
        return cls._instance

    def start_session(
        self,
        viewer_ref: str,
        candidate_ref: str,
        manifest_sha256: str,
        files: List[Dict[str, str]]
    ) -> dict:
        with self.lock:
            self.reap_expired()

            # Max 8 concurrent sessions
            active_count = sum(1 for s in self.sessions.values() if not s.closed)
            if active_count >= 8:
                return {"success": False, "error": "review_unavailable", "message": "Maximum concurrent sessions reached."}

            if viewer_ref in self.sessions:
                existing = self.sessions[viewer_ref]
                if not existing.closed and existing.manifest_sha256 == manifest_sha256:
                    frame0 = existing.get_frame(0)
                    return {
                        "success": True,
                        "is_replay": True,
                        "viewer_ref": viewer_ref,
                        "frame_no": 0,
                        "frame": frame0
                    }
                elif not existing.closed:
                    return {"success": False, "error": "review_conflict"}

            session = PreviewGatewaySession(
                viewer_ref=viewer_ref,
                candidate_ref=candidate_ref,
                manifest_sha256=manifest_sha256,
                files=files
            )
            self.sessions[viewer_ref] = session

        return session.start()

    def apply_action(self, viewer_ref: str, action: dict) -> dict:
        session = self.get_session(viewer_ref)
        if not session or session.closed:
            return {"success": False, "error": "resource_unavailable"}
        return session.apply_action(action)

    def get_frame(self, viewer_ref: str, frame_no: int) -> Optional[dict]:
        session = self.get_session(viewer_ref)
        if not session or session.closed:
            return None
        return session.get_frame(frame_no)

    def close_session(self, viewer_ref: str):
        with self.lock:
            session = self.sessions.pop(viewer_ref, None)
            if session:
                session.close()

    def get_session(self, viewer_ref: str) -> Optional[PreviewGatewaySession]:
        with self.lock:
            session = self.sessions.get(viewer_ref)
            if session and session.is_expired():
                session.close()
                self.sessions.pop(viewer_ref, None)
                return None
            return session

    def reap_expired(self):
        expired = [ref for ref, s in self.sessions.items() if s.is_expired() or s.closed]
        for ref in expired:
            s = self.sessions.pop(ref, None)
            if s:
                s.close()


def run_preview_container_session(
    viewer_ref: str,
    candidate_ref: str,
    manifest_sha256: str,
    files: List[Dict[str, str]]
) -> PreviewGatewaySession:
    gateway = PreviewGateway.get_instance()
    res = gateway.start_session(viewer_ref, candidate_ref, manifest_sha256, files)
    session = gateway.get_session(viewer_ref)
    return session
