"""Tests for provider configuration and template resolution (Spec 017)."""

from __future__ import annotations

import hashlib
from pathlib import Path
import pytest

from v3_engine.scene_generation.config import (
    DEFAULT_TEMPLATE_SHA256,
    ConfigurationError,
    load_provider_config,
    resolve_template,
    _validate_base_url,
)
from v3_engine.scene_generation.contracts import ProviderConfig


def test_gemini_config_defaults_and_normalization() -> None:
    env = {
        "GEMINI_API_KEY": "test-gemini-key",
        "GEMINI_MODEL": "models/gemini-2.5-flash",
    }
    cfg = load_provider_config("gemini", env=env)
    assert cfg.provider == "gemini"
    assert cfg.provider_id == "gemini_api"
    assert cfg.requested_model == "gemini-2.5-flash"
    assert cfg.endpoint == "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent"
    assert cfg.request_timeout == 300
    assert cfg.gemini_thinking_budget == 24576
    assert cfg.gemini_max_output_tokens == 65536
    assert "test-gemini-key" not in repr(cfg)


def test_cli_model_overrides_env_model() -> None:
    env = {
        "GEMINI_API_KEY": "test-key",
        "GEMINI_MODEL": "gemini-2.5-pro",
    }
    cfg = load_provider_config("gemini", model="gemini-2.5-flash", env=env)
    assert cfg.requested_model == "gemini-2.5-flash"


def test_gemini_reasoning_and_output_budgets_are_configurable_and_bounded() -> None:
    env = {
        "GEMINI_API_KEY": "k", "GEMINI_MODEL": "gemini-2.5-flash",
        "V3_GEMINI_THINKING_BUDGET": "12000", "V3_GEMINI_MAX_OUTPUT_TOKENS": "48000",
    }
    cfg = load_provider_config("gemini", env=env)
    assert cfg.gemini_thinking_budget == 12000
    assert cfg.gemini_max_output_tokens == 48000

    for name, value in (("V3_GEMINI_THINKING_BUDGET", "-1"),
                        ("V3_GEMINI_MAX_OUTPUT_TOKENS", "not-a-number")):
        invalid = dict(env)
        invalid[name] = value
        with pytest.raises(ConfigurationError, match=name):
            load_provider_config("gemini", env=invalid)

    invalid = dict(env, V3_GEMINI_THINKING_BUDGET="24000", V3_GEMINI_MAX_OUTPUT_TOKENS="24000")
    with pytest.raises(ConfigurationError, match="lower than"):
        load_provider_config("gemini", env=invalid)


def test_glm_config_default_and_custom_base_url() -> None:
    env = {
        "GLM_API_KEY": "test-glm-key",
        "GLM_MODEL": "glm-4-plus",
    }
    cfg = load_provider_config("glm", env=env)
    assert cfg.endpoint == "https://open.bigmodel.cn/api/paas/v4/chat/completions"

    env_custom = {
        "GLM_API_KEY": "test-glm-key",
        "GLM_MODEL": "glm-4-plus",
        "GLM_BASE_URL": "https://custom.z.ai/api/v1",
    }
    cfg_custom = load_provider_config("glm", env=env_custom)
    assert cfg_custom.endpoint == "https://custom.z.ai/api/v1/chat/completions"


def test_deepseek_config() -> None:
    env = {
        "DEEPSEEK_API_KEY": "test-ds-key",
        "DEEPSEEK_MODEL": "deepseek-chat",
    }
    cfg = load_provider_config("deepseek", env=env)
    assert cfg.endpoint == "https://api.deepseek.com/chat/completions"


def test_qwen_requires_base_url() -> None:
    env = {
        "QWEN_API_KEY": "test-qwen-key",
        "QWEN_MODEL": "qwen-plus",
    }
    with pytest.raises(ConfigurationError, match="QWEN_BASE_URL"):
        load_provider_config("qwen", env=env)

    env["QWEN_BASE_URL"] = "https://dashscope-intl.aliyuncs.com/compatible-mode/v1"
    cfg = load_provider_config("qwen", env=env)
    assert cfg.endpoint == "https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions"


def test_unknown_provider_rejected() -> None:
    with pytest.raises(ConfigurationError, match="Unknown provider"):
        load_provider_config("unknown_model_vendor", env={})


def test_missing_model_rejected() -> None:
    env = {"GEMINI_API_KEY": "some-key"}
    with pytest.raises(ConfigurationError, match="model"):
        load_provider_config("gemini", env=env)


def test_blank_credentials_rejected() -> None:
    env = {"GEMINI_API_KEY": "   ", "GEMINI_MODEL": "gemini-2.5-pro"}
    with pytest.raises(ConfigurationError, match="key"):
        load_provider_config("gemini", env=env)


def test_invalid_base_url_shapes() -> None:
    # Not https
    env = {
        "GLM_API_KEY": "k",
        "GLM_MODEL": "m",
        "GLM_BASE_URL": "http://open.bigmodel.cn/api/paas/v4",
    }
    with pytest.raises(ConfigurationError, match="HTTPS"):
        load_provider_config("glm", env=env)

    # Ends in /chat/completions
    env["GLM_BASE_URL"] = "https://open.bigmodel.cn/api/paas/v4/chat/completions"
    with pytest.raises(ConfigurationError, match="chat/completions"):
        load_provider_config("glm", env=env)


def test_url_validation_never_echoes_userinfo_or_query_secrets() -> None:
    for url, secret in (("https://user:password-secret@example.test/v1", "password-secret"), ("https://example.test/v1?token=query-secret", "query-secret")):
        with pytest.raises(ConfigurationError) as error:
            _validate_base_url(url, "qwen")
        assert secret not in str(error.value)


def test_invalid_timeout() -> None:
    env = {"GEMINI_API_KEY": "k", "GEMINI_MODEL": "m"}
    with pytest.raises(ConfigurationError, match="timeout"):
        load_provider_config("gemini", timeout=0, env=env)
    with pytest.raises(ConfigurationError, match="timeout"):
        load_provider_config("gemini", timeout=4000, env=env)


def test_default_template_resolution(repo_root: Path) -> None:
    text, digest = resolve_template(repo_root=repo_root)
    assert digest == DEFAULT_TEMPLATE_SHA256
    assert len(text) > 0


def test_custom_template_resolution(tmp_path: Path) -> None:
    custom_content = "Custom prompt template content for testing."
    custom_file = tmp_path / "custom-template.txt"
    custom_file.write_text(custom_content, encoding="utf-8")
    actual_sha = hashlib.sha256(custom_content.encode("utf-8")).hexdigest()

    # Success with matching sha256
    text, digest = resolve_template(template_path=custom_file, expected_sha256=actual_sha)
    assert text == custom_content
    assert digest == actual_sha

    # Failure with missing or mismatched sha256
    with pytest.raises(ConfigurationError, match="template-sha256"):
        resolve_template(template_path=custom_file, expected_sha256=None)

    with pytest.raises(ConfigurationError, match="digest mismatch"):
        resolve_template(template_path=custom_file, expected_sha256="wrong-sha")
