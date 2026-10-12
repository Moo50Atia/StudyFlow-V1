"""Settings for Temporal Terminal Pipeline (Spec 018)."""

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class TerminalSettings:
    temporal_address: str = "127.0.0.1:7233"
    temporal_namespace: str = "v3-terminal"
    terminal_root: Path = Path("storage/app/private/v3/terminal")
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

    def resolve_root(self, project_root: Path | None = None) -> Path:
        if self.terminal_root.is_absolute():
            return self.terminal_root
        base = project_root or Path.cwd()
        return (base / self.terminal_root).resolve()


def get_terminal_settings() -> TerminalSettings:
    addr = os.getenv("V3_TERMINAL_TEMPORAL_ADDRESS") or os.getenv("V3_TEMPORAL_ADDRESS", "127.0.0.1:7233")
    ns = os.getenv("V3_TERMINAL_TEMPORAL_NAMESPACE", "v3-terminal")
    root_str = os.getenv("V3_TERMINAL_ROOT", "storage/app/private/v3/terminal")
    return TerminalSettings(
        temporal_address=addr,
        temporal_namespace=ns,
        terminal_root=Path(root_str),
    )
