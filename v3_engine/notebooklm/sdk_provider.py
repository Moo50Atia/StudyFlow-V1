from __future__ import annotations

import logging
from contextlib import asynccontextmanager
from pathlib import Path
from typing import AsyncIterator, Optional, Sequence, Tuple

from notebooklm import ChatGoal, ChatResponseLength, NotebookLMClient
from notebooklm.exceptions import (
    AuthError,
    ChatError,
    NetworkError,
    NotebookError,
    RateLimitError,
    ServerError,
    SourceError,
)

from v3_engine.notebooklm.credentials import get_storage_state_path, validate_storage_state
from v3_engine.notebooklm.ports import (
    AuthenticationRequiredError,
    ChatFormatSettings,
    NotebookFiles,
    NotebookConversation,
    NotebookInventory,
    NotebookRef,
    ProviderError,
    ProviderSession,
    ProviderUnavailableError,
    SourceReceipt,
    TurnAnswer,
)

logger = logging.getLogger("v3_engine.notebooklm")


class SdkProviderSession(NotebookFiles, NotebookConversation):
    def __init__(self, client: NotebookLMClient):
        self.client = client

    @property
    def files(self) -> NotebookFiles:
        return self

    @property
    def conversation(self) -> NotebookConversation:
        return self

    async def close(self) -> None:
        try:
            await self.client.close()
        except Exception:
            pass

    async def create_notebook(self, title: str) -> NotebookRef:
        try:
            nb = await self.client.notebooks.create(title)
            return NotebookRef(id=nb.id, title=nb.title)
        except AuthError as e:
            raise AuthenticationRequiredError(raw_detail=str(e)) from e
        except (RateLimitError, ServerError, NetworkError) as e:
            raise ProviderUnavailableError(raw_detail=str(e)) from e
        except NotebookError as e:
            raise ProviderError("notebook_create_failed", "Failed to create notebook.", raw_detail=str(e)) from e
        except Exception as e:
            raise ProviderError("provider_unknown_error", "Unexpected error creating notebook.", raw_detail=str(e)) from e

    async def get_notebook(self, id: str) -> NotebookInventory:
        try:
            nb = await self.client.notebooks.get(id)
            sources = await self.client.sources.list(id)
            source_ids = [s.id for s in sources]
            return NotebookInventory(id=nb.id, title=nb.title, source_ids=source_ids)
        except AuthError as e:
            raise AuthenticationRequiredError(raw_detail=str(e)) from e
        except (RateLimitError, ServerError, NetworkError) as e:
            raise ProviderUnavailableError(raw_detail=str(e)) from e
        except NotebookError as e:
            raise ProviderError("notebook_not_found", "Notebook not found.", raw_detail=str(e)) from e
        except Exception as e:
            raise ProviderError("provider_unknown_error", "Unexpected error getting notebook.", raw_detail=str(e)) from e

    async def list_notebooks(self) -> list[NotebookRef]:
        try:
            nbs = await self.client.notebooks.list()
            return [NotebookRef(id=nb.id, title=nb.title) for nb in nbs]
        except AuthError as e:
            raise AuthenticationRequiredError(raw_detail=str(e)) from e
        except (RateLimitError, ServerError, NetworkError) as e:
            raise ProviderUnavailableError(raw_detail=str(e)) from e
        except NotebookError as e:
            raise ProviderError("notebook_list_failed", "Failed to list notebooks.", raw_detail=str(e)) from e
        except Exception as e:
            raise ProviderError("provider_unknown_error", "Unexpected error listing notebooks.", raw_detail=str(e)) from e

    async def upload_file(self, notebook_id: str, local_path: Path, mime_type: str, title: str) -> SourceReceipt:
        try:
            src = await self.client.sources.add_file(
                notebook_id,
                str(local_path),
                mime_type=mime_type,
                wait=False,
                title=title,
            )
            status = "processing"
            if getattr(src, "is_ready", False):
                status = "ready"
            elif getattr(src, "is_error", False):
                status = "error"
            return SourceReceipt(
                source_id=src.id,
                title=src.title,
                status=status,
                mime_type=src.content_mime,
            )
        except AuthError as e:
            raise AuthenticationRequiredError(raw_detail=str(e)) from e
        except (RateLimitError, ServerError, NetworkError) as e:
            raise ProviderUnavailableError(raw_detail=str(e)) from e
        except SourceError as e:
            raise ProviderError("source_upload_failed", "Failed to upload source file.", raw_detail=str(e)) from e
        except Exception as e:
            raise ProviderError("provider_unknown_error", "Unexpected error uploading file.", raw_detail=str(e)) from e

    async def get_source(self, notebook_id: str, source_id: str) -> SourceReceipt:
        try:
            src = await self.client.sources.get(notebook_id, source_id)
            status = "processing"
            if getattr(src, "is_ready", False):
                status = "ready"
            elif getattr(src, "is_error", False):
                status = "error"
            return SourceReceipt(
                source_id=src.id,
                title=src.title,
                status=status,
                mime_type=src.content_mime,
            )
        except AuthError as e:
            raise AuthenticationRequiredError(raw_detail=str(e)) from e
        except (RateLimitError, ServerError, NetworkError) as e:
            raise ProviderUnavailableError(raw_detail=str(e)) from e
        except SourceError as e:
            raise ProviderError("source_not_found", "Source not found.", raw_detail=str(e)) from e
        except Exception as e:
            raise ProviderError("provider_unknown_error", "Unexpected error getting source.", raw_detail=str(e)) from e

    async def configure_format(self, notebook_id: str, format_text: str) -> None:
        try:
            await self.client.chat.configure(
                notebook_id,
                goal=ChatGoal.CUSTOM,
                response_length=ChatResponseLength.LONGER,
                custom_prompt=format_text,
            )
        except AuthError as e:
            raise AuthenticationRequiredError(raw_detail=str(e)) from e
        except (RateLimitError, ServerError, NetworkError) as e:
            raise ProviderUnavailableError(raw_detail=str(e)) from e
        except ChatError as e:
            raise ProviderError("chat_configure_failed", "Failed to configure chat format.", raw_detail=str(e)) from e
        except Exception as e:
            raise ProviderError("provider_unknown_error", "Unexpected error configuring format.", raw_detail=str(e)) from e

    async def get_format(self, notebook_id: str) -> ChatFormatSettings:
        try:
            settings = await self.client.chat.get_settings(notebook_id)
            return ChatFormatSettings(
                goal=str(getattr(settings, "goal", "")),
                response_length=str(getattr(settings, "response_length", "")),
                custom_prompt=str(getattr(settings, "custom_prompt", "")),
            )
        except AuthError as e:
            raise AuthenticationRequiredError(raw_detail=str(e)) from e
        except (RateLimitError, ServerError, NetworkError) as e:
            raise ProviderUnavailableError(raw_detail=str(e)) from e
        except ChatError as e:
            raise ProviderError("chat_get_settings_failed", "Failed to get chat settings.", raw_detail=str(e)) from e
        except Exception as e:
            raise ProviderError("provider_unknown_error", "Unexpected error getting format.", raw_detail=str(e)) from e

    async def ask_once(
        self,
        notebook_id: str,
        question: str,
        source_ids: Sequence[str],
        conversation_id: Optional[str] = None,
    ) -> TurnAnswer:
        try:
            res = await self.client.chat.ask(
                notebook_id,
                question,
                source_ids=list(source_ids),
                conversation_id=conversation_id,
            )
            return TurnAnswer(
                text=res.answer,
                conversation_id=res.conversation_id,
                turn_number=res.turn_number,
            )
        except AuthError as e:
            raise AuthenticationRequiredError(raw_detail=str(e)) from e
        except (RateLimitError, ServerError, NetworkError) as e:
            raise ProviderUnavailableError(raw_detail=str(e)) from e
        except ChatError as e:
            raise ProviderError("chat_ask_failed", "Chat turn failed.", raw_detail=str(e)) from e
        except Exception as e:
            raise ProviderError("provider_unknown_error", "Unexpected error asking chat.", raw_detail=str(e)) from e

    async def history(
        self,
        notebook_id: str,
        conversation_id: str,
        limit: int = 100,
    ) -> list[Tuple[str, str]]:
        try:
            return await self.client.chat.get_history(
                notebook_id,
                conversation_id=conversation_id,
                limit=limit,
            )
        except AuthError as e:
            raise AuthenticationRequiredError(raw_detail=str(e)) from e
        except (RateLimitError, ServerError, NetworkError) as e:
            raise ProviderUnavailableError(raw_detail=str(e)) from e
        except ChatError as e:
            raise ProviderError("chat_history_failed", "Failed to retrieve conversation history.", raw_detail=str(e)) from e
        except Exception as e:
            raise ProviderError("provider_unknown_error", "Unexpected error getting history.", raw_detail=str(e)) from e


class SdkSessionProvider:
    def __init__(
        self,
        base_url: Optional[str] = None,
        storage_state_path: Optional[Path] = None,
    ):
        self.base_url = base_url
        self.storage_state_path = storage_state_path

    @asynccontextmanager
    async def open(
        self,
        profile_ref: Optional[str] = None,
        storage_path: Optional[Path] = None,
    ) -> AsyncIterator[ProviderSession]:
        path = storage_path or self.storage_state_path or get_storage_state_path()
        validate_storage_state(path)

        async with NotebookLMClient.from_storage(
            path=path,
            rate_limit_max_retries=0,
            server_error_max_retries=0,
            allow_headless=False,
        ) as client:
            session = SdkProviderSession(client=client)
            try:
                yield session
            finally:
                await session.close()
