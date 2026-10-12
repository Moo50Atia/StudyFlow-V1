from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import AsyncContextManager, Optional, Protocol, Sequence, Tuple


class ProviderError(Exception):
    def __init__(self, code: str, message: str, raw_detail: Optional[str] = None):
        super().__init__(f"{code}: {message}")
        self.code = code
        self.message = message
        self.raw_detail = raw_detail

    def __str__(self) -> str:
        return f"{self.code}: {self.message}"


class AuthenticationRequiredError(ProviderError):
    def __init__(self, message: str = "Authentication required or session expired.", raw_detail: Optional[str] = None):
        super().__init__("authentication_required", message, raw_detail)


class ProviderUnavailableError(ProviderError):
    def __init__(self, message: str = "NotebookLM provider service is unavailable.", raw_detail: Optional[str] = None):
        super().__init__("provider_unavailable", message, raw_detail)


class BundleInvalidError(ProviderError):
    def __init__(self, message: str = "Combined envelope output was invalid.", raw_detail: Optional[str] = None):
        super().__init__("bundle_invalid", message, raw_detail)


class ProviderRateLimitError(ProviderUnavailableError):
    def __init__(self, message: str = "NotebookLM provider rate limit reached.", retry_after_seconds: Optional[float] = None, raw_detail: Optional[str] = None):
        super().__init__(message=message, raw_detail=raw_detail)
        self.code = "provider_rate_limit"
        self.retry_after_seconds = retry_after_seconds


@dataclass(frozen=True)
class NotebookRef:
    id: str
    title: str


@dataclass(frozen=True)
class NotebookInventory:
    id: str
    title: str
    source_ids: list[str]


@dataclass(frozen=True)
class SourceReceipt:
    source_id: str
    title: str
    status: str  # 'processing' | 'ready' | 'error'
    mime_type: Optional[str] = None


@dataclass(frozen=True)
class TurnAnswer:
    text: str
    conversation_id: str
    turn_number: Optional[int] = None


@dataclass(frozen=True)
class ChatFormatSettings:
    goal: str
    response_length: str
    custom_prompt: str


class NotebookFiles(Protocol):
    async def create_notebook(self, title: str) -> NotebookRef:
        ...

    async def get_notebook(self, id: str) -> NotebookInventory:
        ...

    async def list_notebooks(self) -> list[NotebookRef]:
        ...

    async def upload_file(self, notebook_id: str, local_path: Path, mime_type: str, title: str) -> SourceReceipt:
        ...

    async def get_source(self, notebook_id: str, source_id: str) -> SourceReceipt:
        ...

    async def list_sources(self, notebook_id: str) -> list[SourceReceipt]:
        ...

    async def verify_owner(self, proof_notebook_id: str) -> str:
        ...


class NotebookConversation(Protocol):
    async def configure_format(self, notebook_id: str, format_text: str) -> None:
        ...

    async def get_format(self, notebook_id: str) -> ChatFormatSettings:
        ...

    async def ask_once(
        self,
        notebook_id: str,
        question: str,
        source_ids: Sequence[str],
        conversation_id: Optional[str] = None,
        fresh_after_conversation_id: Optional[str] = None,
    ) -> TurnAnswer:
        ...

    async def history(
        self,
        notebook_id: str,
        conversation_id: str,
        limit: int = 100,
    ) -> list[Tuple[str, str]]:
        ...

    async def current_conversation_id(self, notebook_id: str) -> Optional[str]:
        ...

    async def reset_conversation(self, notebook_id: str, conversation_id: str) -> None:
        """Permanently clear exactly this conversation after its local archive is verified."""
        ...


class ProviderSession(NotebookFiles, NotebookConversation, Protocol):
    @property
    def files(self) -> NotebookFiles:
        ...

    @property
    def conversation(self) -> NotebookConversation:
        ...

    async def close(self) -> None:
        ...


class SessionProvider(Protocol):
    def open(
        self,
        profile_ref: Optional[str] = None,
        storage_path: Optional[Path] = None,
    ) -> AsyncContextManager[ProviderSession]:
        ...
