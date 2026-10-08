"""
Container sandbox entrypoint for V3 Scene Qualification.
Reads JSON payload containing tar_base64 from stdin, unpacks candidate,
executes browser checks, and writes report JSON to stdout.
"""

import asyncio
import base64
import hashlib
import io
import json
import os
import sys
import tarfile

from v3_engine.qualification.browser_checks import run_browser_checks

def main():
    try:
        raw_input = sys.stdin.buffer.read()
        if not raw_input:
            sys.stdout.write(json.dumps({"success": False, "error": "empty_input"}))
            sys.stdout.flush()
            sys.exit(1)

        payload = json.loads(raw_input.decode("utf-8"))
        candidate_ref = payload.get("candidate_ref", "00000000-0000-0000-0000-000000000000")
        manifest_sha256 = payload.get("manifest_sha256", "")
        tar_b64 = payload.get("tar_base64", "")
        
        try:
            tar_bytes = base64.b64decode(tar_b64)
        except Exception:
            sys.stdout.write(json.dumps({"success": False, "error": "integrity_mismatch"}))
            sys.stdout.flush()
            sys.exit(1)

        work_dir = "/tmp/candidate"
        os.makedirs(work_dir, exist_ok=True)
        try:
            with tarfile.open(fileobj=io.BytesIO(tar_bytes), mode="r:*") as tar:
                work_dir_abs = os.path.abspath(work_dir)
                for member in tar.getmembers():
                    if not (member.isreg() or member.isdir()):
                        raise ValueError(f"Unsafe tar entry type: {member.name}")
                    target = os.path.abspath(os.path.join(work_dir, member.name))
                    if not (target == work_dir_abs or target.startswith(work_dir_abs + os.sep)):
                        raise ValueError(f"Tar path traversal: {member.name}")
                if hasattr(tarfile, "data_filter"):
                    tar.extractall(path=work_dir, filter="data")
                else:
                    tar.extractall(path=work_dir)
        except Exception:
            sys.stdout.write(json.dumps({"success": False, "error": "checker_unavailable"}))
            sys.stdout.flush()
            sys.exit(1)

        manifest_path = os.path.join(work_dir, "manifest.json")
        if not os.path.isfile(manifest_path):
            sys.stdout.write(json.dumps({"success": False, "error": "integrity_mismatch"}))
            sys.stdout.flush()
            sys.exit(1)

        with open(manifest_path, "rb") as f:
            manifest_bytes = f.read()
        if not manifest_sha256 or hashlib.sha256(manifest_bytes).hexdigest() != manifest_sha256:
            sys.stdout.write(json.dumps({"success": False, "error": "integrity_mismatch"}))
            sys.stdout.flush()
            sys.exit(1)
        manifest = json.loads(manifest_bytes.decode("utf-8"))

        report = asyncio.run(run_browser_checks(work_dir, candidate_ref, manifest, manifest_sha256))
        result = {
            "success": True,
            "report": report
        }
        sys.stdout.write(json.dumps(result))
        sys.stdout.flush()
    except Exception as e:
        sys.stdout.write(json.dumps({"success": False, "error": "checker_unavailable", "detail": str(e)[:256]}))
        sys.stdout.flush()
        sys.exit(1)

if __name__ == "__main__":
    main()
