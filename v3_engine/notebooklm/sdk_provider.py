from __future__ import annotations

import logging
import os
import shutil
import tempfile
from contextlib import asynccontextmanager
from pathlib import Path
from typing import AsyncIterator, Optional, Sequence, Tuple

from notebooklm import ChatGoal, ChatResponseLength, NotebookLMClient
from notebooklm.options import ClientConfig, RetryOptions, WebBackendConfig, WebRequestOptions
from notebooklm.types import SharePermission
from notebooklm.exceptions import (
    AuthError,
    ChatError,
    ClientError,
    NetworkError,
    NotebookError,
    RateLimitError,
    ServerError,
    SourceError,
    ValidationError,
)

from v3_engine.notebooklm.credentials import (
    CredentialsValidationError,
    NotebookCredentialAuthority,
    get_storage_state_path,
    sync_storage_state,
    validate_storage_state,
)
from v3_engine.notebooklm.ports import (
    AuthenticationRequiredError,
    ChatFormatSettings,
    NotebookFiles,
    NotebookConversation,
    NotebookInventory,
    NotebookRef,
    ProviderError,
    ProviderRateLimitError,
    ProviderSession,
    ProviderUnavailableError,
    SourceReceipt,
    TurnAnswer,
    require_notebook_owner,
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
        upload_path = Path(local_path)
        temporary_path: Path | None = None
        try:
            if mime_type.split(';', 1)[0].strip().lower() in {'text/plain', 'text/markdown'}:
                # Captured framework instructions are native text sources. Keep
                # their exact UTF-8 content on the same authenticated RPC path.
                src = await self.client.sources.add_text(
                    notebook_id, title, upload_path.read_bytes().decode('utf-8'),
                    wait=False, idempotent=False,
                )
                status = 'ready' if getattr(src, 'is_ready', False) else 'error' if getattr(src, 'is_error', False) else 'processing'
                return SourceReceipt(src.id, src.title, status, mime_type)
            # Web intake deliberately stores source bytes under opaque `.bin`
            # names. notebooklm-py validates the filename extension before it
            # starts the upload, even when mime_type is supplied. Give the SDK
            # a short-lived private copy with the real extension while keeping
            # the canonical retained artifact opaque and unchanged.
            suffix_by_mime = {
                "application/pdf": ".pdf",
                "text/plain": ".txt",
                "text/markdown": ".md",
            }
            expected_suffix = suffix_by_mime.get(mime_type.split(";", 1)[0].strip().lower())
            if expected_suffix and upload_path.suffix.lower() != expected_suffix:
                fd, temporary_name = tempfile.mkstemp(
                    prefix=".notebooklm-upload-",
                    suffix=expected_suffix,
                    dir=str(upload_path.parent),
                )
                temporary_path = Path(temporary_name)
                os.fchmod(fd, 0o600)
                with os.fdopen(fd, "wb") as target, upload_path.open("rb") as source:
                    shutil.copyfileobj(source, target)
                    target.flush()
                    os.fsync(target.fileno())
                upload_path = temporary_path

            src = await self.client.sources.add_file(
                notebook_id,
                str(upload_path),
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
        except ValidationError as e:
            raise ProviderError("source_upload_invalid", "Source upload was rejected by the provider adapter.", raw_detail=str(e)) from e
        except Exception as e:
            raise ProviderError("provider_unknown_error", "Unexpected error uploading file.", raw_detail=str(e)) from e
        finally:
            if temporary_path is not None:
                temporary_path.unlink(missing_ok=True)

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

    async def list_sources(self, notebook_id: str) -> list[SourceReceipt]:
        try:
            sources = await self.client.sources.list(notebook_id)
            receipts: list[SourceReceipt] = []
            for src in sources:
                status = "processing"
                if getattr(src, "is_ready", False):
                    status = "ready"
                elif getattr(src, "is_error", False):
                    status = "error"
                receipts.append(
                    SourceReceipt(
                        source_id=src.id,
                        title=src.title,
                        status=status,
                        mime_type=getattr(src, "content_mime", None),
                    )
                )
            return receipts
        except AuthError as e:
            raise AuthenticationRequiredError(raw_detail=str(e)) from e
        except RateLimitError as e:
            raise ProviderRateLimitError(retry_after_seconds=getattr(e, "retry_after", None), raw_detail=str(e)) from e
        except (ServerError, NetworkError) as e:
            raise ProviderUnavailableError(raw_detail=str(e)) from e
        except Exception as e:
            raise ProviderError("source_list_failed", "Failed to list sources.", raw_detail=str(e)) from e

    async def verify_owner(self, proof_notebook_id: str) -> str:
        try:
            nb = await self.client.notebooks.get(proof_notebook_id)
            role = getattr(nb, "role", None)
            if role is SharePermission.OWNER:
                return "owner"
            if role is not None:
                return "not_owner"
            return "unknown"
        except AuthError as e:
            raise AuthenticationRequiredError(raw_detail=str(e)) from e
        except RateLimitError as e:
            raise ProviderRateLimitError(retry_after_seconds=getattr(e, "retry_after", None), raw_detail=str(e)) from e
        except (ServerError, NetworkError) as e:
            raise ProviderUnavailableError(raw_detail=str(e)) from e
        except (NotebookError, ClientError) as e:
            raise ProviderError(
                "notebook_verification_failed",
                "NotebookLM could not read the configured proof notebook. Check its link and access.",
                raw_detail=str(e),
            ) from e
        except Exception as e:
            raise ProviderError(
                "ownership_check_failed",
                "NotebookLM ownership verification failed unexpectedly.",
                raw_detail=str(e),
            ) from e

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
        fresh_after_conversation_id: Optional[str] = None,
    ) -> TurnAnswer:
        try:
            if conversation_id is not None:
                # SDK 0.8.4 builds follow-up request context from its local
                # cache, not from the conversation ID or get_history(). Each
                # activity opens a fresh client, so restore verified server
                # turns before sending the next literal Continue.
                from notebooklm._conversation_cache import MAX_TURNS_PER_CONVERSATION

                history = await self.history(
                    notebook_id, conversation_id,
                    limit=2 * (MAX_TURNS_PER_CONVERSATION + 1),
                )
                if not isinstance(history, list) or any(
                    not isinstance(turn, (tuple, list)) or len(turn) != 2
                    or not all(isinstance(text, str) for text in turn)
                    for turn in history
                ):
                    raise ProviderError("chat_history_invalid", "Cannot restore malformed conversation history.")
                if len(history) > MAX_TURNS_PER_CONVERSATION:
                    raise ProviderError("chat_history_truncated", "Conversation exceeds the SDK context capacity.")
                cache = getattr(self.client.chat, "_cache", None)
                if cache is None or not callable(getattr(cache, "cache_conversation_turn", None)):
                    raise ProviderError("sdk_history_unsupported", "SDK cannot restore conversation context.")
                self.client.chat.clear_cache(conversation_id)
                for index, (query, answer) in enumerate(history, start=1):
                    cache.cache_conversation_turn(conversation_id, query, answer, index)
            if fresh_after_conversation_id is not None:
                if conversation_id is not None:
                    raise ProviderError("chat_reset_conflict", "Fresh chat cannot continue an explicit conversation.")
                current = await self.client.chat.get_conversation_id(notebook_id)
                if current is not None:
                    if current != fresh_after_conversation_id:
                        raise ProviderError("chat_reset_conflict", "A different conversation is already active.")
                    history = await self.client.chat.get_history(notebook_id, conversation_id=current, limit=1)
                    if not isinstance(history, list) or history:
                        raise ProviderError("chat_reset_conflict", "Archived conversation still has server history.")
                    # Pinned SDK 0.8.4 documents stale hPTbtc IDs after deletion.
                    # Its tombstone normally lives only in the deleting client.
                    # Activities open new clients, so restore that marker only
                    # after proving the exact archived chat has no turns.
                    deleted = getattr(self.client.chat, "_deleted_conversations", None)
                    if deleted is None or not callable(getattr(deleted, "record", None)):
                        raise ProviderError("sdk_reset_unsupported", "SDK cannot restore the verified reset marker.")
                    deleted.record(current)
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
        except ProviderError:
            raise
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

    async def reset_conversation(self, notebook_id: str, conversation_id: str) -> None:
        try:
            await self.client.chat.delete_conversation(notebook_id, conversation_id)
        except AuthError as e:
            raise AuthenticationRequiredError(raw_detail=str(e)) from e
        except (RateLimitError, ServerError, NetworkError) as e:
            raise ProviderUnavailableError(raw_detail=str(e)) from e
        except Exception as e:
            raise ProviderError("chat_reset_failed", "Failed to reset archived conversation.", raw_detail=str(e)) from e

    async def current_conversation_id(self, notebook_id: str) -> Optional[str]:
        try:
            return await self.client.chat.get_conversation_id(notebook_id)
        except AuthError as e:
            raise AuthenticationRequiredError(raw_detail=str(e)) from e
        except RateLimitError as e:
            raise ProviderRateLimitError(retry_after_seconds=getattr(e, "retry_after", None), raw_detail=str(e)) from e
        except (ServerError, NetworkError) as e:
            raise ProviderUnavailableError(raw_detail=str(e)) from e
        except Exception as e:
            raise ProviderError("conversation_discovery_failed", "Failed to discover conversation.", raw_detail=str(e)) from e


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
        authority = NotebookCredentialAuthority()
        await self._verify_profile_if_needed(authority, Path(path))
        # Every SDK-backed caller shares the same profile resolver. A run-local
        # copy may move to a refreshed session only after a successful owner
        # check has published that profile generation.
        try:
            path = authority.resolve_run_copy(Path(path))
        except CredentialsValidationError as exc:
            raise ProviderError(exc.code, exc.message) from exc
        async with self._open_client(path) as session:
            yield session

    @asynccontextmanager
    async def open_for_verification(
        self,
        storage_path: Optional[Path] = None,
    ) -> AsyncIterator[ProviderSession]:
        """Open a session only for the read-only configured-owner check."""
        path = storage_path or self.storage_state_path or get_storage_state_path()
        async with self._open_client(Path(path)) as session:
            yield session

    async def _verify_profile_if_needed(self, authority: NotebookCredentialAuthority, path: Path) -> None:
        status = authority.profile_status()
        if status["verified"]:
            return
        proof_notebook_id = authority.proof_notebook_id()
        if not proof_notebook_id:
            raise ProviderError(
                "profile_unverified",
                "NotebookLM profile needs a configured proof notebook before ownership can be verified.",
            )
        verification_path = authority.verification_path(path)
        async with self._open_client(verification_path) as session:
            role = await session.files.verify_owner(proof_notebook_id)
            require_notebook_owner(role)
        authority.record_verified(proof_notebook_id, verification_path)

    @asynccontextmanager
    async def _open_client(self, path: Path) -> AsyncIterator[ProviderSession]:
        path = path.expanduser().resolve()
        temporary_path: Path | None = None
        if path == NotebookCredentialAuthority().master_path:
            # SDK cookie/CSRF refreshes belong to this operation's private
            # snapshot. A slow client must not overwrite a newer manual refresh.
            fd, raw_path = tempfile.mkstemp(dir=path.parent, prefix=".notebooklm-operation-")
            os.close(fd)
            temporary_path = Path(raw_path)
            try:
                sync_storage_state(path, temporary_path)
            except Exception:
                temporary_path.unlink(missing_ok=True)
                raise
            path = temporary_path
        try:
            validate_storage_state(path)
            client_context = NotebookLMClient.from_storage(
                path=path,
                allow_headless=False,
                config=ClientConfig(
                    backend=WebBackendConfig(request=WebRequestOptions(
                        base_url=self.base_url or os.environ.get("NOTEBOOKLM_ORIGIN") or os.environ.get("NOTEBOOKLM_BASE_URL"),
                    )),
                    retry=RetryOptions(rate_limit_max_retries=0, server_error_max_retries=0),
                ),
            )
            try:
                # Token/CSRF bootstrap happens while entering the SDK context.
                client = await client_context.__aenter__()
            except AuthError as e:
                raise AuthenticationRequiredError(raw_detail=str(e)) from e
            except ValueError as e:
                detail = str(e).casefold()
                if any(marker in detail for marker in (
                    "csrf token not found",
                    "authentication expired",
                    "cookie mismatch",
                    "authentication required",
                )):
                    raise AuthenticationRequiredError(raw_detail=str(e)) from e
                raise ProviderError(
                    "provider_session_failed",
                    "NotebookLM session initialization failed.",
                    raw_detail=str(e),
                ) from e

            session = SdkProviderSession(client=client)
            try:
                yield session
            except BaseException as exc:
                await session.close()
                await client_context.__aexit__(type(exc), exc, exc.__traceback__)
                raise
            else:
                await session.close()
                await client_context.__aexit__(None, None, None)
        finally:
            if temporary_path is not None:
                temporary_path.unlink(missing_ok=True)
