"""Qualification activity and workflow adapter for Spec 012."""
import asyncio
import base64
import hashlib
import io
import json
import re
import tarfile
import time
from typing import Any, Dict, List, Optional, Tuple
import httpx
from temporalio import activity
from temporalio.exceptions import ApplicationError

from v3_engine.qualification.container_runner import run_container_check
from v3_engine.qualification.client import SceneJevAttemptClient, get_jev_attempt_client as _get_jev_attempt_client
from v3_engine.qualification.jev import SceneJevClient, get_scene_jev_client as _get_scene_jev_client, SceneJevTechnicalError
from v3_engine.qualification.repair import SceneRepairClient, RepairRunner
from v3_engine.settings import get_settings

ALLOWED_EXTENSIONS = {".html", ".js", ".css", ".svg", ".json"}
SEGMENT_REGEX = re.compile(r"^[A-Za-z0-9._-]+$")


class PackageValidationError(ValueError):
    """Raised when scene candidate package fails validation."""
    pass


def build_candidate_tar(
    files: List[Dict[str, Any]],
    manifest: Dict[str, Any],
    manifest_raw: Optional[bytes] = None,
    expected_manifest_sha256: Optional[str] = None,
) -> Tuple[bytes, str]:
    """
    Validate candidate files against manifest and build a safe, reproducible in-memory tar.gz.

    Revalidates:
    - Path safety (no path traversal, no leading slashes, no symlinks, safe segment chars).
    - Presence of index.html.
    - File contents match manifest sha256 and size (bytes or size_bytes).
    - Preserves exact manifest bytes if provided; otherwise encodes canonical json.
    - Returns (tar_bytes, manifest_sha256).
    """
    if not isinstance(manifest, dict):
        raise PackageValidationError("Manifest must be a dict")

    # Preserve exact manifest bytes if provided; otherwise serialize canonically
    if manifest_raw is not None:
        manifest_bytes = manifest_raw
    else:
        manifest_bytes = json.dumps(manifest, separators=(",", ":")).encode("utf-8")

    computed_manifest_sha = hashlib.sha256(manifest_bytes).hexdigest()
    if expected_manifest_sha256:
        if computed_manifest_sha.lower() != expected_manifest_sha256.lower():
            raise PackageValidationError(
                f"Mismatched manifest digest: expected {expected_manifest_sha256}, got {computed_manifest_sha}"
            )

    declared_files = manifest.get("files", [])
    entrypoint = manifest.get("entrypoint", "index.html")

    files_by_path: Dict[str, Any] = {}
    for f in files:
        if not isinstance(f, dict) or "path" not in f or "content" not in f:
            raise PackageValidationError("File items must be dicts with path and content")
        p = f["path"]
        if not isinstance(p, str) or not p:
            raise PackageValidationError("File path must be a non-empty string")
        if "\\" in p or ":" in p or p.startswith("/"):
            raise PackageValidationError(f"Unsafe tar entry path: {p}")

        segments = p.split("/")
        if len(segments) < 1 or len(segments) > 4:
            raise PackageValidationError(f"Path must have 1-4 segments: {p}")
        for seg in segments:
            if not seg or seg in {".", ".."} or seg.startswith(".") or not SEGMENT_REGEX.match(seg):
                raise PackageValidationError(f"Unsafe tar entry path segment in: {p}")

        dot_idx = p.rfind(".")
        if dot_idx == -1 or p[dot_idx:].lower() not in ALLOWED_EXTENSIONS:
            raise PackageValidationError(f"Disallowed extension in: {p}")

        files_by_path[p] = f

    if entrypoint not in files_by_path:
        raise PackageValidationError(f"Entrypoint '{entrypoint}' missing from files")

    # Validate against manifest declarations
    for decl in declared_files:
        p = decl.get("path")
        if not p or p not in files_by_path:
            raise PackageValidationError(f"Missing declared file: {p}")
        content = files_by_path[p]["content"]
        content_bytes = content.encode("utf-8") if isinstance(content, str) else content

        expected_size = decl.get("bytes") if "bytes" in decl else decl.get("size_bytes")
        if expected_size is not None and len(content_bytes) != expected_size:
            raise PackageValidationError(
                f"Corrupted artifact bytes size mismatch for {p}: expected {expected_size}, got {len(content_bytes)}"
            )
        decl_sha = decl.get("sha256")
        if decl_sha:
            actual_sha = hashlib.sha256(content_bytes).hexdigest()
            if actual_sha.lower() != decl_sha.lower():
                raise PackageValidationError(
                    f"Mismatched digest for {p}: expected {decl_sha}, got {actual_sha}"
                )

    # Build tar safely in memory
    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode="w:gz") as tar:
        # Add manifest.json
        tarinfo = tarfile.TarInfo(name="manifest.json")
        tarinfo.size = len(manifest_bytes)
        tarinfo.mtime = int(time.time())
        tarinfo.mode = 0o644
        tarinfo.type = tarfile.REGTYPE
        tar.addfile(tarinfo, io.BytesIO(manifest_bytes))

        # Add files in deterministic sorted order
        for p in sorted(files_by_path.keys()):
            c = files_by_path[p]["content"]
            c_bytes = c.encode("utf-8") if isinstance(c, str) else c
            tarinfo = tarfile.TarInfo(name=p)
            tarinfo.size = len(c_bytes)
            tarinfo.mtime = int(time.time())
            tarinfo.mode = 0o644
            tarinfo.type = tarfile.REGTYPE
            tar.addfile(tarinfo, io.BytesIO(c_bytes))

    return buf.getvalue(), computed_manifest_sha


