#!/usr/bin/env python3
"""Terminal scene generation entrypoint (Spec 017)."""

from __future__ import annotations

from pathlib import Path
import sys

PROJECT_ROOT = Path(__file__).resolve().parents[2]
if str(PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(PROJECT_ROOT))

from v3_engine.local_env import load_local_env
from v3_engine.scene_generation.cli import main

if __name__ == "__main__":
    env_file = PROJECT_ROOT / ".env"
    if env_file.exists():
        load_local_env(env_file)
    sys.exit(main())
