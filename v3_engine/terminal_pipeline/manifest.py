"""Submission manifest and input snapshot capture for Terminal Pipeline (Spec 018)."""

from __future__ import annotations

import hashlib
import json
import os
import re
import shutil
import uuid
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from v3_engine.terminal_pipeline.contracts import (
    ArtifactRef,
    SafeCode,
    validate_canonical_uuid,
    validate_sha256,
)
from v3_engine.scene_generation.config import resolve_gemini_generation_settings

VALID_PROVIDERS = {"gemini", "glm", "deepseek", "qwen"}
VALID_SCENE_GENERATION_MODES = {"generate", "export_prompts"}
VALID_PDF_PROCESSING_MODES = {"joint", "per_pdf"}
VALID_HANDOFF_KINDS = {
    "notebooklm_multi_pdf_lesson_raw_handoff",
    "notebooklm_multi_pdf_lesson_handoff",
    "notebooklm_lesson_handoff",
}


def canonical_json_dumps(obj: Any) -> str:
    """Format JSON strictly as UTF-8, sorted keys, compact separators, no NaN."""
    return json.dumps(obj, sort_keys=True, separators=(",", ":"), ensure_ascii=True, allow_nan=False)


def compute_digest(obj: Any, exclude_keys: tuple[str, ...] = ("manifest_sha256",)) -> str:
    """Compute SHA-256 digest of canonical JSON serialization excluding specified keys."""
    if isinstance(obj, dict):
        clean_dict = {k: v for k, v in obj.items() if k not in exclude_keys}
        raw = canonical_json_dumps(clean_dict).encode("utf-8")
    else:
        raw = canonical_json_dumps(obj).encode("utf-8")
    return hashlib.sha256(raw).hexdigest()


def compute_file_sha256(path: Path) -> tuple[str, int]:
    h = hashlib.sha256()
    size = 0
    with open(path, "rb") as f:
        while chunk := f.read(65536):
            h.update(chunk)
            size += len(chunk)
    return h.hexdigest(), size


def _tree_identity(path: Path) -> list[tuple[str, str, int]]:
    if path.is_symlink():
        raise ManifestCaptureError("Symbolic-link handoff input is not permitted", SafeCode.input_invalid)
    files = [path] if path.is_file() else sorted(p for p in path.rglob("*") if p.is_file())
    rows = []
    for item in files:
        if item.is_symlink():
            raise ManifestCaptureError("Symbolic-link handoff input is not permitted", SafeCode.input_invalid)
        sha, size = compute_file_sha256(item)
        rows.append((item.name if path.is_file() else str(item.relative_to(path)), sha, size))
    return rows


def resolve_original_pdfs(
    pdf_dir: str | Path | None = None,
    pdf_files: Any = None,
    project_root: Path | None = None,
    check_hashes: bool = False,
) -> list[Path]:
    """Resolve and validate original PDF paths preserving explicit order or folder filename order.

    Enforces:
    - Exactly one of pdf_dir or pdf_files must be provided.
    - If pdf_dir: directory must exist; nonrecursive enumeration of *.pdf files in Unicode filename order; non-empty.
    - If pdf_files: must be a non-empty list of existing files; preserves supplied order.
    - Rejects duplicate canonical paths.
    - If check_hashes is True, rejects duplicate file content hashes across originals.
    """
    proj_root = project_root or Path.cwd()
    if (pdf_dir is None and pdf_files is None) or (pdf_dir is not None and pdf_files is not None):
        raise ManifestCaptureError("Exactly one of pdf_dir or pdf_files must be provided", SafeCode.input_invalid)

    resolved: list[Path] = []
    if pdf_dir is not None:
        dir_path = (proj_root / str(pdf_dir)).resolve()
        if not dir_path.is_dir():
            raise ManifestCaptureError(f"pdf_dir does not exist: {pdf_dir}", SafeCode.input_invalid)
        found = sorted([p for p in dir_path.iterdir() if p.is_file() and p.name.lower().endswith(".pdf")], key=lambda p: p.name)
        if not found:
            raise ManifestCaptureError(f"No .pdf files found in {pdf_dir}", SafeCode.input_invalid)
        resolved = found
    else:
        if not isinstance(pdf_files, (list, tuple)) or not pdf_files:
            raise ManifestCaptureError("pdf_files must be a non-empty list", SafeCode.input_invalid)
        for pf in pdf_files:
            p = (proj_root / str(pf)).resolve()
            if not p.is_file():
                raise ManifestCaptureError(f"PDF file does not exist: {pf}", SafeCode.input_invalid)
            resolved.append(p)

    seen_paths: set[str] = set()
    for p in resolved:
        canon = str(p)
        if canon in seen_paths:
            raise ManifestCaptureError(f"Duplicate PDF path in subject: {p.name}", SafeCode.input_invalid)
        seen_paths.add(canon)

    if check_hashes:
        seen_hashes: set[str] = set()
        for p in resolved:
            src_hash, _ = compute_file_sha256(p)
            if src_hash in seen_hashes:
                raise ManifestCaptureError(f"Duplicate PDF content hash in subject: {p.name}", SafeCode.input_invalid)
            seen_hashes.add(src_hash)

    return resolved


