"""Provider catalog mapping and captured scene generation context (Spec 019 US3)."""

from __future__ import annotations

import os
from typing import Any, Mapping, Optional

from v3_engine.scene_generation.contracts import ProviderConfig

PROVIDER_CATALOG = {
    "gemini_api": {
        "python_alias": "gemini",
        "key_env": "GEMINI_API_KEY",
        "approved_endpoint": "https://generativelanguage.googleapis.com",
        "default_model": "gemini-2.5-flash",
        "gemini_thinking_budget": 24576,
        "gemini_max_output_tokens": 65536,
    },
    "glm_api": {
        "python_alias": "glm",
        "key_env": "GLM_API_KEY",
        "approved_endpoint": "https://open.bigmodel.cn/api/paas/v4",
        "default_model": "glm-4-plus",
        "gemini_thinking_budget": 0,
        "gemini_max_output_tokens": 0,
    },
    "deepseek_api": {
        "python_alias": "deepseek",
        "key_env": "DEEPSEEK_API_KEY",
        "approved_endpoint": "https://api.deepseek.com",
        "default_model": "deepseek-chat",
        "gemini_thinking_budget": 0,
        "gemini_max_output_tokens": 0,
    },
    "qwen_api": {
        "python_alias": "qwen",
        "key_env": "QWEN_API_KEY",
        "approved_endpoint": "https://dashscope.aliyuncs.com/compatible-mode/v1",
        "default_model": "qwen-plus",
        "gemini_thinking_budget": 0,
        "gemini_max_output_tokens": 0,
    },
}


def is_export_manual_mode(captured_settings: Mapping[str, Any]) -> bool:
    """Return True if run mode is export_manual (requiring zero generation API keys)."""
    mode = captured_settings.get("mode")
    return mode == "export_manual" or captured_settings.get("requires_generation_key") is False


def build_provider_config(
    captured_settings: Mapping[str, Any],
    env: Optional[Mapping[str, str]] = None,
) -> Optional[ProviderConfig]:
    """Build ProviderConfig from captured settings document or return None for manual mode."""
    if is_export_manual_mode(captured_settings):
        return None

    provider_data = captured_settings.get("provider") or {}
    provider_id = provider_data.get("id", "gemini_api")

    catalog_entry = PROVIDER_CATALOG.get(provider_id)
    if not catalog_entry:
        raise ValueError(f"Unapproved or unknown provider ID: {provider_id}")

    env_map = env if env is not None else os.environ
    key_env = catalog_entry["key_env"]
    api_key = env_map.get(key_env, "").strip()
    if not api_key:
        raise ValueError(f"Missing required provider API key in environment variable: {key_env}")

    alias = catalog_entry["python_alias"]
    requested_model = provider_data.get("requested_model") or catalog_entry["default_model"]
    endpoint = provider_data.get("endpoint") or catalog_entry["approved_endpoint"]
    thinking_budget = provider_data.get("gemini_thinking_budget", catalog_entry["gemini_thinking_budget"])
    max_output = provider_data.get("gemini_max_output_tokens", catalog_entry["gemini_max_output_tokens"])

    return ProviderConfig(
        provider=alias,
        provider_id=provider_id,
        requested_model=requested_model,
        endpoint=endpoint,
        api_key=api_key,
        gemini_thinking_budget=int(thinking_budget or 24576),
        gemini_max_output_tokens=int(max_output or 65536),
    )
