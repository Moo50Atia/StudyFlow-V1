"""
V3 Engine Test Configuration and External Fixture Opt-In Guards.

Governed by R00 (docs/reviews/V3-POC-REPAIR-TASKS-2026-10-04.md).
Ensures offline regressions are fully isolated and external services
(Temporal test server, Docker containers, Playwright browsers, live APIs)
require explicit opt-in via pytest markers (-m), CLI options, or environment variables.
"""

import os
import sys
from pathlib import Path

# Ensure .venv site-packages is in sys.path
_venv_site = Path(__file__).resolve().parent.parent.parent / ".venv" / "lib" / "python3.12" / "site-packages"
if _venv_site.exists() and str(_venv_site) not in sys.path:
    sys.path.insert(0, str(_venv_site))

import pytest


def pytest_addoption(parser):
    """Register explicit opt-in CLI flags for external execution lanes."""
    parser.addoption(
        "--run-live",
        action="store_true",
        default=False,
        help="Explicitly enable live provider tests requiring real credentials.",
    )
    parser.addoption(
        "--run-container",
        action="store_true",
        default=False,
        help="Explicitly enable container tests requiring real Docker runtime.",
    )
    parser.addoption(
        "--run-temporal",
        action="store_true",
        default=False,
        help="Explicitly enable Temporal workflow tests requiring Temporal test server.",
    )
    parser.addoption(
        "--run-browser-ui",
        action="store_true",
        default=False,
        help="Explicitly enable browser UI tests requiring Playwright/Chromium runtime.",
    )
    parser.addoption(
        "--run-integration",
        action="store_true",
        default=False,
        help="Explicitly enable multi-service integration tests.",
    )
    parser.addoption(
        "--run-deployment",
        action="store_true",
        default=False,
        help="Explicitly enable packaging and target deployment tests.",
    )


def _is_marker_opted_in(item, marker_name: str, cli_option: str, env_var: str) -> bool:
    """Determine whether an external marker has been explicitly opted into."""
    if item.config.getoption(cli_option, default=False):
        return True
    if os.environ.get(env_var) in ("1", "true", "TRUE"):
        return True
    
    # Check if selected explicitly in -m expression (and not negated)
    markexpr = item.config.getoption("-m", default="")
    if markexpr:
        # Check if the marker is mentioned positively
        # E.g. -m "temporal", -m "temporal and not live"
        tokens = markexpr.replace("(", " ").replace(")", " ").split()
        for i, token in enumerate(tokens):
            if token == marker_name:
                # Ensure it wasn't preceded by 'not'
                if i == 0 or tokens[i - 1] != "not":
                    return True

    return False


def pytest_runtest_setup(item):
    """
    Enforce explicit opt-in before opening connections or starting local servers.
    Fails closed with a clear, actionable skip reason if opt-in is absent.
    """
    guards = [
        ("live", "--run-live", "V3_RUN_LIVE", "live credentials"),
        ("container", "--run-container", "V3_RUN_CONTAINER", "Docker container runtime"),
        ("temporal", "--run-temporal", "V3_RUN_TEMPORAL", "Temporal test server"),
        ("browser_ui", "--run-browser-ui", "V3_RUN_BROWSER_UI", "Browser/Playwright runtime"),
        ("integration", "--run-integration", "V3_RUN_INTEGRATION", "Connected multi-service integration"),
        ("deployment", "--run-deployment", "V3_RUN_DEPLOYMENT", "Target deployment environment"),
    ]

    for marker_name, cli_opt, env_var, desc in guards:
        if item.get_closest_marker(marker_name):
            if not _is_marker_opted_in(item, marker_name, cli_opt, env_var):
                pytest.skip(
                    f"Test requires {desc}. Opt-in required via '-m {marker_name}', "
                    f"'{cli_opt}', or '{env_var}=1'."
                )


@pytest.fixture(autouse=True)
def setup_test_hmac_keys(monkeypatch):
    """Ensure tests run with non-empty test secrets by default unless specifically deleted."""
    if "V3_BRIDGE_HMAC_KEY" not in os.environ:
        monkeypatch.setenv("V3_BRIDGE_HMAC_KEY", "test-suite-bridge-key")
    if "V3_CALLBACK_HMAC_KEY" not in os.environ:
        monkeypatch.setenv("V3_CALLBACK_HMAC_KEY", "test-suite-callback-key")


@pytest.fixture
async def temporal_local_env(tmp_path: Path):
    """Start an isolated local Temporal dev server using the installed CLI and temp database."""
    from temporalio.testing import WorkflowEnvironment

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
