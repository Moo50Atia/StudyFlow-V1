#!/usr/bin/env python3
"""Entrypoint for the Temporal Terminal Pipeline (Spec 018)."""

import sys
from pathlib import Path

PROJECT_ROOT = Path(__file__).resolve().parents[2]
if str(PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(PROJECT_ROOT))

from v3_engine.local_env import load_local_env
from v3_engine.terminal_pipeline.cli import main

if __name__ == "__main__":
    env_file = PROJECT_ROOT / ".env"
    if env_file.exists():
        load_local_env(env_file)
    sys.exit(main())
