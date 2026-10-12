"""Configuration loader and validation for terminal scene generation (Spec 017)."""

from __future__ import annotations

import hashlib
import os
from pathlib import Path
import re
from typing import Mapping, Optional
from urllib.parse import urlparse

from v3_engine.scene_generation.contracts import ProviderConfig

DEFAULT_TEMPLATE_SHA256 = "5849d8e423ef5a2e0e16dfb61eec517011f992e136cdbcc12cef684e8a25e302"
MODEL_PATTERN = re.compile(r"^[A-Za-z0-9._:/-]{1,100}$")


def _bounded_integer(value: str | None, name: str, default: int, minimum: int, maximum: int) -> int:
    raw = str(value if value is not None else default).strip()
    try:
        parsed = int(raw)
    except (TypeError, ValueError) as exc:
        raise ConfigurationError(f"{name} must be an integer between {minimum} and {maximum}.") from exc
    if parsed < minimum or parsed > maximum:
        raise ConfigurationError(f"{name} must be an integer between {minimum} and {maximum}.")
    return parsed


def resolve_gemini_generation_settings(env: Optional[Mapping[str, str]] = None) -> tuple[int, int]:
    """Return validated (thinking budget, total output-token cap) from the active environment."""
    env_map = env if env is not None else os.environ
    thinking_budget = _bounded_integer(
        env_map.get("V3_GEMINI_THINKING_BUDGET"), "V3_GEMINI_THINKING_BUDGET", 24576, 0, 24576
    )
    max_output_tokens = _bounded_integer(
        env_map.get("V3_GEMINI_MAX_OUTPUT_TOKENS"), "V3_GEMINI_MAX_OUTPUT_TOKENS", 65536, 1024, 65536
    )
    if thinking_budget >= max_output_tokens:
        raise ConfigurationError("V3_GEMINI_THINKING_BUDGET must be lower than V3_GEMINI_MAX_OUTPUT_TOKENS.")
    return thinking_budget, max_output_tokens

PROVIDER_METADATA = {
    "gemini": {
        "provider_id": "gemini_api",
        "key_env": "GEMINI_API_KEY",
        "model_env": "GEMINI_MODEL",
    },
    "glm": {
        "provider_id": "glm_api",
        "key_env": "GLM_API_KEY",
        "model_env": "GLM_MODEL",
        "default_base_url": "https://open.bigmodel.cn/api/paas/v4",
    },
    "deepseek": {
        "provider_id": "deepseek_api",
        "key_env": "DEEPSEEK_API_KEY",
        "model_env": "DEEPSEEK_MODEL",
        "default_base_url": "https://api.deepseek.com",
    },
    "qwen": {
        "provider_id": "qwen_api",
        "key_env": "QWEN_API_KEY",
        "model_env": "QWEN_MODEL",
    },
}


class ConfigurationError(Exception):
    """Raised when CLI arguments or environment configuration are invalid (exit code 2)."""


def _validate_base_url(url: str, provider: str) -> str:
    cleaned = url.strip().rstrip("/")
    if cleaned.endswith("/chat/completions"):
        raise ConfigurationError(f"Configured base URL for {provider} must not end in '/chat/completions'.")
    parsed = urlparse(cleaned)
    is_local_test = parsed.scheme == "http" and parsed.hostname in ("127.0.0.1", "localhost")
    if parsed.scheme != "https" and not is_local_test:
        raise ConfigurationError(f"Configured base URL for {provider} must use HTTPS scheme.")
    if parsed.query or parsed.fragment:
        raise ConfigurationError(f"Configured base URL for {provider} must not contain query or fragment.")
    if parsed.username or parsed.password:
        raise ConfigurationError(f"Configured base URL for {provider} must not contain userinfo.")
    if not parsed.netloc:
        raise ConfigurationError(f"Configured base URL for {provider} is invalid.")
    return cleaned


