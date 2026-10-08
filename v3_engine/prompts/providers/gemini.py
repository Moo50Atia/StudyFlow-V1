import json
from typing import Any, Dict, Optional, Tuple
import httpx


async def call_gemini_feedback(
    model: str,
    api_key: str,
    template_bytes: str,
    user_payload: Dict[str, Any],
    client: Optional[httpx.AsyncClient] = None,
) -> Tuple[str, str, Optional[str], str]:
    """Call Google Gemini generateContent API for feedback revision.
    
    Returns (text, modelVersion, responseId, raw_response_json).
    """
    url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"
    payload = {
        "systemInstruction": {
            "parts": [{"text": template_bytes}]
        },
        "contents": [
            {
                "role": "user",
                "parts": [{"text": json.dumps(user_payload, ensure_ascii=False)}]
            }
        ],
    }

    headers = {
        "x-goog-api-key": api_key,
        "Content-Type": "application/json",
        "Accept": "application/json",
    }

    should_close = False
    if client is None:
        client = httpx.AsyncClient(timeout=60.0)
        should_close = True

    try:
        resp = await client.post(url, json=payload, headers=headers)
    finally:
        if should_close:
            await client.aclose()

    if resp.status_code != 200:
        raise ValueError(f"Gemini API returned HTTP {resp.status_code}: {resp.text}")

    raw_json = resp.text
    data = resp.json()

    candidates = data.get("candidates")
    if not candidates or len(candidates) != 1:
        raise ValueError(f"Gemini response must contain exactly one candidate, got: {len(candidates) if candidates else 0}")

    candidate = candidates[0]
    finish_reason = candidate.get("finishReason")
    if finish_reason != "STOP":
        raise ValueError(f"Gemini response finishReason must be 'STOP', got '{finish_reason}'")

    content = candidate.get("content", {})
    parts = content.get("parts", [])
    if not parts or not parts[0].get("text"):
        raise ValueError("Gemini candidate content has no text parts")

    text = parts[0]["text"]
    model_version = data.get("modelVersion", model)
    response_id = data.get("responseId")

    return text, model_version, response_id, raw_json