def _reuse_existing_capture(raw_dict: dict[str, Any], manifest_file: Path, terminal_root: Path, project_root: Path, scene_generation_mode: str, gemini_thinking_budget: int, gemini_max_output_tokens: int, pdf_processing: str | None = None, prepared_input_manifest: Path | None = None) -> SubmissionManifest:
    try:
        existing = SubmissionManifest.from_dict(json.loads(manifest_file.read_text(encoding="utf-8")))
    except Exception as exc:
        raise ManifestCaptureError("Existing captured manifest is unreadable", SafeCode.input_changed) from exc
    if not existing.verify_digest():
        raise ManifestCaptureError("Existing captured manifest digest is invalid", SafeCode.input_changed)
    raw_subjects = raw_dict.get("subjects", [])
    if len(raw_subjects) != len(existing.subjects):
        raise ManifestCaptureError("Submission ID reused with different subjects", SafeCode.input_changed)
    old_by_key = {subject.subject_key: subject for subject in existing.subjects}
    for raw in raw_subjects:
        if not isinstance(raw, dict):
            raise ManifestCaptureError("Each subject must be an object", SafeCode.input_invalid)
        key = str(raw.get("subject_key", "")).strip()
        old = old_by_key.get(key)
        mode = str(raw.get("mode", "new")).strip()
        if prepared_input_manifest is not None:
            if old is None or old.hosted_input_manifest_ref is None or not old.hosted_input_manifest_ref.verify_on_disk(terminal_root):
                raise ManifestCaptureError("Hosted input capture is unavailable", SafeCode.input_changed)
            digest, size = compute_file_sha256(prepared_input_manifest)
            if digest != old.hosted_input_manifest_ref.sha256 or size != old.hosted_input_manifest_ref.bytes:
                raise ManifestCaptureError("Hosted manifest changed on replay", SafeCode.input_changed)
        elif old is not None and old.hosted_input_manifest_ref is not None:
            raise ManifestCaptureError("Hosted replay requires its prepared input manifest", SafeCode.input_changed)
        if (old is None or old.mode != mode or old.provider != str(raw.get("provider", "")).strip().lower()
                or old.model != str(raw.get("model", "")).strip()
                or old.scene_generation_mode != scene_generation_mode
                or old.pdf_processing != (pdf_processing or raw.get("pdf_processing", "joint"))
                or old.gemini_thinking_budget != gemini_thinking_budget
                or old.gemini_max_output_tokens != gemini_max_output_tokens):
            raise ManifestCaptureError("Submission ID reused with changed subject configuration", SafeCode.input_changed)
        if int(raw.get("timeout_seconds", 300)) != old.timeout_seconds:
            raise ManifestCaptureError("Submission ID reused with changed timeout", SafeCode.input_changed)
        if str(raw.get("display_title", raw.get("title", key))) != old.display_title:
            raise ManifestCaptureError("Submission ID reused with changed subject title", SafeCode.input_changed)
        if raw.get("subject_run_id") and raw["subject_run_id"] != old.subject_run_id:
            raise ManifestCaptureError("Submission ID reused with changed subject identity", SafeCode.input_changed)
        if mode == "new":
            if str(raw.get("account_check_notebook_id", "")) != str(old.account_check_notebook_id or ""):
                raise ManifestCaptureError("Submission ID reused with changed proof notebook", SafeCode.input_changed)
            current = resolve_original_pdfs(
                pdf_dir=raw.get("pdf_dir"),
                pdf_files=raw.get("pdf_files"),
                project_root=project_root,
                check_hashes=False,
            )
            if old.pdf_preparation_ref is not None:
                from v3_engine.terminal_pipeline.pdf_preparation import verify_preparation
                prep_inv_path = old.pdf_preparation_ref.resolve(terminal_root)
                try:
                    inventory = verify_preparation(prep_inv_path)
                except Exception as exc:
                    raise ManifestCaptureError(f"Prepared inventory verification failed: {exc}", SafeCode.input_changed) from exc
                if len(current) != len(inventory.originals):
                    raise ManifestCaptureError("Submission ID reused with changed PDF set", SafeCode.input_changed)
                for cur_p, orig_rec in zip(current, inventory.originals):
                    orig_rel = str(cur_p.relative_to(project_root) if cur_p.is_relative_to(project_root) else cur_p)
                    if orig_rel != orig_rec.original_path and str(cur_p) != orig_rec.original_path and cur_p.name != orig_rec.original_filename:
                        raise ManifestCaptureError("Captured original PDF changed; refusing overwrite", SafeCode.input_changed)
                    cur_sha, cur_size = compute_file_sha256(cur_p)
                    if cur_sha != orig_rec.sha256 or cur_size != orig_rec.bytes:
                        raise ManifestCaptureError("Captured PDF bytes changed; refusing overwrite", SafeCode.input_changed)
                for snapshot in old.inputs:
                    if not snapshot.snapshot_ref.verify_on_disk(terminal_root):
                        raise ManifestCaptureError("Captured input artifact missing or changed; refusing overwrite", SafeCode.input_changed)
            else:
                old_pdfs = [item for item in old.inputs if item.kind == "pdf"]
                if len(current) != len(old_pdfs):
                    raise ManifestCaptureError("Submission ID reused with changed PDF set", SafeCode.input_changed)
                for current_path, snapshot in zip(current, old_pdfs):
                    original = str(current_path.relative_to(project_root) if current_path.is_relative_to(project_root) else current_path)
                    sha, size = compute_file_sha256(current_path)
                    if original != snapshot.original_path or sha != snapshot.sha256 or size != snapshot.bytes or not snapshot.snapshot_ref.verify_on_disk(terminal_root):
                        raise ManifestCaptureError("Captured PDF bytes changed; refusing overwrite", SafeCode.input_changed)
            step_path = (project_root / str(raw.get("steps", "steps.txt"))).resolve()
            old_steps = next((item for item in old.inputs if item.kind == "steps"), None)
            if old_steps is None:
                raise ManifestCaptureError("Captured steps snapshot is missing", SafeCode.input_changed)
            step_original = str(step_path.relative_to(project_root) if step_path.is_relative_to(project_root) else step_path)
            sha, size = compute_file_sha256(step_path)
            if step_original != old_steps.original_path or sha != old_steps.sha256 or size != old_steps.bytes or not old_steps.snapshot_ref.verify_on_disk(terminal_root):
                raise ManifestCaptureError("Captured steps bytes changed; refusing overwrite", SafeCode.input_changed)
        else:
            source = (project_root / str(raw.get("handoff", ""))).resolve()
            old_handoff = next((item for item in old.inputs if item.kind == "handoff"), None)
            if old_handoff is None:
                raise ManifestCaptureError("Captured handoff is missing", SafeCode.input_changed)
            original = str(source.relative_to(project_root) if source.is_relative_to(project_root) else source)
            retained = old_handoff.snapshot_ref.resolve(terminal_root)
            if original != old_handoff.original_path or _tree_identity(source) != _tree_identity(retained):
                raise ManifestCaptureError("Handoff bytes changed; refusing overwrite", SafeCode.input_changed)
    return existing


