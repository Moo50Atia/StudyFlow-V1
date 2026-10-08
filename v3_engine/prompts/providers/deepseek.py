import json
from typing import Any, Dict, Optional, Tuple
import httpx


async def call_deepseek_feedback(
    model: str,
    api_key: str,
    template_bytes: str,
    user_payload: Dict[str, Any],
    client: Optional[httpx.AsyncClient] = None,
) -> Tuple[str, str, Optional[str], str]:
    """Call DeepSeek chat completions API for feedback revision.
    
    Returns (text, model, response_id, raw_response_json).
    """
    url = "https://api.deepseek.com/chat/completions"
    payload = {
        "model": model,
        "messages": [
            {"role": "system", "content": template_bytes},
            {"role": "user", "content": json.dumps(user_payload, ensure_ascii=False)},
        ],
        "stream": False,
    }

    headers = {
        "Authorization": f"Bearer {api_key}",
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
        raise ValueError(f"DeepSeek API returned HTTP {resp.status_code}: {resp.text}")

    raw_json = resp.text
    data = resp.json()

    choices = data.get("choices")
    if not choices or len(choices) != 1:
        raise ValueError(f"DeepSeek response must contain exactly one choice, got: {len(choices) if choices else 0}")

    choice = choices[0]
    finish_reason = choice.get("finish_reason")
    if finish_reason != "stop":
        raise ValueError(f"DeepSeek response finish_reason must be 'stop', got '{finish_reason}'")

    message = choice.get("message", {})
    text = message.get("content")
    if not text:
        raise ValueError("DeepSeek choice message content is empty")

    returned_model = data.get("model", model)
    response_id = data.get("id")

    return text, returned_model, response_id, raw_json
