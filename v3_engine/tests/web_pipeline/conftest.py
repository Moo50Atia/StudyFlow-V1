import hashlib
import json
import os
import shutil
import tempfile
import pytest

MOCK_RUN_ID = "09a59afe-ed26-4567-906b-e42d87d53362"


@pytest.fixture
def isolated_terminal_root(tmp_path):
    root = tmp_path / "terminal-root"
    root.mkdir(parents=True, exist_ok=True)
    return str(root)


@pytest.fixture
def valid_static_package():
    return {
        "schema_version": 1,
        "files": [
            {
                "path": "index.html",
                "content": "<!DOCTYPE html><html><head><title>Test</title><link rel=\"stylesheet\" href=\"./styles.css\"></head><body><div id=\"app\">Canvas</div><script src=\"./script.js\"></script></body></html>",
            },
            {
                "path": "styles.css",
                "content": "body { margin: 0; background: #0b0f19; color: #fff; } canvas { display: block; }",
            },
            {
                "path": "script.js",
                "content": "const canvas = document.createElement('canvas'); document.body.appendChild(canvas);",
            },
        ],
    }


def make_malformed_static_package(reason: str):
    valid = {
        "schema_version": 1,
        "files": [
            {
                "path": "index.html",
                "content": "<!DOCTYPE html><html><head><title>Test</title></head><body>Hello</body></html>",
            },
            {
                "path": "styles.css",
                "content": "body { background: #000; }",
            },
            {
                "path": "script.js",
                "content": "console.log('test');",
            },
        ],
    }
    if reason == "extra_file":
        valid["files"].append({"path": "extra.png", "content": "fake_png"})
        return valid
    elif reason == "missing_file":
        valid["files"] = valid["files"][:2]
        return valid
    elif reason == "path_traversal":
        valid["files"][0]["path"] = "../secret.html"
        return valid
    elif reason == "empty_file":
        valid["files"][1]["content"] = ""
        return valid
    elif reason == "oversize_file":
        valid["files"][2]["content"] = "x" * (1024 * 1024 + 10)
        return valid
    elif reason == "invalid_schema":
        valid["schema_version"] = 2
        return valid
    else:
        raise ValueError(f"Unknown malformation reason: {reason}")


