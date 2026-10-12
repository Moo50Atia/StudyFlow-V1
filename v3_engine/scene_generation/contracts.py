"""Contracts and data models for terminal scene generation (Spec 017)."""

from __future__ import annotations

from dataclasses import dataclass, field
import hashlib
from typing import Any, Callable, Coroutine, Mapping, Optional


@dataclass(frozen=True)
class SceneInput:
    """Normalized in-memory scene input derived from a handoff."""

    scene_key: str
    notebook_id: str
    conversation_id: str
    lesson_ordinal: int
    scene_ordinal: int
    lesson_title: str
    section_title: str
    scene_type: Optional[str]
    prompt: Optional[str]
    prompt_sha256: Optional[str]
    input_error: Optional[str]
    source_index: int
    source_json_pointer: str

    def __post_init__(self) -> None:
        if isinstance(self.lesson_ordinal, bool) or not isinstance(self.lesson_ordinal, int) or self.lesson_ordinal < 1:
            raise ValueError(f"lesson_ordinal must be a positive integer, got {self.lesson_ordinal!r}")
        if isinstance(self.scene_ordinal, bool) or not isinstance(self.scene_ordinal, int) or self.scene_ordinal < 1:
            raise ValueError(f"scene_ordinal must be a positive integer, got {self.scene_ordinal!r}")
        if not self.notebook_id or not self.conversation_id:
            raise ValueError("notebook_id and conversation_id must be nonblank strings")
        expected_key = f"{self.notebook_id}:{self.conversation_id}:W{self.lesson_ordinal}:S{self.scene_ordinal}"
        if self.scene_key != expected_key:
            raise ValueError(f"scene_key mismatch: expected {expected_key}, got {self.scene_key}")


@dataclass(frozen=True)
class InputIssue:
    """An issue detected during handoff inventory loading."""

    lesson_ordinal: int
    scene_ordinal: Optional[int]
    code: str

    def __post_init__(self) -> None:
        if isinstance(self.lesson_ordinal, bool) or not isinstance(self.lesson_ordinal, int) or self.lesson_ordinal < 1:
            raise ValueError(f"lesson_ordinal must be a positive integer, got {self.lesson_ordinal!r}")
        if self.scene_ordinal is not None:
            if isinstance(self.scene_ordinal, bool) or not isinstance(self.scene_ordinal, int) or self.scene_ordinal < 1:
                raise ValueError(f"scene_ordinal must be a positive integer or None, got {self.scene_ordinal!r}")


@dataclass(frozen=True)
class InputSnapshot:
    """Exact snapshot metadata for one input file."""

    source_index: int
    original_path: str
    relative_name: str
    sha256: str
    bytes: int
    snapshot_path: str


@dataclass(frozen=True)
class HandoffInventory:
    """Complete inventory of parsed inputs and issues."""

    notebook_id: str
    conversation_id: str
    scene_inputs: list[SceneInput]
    input_issues: list[InputIssue]
    input_snapshots: list[InputSnapshot]
    expected_work_count: Optional[int]


@dataclass(frozen=True)
class ProviderConfig:
    """Immutable configuration for an AI provider."""

    provider: str  # cli alias: gemini, glm, deepseek, qwen
    provider_id: str  # stored id: gemini_api, glm_api, deepseek_api, qwen_api
    requested_model: str
    endpoint: str
    request_timeout: int = 300
    gemini_thinking_budget: int = 24576
    gemini_max_output_tokens: int = 65536
    api_key: str = field(repr=False, default="")  # excluded from repr and serialization


@dataclass(frozen=True)
class ProviderCheckResult:
    """Outcome of a provider check connection probe."""

    passed: bool
    safe_code: str
    requested_model: str
    returned_model: Optional[str] = None
    http_status: Optional[int] = None


from abc import ABC, abstractmethod

RawReceiptSink = Callable[[bytes, Mapping[str, Any]], Coroutine[Any, Any, str]]


class AIProvider(ABC):
    """Abstract base class for all supported scene generation providers."""

    @abstractmethod
    async def check_connection(self) -> ProviderCheckResult:
        """Execute a single small connection probe."""
        ...

    @abstractmethod
    async def generate_scene(
        self, request: SceneGenerationRequest, *, receipt_sink: RawReceiptSink
    ) -> ProviderResponse:
        """Generate a scene prompt, persisting the raw response through receipt_sink before parsing."""
        ...

    @abstractmethod
    async def aclose(self) -> None:
        """Close underlying HTTP client resources."""
        ...


@dataclass(frozen=True)
class SceneGenerationRequest:
    """A single prompt generation request sent to an AI provider."""

    system_text: str
    prompt: str
    output_contract: str
    host_context: dict[str, Any]


@dataclass(frozen=True)
class ProviderResponse:
    """Normalized response from an AI provider."""

    outcome: str  # complete, failed, uncertain
    safe_code: str
    http_status: Optional[int] = None
    requested_model: Optional[str] = None
    returned_model: Optional[str] = None
    model_reported: bool = True
    response_id: Optional[str] = None
    finish_reason: Optional[str] = None
    text: Optional[str] = None
    usage: Optional[dict[str, Optional[int]]] = None
    raw_receipt_ref: Optional[str] = None
    request_started_at: Optional[str] = None
    request_finished_at: Optional[str] = None
    duration_ms: Optional[int] = None
