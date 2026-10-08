"""Live provider authoring test for Spec 011 (T014).

Marked with @pytest.mark.live. Requires live provider credentials and approved scene template.
"""
import os
import pytest

from v3_engine.authoring.providers import (
    call_gemini_authoring,
    call_glm_authoring,
    call_deepseek_authoring,
)
from v3_engine.authoring.package import parse_scene_package


@pytest.mark.live
@pytest.mark.asyncio
async def test_live_provider_authoring():
    gemini_key = os.getenv("GEMINI_API_KEY")
    glm_key = os.getenv("GLM_API_KEY")
    deepseek_key = os.getenv("DEEPSEEK_API_KEY")

    if not any([gemini_key, glm_key, deepseek_key]):
        pytest.skip("No live provider API keys configured in environment (GEMINI_API_KEY, GLM_API_KEY, DEEPSEEK_API_KEY)")

    template_text = os.getenv(
        "V3_LIVE_SCENE_TEMPLATE",
        "<!DOCTYPE html><html><head><title>Template</title></head><body><div id='app'></div></body></html>",
    )
    prompt = os.getenv(
        "V3_LIVE_PROMPT",
        "Generate a simple interactive 3D visualization scene illustrating photosynthesis.",
    )

    if gemini_key:
        model = os.getenv("GEMINI_MODEL", "gemini-2.5-pro")
        resp = await call_gemini_authoring(gemini_key, model, prompt, template_text)
    elif glm_key:
        model = os.getenv("GLM_MODEL", "glm-4-plus")
        resp = await call_glm_authoring(glm_key, model, prompt, template_text)
    else:
        model = os.getenv("DEEPSEEK_MODEL", "deepseek-coder")
        resp = await call_deepseek_authoring(deepseek_key, model, prompt, template_text)

    assert "text" in resp
    raw_text = resp["text"]
    pkg = parse_scene_package(raw_text)

    assert pkg.schema_version == 1
    assert len(pkg.files) >= 1
    assert any(f.path == "index.html" for f in pkg.files)