@pytest.fixture
def synthetic_two_pdf_archive(tmp_path):
    terminal_root = tmp_path / "terminal-root"
    run_dir = terminal_root / "runs" / MOCK_RUN_ID
    private_dir = run_dir / "private"
    export_dir = private_dir / "scene-prompt-exports"
    handoff_dir = private_dir / "lesson_handoffs"

    export_dir.mkdir(parents=True, exist_ok=True)
    handoff_dir.mkdir(parents=True, exist_ok=True)

    pdf1_bytes = b"SAMPLE_PDF_1_BYTES_DETERMINISTIC"
    pdf2_bytes = b"SAMPLE_PDF_2_BYTES_DETERMINISTIC"
    pdf1_hash = hashlib.sha256(pdf1_bytes).hexdigest()
    pdf2_hash = hashlib.sha256(pdf2_bytes).hexdigest()

    subject_config = {
        "run_id": MOCK_RUN_ID,
        "created_at": "2026-10-10T00:00:00Z",
        "subject_name": "Physics Mechanics",
        "pdf_files": [
            {"ordinal": 1, "filename": "01_kinematics.pdf", "sha256": pdf1_hash},
            {"ordinal": 2, "filename": "02_kinetics.pdf", "sha256": pdf2_hash},
        ],
        "framework": {
            "sha256": hashlib.sha256(b"FRAMEWORK_BYTES").hexdigest(),
            "version": "v1.0.0",
        },
    }
    with open(run_dir / "subject-config.json", "w", encoding="utf-8") as f:
        json.dump(subject_config, f, indent=2)

    for ord_num, h, fname, lessons, prompts in [
        (1, pdf1_hash, "01_kinematics.pdf", 2, 3),
        (2, pdf2_hash, "02_kinetics.pdf", 1, 2),
    ]:
        arch = {
            "run_id": MOCK_RUN_ID,
            "pdf_ordinal": ord_num,
            "filename": fname,
            "input_sha256": h,
            "status": "completed",
            "lesson_count": lessons,
            "prompt_count": prompts,
        }
        with open(run_dir / f"archive_pdf_{ord_num}.json", "w", encoding="utf-8") as f:
            json.dump(arch, f, indent=2)

    prompts_data = {
        "schema_version": 1,
        "run_id": MOCK_RUN_ID,
        "prompts": [
            {
                "pdf_ordinal": 1,
                "lesson_ordinal": 1,
                "scene_ordinal": 1,
                "scene_title": "Position and Velocity",
                "scene_type": "Interactive Graph",
                "prompt_text": "Create a 2D interactive scene for s(t) and v(t).",
            },
            {
                "pdf_ordinal": 1,
                "lesson_ordinal": 1,
                "scene_ordinal": 2,
                "scene_title": "Acceleration Vector",
                "scene_type": "Vector Visual",
                "prompt_text": "Particle on track with acceleration vector.",
            },
            {
                "pdf_ordinal": 1,
                "lesson_ordinal": 2,
                "scene_ordinal": 1,
                "scene_title": "Normal Acceleration",
                "scene_type": "Osculating Circle",
                "prompt_text": "Osculating circle showing an = v^2/rho.",
            },
            {
                "pdf_ordinal": 2,
                "lesson_ordinal": 1,
                "scene_ordinal": 1,
                "scene_title": "Newton Second Law",
                "scene_type": "FBD Simulation",
                "prompt_text": "Block with mass m on rough surface with force P.",
            },
            {
                "pdf_ordinal": 2,
                "lesson_ordinal": 1,
                "scene_ordinal": 2,
                "scene_title": "Friction Slip Threshold",
                "scene_type": "Threshold Gauge",
                "prompt_text": "Static friction vs kinetic friction transition.",
            },
        ],
    }
    with open(export_dir / "scene-prompts.json", "w", encoding="utf-8") as f:
        json.dump(prompts_data, f, indent=2)

    return {
        "terminal_root": str(terminal_root),
        "run_id": MOCK_RUN_ID,
        "run_dir": str(run_dir),
        "prompts_file": str(export_dir / "scene-prompts.json"),
        "pdf1_hash": pdf1_hash,
        "pdf2_hash": pdf2_hash,
    }


class RequestCounter:
    def __init__(self):
        self.counts: dict[str, int] = {}

    def inc(self, key: str) -> int:
        self.counts[key] = self.counts.get(key, 0) + 1
        return self.counts[key]

    def get(self, key: str) -> int:
        return self.counts.get(key, 0)

    def reset(self):
        self.counts.clear()


@pytest.fixture
def request_counter() -> RequestCounter:
    return RequestCounter()


@pytest.fixture
def mock_external_transport_double(request_counter):
    """
    Test double for external provider transports ONLY.
    Never replaces internal bridge, workers, activities, or persistence.
    """
    class ExternalTransportDouble:
        def __init__(self, counter: RequestCounter):
            self.counter = counter
            self.simulate_failure: bool = False
            self.failure_error: Exception | None = None

        def set_failure(self, err: Exception | None):
            self.simulate_failure = True
            self.failure_error = err or RuntimeError("Simulated external transport failure")

        def clear_failure(self):
            self.simulate_failure = False
            self.failure_error = None

        def call_notebooklm(self, action: str, payload: dict) -> dict:
            self.counter.inc(f"notebooklm:{action}")
            if self.simulate_failure:
                raise self.failure_error or RuntimeError("NotebookLM transport error")
            return {"status": "ok", "action": action, "mock_source_id": "src-uuid-123"}

        def call_scene_api(self, prompt: str, settings: dict) -> dict:
            self.counter.inc("scene_api:generate")
            if self.simulate_failure:
                raise self.failure_error or RuntimeError("Scene provider transport error")
            return {
                "status": "ok",
                "model": settings.get("model", "gemini-2.0-flash"),
                "files": {
                    "index.html": "<!DOCTYPE html><html><body>Canvas</body></html>",
                    "styles.css": "canvas { display: block; }",
                    "script.js": "// Three.js code",
                },
                "usage": {"prompt_tokens": 100, "completion_tokens": 200},
                "duration_ms": 450,
            }

    return ExternalTransportDouble(request_counter)

