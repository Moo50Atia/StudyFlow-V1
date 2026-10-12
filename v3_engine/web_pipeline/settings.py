"""Settings for V3 Web Pipeline Integration."""

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class WebPipelineSettings:
    terminal_root: Path
    temporal_address: str = "127.0.0.1:7233"
    temporal_namespace: str = "v3-terminal"
    control_queue: str = "v3-terminal-control"
    io_queue: str = "v3-terminal-io"
    build_queue: str = "v3-terminal-build"
    max_admitted_subjects: int = 4
    max_io_activities: int = 4
    max_build_activities: int = 2
    max_queued_subjects: int = 64
    drain_history_events: int = 8000
    reject_history_events: int = 12000
    account_alias: str = "mo50atiapro"
    bridge_url: str = "http://127.0.0.1:8765"
    hmac_secret: str = ""

    def resolve_root(self) -> Path:
        """Return the captured absolute terminal root."""
        return self.terminal_root


def get_web_pipeline_settings(project_root: Path | None = None) -> WebPipelineSettings:
    """
    Resolve and capture a single absolute terminal root and orchestration settings.
    Default application storage is storage/app/private/v3/terminal.
    """
    base = project_root or Path.cwd()
    root_env = os.getenv("V3_TERMINAL_ROOT")

    if root_env:
        raw_root = Path(root_env)
        terminal_root = raw_root if raw_root.is_absolute() else (base / raw_root).resolve()
    else:
        terminal_root = (base / "storage/app/private/v3/terminal").resolve()

    addr = os.getenv("V3_TERMINAL_TEMPORAL_ADDRESS") or os.getenv("V3_TEMPORAL_ADDRESS", "127.0.0.1:7233")
    ns = os.getenv("V3_TERMINAL_TEMPORAL_NAMESPACE", "v3-terminal")
    bridge_url = os.getenv("V3_WEB_PIPELINE_BRIDGE_URL") or os.getenv("V3_BRIDGE_URL", "http://127.0.0.1:8765")
    hmac = os.getenv("V3_WEB_PIPELINE_HMAC_SECRET") or os.getenv("V3_BRIDGE_HMAC_KEY", "")

    return WebPipelineSettings(
        terminal_root=terminal_root,
        temporal_address=addr,
        temporal_namespace=ns,
        bridge_url=bridge_url,
        hmac_secret=hmac,
    )


get_settings = get_web_pipeline_settings

