import os
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import urlsplit

from v3_engine.local_env import load_local_env

load_local_env(Path(__file__).resolve().parents[1] / ".env")

@dataclass(frozen=True)
class EngineSettings:
    temporal_address: str = os.getenv("V3_TEMPORAL_ADDRESS", "127.0.0.1:7233")
    temporal_namespace: str = os.getenv("V3_TEMPORAL_NAMESPACE", "default")
    task_queue: str = os.getenv("V3_TASK_QUEUE", "v3-cases")
    bridge_hmac_key: str = os.getenv("V3_BRIDGE_HMAC_KEY", "")
    callback_hmac_key: str = os.getenv("V3_CALLBACK_HMAC_KEY", "")
    laravel_callback_url: str = os.getenv("V3_LARAVEL_CALLBACK_URL", "http://127.0.0.1:8000/api/internal/v1/case-events")
    bridge_port: int = int(os.getenv("V3_BRIDGE_PORT", "8765"))
    bridge_host: str = os.getenv("V3_BRIDGE_HOST", "127.0.0.1")
    jev_model: str = os.getenv("JEV_MODEL", "")
    typesafe_api_key: str = os.getenv("TYPESAFE_API_KEY", "")
    typesafe_base_url: str = os.getenv("TYPESAFE_BASE_URL", "https://api.typesafe.ai")
    gemini_api_key: str = os.getenv("GEMINI_API_KEY", "")
    glm_api_key: str = os.getenv("GLM_API_KEY", "")
    deepseek_api_key: str = os.getenv("DEEPSEEK_API_KEY", "")
    qwen_api_key: str = os.getenv("QWEN_API_KEY", "")

    @property
    def laravel_api_origin(self) -> str:
        """Return the origin for internal paths from the configured callback URL."""
        parsed = urlsplit(self.laravel_callback_url)
        callback_path = "/api/internal/v1/case-events"
        if (
            parsed.scheme not in {"http", "https"}
            or not parsed.netloc
            or parsed.username is not None
            or parsed.password is not None
            or parsed.query
            or parsed.fragment
            or not parsed.path.endswith(callback_path)
        ):
            raise ValueError("V3_LARAVEL_CALLBACK_URL must be an absolute case-events callback URL.")
        return f"{parsed.scheme}://{parsed.netloc}"

def get_settings() -> EngineSettings:
    return EngineSettings(
        temporal_address=os.getenv("V3_TEMPORAL_ADDRESS", "127.0.0.1:7233"),
        temporal_namespace=os.getenv("V3_TEMPORAL_NAMESPACE", "default"),
        task_queue=os.getenv("V3_TASK_QUEUE", "v3-cases"),
        bridge_hmac_key=os.getenv("V3_BRIDGE_HMAC_KEY", ""),
        callback_hmac_key=os.getenv("V3_CALLBACK_HMAC_KEY", ""),
        laravel_callback_url=os.getenv("V3_LARAVEL_CALLBACK_URL", "http://127.0.0.1:8000/api/internal/v1/case-events"),
        bridge_port=int(os.getenv("V3_BRIDGE_PORT", "8765")),
        bridge_host=os.getenv("V3_BRIDGE_HOST", "127.0.0.1"),
        jev_model=os.getenv("JEV_MODEL", ""),
        typesafe_api_key=os.getenv("TYPESAFE_API_KEY", ""),
        typesafe_base_url=os.getenv("TYPESAFE_BASE_URL", "https://api.typesafe.ai"),
        gemini_api_key=os.getenv("GEMINI_API_KEY", ""),
        glm_api_key=os.getenv("GLM_API_KEY", ""),
        deepseek_api_key=os.getenv("DEEPSEEK_API_KEY", ""),
        qwen_api_key=os.getenv("QWEN_API_KEY", ""),
    )
