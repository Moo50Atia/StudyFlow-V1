"""Local worker configuration and persistent credentials."""

from __future__ import annotations

import json
import os
from dataclasses import asdict, dataclass
from pathlib import Path
from typing import List, Optional


@dataclass
class WorkerConfig:
    server_origin: str
    device_id: str
    device_token: str
    workspace_dir: str
    capabilities: List[str]
    terminal_manifest_path: Optional[str] = None
    workspace_budget_bytes: int = 8 * 1024 * 1024 * 1024
    workspace_safety_margin_bytes: int = 512 * 1024 * 1024

    def __post_init__(self) -> None:
        if self.workspace_budget_bytes < 1 or self.workspace_safety_margin_bytes < 0:
            raise ValueError("Workspace budget must be positive and safety margin cannot be negative.")
        if self.workspace_safety_margin_bytes >= self.workspace_budget_bytes:
            raise ValueError("Workspace safety margin must be smaller than its budget.")

    @classmethod
    def from_dict(cls, data: dict) -> WorkerConfig:
        return cls(
            server_origin=str(data.get("server_origin", "")).rstrip("/"),
            device_id=str(data.get("device_id", "")),
            device_token=str(data.get("device_token", "")),
            workspace_dir=str(data.get("workspace_dir", str(Path.home() / ".local_worker" / "workspace"))),
            capabilities=list(data.get("capabilities", ["notebooklm_authoring_v1", "scene_return_v1"])),
            terminal_manifest_path=data.get("terminal_manifest_path"),
            workspace_budget_bytes=int(data.get("workspace_budget_bytes", 8 * 1024 * 1024 * 1024)),
            workspace_safety_margin_bytes=int(data.get("workspace_safety_margin_bytes", 512 * 1024 * 1024)),
        )

    def to_dict(self) -> dict:
        return asdict(self)


def get_default_config_path() -> Path:
    env_path = os.environ.get("LOCAL_WORKER_CONFIG")
    if env_path:
        return Path(env_path).expanduser().resolve()
    return (Path.home() / ".local_worker" / "config.json").resolve()


def load_config(path: Optional[Path] = None) -> WorkerConfig:
    config_file = path or get_default_config_path()
    if not config_file.is_file():
        raise FileNotFoundError(f"Configuration file not found at {config_file}")

    data = json.loads(config_file.read_text(encoding="utf-8"))
    return WorkerConfig.from_dict(data)


def save_config(config: WorkerConfig, path: Optional[Path] = None) -> Path:
    config_file = path or get_default_config_path()
    config_file.parent.mkdir(parents=True, exist_ok=True)

    text = json.dumps(config.to_dict(), indent=2)
    config_file.write_text(text, encoding="utf-8")

    # Set strict permissions: readable and writable only by owner (0600)
    try:
        os.chmod(config_file, 0o600)
    except OSError:
        pass

    return config_file