@dataclass(frozen=True)
class InputSnapshot:
    kind: str  # "pdf", "steps", "handoff"
    ordinal: int
    original_path: str
    snapshot_ref: ArtifactRef
    sha256: str
    bytes: int
    pdf_lineage: dict[str, Any] | None = None

    def to_dict(self) -> dict[str, Any]:
        d: dict[str, Any] = {
            "kind": self.kind,
            "ordinal": self.ordinal,
            "original_path": self.original_path,
            "snapshot_ref": self.snapshot_ref.to_dict(),
            "sha256": self.sha256,
            "bytes": self.bytes,
        }
        if self.pdf_lineage is not None:
            d["pdf_lineage"] = self.pdf_lineage
        return d

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> InputSnapshot:
        lineage = data.get("pdf_lineage")
        return cls(
            kind=str(data["kind"]),
            ordinal=int(data["ordinal"]),
            original_path=str(data["original_path"]),
            snapshot_ref=ArtifactRef.from_dict(data["snapshot_ref"]),
            sha256=str(data["sha256"]),
            bytes=int(data["bytes"]),
            pdf_lineage=dict(lineage) if isinstance(lineage, dict) else None,
        )


@dataclass(frozen=True)
class SubjectConfig:
    subject_key: str
    subject_run_id: str
    mode: str
    display_title: str
    provider: str
    model: str
    inputs: list[InputSnapshot]
    timeout_seconds: int = 300
    endpoint: str | None = None
    toolchain: str = "plain-html-css-js-v1"
    account_alias: str = "mo50atiapro"
    account_check_notebook_id: str | None = None
    handoff_kind: str | None = None
    handoff_ref: ArtifactRef | None = None
    scene_generation_mode: str = "generate"
    gemini_thinking_budget: int = 24576
    gemini_max_output_tokens: int = 65536
    pdf_processing: str = "joint"
    pdf_preparation_ref: ArtifactRef | None = None
    hosted_input_manifest_ref: ArtifactRef | None = None
    captured_notebooklm_protocol_json: str | None = None
    captured_notebooklm_protocol_sha256: str | None = None
    captured_scene_system_prompt: str | None = None
    captured_scene_system_prompt_sha256: str | None = None

    def __post_init__(self) -> None:
        if not self.subject_key:
            raise ValueError("subject_key cannot be empty")
        validate_canonical_uuid(self.subject_run_id, "SubjectConfig.subject_run_id")
        if self.mode not in {"new", "saved"}:
            raise ValueError(f"mode must be 'new' or 'saved', got: {self.mode!r}")
        if self.scene_generation_mode not in VALID_SCENE_GENERATION_MODES:
            raise ValueError(f"scene_generation_mode must be one of {sorted(VALID_SCENE_GENERATION_MODES)}")
        if self.pdf_processing not in VALID_PDF_PROCESSING_MODES or (self.mode == "saved" and self.pdf_processing != "joint"):
            raise ValueError("pdf_processing must be 'joint' or 'per_pdf'; per_pdf requires new mode")
        if not (0 <= self.gemini_thinking_budget <= 24576 and 1024 <= self.gemini_max_output_tokens <= 65536
                and self.gemini_thinking_budget < self.gemini_max_output_tokens):
            raise ValueError("Captured Gemini thinking/output token budgets are invalid")
        if self.provider not in VALID_PROVIDERS:
            raise ValueError(f"provider must be one of {sorted(VALID_PROVIDERS)}, got: {self.provider!r}")
        if not self.model or not self.model.strip():
            raise ValueError("model must be explicit and non-empty")
        if not (1 <= self.timeout_seconds <= 1500):
            raise ValueError(f"timeout_seconds must be between 1 and 1500, got: {self.timeout_seconds}")
        if self.mode == "new":
            if not self.account_check_notebook_id:
                raise ValueError("account_check_notebook_id is required in new mode")
            if not self.inputs:
                raise ValueError("new mode requires at least one input snapshot")
        elif self.mode == "saved":
            if self.account_check_notebook_id is not None:
                raise ValueError("account_check_notebook_id is forbidden in saved mode")
        for text_field, digest_field in (
            ("captured_notebooklm_protocol_json", "captured_notebooklm_protocol_sha256"),
            ("captured_scene_system_prompt", "captured_scene_system_prompt_sha256"),
        ):
            text = getattr(self, text_field)
            digest = getattr(self, digest_field)
            if (text is None) != (digest is None):
                raise ValueError(f"{text_field} and {digest_field} must be captured together")
            if text is not None and hashlib.sha256(text.encode("utf-8")).hexdigest() != digest:
                raise ValueError(f"{text_field} digest does not match its captured bytes")

    def to_dict(self) -> dict[str, Any]:
        d: dict[str, Any] = {
            "subject_key": self.subject_key,
            "subject_run_id": self.subject_run_id,
            "mode": self.mode,
            "display_title": self.display_title,
            "provider": self.provider,
            "model": self.model,
            "timeout_seconds": self.timeout_seconds,
            "toolchain": self.toolchain,
            "scene_generation_mode": self.scene_generation_mode,
            "gemini_thinking_budget": self.gemini_thinking_budget,
            "gemini_max_output_tokens": self.gemini_max_output_tokens,
            "inputs": [inp.to_dict() for inp in self.inputs],
        }
        # Omit the legacy default to preserve existing manifest digests.
        if self.pdf_processing != "joint":
            d["pdf_processing"] = self.pdf_processing
        if self.pdf_preparation_ref is not None:
            d["pdf_preparation_ref"] = self.pdf_preparation_ref.to_dict()
        if self.hosted_input_manifest_ref is not None:
            d["hosted_input_manifest_ref"] = self.hosted_input_manifest_ref.to_dict()
        if self.captured_notebooklm_protocol_json is not None:
            d["captured_notebooklm_protocol_json"] = self.captured_notebooklm_protocol_json
            d["captured_notebooklm_protocol_sha256"] = self.captured_notebooklm_protocol_sha256
        if self.captured_scene_system_prompt is not None:
            d["captured_scene_system_prompt"] = self.captured_scene_system_prompt
            d["captured_scene_system_prompt_sha256"] = self.captured_scene_system_prompt_sha256
        if self.endpoint:
            d["endpoint"] = self.endpoint
        if self.mode == "new":
            d["account_alias"] = self.account_alias
            d["account_check_notebook_id"] = self.account_check_notebook_id
        if self.mode == "saved":
            if self.handoff_kind:
                d["handoff_kind"] = self.handoff_kind
            if self.handoff_ref:
                d["handoff_ref"] = self.handoff_ref.to_dict()
        return d

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> SubjectConfig:
        inputs = [InputSnapshot.from_dict(inp) for inp in data.get("inputs", [])]
        handoff_ref = ArtifactRef.from_dict(data["handoff_ref"]) if data.get("handoff_ref") else None
        prep_ref = ArtifactRef.from_dict(data["pdf_preparation_ref"]) if data.get("pdf_preparation_ref") else None
        return cls(
            subject_key=str(data["subject_key"]),
            subject_run_id=str(data["subject_run_id"]),
            mode=str(data["mode"]),
            display_title=str(data.get("display_title", data["subject_key"])),
            provider=str(data["provider"]),
            model=str(data["model"]),
            inputs=inputs,
            timeout_seconds=int(data.get("timeout_seconds", 300)),
            endpoint=data.get("endpoint"),
            toolchain=str(data.get("toolchain", "plain-html-css-js-v1")),
            account_alias=str(data.get("account_alias", "mo50atiapro")),
            account_check_notebook_id=data.get("account_check_notebook_id"),
            handoff_kind=data.get("handoff_kind"),
            handoff_ref=handoff_ref,
            scene_generation_mode=str(data.get("scene_generation_mode", "generate")),
            gemini_thinking_budget=int(data.get("gemini_thinking_budget", 24576)),
            gemini_max_output_tokens=int(data.get("gemini_max_output_tokens", 65536)),
            pdf_processing=str(data.get("pdf_processing", "joint")),
            pdf_preparation_ref=prep_ref,
            hosted_input_manifest_ref=ArtifactRef.from_dict(data["hosted_input_manifest_ref"]) if data.get("hosted_input_manifest_ref") else None,
            captured_notebooklm_protocol_json=data.get("captured_notebooklm_protocol_json"),
            captured_notebooklm_protocol_sha256=data.get("captured_notebooklm_protocol_sha256"),
            captured_scene_system_prompt=data.get("captured_scene_system_prompt"),
            captured_scene_system_prompt_sha256=data.get("captured_scene_system_prompt_sha256"),
        )


