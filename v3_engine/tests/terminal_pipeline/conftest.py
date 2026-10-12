"""Temporal test fixtures and managed local dev server lifecycle (Spec 018 / T012)."""

from __future__ import annotations

import asyncio
import os
import shutil
from pathlib import Path
from typing import AsyncGenerator
import pytest

from temporalio.client import Client
from temporalio.testing import WorkflowEnvironment


@pytest.fixture
async def temporal_local_env(tmp_path: Path) -> AsyncGenerator[WorkflowEnvironment, None]:
    """Start an isolated local Temporal dev server using the installed CLI and temp database."""
    db_file = tmp_path / "server.sqlite"
    temporal_bin = "/home/moahmmed/.local/bin/temporal"
    if not os.path.isfile(temporal_bin):
        pytest.skip(f"Temporal CLI binary not found at {temporal_bin}")

    env = await WorkflowEnvironment.start_local(
        dev_server_existing_path=temporal_bin,
        namespace="v3-terminal",
        dev_server_database_filename=str(db_file),
    )
    try:
        yield env
    finally:
        await env.shutdown()