def load_provider_config(
    provider: str,
    model: Optional[str] = None,
    timeout: int = 300,
    env: Optional[Mapping[str, str]] = None,
) -> ProviderConfig:
    """Load and validate provider configuration from env and CLI arguments."""
    env_map = env if env is not None else os.environ

    provider_clean = provider.strip().lower()
    if provider_clean not in PROVIDER_METADATA:
        raise ConfigurationError(
            f"Unknown provider '{provider}'. Must be one of: {', '.join(PROVIDER_METADATA.keys())}"
        )

    meta = PROVIDER_METADATA[provider_clean]
    provider_id = meta["provider_id"]

    # Timeout validation
    if isinstance(timeout, bool) or not isinstance(timeout, int) or timeout < 1 or timeout > 3600:
        raise ConfigurationError(f"Request timeout must be an integer between 1 and 3600 seconds, got {timeout!r}")

    # Model resolution
    raw_model = model or env_map.get(meta["model_env"], "")
    model_str = raw_model.strip()
    if not model_str:
        raise ConfigurationError(
            f"A model must be specified for provider '{provider_clean}' via --model or environment variable {meta['model_env']}."
        )

    # Gemini model normalization: strip optional 'models/' prefix
    if provider_clean == "gemini" and model_str.startswith("models/"):
        model_str = model_str[len("models/") :]

    if not MODEL_PATTERN.fullmatch(model_str):
        raise ConfigurationError(
            f"Model name '{model_str}' is invalid. Must be 1-100 characters matching [A-Za-z0-9._:/-]."
        )

    # API key validation
    raw_key = env_map.get(meta["key_env"], "")
    api_key = raw_key.strip()
    if not api_key:
        raise ConfigurationError(
            f"API key for provider '{provider_clean}' is missing or blank in environment variable {meta['key_env']}."
        )

    # Endpoint resolution
    if provider_clean == "gemini":
        if "GEMINI_BASE_URL" in env_map and env_map["GEMINI_BASE_URL"].strip():
            valid_base = _validate_base_url(env_map["GEMINI_BASE_URL"], "gemini")
            endpoint = f"{valid_base}/v1beta/models/{model_str}:generateContent"
        else:
            endpoint = f"https://generativelanguage.googleapis.com/v1beta/models/{model_str}:generateContent"
    elif provider_clean == "glm":
        base_url = env_map.get("GLM_BASE_URL", meta["default_base_url"])
        valid_base = _validate_base_url(base_url, "glm")
        endpoint = f"{valid_base}/chat/completions"
    elif provider_clean == "deepseek":
        base_url = env_map.get("DEEPSEEK_BASE_URL", meta["default_base_url"])
        valid_base = _validate_base_url(base_url, "deepseek")
        endpoint = f"{valid_base}/chat/completions"
    elif provider_clean == "qwen":
        raw_base = env_map.get("QWEN_BASE_URL", "")
        if not raw_base or not raw_base.strip():
            raise ConfigurationError(
                "QWEN_BASE_URL is required for Alibaba Model Studio Qwen (e.g. 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1')."
            )
        valid_base = _validate_base_url(raw_base, "qwen")
        endpoint = f"{valid_base}/chat/completions"
    else:
        raise ConfigurationError(f"Unhandled provider: {provider_clean}")

    if provider_clean == "gemini":
        thinking_budget, max_output_tokens = resolve_gemini_generation_settings(env_map)
    else:
        thinking_budget = 24576
        max_output_tokens = 65536

    return ProviderConfig(
        provider=provider_clean,
        provider_id=provider_id,
        requested_model=model_str,
        endpoint=endpoint,
        request_timeout=timeout,
        gemini_thinking_budget=thinking_budget,
        gemini_max_output_tokens=max_output_tokens,
        api_key=api_key,
    )


def resolve_template(
    template_path: Optional[Path] = None,
    expected_sha256: Optional[str] = None,
    repo_root: Optional[Path] = None,
) -> tuple[str, str]:
    """Resolve and validate template UTF-8 text and sha256 digest."""
    root = repo_root or Path(__file__).resolve().parents[2]

    if template_path is None:
        target_path = root / "Google-Ai-studio-System-prompts"
        if not target_path.exists():
            raise ConfigurationError(f"Default template not found at {target_path}")
        if target_path.is_symlink():
            raise ConfigurationError("Default template must not be a symbolic link.")

        content_bytes = target_path.read_bytes()
        actual_sha = hashlib.sha256(content_bytes).hexdigest()
        if actual_sha != DEFAULT_TEMPLATE_SHA256:
            raise ConfigurationError(
                f"Default template digest mismatch: expected {DEFAULT_TEMPLATE_SHA256}, got {actual_sha}"
            )
        try:
            content_text = content_bytes.decode("utf-8")
        except UnicodeDecodeError as exc:
            raise ConfigurationError("Default template must be valid UTF-8 text.") from exc
        return content_text, actual_sha

    # Custom template specified via --template
    custom_path = Path(template_path)
    if not custom_path.exists():
        raise ConfigurationError(f"Custom template not found at {custom_path}")
    if custom_path.is_symlink():
        raise ConfigurationError("Custom template must not be a symbolic link.")

    if not expected_sha256 or not expected_sha256.strip():
        raise ConfigurationError("Custom --template requires explicit --template-sha256.")

    size = custom_path.stat().st_size
    if size < 1 or size > 65536:
        raise ConfigurationError(f"Custom template size must be between 1 and 65536 bytes, got {size}")

    content_bytes = custom_path.read_bytes()
    actual_sha = hashlib.sha256(content_bytes).hexdigest()
    if actual_sha != expected_sha256.strip().lower():
        raise ConfigurationError(
            f"Custom template digest mismatch: expected {expected_sha256.strip().lower()}, got {actual_sha}"
        )

    try:
        content_text = content_bytes.decode("utf-8")
    except UnicodeDecodeError as exc:
        raise ConfigurationError("Custom template must be valid UTF-8 text.") from exc

    return content_text, actual_sha