@dataclass(frozen=True)
class SubmissionManifest:
    schema_version: int
    submission_id: str
    created_at: str
    manifest_sha256: str
    subjects: list[SubjectConfig]

    def __post_init__(self) -> None:
        if self.schema_version != 1:
            raise ValueError(f"schema_version must be 1, got: {self.schema_version}")
        validate_canonical_uuid(self.submission_id, "SubmissionManifest.submission_id")
        validate_sha256(self.manifest_sha256, "SubmissionManifest.manifest_sha256")
        if not self.subjects:
            raise ValueError("subjects must not be empty")
        seen_keys = set()
        for s in self.subjects:
            if s.subject_key in seen_keys:
                raise ValueError(f"Duplicate subject_key in submission: {s.subject_key!r}")
            seen_keys.add(s.subject_key)

    def to_dict(self) -> dict[str, Any]:
        return {
            "schema_version": self.schema_version,
            "submission_id": self.submission_id,
            "created_at": self.created_at,
            "manifest_sha256": self.manifest_sha256,
            "subjects": [s.to_dict() for s in self.subjects],
        }

    def verify_digest(self) -> bool:
        clean = {k: v for k, v in self.to_dict().items() if k != "manifest_sha256"}
        expected = hashlib.sha256(canonical_json_dumps(clean).encode("utf-8")).hexdigest()
        return expected == self.manifest_sha256

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> SubmissionManifest:
        subjects = [SubjectConfig.from_dict(s) for s in data["subjects"]]
        return cls(
            schema_version=int(data["schema_version"]),
            submission_id=str(data["submission_id"]),
            created_at=str(data["created_at"]),
            manifest_sha256=str(data["manifest_sha256"]),
            subjects=subjects,
        )


