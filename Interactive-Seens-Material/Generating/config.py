"""
Global configuration for the Generating pipeline.

All settings can be overridden via environment variables.
"""

import os
from pathlib import Path


# ── Paths ──────────────────────────────────────────────────────────────────────

# Root of the Generating module
GENERATING_ROOT = Path(__file__).parent.resolve()

# Root of the Interactive-Seens-Material directory
PROJECT_ROOT = GENERATING_ROOT.parent.resolve()

# Where per-material working directories are stored
MATERIALS_PATH = GENERATING_ROOT / "Materials"

# Where templates (route keywords, step frameworks) are stored
TEMPLATES_PATH = GENERATING_ROOT / "Templates"

# Default output path for generated HTML files
OUTPUT_BASE_PATH = os.environ.get(
    "STUDYFLOW_OUTPUT_PATH",
    str(PROJECT_ROOT)
)


# ── AI Provider ────────────────────────────────────────────────────────────────

AI_PROVIDER = os.environ.get("STUDYFLOW_AI_PROVIDER", "gemini")
AI_API_KEY = os.environ.get("STUDYFLOW_AI_API_KEY", "")
AI_MODEL = os.environ.get("STUDYFLOW_AI_MODEL", "gemini-2.5-flash")

# Max tokens to send per LLM call (safety margin below context window)
AI_MAX_INPUT_TOKENS = int(os.environ.get("STUDYFLOW_AI_MAX_INPUT_TOKENS", "100000"))

# Temperature for generation
AI_TEMPERATURE = float(os.environ.get("STUDYFLOW_AI_TEMPERATURE", "0.2"))


# ── OCR ────────────────────────────────────────────────────────────────────────

# Path to Tesseract binary (auto-detected if on PATH)
TESSERACT_PATH = os.environ.get("TESSERACT_PATH", "tesseract")

# Default OCR language
OCR_LANGUAGE = os.environ.get("STUDYFLOW_OCR_LANG", "eng")

# Minimum characters per page to consider it as having text (vs needing OCR)
OCR_TEXT_DENSITY_THRESHOLD = int(os.environ.get("STUDYFLOW_OCR_THRESHOLD", "50"))

# OCR backend: "tesseract" or "easyocr"
OCR_BACKEND = os.environ.get("STUDYFLOW_OCR_BACKEND", "tesseract")


# ── Chunking ───────────────────────────────────────────────────────────────────

# Target chunk size in characters (well within LLM context limits)
CHUNK_TARGET_SIZE = int(os.environ.get("STUDYFLOW_CHUNK_SIZE", "80000"))

# Overlap between chunks in characters (to preserve context at boundaries)
CHUNK_OVERLAP = int(os.environ.get("STUDYFLOW_CHUNK_OVERLAP", "2000"))

# Approximate chars-per-token ratio for token estimation
CHARS_PER_TOKEN = float(os.environ.get("STUDYFLOW_CHARS_PER_TOKEN", "4.0"))


# ── Route Detection ───────────────────────────────────────────────────────────

# Supported routes
SUPPORTED_ROUTES = [
    "Medical",
    "Engineering",
    "Computer Science",
    "Mathematics",
    "Physics",
    "General",
]

# Fallback route if detection fails
DEFAULT_ROUTE = "General"

# Minimum confidence for AI route detection (below this → fallback)
ROUTE_CONFIDENCE_THRESHOLD = float(
    os.environ.get("STUDYFLOW_ROUTE_CONFIDENCE", "0.7")
)


# ── Validation ─────────────────────────────────────────────────────────────────

# Minimum coverage percentage to pass validation
VALIDATION_MIN_COVERAGE = float(
    os.environ.get("STUDYFLOW_MIN_COVERAGE", "85.0")
)

# Minimum visualization readiness score to generate Dynamic Views
VISUALIZATION_READINESS_THRESHOLD = float(
    os.environ.get("STUDYFLOW_VIZ_THRESHOLD", "60.0")
)


# ── Dynamic View ───────────────────────────────────────────────────────────────

# Enable caching of Dynamic View prompts
DV_CACHE_ENABLED = os.environ.get("STUDYFLOW_DV_CACHE", "true").lower() == "true"

# Scene types available for Dynamic View mapping
DV_SCENE_TYPES = [
    "Process",
    "Comparison",
    "Accumulation",
    "Parallel Flow",
    "Time Evolution",
]


# ── Logging ────────────────────────────────────────────────────────────────────

LOG_LEVEL = os.environ.get("STUDYFLOW_LOG_LEVEL", "INFO")
LOG_FORMAT = "%(asctime)s [%(levelname)s] %(name)s: %(message)s"


# ── SaaS Readiness & HITL ──────────────────────────────────────────────────────

# Unique pipeline run ID format (set by orchestrator at runtime)
# Used for multi-user / queue processing traceability
PIPELINE_RUN_ID_PREFIX = "run"

# Pause pipeline after Stage 5 for human approval of the structure
REQUIRE_HUMAN_APPROVAL_AFTER_STAGE_5 = os.environ.get("STUDYFLOW_HITL_STAGE_5", "true").lower() == "true"

# Pricing for cost estimation (USD per 1k tokens, default based on Gemini 1.5 Flash)
COST_PER_1K_PROMPT_TOKENS = float(os.environ.get("STUDYFLOW_COST_PROMPT", "0.000073"))
COST_PER_1K_COMPLETION_TOKENS = float(os.environ.get("STUDYFLOW_COST_COMPLETION", "0.0003"))


def get_material_dir(material_name: str) -> Path:
    """Get or create the working directory for a material."""
    material_dir = MATERIALS_PATH / material_name
    material_dir.mkdir(parents=True, exist_ok=True)
    return material_dir


def get_output_dir(user_name: str) -> Path:
    """Get or create the output directory for generated HTML files."""
    output_dir = Path(OUTPUT_BASE_PATH) / user_name
    output_dir.mkdir(parents=True, exist_ok=True)
    return output_dir