class SceneCheckClient:
    """Signed HMAC client for internal scene check routes."""

    def __init__(self, base_url: str, hmac_key: str):
        self.base_url = base_url.rstrip("/")
        self.hmac_key = hmac_key

    def _sign_headers(self, method: str, path: str, body_bytes: bytes) -> Dict[str, str]:
        from v3_engine.contracts import compute_hmac_signature
        ts = int(time.time())
        sig = compute_hmac_signature(method, path, ts, body_bytes, self.hmac_key)
        return {
            "X-V3-Timestamp": str(ts),
            "X-V3-Signature": sig,
            "X-V3-Contract-Version": "1",
            "Content-Type": "application/json",
            "Accept": "application/json",
        }

    async def _request(self, method: str, path: str, payload: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
        raw_body = json.dumps(payload, separators=(",", ":")).encode("utf-8") if payload is not None else b""
        headers = self._sign_headers(method, path, raw_body)
        url = f"{self.base_url}{path}"
        async with httpx.AsyncClient(timeout=30.0) as client:
            if method.upper() == "GET":
                resp = await client.get(url, headers=headers)
            else:
                resp = await client.post(url, content=raw_body, headers=headers)
            if resp.status_code >= 400:
                raise RuntimeError(f"Internal API {method} {path} returned {resp.status_code}: {resp.text}")
            return resp.json().get("data", {})

    async def claim_attempt(self, case_id: str, candidate_ref: str, attempt_no: int) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/scene-check-attempts"
        payload = {"schema_version": 1, "candidate_ref": candidate_ref, "attempt_no": attempt_no}
        return await self._request("POST", path, payload)

    async def record_result(
        self,
        case_id: str,
        attempt_ref: str,
        report_json: str,
        report_sha: str,
        screenshot_b64: str,
        screenshot_sha: str,
    ) -> Dict[str, Any]:
        path = f"/api/internal/v1/cases/{case_id}/scene-check-attempts/{attempt_ref}/result"
        payload = {
            "schema_version": 1,
            "report_json": report_json,
            "report_sha256": report_sha,
            "screenshot_png_base64": screenshot_b64,
            "screenshot_sha256": screenshot_sha,
        }
        return await self._request("POST", path, payload)


def get_check_client() -> SceneCheckClient:
    settings = get_settings()
    if not settings.callback_hmac_key:
        raise RuntimeError("V3_CALLBACK_HMAC_KEY is not configured.")
    return SceneCheckClient(settings.laravel_api_origin, settings.callback_hmac_key)


def get_jev_attempt_client() -> SceneJevAttemptClient:
    return _get_jev_attempt_client()


get_jev_client = get_jev_attempt_client


def get_scene_jev_client() -> SceneJevClient:
    return _get_scene_jev_client()


def get_repair_client() -> SceneRepairClient:
    settings = get_settings()
    if not settings.callback_hmac_key:
        raise RuntimeError("V3_CALLBACK_HMAC_KEY is not configured.")
    return SceneRepairClient(settings.laravel_api_origin, settings.callback_hmac_key)


def get_authoring_client():
    from v3_engine.authoring.activity import AuthoringClient
    settings = get_settings()
    if not settings.callback_hmac_key:
        raise RuntimeError("V3_CALLBACK_HMAC_KEY is not configured.")
    return AuthoringClient(settings.laravel_api_origin, settings.callback_hmac_key)


async def run_browser_checks_in_sandbox(
    candidate_tar_bytes: bytes,
    candidate_ref: str,
    manifest_sha256: str,
) -> Dict[str, Any]:
    """
    Execute container check without blocking the async event loop.
    Returns the result dictionary from run_container_check:
    {"success": bool, "error": Optional[str], "report": Optional[dict], "screenshot_png": Optional[str]}
    """
    return await asyncio.to_thread(
        run_container_check,
        candidate_tar_bytes,
        candidate_ref,
        manifest_sha256,
    )


async def call_jev_provider(
    model: str,
    evidence: Dict[str, Any],
    questions: Optional[Dict[str, Any]] = None,
    evaluator: Optional[SceneJevClient] = None,
) -> str:
    """Invoke the implemented TypeSafe evaluator with validated credentials and canonical questions."""
    evaluator = evaluator or get_scene_jev_client()
    result = await evaluator.evaluate_scene(evidence, model=model)
    return json.dumps(result, separators=(",", ":"))


@activity.defn(name="qualify_scene_activity")
async def qualify_scene_activity(payload: Dict[str, Any]) -> Dict[str, Any]:
    case_id = payload["case_id"]
    candidate_ref = payload["candidate_ref"]
    manifest_sha = payload.get("manifest_sha256")
    package_files = payload.get("package_files")
    manifest = payload.get("manifest")
    manifest_raw = payload.get("manifest_raw")

    check_client = get_check_client()
    jev_client = get_jev_client()

    # 1. Fetch package from Laravel if not passed in payload
    if package_files is None:
        try:
            authoring_client = get_authoring_client()
            candidate_data = await authoring_client.get_candidate(case_id, candidate_ref)
            if isinstance(candidate_data, dict):
                package_files = candidate_data.get("files", [])
                manifest = candidate_data.get("manifest", {})
                if not manifest_sha:
                    manifest_sha = candidate_data.get("manifest_sha256")
                if not manifest_raw:
                    encoded_manifest = candidate_data.get("manifest_raw_base64")
                    if isinstance(encoded_manifest, str):
                        manifest_raw = base64.b64decode(encoded_manifest, validate=True)
                    else:
                        manifest_raw = candidate_data.get("manifest_raw")
        except Exception as exc:
            return {
                "status": "technical_failure",
                "error": f"Failed to fetch candidate package: {exc}",
                "candidate_ref": candidate_ref,
            }

    # If manifest is still None but package_files is provided (e.g. mock test payload)
    if manifest is None and package_files is not None:
        manifest = {
            "schema_version": 1,
            "entrypoint": "index.html",
            "files": [
                {
                    "path": f["path"],
                    "sha256": hashlib.sha256((f["content"].encode("utf-8") if isinstance(f["content"], str) else f["content"])).hexdigest(),
                    "bytes": len(f["content"].encode("utf-8") if isinstance(f["content"], str) else f["content"]),
                }
                for f in package_files
            ],
        }

    # 2. Revalidate and build candidate tar
    try:
        candidate_tar_bytes, computed_manifest_sha = build_candidate_tar(
            files=package_files,
            manifest=manifest,
            manifest_raw=manifest_raw,
            expected_manifest_sha256=manifest_sha if (manifest_sha and len(manifest_sha) == 64) else None,
        )
    except PackageValidationError as e:
        return {
            "status": "technical_failure",
            "error": str(e),
            "candidate_ref": candidate_ref,
        }

    effective_manifest_sha = manifest_sha or computed_manifest_sha

    # 3. Claim check attempt
    claim_check = await check_client.claim_attempt(case_id, candidate_ref, attempt_no=1)
    attempt_ref = claim_check["attempt_ref"]

    if claim_check.get("disposition") == "recorded":
        # A prior attempt committed its result but the Temporal activity may
        # have lost the acknowledgement. Reuse the persisted run identity;
        # never execute Chromium again and create a conflicting report.
        check_state = claim_check.get("verdict")
        check_ref = claim_check.get("check_ref")
    else:
        # 4. Run browser check in sandbox
        runner_res = await run_browser_checks_in_sandbox(
            candidate_tar_bytes,
            candidate_ref,
            effective_manifest_sha,
        )

        if not isinstance(runner_res, dict):
            return {
                "status": "technical_failure",
                "error": "checker_unavailable",
                "candidate_ref": candidate_ref,
                "attempt_ref": attempt_ref,
            }

        if runner_res.get("report") is not None:
            if not runner_res.get("success", False):
                return {
                    "status": "technical_failure",
                    "error": runner_res.get("error", "checker_unavailable"),
                    "candidate_ref": candidate_ref,
                    "attempt_ref": attempt_ref,
                }
            report = runner_res["report"]
            screenshot_b64 = runner_res.get("screenshot_png") or report.get("screenshot_png_base64")
        elif "checks" in runner_res:
            report = runner_res
            screenshot_b64 = runner_res.get("screenshot_png_base64")
        else:
            return {
                "status": "technical_failure",
                "error": runner_res.get("error", "checker_unavailable"),
                "candidate_ref": candidate_ref,
                "attempt_ref": attempt_ref,
            }

        if not screenshot_b64:
            return {
                "status": "technical_failure",
                "error": "missing_screenshot",
                "candidate_ref": candidate_ref,
                "attempt_ref": attempt_ref,
            }

        try:
            screenshot_bytes = base64.b64decode(screenshot_b64)
        except Exception:
            return {
                "status": "technical_failure",
                "error": "invalid_screenshot_base64",
                "candidate_ref": candidate_ref,
                "attempt_ref": attempt_ref,
            }

        if not screenshot_bytes.startswith(b"\x89PNG\r\n\x1a\n"):
            return {
                "status": "technical_failure",
                "error": "invalid_screenshot_png",
                "candidate_ref": candidate_ref,
                "attempt_ref": attempt_ref,
            }

        screenshot_sha = hashlib.sha256(screenshot_bytes).hexdigest()
        report_json = json.dumps(report, separators=(",", ":"))
        report_sha = hashlib.sha256(report_json.encode("utf-8")).hexdigest()

        # 5. Record check result
        check_result = await check_client.record_result(
            case_id=case_id,
            attempt_ref=attempt_ref,
            report_json=report_json,
            report_sha=report_sha,
            screenshot_b64=screenshot_b64,
            screenshot_sha=screenshot_sha,
        )

        check_state = check_result.get("state")
        check_ref = check_result.get("check_ref")

    if check_state != "pass":
        return {
            "status": "check_failed",
            "candidate_ref": candidate_ref,
            "check_ref": check_ref,
            "qualification_ref": None,
        }

    # 6. Check passed -> Claim JEV attempt with Laravel client
    claim_jev = await jev_client.claim_attempt(case_id, candidate_ref, check_ref, attempt_no=1)
    jev_attempt_ref = claim_jev["attempt_ref"]

    # 7. Fetch JEV context from Laravel
    context = await jev_client.get_context(case_id, jev_attempt_ref)
    model = context.get("model", "jev-latest")
    evidence = context.get("evidence", {})

    # 8. Call TypeSafe evaluator
    try:
        raw_answer_json = await call_jev_provider(model, evidence, {})
    except SceneJevTechnicalError as exc:
        await jev_client.record_technical_failure(
            case_id=case_id,
            attempt_ref=jev_attempt_ref,
            error_code=exc.error_code,
        )
        return {
            "status": "technical_failure",
            "error": exc.error_code,
            "candidate_ref": candidate_ref,
            "attempt_ref": jev_attempt_ref,
        }

    eval_result = json.loads(raw_answer_json) if isinstance(raw_answer_json, str) else raw_answer_json
    returned_model = eval_result.get("model", model) if isinstance(eval_result, dict) else model
    if not isinstance(raw_answer_json, str):
        raw_answer_json = json.dumps(raw_answer_json, separators=(",", ":"))
    answer_sha = hashlib.sha256(raw_answer_json.encode("utf-8")).hexdigest()

    # 9. Record JEV result in Laravel
    jev_res = await jev_client.record_result(
        case_id,
        jev_attempt_ref,
        raw_answer_json,
        answer_sha,
        returned_model,
    )

    decision = jev_res.get("decision")
    qual_ref = jev_res.get("qualification_ref")

    if decision == "pass":
        return {
            "status": "qualified",
            "candidate_ref": candidate_ref,
            "check_ref": check_ref,
            "qualification_ref": qual_ref,
        }
    elif decision == "revise":
        return {
            "status": "repair_pending",
            "candidate_ref": candidate_ref,
            "check_ref": check_ref,
            "qualification_ref": qual_ref,
        }
    else:
        return {
            "status": "blocked",
            "candidate_ref": candidate_ref,
            "check_ref": check_ref,
            "qualification_ref": qual_ref,
        }


@activity.defn(name="repair_scene_activity")
async def repair_scene_activity(payload: Dict[str, Any]) -> Dict[str, Any]:
    case_id = payload["case_id"]
    failed_candidate_ref = payload["failed_candidate_ref"]
    expected_ordinal = payload["expected_ordinal"]

    repair_client = get_repair_client()

    # 1. Claim repair
    try:
        claim_res = await repair_client.claim_repair(case_id, failed_candidate_ref, expected_ordinal)
    except Exception as exc:
        err_msg = str(exc)
        if "exhausted" in err_msg.lower() or "cannot be repaired" in err_msg.lower() or "terminal state" in err_msg.lower():
            return {"status": "blocked", "safe_error_code": "repair_budget_exhausted", "error": err_msg}
        if "held" in err_msg.lower() or "cancelled" in err_msg.lower():
            return {"status": "held", "safe_error_code": "case_held_or_cancelled", "error": err_msg}
        return {"status": "technical_failure", "safe_error_code": "claim_failed", "error": err_msg}

    repair_ref = claim_res["repair_ref"]
    authoring_request_ref = claim_res["authoring_request_ref"]

    # 2. Get repair context
    try:
        repair_context = await repair_client.get_repair_context(case_id, repair_ref)
    except Exception as exc:
        return {"status": "technical_failure", "safe_error_code": "context_failed", "error": str(exc)}

    prompt = repair_context.get("prompt", "")
    template_text = repair_context.get("template_text", "")

    # 3. Run repair attempt using RepairRunner
    authoring_client = get_authoring_client()
    settings = get_settings()
    api_keys = {
        "gemini_api_key": settings.gemini_api_key,
        "glm_api_key": settings.glm_api_key,
        "deepseek_api_key": settings.deepseek_api_key,
    }
    runner = RepairRunner(authoring_client=authoring_client, api_keys=api_keys, timeout_seconds=60.0)

    try:
        outcome = await runner.run_repair_attempt(
            case_id=case_id,
            request_ref=authoring_request_ref,
            ordinal=expected_ordinal,
            prompt=prompt,
            template_text=template_text,
            repair_context=repair_context,
        )
    except Exception as exc:
        return {"status": "technical_failure", "safe_error_code": "runner_failed", "error": str(exc)}

    if outcome.status == "candidate_ready":
        # Link repair candidate
        try:
            link_res = await repair_client.link_repair_candidate(
                case_id=case_id,
                repair_ref=repair_ref,
                candidate_ref=outcome.candidate_ref,
            )
            return {
                "status": "candidate_ready",
                "candidate_ref": outcome.candidate_ref,
                "repair_ref": repair_ref,
                "ordinal": expected_ordinal,
                "link_data": link_res,
            }
        except Exception as exc:
            return {"status": "technical_failure", "safe_error_code": "link_failed", "error": str(exc)}

    elif outcome.status == "uncertain":
        return {
            "status": "uncertain",
            "safe_error_code": outcome.safe_error_code or "repair_attempt_uncertain",
            "repair_ref": repair_ref,
        }
    else:
        return {
            "status": "blocked",
            "safe_error_code": outcome.safe_error_code or "repair_attempt_blocked",
            "repair_ref": repair_ref,
        }


@activity.defn(name="ensure_review_ready_activity")
async def ensure_review_ready_activity(payload: Dict[str, Any]) -> Dict[str, Any]:
    case_id = payload["case_id"]
    candidate_ref = payload["candidate_ref"]

    from v3_engine.review.activity import _get_review_client
    client = _get_review_client()
    try:
        data = await client.ensure_review_ready(case_id, candidate_ref)
        return {"status": "ready", "data": data}
    except ApplicationError as err:
        return {"status": "unavailable", "error": str(err)}
    except Exception as exc:
        return {"status": "technical_failure", "error": str(exc)}
