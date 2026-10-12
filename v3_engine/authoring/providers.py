import json
from typing import Any, Dict, Optional, Tuple
import httpx
from v3_engine.authoring.contracts import OUTPUT_CONTRACT


def format_user_payload(prompt: str) -> Dict[str, Any]:
    return {
        "schema_version": 1,
        "prompt": prompt,
        "output_contract": OUTPUT_CONTRACT,
    }


async def call_gemini_authoring(
    model: str,
    api_key: str,
    template_text: str,
    prompt: str,
    client: Optional[httpx.AsyncClient] = None,
    thinking_budget: Optional[int] = None,
) -> Tuple[str, str, Optional[str], str, Dict[str, Any]]:
    """Call Google Gemini generateContent API for scene authoring."""
    url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"
    payload = {
        "systemInstruction": {
            "parts": [{"text": template_text}]
        },
        "contents": [
            {
                "role": "user",
                "parts": [{"text": json.dumps(format_user_payload(prompt), ensure_ascii=False)}]
            }
        ],
    }
    if thinking_budget is not None:
        if isinstance(thinking_budget, bool) or not isinstance(thinking_budget, int) or not 0 <= thinking_budget <= 24576:
            raise ValueError("Gemini thinking budget must be an integer from 0 to 24576")
        payload["generationConfig"] = {"thinkingConfig": {"thinkingBudget": thinking_budget}}

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

    usage_meta = data.get("usageMetadata", {})
    usage = {
        "input_tokens": usage_meta.get("promptTokenCount"),
        "output_tokens": usage_meta.get("candidatesTokenCount"),
        "cost_minor_units": None,
        "currency": None,
    }

    return text, model_version, response_id, raw_json, usage


async def call_glm_authoring(
    model: str,
    api_key: str,
    template_text: str,
    prompt: str,
    client: Optional[httpx.AsyncClient] = None,
) -> Tuple[str, str, Optional[str], str, Dict[str, Any]]:
    """Call Z.AI GLM chat completions API for scene authoring."""
    url = "https://open.bigmodel.cn/api/paas/v4/chat/completions"
    payload = {
        "model": model,
        "messages": [
            {"role": "system", "content": template_text},
            {"role": "user", "content": json.dumps(format_user_payload(prompt), ensure_ascii=False)},
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
        raise ValueError(f"GLM API returned HTTP {resp.status_code}: {resp.text}")

    raw_json = resp.text
    data = resp.json()

    choices = data.get("choices")
    if not choices or len(choices) != 1:
        raise ValueError(f"GLM response must contain exactly one choice, got: {len(choices) if choices else 0}")

    choice = choices[0]
    finish_reason = choice.get("finish_reason")
    if finish_reason != "stop":
        raise ValueError(f"GLM response finish_reason must be 'stop', got '{finish_reason}'")

    message = choice.get("message", {})
    text = message.get("content")
    if not text:
        raise ValueError("GLM choice message content is empty")

    returned_model = data.get("model", model)
    response_id = data.get("id")

    usage_data = data.get("usage", {})
    usage = {
        "input_tokens": usage_data.get("prompt_tokens"),
        "output_tokens": usage_data.get("completion_tokens"),
        "cost_minor_units": None,
        "currency": None,
    }

    return text, returned_model, response_id, raw_json, usage


async def call_deepseek_authoring(
    model: str,
    api_key: str,
    template_text: str,
    prompt: str,
    client: Optional[httpx.AsyncClient] = None,
) -> Tuple[str, str, Optional[str], str, Dict[str, Any]]:
    """Call DeepSeek chat completions API for scene authoring."""
    url = "https://api.deepseek.com/chat/completions"
    payload = {
        "model": model,
        "messages": [
            {"role": "system", "content": template_text},
            {"role": "user", "content": json.dumps(format_user_payload(prompt), ensure_ascii=False)},
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

    usage_data = data.get("usage", {})
    usage = {
        "input_tokens": usage_data.get("prompt_tokens"),
        "output_tokens": usage_data.get("completion_tokens"),
        "cost_minor_units": None,
        "currency": None,
    }

    return text, returned_model, response_id, raw_json, usage


async def call_qwen_authoring(
    model: str,
    api_key: str,
    template_text: str,
    prompt: str,
    client: Optional[httpx.AsyncClient] = None,
) -> Tuple[str, str, Optional[str], str, Dict[str, Any]]:
    """Call Alibaba Qwen chat completions API for scene authoring."""
    url = "https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions"
    payload = {
        "model": model,
        "messages": [
            {"role": "system", "content": template_text},
            {"role": "user", "content": json.dumps(format_user_payload(prompt), ensure_ascii=False)},
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
        raise ValueError(f"Qwen API returned HTTP {resp.status_code}: {resp.text}")

    raw_json = resp.text
    data = resp.json()

    choices = data.get("choices")
    if not choices or len(choices) != 1:
        raise ValueError(f"Qwen response must contain exactly one choice, got: {len(choices) if choices else 0}")

    choice = choices[0]
    finish_reason = choice.get("finish_reason")
    if finish_reason != "stop":
        raise ValueError(f"Qwen response finish_reason must be 'stop', got '{finish_reason}'")

    message = choice.get("message", {})
    text = message.get("content")
    if not text:
        raise ValueError("Qwen choice message content is empty")

    returned_model = data.get("model", model)
    response_id = data.get("id")

    usage_data = data.get("usage", {})
    usage = {
        "input_tokens": usage_data.get("prompt_tokens"),
        "output_tokens": usage_data.get("completion_tokens"),
        "cost_minor_units": None,
        "currency": None,
    }

    return text, returned_model, response_id, raw_json, usage