class ManifestCaptureError(Exception):
    def __init__(self, message: str, safe_code: SafeCode = SafeCode.input_invalid):
        super().__init__(message)
        self.safe_code = safe_code


def capture_manifest(
    raw_dict: dict[str, Any],
    terminal_root: Path,
    project_root: Path | None = None,
    scene_generation_mode: str = "generate",
    pdf_processing: str | None = None,
    prepared_input_manifest: Path | None = None,
) -> tuple[SubmissionManifest, Path]:
    """Validate raw manifest dict, snapshot inputs into terminal_root, compute canonical digest, and save manifest.json."""
    proj_root = project_root or Path.cwd()
    schema_version = raw_dict.get("schema_version", 1)
    if schema_version != 1:
        raise ManifestCaptureError("schema_version must be 1", SafeCode.input_invalid)

    sub_id_raw = raw_dict.get("submission_id")
    if not sub_id_raw:
        sub_id = str(uuid.uuid4())
    else:
        try:
            sub_id = validate_canonical_uuid(str(sub_id_raw), "submission_id")
        except ValueError as e:
            raise ManifestCaptureError(str(e), SafeCode.input_invalid) from e

    raw_subjects = raw_dict.get("subjects", [])
    if not isinstance(raw_subjects, list) or not raw_subjects:
        raise ManifestCaptureError("subjects must be a non-empty list", SafeCode.input_invalid)
    for subject in raw_subjects:
        selected = pdf_processing or (subject.get("pdf_processing", "joint") if isinstance(subject, dict) else None)
        if selected not in VALID_PDF_PROCESSING_MODES or (isinstance(subject, dict) and subject.get("mode", "new") == "saved" and selected != "joint"):
            raise ManifestCaptureError("pdf_processing must be 'joint' or 'per_pdf'; per_pdf requires new mode")

    hosted = None
    if prepared_input_manifest is not None:
        from v3_engine.local_worker.protocol import strict_json_decode, validate_input_manifest
        if len(raw_subjects) != 1 or (pdf_processing or raw_subjects[0].get('pdf_processing')) != 'per_pdf' or raw_subjects[0].get('mode', 'new') != 'new':
            raise ManifestCaptureError("Prepared hosted inputs require one new per-PDF subject")
        prepared_input_manifest = prepared_input_manifest.resolve()
        hosted_bytes = prepared_input_manifest.read_bytes()
        hosted = strict_json_decode(hosted_bytes, max_size=2097152)
        validate_input_manifest(hosted)
        seal = prepared_input_manifest.with_suffix('.sha256').read_text(encoding='ascii').strip()
        if hashlib.sha256(hosted_bytes).hexdigest() != seal or hosted['task_kind'] != 'author_prompts':
            raise ManifestCaptureError("Prepared input manifest seal/role mismatch", SafeCode.input_changed)
        if raw_subjects[0].get('subject_run_id') != str(uuid.uuid5(uuid.UUID(hosted['job_id']), 'terminal-subject-v1')):
            raise ManifestCaptureError("Hosted job and terminal run identities differ", SafeCode.input_changed)

    submission_dir = terminal_root / "submissions" / sub_id
    manifest_file = submission_dir / "manifest.json"
    if scene_generation_mode not in VALID_SCENE_GENERATION_MODES:
        raise ManifestCaptureError("scene_generation_mode must be 'generate' or 'export_prompts'", SafeCode.input_invalid)
    try:
        gemini_thinking_budget, gemini_max_output_tokens = resolve_gemini_generation_settings()
    except Exception as exc:
        raise ManifestCaptureError(str(exc), SafeCode.input_invalid) from exc
    if manifest_file.exists():
        return _reuse_existing_capture(raw_dict, manifest_file, terminal_root, proj_root,
                                       scene_generation_mode, gemini_thinking_budget,
                                       gemini_max_output_tokens, pdf_processing, prepared_input_manifest), manifest_file
    inputs_dir = submission_dir / "inputs"
    inputs_dir.mkdir(parents=True, exist_ok=True)

    captured_subjects: list[SubjectConfig] = []
    seen_keys: set[str] = set()

    for raw_s in raw_subjects:
        if not isinstance(raw_s, dict):
            raise ManifestCaptureError("Each subject must be an object", SafeCode.input_invalid)
        key = str(raw_s.get("subject_key", "")).strip()
        if not key:
            raise ManifestCaptureError("subject_key is required", SafeCode.input_invalid)
        if key in seen_keys:
            raise ManifestCaptureError(f"Duplicate subject_key: {key}", SafeCode.input_invalid)
        seen_keys.add(key)

        mode = str(raw_s.get("mode", "new")).strip()
        if mode not in {"new", "saved"}:
            raise ManifestCaptureError(f"Unsupported mode: {mode}", SafeCode.input_invalid)

        provider = str(raw_s.get("provider", "")).strip().lower()
        if provider not in VALID_PROVIDERS:
            raise ManifestCaptureError(f"Invalid provider: {provider}", SafeCode.input_invalid)

        model = str(raw_s.get("model", "")).strip()
        if not model:
            raise ManifestCaptureError("model is required", SafeCode.input_invalid)

        timeout = int(raw_s.get("timeout_seconds", 300))
        if not (1 <= timeout <= 1500):
            raise ManifestCaptureError("timeout_seconds must be 1-1500", SafeCode.input_invalid)

        display_title = str(raw_s.get("display_title", raw_s.get("title", key)))
        subject_run_id = raw_s.get("subject_run_id")
        if not subject_run_id:
            subject_run_id = str(uuid.uuid4())
        else:
            subject_run_id = validate_canonical_uuid(str(subject_run_id), "subject_run_id")

        snapshots: list[InputSnapshot] = []

        if mode == "new":
            if "handoff" in raw_s:
                raise ManifestCaptureError("handoff field forbidden in new mode", SafeCode.input_invalid)

            proof_nb = raw_s.get("account_check_notebook_id")
            if not proof_nb or not str(proof_nb).strip():
                raise ManifestCaptureError("account_check_notebook_id required in new mode", SafeCode.input_invalid)

            resolved_pdfs = resolve_original_pdfs(
                pdf_dir=raw_s.get("pdf_dir"),
                pdf_files=raw_s.get("pdf_files"),
                project_root=proj_root,
                check_hashes=True,
            )

            steps_rel = raw_s.get("steps", "steps.txt")
            steps_path = (proj_root / str(steps_rel)).resolve()
            if not steps_path.is_file():
                raise ManifestCaptureError(f"Steps framework file does not exist: {steps_rel}", SafeCode.input_invalid)

            effective_pdf_proc = pdf_processing or raw_s.get("pdf_processing", "joint")
            hosted_ref = None
            if hosted is not None:
                from v3_engine.terminal_pipeline.pdf_preparation import inspect_pdf_strict
                if len(resolved_pdfs) != len(hosted['items']):
                    raise ManifestCaptureError("Prepared PDF count differs from hosted inventory")
                for idx, (pdf_p, item) in enumerate(zip(resolved_pdfs, hosted['items']), 1):
                    sha, size = compute_file_sha256(pdf_p)
                    if (item['upload_ordinal'] != idx or sha != item['prepared_sha256']
                            or size != item['prepared_byte_count'] or inspect_pdf_strict(pdf_p) != item['prepared_page_count']):
                        raise ManifestCaptureError("Prepared PDF bytes/order differ from hosted inventory", SafeCode.input_changed)
                    dest = inputs_dir / f'hosted-pdf-{idx:04d}.pdf'
                    shutil.copy2(pdf_p, dest)
                    if compute_file_sha256(dest) != (sha, size):
                        raise ManifestCaptureError("Prepared PDF snapshot corruption", SafeCode.storage_unavailable)
                    snapshots.append(InputSnapshot(kind='pdf', ordinal=idx, original_path=str(pdf_p),
                        snapshot_ref=ArtifactRef(str(dest.relative_to(terminal_root)), sha, size), sha256=sha, bytes=size,
                        pdf_lineage={'source_ordinal': item['source_ordinal'], 'part_ordinal': item['part_ordinal'],
                            'start_page': item['original_page_start'], 'end_page': item['original_page_end'],
                            'inventory_sha256': hosted['preparation']['inventory_sha256'],
                            'hosted_run_id': hosted['run_id'], 'hosted_item_id': item['item_id'],
                            'original_sha256': item['original_sha256'], 'original_page_count': item['original_page_count']}))
                hosted_dest = inputs_dir / 'hosted-input-manifest.json'
                shutil.copy2(prepared_input_manifest, hosted_dest)
                digest, size = compute_file_sha256(hosted_dest)
                if digest != seal:
                    raise ManifestCaptureError("Hosted manifest changed while capturing", SafeCode.input_changed)
                hosted_ref = ArtifactRef(str(hosted_dest.relative_to(terminal_root)), digest, size)
                pdf_prep_ref = None
            elif effective_pdf_proc == "per_pdf":
                from v3_engine.terminal_pipeline.pdf_preparation import prepare_pdf_sources, PreparationError, PreparationPolicy
                subject_token = f"{re.sub(r'[^a-zA-Z0-9_-]', '_', key)}-{hashlib.sha256(key.encode('utf-8')).hexdigest()[:8]}"
                prep_dir = submission_dir / "preparation" / subject_token
                try:
                    prep_inv = prepare_pdf_sources(resolved_pdfs, prep_dir, policy=PreparationPolicy(version=2, split_trigger_pages=50))
                except PreparationError as pe:
                    raise ManifestCaptureError(str(pe), pe.safe_code) from pe
                except Exception as exc:
                    raise ManifestCaptureError(f"PDF preparation failed for {key}: {exc}", SafeCode.input_invalid) from exc

                for prep in prep_inv.prepared_pdfs:
                    orig_rec = prep_inv.originals[prep.source_ordinal - 1]
                    final_path = prep_dir / prep.artifact_ref.path
                    final_ref = ArtifactRef(
                        path=str(final_path.relative_to(terminal_root)),
                        sha256=prep.artifact_ref.sha256,
                        bytes=prep.artifact_ref.bytes,
                    )
                    lineage = {
                        "source_ordinal": prep.source_ordinal,
                        "part_ordinal": prep.part_ordinal,
                        "start_page": prep.start_page,
                        "end_page": prep.end_page,
                        "inventory_sha256": prep_inv.inventory_sha256,
                    }
                    snapshots.append(
                        InputSnapshot(
                            kind="pdf",
                            ordinal=prep.upload_ordinal,
                            original_path=orig_rec.original_path,
                            snapshot_ref=final_ref,
                            sha256=prep.artifact_ref.sha256,
                            bytes=prep.artifact_ref.bytes,
                            pdf_lineage=lineage,
                        )
                    )
                inv_file = prep_dir / "inventory.json"
                inv_file_hash, inv_file_size = compute_file_sha256(inv_file)
                pdf_prep_ref = ArtifactRef(
                    path=str(inv_file.relative_to(terminal_root)),
                    sha256=inv_file_hash,
                    bytes=inv_file_size,
                )
            else:
                pdf_prep_ref = None
                # Snapshot PDFs (legacy joint)
                for idx, pdf_p in enumerate(resolved_pdfs, start=1):
                    src_hash, src_size = compute_file_sha256(pdf_p)

                    dest_filename = f"subject-{key}-pdf-{idx:04d}-{pdf_p.name}"
                    dest_path = inputs_dir / dest_filename
                    shutil.copy2(pdf_p, dest_path)
                    dest_hash, dest_size = compute_file_sha256(dest_path)
                    if dest_hash != src_hash or dest_size != src_size:
                        raise ManifestCaptureError(f"Snapshot copy corruption for {pdf_p.name}", SafeCode.storage_unavailable)

                    rel_path = str(dest_path.relative_to(terminal_root))
                    ref = ArtifactRef(path=rel_path, sha256=dest_hash, bytes=dest_size)
                    snapshots.append(
                        InputSnapshot(
                            kind="pdf",
                            ordinal=idx,
                            original_path=str(pdf_p.relative_to(proj_root) if pdf_p.is_relative_to(proj_root) else pdf_p),
                            snapshot_ref=ref,
                            sha256=dest_hash,
                            bytes=dest_size,
                        )
                    )

            # Snapshot framework steps
            steps_hash, steps_size = compute_file_sha256(steps_path)
            dest_steps = inputs_dir / f"subject-{key}-steps.txt"
            shutil.copy2(steps_path, dest_steps)
            d_hash, d_size = compute_file_sha256(dest_steps)
            if d_hash != steps_hash or d_size != steps_size:
                raise ManifestCaptureError("Snapshot copy corruption for steps.txt", SafeCode.storage_unavailable)

            steps_ref = ArtifactRef(
                path=str(dest_steps.relative_to(terminal_root)),
                sha256=d_hash,
                bytes=d_size,
            )
            snapshots.append(
                InputSnapshot(
                    kind="steps",
                    ordinal=len(snapshots) + 1,
                    original_path=str(steps_path.relative_to(proj_root) if steps_path.is_relative_to(proj_root) else steps_path),
                    snapshot_ref=steps_ref,
                    sha256=d_hash,
                    bytes=d_size,
                )
            )

            captured_subjects.append(
                SubjectConfig(
                    subject_key=key,
                    subject_run_id=subject_run_id,
                    mode="new",
                    display_title=display_title,
                    provider=provider,
                    model=model,
                    inputs=snapshots,
                    timeout_seconds=timeout,
                    endpoint=raw_s.get("endpoint"),
                    toolchain=str(raw_s.get("toolchain", "plain-html-css-js-v1")),
                    account_alias=str(raw_s.get("account_alias", "mo50atiapro")),
                    account_check_notebook_id=str(proof_nb),
                    pdf_processing=effective_pdf_proc,
                    pdf_preparation_ref=pdf_prep_ref,
                    hosted_input_manifest_ref=hosted_ref,
                    scene_generation_mode=scene_generation_mode,
                    gemini_thinking_budget=gemini_thinking_budget,
                    gemini_max_output_tokens=gemini_max_output_tokens,
                )
            )

        elif mode == "saved":
            for forbidden in ["pdf_dir", "pdf_files", "steps", "account_check_notebook_id"]:
                if forbidden in raw_s:
                    raise ManifestCaptureError(f"Field '{forbidden}' forbidden in saved mode", SafeCode.input_invalid)

            handoff_input = raw_s.get("handoff")
            if not handoff_input:
                raise ManifestCaptureError("handoff path is required in saved mode", SafeCode.input_invalid)
            h_path = (proj_root / str(handoff_input)).resolve()
            if not h_path.exists():
                raise ManifestCaptureError(f"Handoff path does not exist: {handoff_input}", SafeCode.input_invalid)

            dest_h_name = f"subject-{key}-handoff-{h_path.name}"
            dest_h_path = inputs_dir / dest_h_name
            if h_path.is_dir():
                shutil.copytree(h_path, dest_h_path, dirs_exist_ok=True)
                # Compute digest of directory or main file
                # In directory mode, we identify the handoff format
                handoff_kind = "notebooklm_multi_pdf_lesson_raw_handoff"
                ref_hash, ref_size = compute_file_sha256(next(dest_h_path.glob("*.json")))
            else:
                shutil.copy2(h_path, dest_h_path)
                ref_hash, ref_size = compute_file_sha256(dest_h_path)
                with open(dest_h_path, "r", encoding="utf-8") as f:
                    data = json.load(f)
                    handoff_kind = data.get("schema_version") if isinstance(data.get("schema_version"), str) else data.get("export_kind") or "notebooklm_lesson_handoff"

            h_ref = ArtifactRef(
                path=str(dest_h_path.relative_to(terminal_root)),
                sha256=ref_hash,
                bytes=ref_size,
            )
            snapshots.append(
                InputSnapshot(
                    kind="handoff",
                    ordinal=1,
                    original_path=str(h_path.relative_to(proj_root) if h_path.is_relative_to(proj_root) else h_path),
                    snapshot_ref=h_ref,
                    sha256=ref_hash,
                    bytes=ref_size,
                )
            )
            captured_subjects.append(
                SubjectConfig(
                    subject_key=key,
                    subject_run_id=subject_run_id,
                    mode="saved",
                    display_title=display_title,
                    provider=provider,
                    model=model,
                    inputs=snapshots,
                    timeout_seconds=timeout,
                    endpoint=raw_s.get("endpoint"),
                    toolchain=str(raw_s.get("toolchain", "plain-html-css-js-v1")),
                    handoff_kind=handoff_kind,
                    handoff_ref=h_ref,
                    scene_generation_mode=scene_generation_mode,
                    gemini_thinking_budget=gemini_thinking_budget,
                    gemini_max_output_tokens=gemini_max_output_tokens,
                )
            )

    created_at = datetime.now(timezone.utc).isoformat()
    manifest_dict_pre = {
        "schema_version": 1,
        "submission_id": sub_id,
        "created_at": created_at,
        "subjects": [s.to_dict() for s in captured_subjects],
    }
    manifest_sha256 = compute_digest(manifest_dict_pre)

    manifest = SubmissionManifest(
        schema_version=1,
        submission_id=sub_id,
        created_at=created_at,
        manifest_sha256=manifest_sha256,
        subjects=captured_subjects,
    )

    manifest_json = canonical_json_dumps(manifest.to_dict())
    with open(manifest_file, "w", encoding="utf-8") as f:
        f.write(manifest_json)

    return manifest, manifest_file
