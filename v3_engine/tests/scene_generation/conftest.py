"""Pytest fixtures for terminal scene generation (Spec 017)."""

from __future__ import annotations

import os
from pathlib import Path
import pytest

FIXTURES_DIR = Path(__file__).parent / "fixtures"


@pytest.fixture
def fixtures_dir() -> Path:
    return FIXTURES_DIR


@pytest.fixture
def repo_root() -> Path:
    return Path(__file__).resolve().parents[3]


@pytest.fixture
def default_template_path(repo_root: Path) -> Path:
    return repo_root / "Google-Ai-studio-System-prompts"
