import hashlib
import json
import time
from typing import Any, Dict
import httpx
from temporalio import activity
from v3_engine.contracts import compute_hmac_signature
from v3_engine.settings import get_settings
from v3_engine.sources.extract_pdf import extract_pdf_source

@activity.defn(name="extract_case_source")
async def extract_case_source(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Activity for extracting case source."""
    case_id = payload["case_id"]
    activation_id = payload["activation_id"]

    settings = get_settings()
    hmac_key = settings.callback_hmac_key
    raw_url = settings.laravel_callback_url
    base_url = raw_url.rsplit("/case-events", 1)[0] if "/case-events" in raw_url else raw_url.rstrip("/")

    # 1. Download PDF stream
    pdf_path = f"/api/internal/v1/source-activations/{activation_id}/pdf"
    now_ts = int(time.time())
    sig = compute_hmac_signature("GET", pdf_path, now_ts, b"", hmac_key)
    headers = {
        "X-V3-Timestamp": str(now_ts),
        "X-V3-Signature": sig,
        "X-V3-Contract-Version": "1",
    }

    async with httpx.AsyncClient(timeout=60.0) as client:
        resp = await client.get(f"{base_url}/source-activations/{activation_id}/pdf", headers=headers)
        if resp.status_code != 200:
            raise RuntimeError(f"PDF download failed: {resp.status_code}")

        pdf_bytes = resp.content
        max_ocr_pages = int(resp.headers.get("X-V3-Max-OCR-Pages", "20"))

        # Verify sha256
        expected_sha = resp.headers.get("X-V3-Document-SHA256")
        if expected_sha and hashlib.sha256(pdf_bytes).hexdigest() != expected_sha:
            raise RuntimeError("PDF checksum mismatch from header")

        # 2. Extract PDF
        result = extract_pdf_source(pdf_bytes, max_ocr_pages=max_ocr_pages)

        # 3. Post extraction result to Laravel
        extraction_path = f"/api/internal/v1/source-activations/{activation_id}/extraction"
        extraction_payload = {
            "schema_version": 1,
            "case_id": case_id,
            "status": result.status,
            "text": result.text,
            "text_sha256": result.text_sha256,
            "page_report": [p.model_dump() for p in result.page_report],
            "extractor_version": result.extractor_version,
        }
        body_bytes = json.dumps(
            extraction_payload,
            separators=(",", ":"),
            ensure_ascii=False,
        ).encode("utf-8")
        now_ts = int(time.time())
        sig_post = compute_hmac_signature("POST", extraction_path, now_ts, body_bytes, hmac_key)
        post_headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "X-V3-Timestamp": str(now_ts),
            "X-V3-Signature": sig_post,
            "X-V3-Contract-Version": "1",
        }

        post_resp = await client.post(
            f"{base_url}/source-activations/{activation_id}/extraction",
            content=body_bytes,
            headers=post_headers,
        )

        if post_resp.status_code not in (200, 201):
            raise RuntimeError(f"POST extraction failed: {post_resp.status_code} {post_resp.text}")

        data = post_resp.json()["data"]
        return {
            "status": result.status,
            "extracted_source_ref": data["extracted_source_ref"],
            "page_count": result.page_count,
            "error_code": result.error_code,
        }
