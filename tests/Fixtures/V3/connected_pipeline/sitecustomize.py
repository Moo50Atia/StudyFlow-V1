"""Test-worker-only provider responses for the offline connected V3 gate.

Loaded only by the worker subprocess launched from the integration test. The
production worker, Temporal activities, HMAC clients, Laravel routes, state
transitions, browser container and preview gateway remain real.
"""
import json
import re

import httpx


def _typed_pass(model):
    return {
        "model": model or "offline-fixture-model",
        "answers": {
            "choice": {"type": "choice", "choice": "pass", "confidence": 1.0},
            "score": {"type": "score", "score": 100, "confidence": 1.0},
            "noul": {"type": "noul", "noul": 1.0},
        },
    }


_OriginalAsyncClient = httpx.AsyncClient
_RealTransport = httpx.AsyncHTTPTransport


class _ExternalProviderFixtureTransport(httpx.AsyncBaseTransport):
    """Stub only configured external HTTP endpoints; localhost remains real."""

    def __init__(self):
        self.real = _RealTransport()

    async def handle_async_request(self, request):
        host = request.url.host or ""
        if host == "api.typesafe.ai" or host == "generativelanguage.googleapis.com":
            model = "offline-fixture-model"
            try:
                raw = request.read().decode("utf-8")
                model = json.loads(raw).get("model", model)
            except Exception:
                pass
            if request.url.host == "api.typesafe.ai":
                return httpx.Response(200, json=_typed_pass(model), request=request)
            model_match = re.search(r"/models/([^:/]+):generateContent", request.url.path)
            if model_match:
                model = model_match.group(1)
            scene_root = __import__("pathlib").Path(__file__).parent / "scene"
            files = [
                {"path": path.name, "content": path.read_text(encoding="utf-8")}
                for path in sorted(scene_root.iterdir())
                if path.is_file()
            ]
            package_text = json.dumps({"schema_version": 1, "files": files})
            return httpx.Response(200, json={
                "modelVersion": model,
                "responseId": "offline-scene-fixture",
                "candidates": [{"finishReason": "STOP", "content": {"parts": [{"text": package_text}]}}],
                "usageMetadata": {},
            }, request=request)
        return await self.real.handle_async_request(request)

    async def aclose(self):
        await self.real.aclose()


class _FixtureAsyncClient(_OriginalAsyncClient):
    def __init__(self, *args, **kwargs):
        if kwargs.get("transport") is None:
            kwargs["transport"] = _ExternalProviderFixtureTransport()
        super().__init__(*args, **kwargs)


httpx.AsyncClient = _FixtureAsyncClient
