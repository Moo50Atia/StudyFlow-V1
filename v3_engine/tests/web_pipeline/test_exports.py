"""Tests for final prompt exports and bundle finalization (T032)."""

import json
import pytest
from pathlib import Path
from v3_engine.web_pipeline.finalize import (
    format_prompt_export_json,
    format_prompt_export_text,
    collect_run_prompt_manifest,
)


@pytest.fixture
def sample_archives():
    return [
        {
            "pdf_ordinal": 1,
            "filename": "kinematics.pdf",
            "state": "completed",
            "scenes": [
                {
                    "scene_ordinal": 1,
                    "section_title": "Position and Velocity",
                    "scene_type": "2D Interactive Graph",
                    "prompt_body": "Create an interactive graph showing s(t) and v(t) integration.",
                }
            ],
        },
        {
            "pdf_ordinal": 2,
            "filename": "dynamics.pdf",
            "state": "completed",
            "scenes": [
                {
                    "scene_ordinal": 1,
                    "section_title": "Friction Simulation",
                    "scene_type": "3D Physics Simulation",
                    "prompt_body": "Render a 3D block with horizontal force showing friction threshold.",
                }
            ],
        },
    ]


def test_collect_run_prompt_manifest_gathers_all_scenes(sample_archives):
    manifest = collect_run_prompt_manifest("run-123", sample_archives)
    assert manifest["schema_version"] == 1
    assert manifest["run_id"] == "run-123"
    assert manifest["total_prompts"] == 2
    assert len(manifest["prompts"]) == 2
    assert manifest["prompts"][0]["section_title"] == "Position and Velocity"
    assert manifest["prompts"][1]["section_title"] == "Friction Simulation"


def test_collect_run_prompt_manifest_handles_partial_failed_items():
    mixed_archives = [
        {
            "pdf_ordinal": 1,
            "filename": "kinematics.pdf",
            "state": "completed",
            "scenes": [
                {
                    "scene_ordinal": 1,
                    "section_title": "Position and Velocity",
                    "scene_type": "2D Interactive Graph",
                    "prompt_body": "Prompt 1",
                }
            ],
        },
        {
            "pdf_ordinal": 2,
            "filename": "broken.pdf",
            "state": "failed",
            "scenes": [],
        },
    ]
    manifest = collect_run_prompt_manifest("run-partial", mixed_archives)
    assert manifest["total_prompts"] == 1
    assert len(manifest["prompts"]) == 1
    assert manifest["status"] == "partial"


def test_format_prompt_export_json_output(sample_archives):
    manifest = collect_run_prompt_manifest("run-123", sample_archives)
    json_str = format_prompt_export_json(manifest)
    parsed = json.loads(json_str)
    assert parsed["run_id"] == "run-123"
    assert parsed["total_prompts"] == 2
    assert "prompts" in parsed


def test_format_prompt_export_text_output(sample_archives):
    manifest = collect_run_prompt_manifest("run-123", sample_archives)
    text = format_prompt_export_text(manifest)
    assert "Run: run-123" in text
    assert "Position and Velocity" in text
    assert "Friction Simulation" in text
    assert "2D Interactive Graph" in text
    assert "Create an interactive graph" in text
