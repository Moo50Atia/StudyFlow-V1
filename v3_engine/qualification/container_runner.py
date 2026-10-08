"""
Container runner invoking isolated Playwright Chromium container for V3 Scene Qualification.
"""

import base64
import json
import os
import subprocess
from pathlib import Path
from typing import Any, Dict

IMAGE_TAG = "v3-scene-checker:012-v1"

def run_container_check(candidate_tar_bytes: bytes, candidate_ref: str, manifest_sha256: str) -> Dict[str, Any]:
    seccomp_path = Path(__file__).parent / "seccomp-profile.json"
    
    payload = {
        "candidate_ref": candidate_ref,
        "manifest_sha256": manifest_sha256,
        "tar_base64": base64.b64encode(candidate_tar_bytes).decode("ascii")
    }
    input_bytes = json.dumps(payload).encode("utf-8")

    cmd = [
        "docker", "run", "--rm", "-i",
        "--network", "none",
        "--ipc", "private",
        "--shm-size=256m",
        "--read-only",
        "--cap-drop=ALL",
        "--security-opt", "no-new-privileges",
        f"--security-opt=seccomp={seccomp_path}",
        "--memory=1g",
        "--cpus=1",
        "--pids-limit=128",
        "--tmpfs", "/tmp:rw,nosuid,size=128m",
        "-e", "HOME=/tmp",
        "--user", "10001:10001",
        IMAGE_TAG
    ]

    try:
        proc = subprocess.run(
            cmd,
            input=input_bytes,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=60
        )
    except subprocess.TimeoutExpired:
        return {
            "success": False,
            "error": "checker_unavailable",
            "report": None,
            "screenshot_png": None
        }
    except Exception as e:
        return {
            "success": False,
            "error": "checker_unavailable",
            "report": None,
            "screenshot_png": None
        }

    try:
        raw_stdout = proc.stdout.decode("utf-8") if proc.stdout else ""
        out_json = json.loads(raw_stdout)
        if not isinstance(out_json, dict) or not out_json.get("success", False):
            err = out_json.get("error") if isinstance(out_json, dict) else "checker_unavailable"
            return {
                "success": False,
                "error": err or "checker_unavailable",
                "report": None,
                "screenshot_png": None
            }
        report = out_json.get("report")
        if not isinstance(report, dict):
            return {
                "success": False,
                "error": "checker_unavailable",
                "report": None,
                "screenshot_png": None
            }
        return {
            "success": True,
            "report": report,
            "screenshot_png": report.get("screenshot_png_base64")
        }
    except Exception:
        return {
            "success": False,
            "error": "checker_unavailable",
            "report": None,
            "screenshot_png": None
        }
