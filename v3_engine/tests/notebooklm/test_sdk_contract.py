import inspect
from pathlib import Path
from types import SimpleNamespace
import httpx
import pytest
from unittest.mock import AsyncMock, patch

from notebooklm import ChatGoal, ChatResponseLength, NotebookLMClient
from notebooklm.types import Notebook, Source, AskResult, SharePermission
from notebooklm._types.chat import ChatSettings

from v3_engine.notebooklm.ports import (
    AuthenticationRequiredError,
    SessionProvider,
    NotebookFiles,
    NotebookConversation,
    NotebookRef,
    SourceReceipt,
    TurnAnswer,
)
from v3_engine.notebooklm.sdk_provider import SdkProviderSession


def test_sdk_method_signatures():
    """Verify notebooklm-py 0.8.4 matches our port expectations."""
    # notebooks namespace
    from notebooklm._web.notebooks import WebNotebooksAPI
    create_sig = inspect.signature(WebNotebooksAPI.create)
    assert "title" in create_sig.parameters

    get_sig = inspect.signature(WebNotebooksAPI.get)
    assert "notebook_id" in get_sig.parameters

    list_sig = inspect.signature(WebNotebooksAPI.list)
    assert len(list_sig.parameters) <= 2  # self, etc.

    # sources namespace
    from notebooklm._web.sources import WebSourcesAPI
    add_file_sig = inspect.signature(WebSourcesAPI.add_file)
    assert "notebook_id" in add_file_sig.parameters
    assert "file_path" in add_file_sig.parameters
    assert "mime_type" in add_file_sig.parameters
    assert "wait" in add_file_sig.parameters
    assert "title" in add_file_sig.parameters

    get_src_sig = inspect.signature(WebSourcesAPI.get)
    assert "notebook_id" in get_src_sig.parameters
    assert "source_id" in get_src_sig.parameters

    # chat namespace
    from notebooklm._web.chat import WebChatAPI
    config_sig = inspect.signature(WebChatAPI.configure)
    assert "notebook_id" in config_sig.parameters
    assert "goal" in config_sig.parameters
    assert "response_length" in config_sig.parameters
    assert "custom_prompt" in config_sig.parameters

    ask_sig = inspect.signature(WebChatAPI.ask)
    assert "notebook_id" in ask_sig.parameters
    assert "question" in ask_sig.parameters
    assert "source_ids" in ask_sig.parameters
    assert "conversation_id" in ask_sig.parameters

    history_sig = inspect.signature(WebChatAPI.get_history)
    assert "notebook_id" in history_sig.parameters
    assert "conversation_id" in history_sig.parameters
    assert "limit" in history_sig.parameters


@pytest.mark.asyncio
async def test_sdk_instantiation_disables_retries():
    """The yielded SDK session satisfies the file and conversation port shape."""
    session = SdkProviderSession(client=AsyncMock())
    assert session.files is session
    assert session.conversation is session
    assert callable(session.files.upload_file)
    assert callable(session.conversation.ask_once)


@pytest.mark.asyncio
async def test_owner_verification_uses_the_sdk_permission_enum():
    client = AsyncMock()
    client.notebooks.get.return_value = Notebook(
        id="proof-notebook", title="approved proof", role=SharePermission.OWNER,
    )
    session = SdkProviderSession(client)
    assert await session.verify_owner("proof-notebook") == "owner"
    client.notebooks.get.assert_awaited_once_with("proof-notebook")


@pytest.mark.asyncio
async def test_sdk_http_transport_single_send_on_error(monkeypatch):
    """Provider errors do not cause the wrapper to issue another chat request."""
    from notebooklm.exceptions import RateLimitError
    client = AsyncMock()
    client.chat.ask.side_effect = RateLimitError("rate limited")
    session = SdkProviderSession(client)
    with pytest.raises(Exception):
        await session.ask_once("notebook", "Continue", ["pdf", "steps"], "conversation")
    client.chat.ask.assert_awaited_once()


@pytest.mark.asyncio
async def test_sdk_file_port_uses_real_namespaces_and_exposes_source_inventory(tmp_path):
    client = AsyncMock()
    client.notebooks.create.return_value = SimpleNamespace(id="notebook-1", title="case-title")
    client.notebooks.get.return_value = SimpleNamespace(id="notebook-1", title="case-title")
    client.notebooks.list.return_value = [SimpleNamespace(id="notebook-1", title="case-title")]
    client.sources.add_file.return_value = SimpleNamespace(
        id="source-1", title="accepted.pdf", content_mime="application/pdf",
        is_ready=False, is_error=False,
    )
    client.sources.get.return_value = SimpleNamespace(
        id="source-1", title="accepted.pdf", content_mime="application/pdf",
        is_ready=True, is_error=False,
    )
    client.sources.list.return_value = [SimpleNamespace(id="source-1")]
    session = SdkProviderSession(client)
    pdf_path = tmp_path / "accepted.pdf"
    pdf_path.write_bytes(b"%PDF-1.4 fixture")

    notebook = await session.files.create_notebook("case-title")
    uploaded = await session.files.upload_file(notebook.id, pdf_path, "application/pdf", "accepted.pdf")
    ready = await session.files.get_source(notebook.id, uploaded.source_id)
    inventory = await session.files.get_notebook(notebook.id)
    listed = await session.files.list_notebooks()

    assert notebook.id == "notebook-1"
    assert uploaded.status == "processing" and ready.status == "ready"
    assert inventory.source_ids == ["source-1"]
    assert len(listed) == 1 and listed[0].id == "notebook-1"
    client.sources.add_file.assert_awaited_once_with(
        "notebook-1", str(pdf_path), mime_type="application/pdf",
        wait=False, title="accepted.pdf",
    )


@pytest.mark.asyncio
async def test_sdk_upload_gives_opaque_pdf_a_private_pdf_suffix(tmp_path):
    client = AsyncMock()
    staged_paths = []

    async def add_file(*args, **kwargs):
        staged_path = Path(args[1])
        staged_paths.append(staged_path)
        assert staged_path.suffix == ".pdf"
        assert staged_path.exists()
        assert staged_path.stat().st_mode & 0o777 == 0o600
        assert staged_path.read_bytes() == body
        return SimpleNamespace(
            id="source-opaque-pdf", title=kwargs["title"],
            content_mime="application/pdf", is_ready=False, is_error=False,
        )

    client.sources.add_file.side_effect = add_file
    session = SdkProviderSession(client)
    opaque_path = tmp_path / "a7b2c4d6.bin"
    body = b"%PDF-1.4 private fixture"
    opaque_path.write_bytes(body)

    receipt = await session.files.upload_file(
        "notebook-1", opaque_path, "application/pdf", "lesson-source.pdf",
    )

    staged_path = staged_paths[0]
    assert staged_path != opaque_path
    assert staged_path.suffix == ".pdf"
    assert not staged_path.exists(), "Temporary provider copy must be removed after upload."
    assert opaque_path.read_bytes() == body, "Canonical opaque artifact must remain unchanged."
    assert receipt.source_id == "source-opaque-pdf"


def _create_synthetic_storage_state(path: Path):
    import json
    cookies = [
        {
            "name": name,
            "value": f"mock_{name}_val",
            "domain": ".google.com",
            "path": "/",
            "expires": 1893456000,
            "httpOnly": True,
            "secure": True,
            "sameSite": "None",
        }
        for name in ["SID", "HSID", "SSID", "APISID", "SAPISID", "__Secure-1PSIDTS"]
    ]
    path.write_text(json.dumps({"cookies": cookies, "origins": []}), encoding="utf-8")
    path.chmod(0o600)


class MockTransportRecorder:
    def __init__(self, chat_fail_mode=None, upload_fail_mode=None):
        self.chat_fail_mode = chat_fail_mode
        self.upload_fail_mode = upload_fail_mode
        self.chat_mutation_requests = []
        self.upload_mutation_requests = []
        self.upload_counter = 0

    async def handle(self, request, *args, **kwargs):
        import json
        url_str = str(request.url)
        if "notebook.google.com" in url_str and request.method == "GET":
            html = '<html><script>window.WIZ_global_data = {"SNlM0e": "fake_csrf", "FdrFJe": "fake_session_id"};</script></html>'
            return httpx.Response(200, text=html, request=request)
        if "GenerateFreeFormStreamed" in url_str:
            self.chat_mutation_requests.append(request)
            if self.chat_fail_mode == "timeout":
                raise httpx.ReadTimeout("Read timed out", request=request)
            elif self.chat_fail_mode == "429":
                return httpx.Response(429, text="Too Many Requests", request=request)
            elif self.chat_fail_mode == "500":
                return httpx.Response(500, text="Internal Server Error", request=request)
            elif self.chat_fail_mode == "auth_expiry":
                return httpx.Response(401, text="Unauthorized", request=request)
            first = ["Hello from NotebookLM", None, ["conv-1"], None, [None, None, None, None, 1]]
            inner_data = [first, None, None, None, True]
            envelope = ["wrb.fr", None, json.dumps(inner_data)]
            return httpx.Response(200, text=")]}'\n" + json.dumps([envelope]) + "\n", request=request)
        if "batchexecute" in url_str:
            if "khqZz" in url_str:
                payload = json.dumps([["wrb.fr", "khqZz", "[[]]", None, None, None]])
            elif "o4cbdc" in url_str:
                self.upload_mutation_requests.append(request)
                if self.upload_fail_mode == "timeout":
                    raise httpx.ReadTimeout("Read timed out", request=request)
                elif self.upload_fail_mode == "429":
                    return httpx.Response(429, text="Too Many Requests", request=request)
                elif self.upload_fail_mode == "500":
                    return httpx.Response(500, text="Internal Server Error", request=request)
                elif self.upload_fail_mode == "auth_expiry":
                    return httpx.Response(401, text="Unauthorized", request=request)
                self.upload_counter += 1
                payload = json.dumps([["wrb.fr", "o4cbdc", f'[[["source-id-{self.upload_counter}"]]]', None, None, None]])
            elif "rLM1Ne" in url_str:
                src = [[f"source-id-{self.upload_counter}"], f"doc-{self.upload_counter}", None, None]
                nb_payload = json.dumps([[None, [src]]])
                payload = json.dumps([["wrb.fr", "rLM1Ne", nb_payload, None, None, None]])
            else:
                payload = json.dumps([["wrb.fr", "unknown", "[]", None, None, None]])
            raw = ")]}'\n" + str(len(payload)) + "\n" + payload + "\n"
            return httpx.Response(200, text=raw, request=request)
        if "upload" in url_str:
            return httpx.Response(200, text="ok", headers={"x-goog-upload-url": f"https://notebook.google.com/upload/_/?upload_id=upload{self.upload_counter}", "x-goog-upload-status": "final"}, request=request)
        return httpx.Response(200, text="ok", request=request)


@pytest.mark.parametrize("fail_mode", ["timeout", "429", "500", "auth_expiry"])
@pytest.mark.asyncio
async def test_sdk_http_transport_chat_mutation_single_send_under_failure(fail_mode, tmp_path):
    """Under timeout, 429, 5xx, or auth expiry, NotebookLMClient issues exactly 1 chat mutation."""
    state_file = tmp_path / "storage_state.json"
    _create_synthetic_storage_state(state_file)

    recorder = MockTransportRecorder(chat_fail_mode=fail_mode)
    orig_send = httpx.AsyncClient.send
    httpx.AsyncClient.send = recorder.handle
    try:
        async with NotebookLMClient.from_storage(
            path=state_file,
            rate_limit_max_retries=0,
            server_error_max_retries=0,
            allow_headless=False,
        ) as client:
            session = SdkProviderSession(client=client)
            with pytest.raises(Exception):
                await session.ask_once("test-nb", "Start with First lesson", ["src-1"], "conv-1")
            assert len(recorder.chat_mutation_requests) == 1
    finally:
        httpx.AsyncClient.send = orig_send


@pytest.mark.parametrize("fail_mode", ["timeout", "429", "500", "auth_expiry"])
@pytest.mark.asyncio
async def test_sdk_http_transport_upload_mutation_single_send_under_failure(fail_mode, tmp_path):
    """Under timeout, 429, 5xx, or auth expiry, NotebookLMClient issues exactly 1 upload registration mutation."""
    state_file = tmp_path / "storage_state.json"
    _create_synthetic_storage_state(state_file)

    pdf_file = tmp_path / "sample.pdf"
    pdf_file.write_bytes(b"%PDF-1.4 sample content")

    recorder = MockTransportRecorder(upload_fail_mode=fail_mode)
    orig_send = httpx.AsyncClient.send
    httpx.AsyncClient.send = recorder.handle
    try:
        async with NotebookLMClient.from_storage(
            path=state_file,
            rate_limit_max_retries=0,
            server_error_max_retries=0,
            allow_headless=False,
        ) as client:
            session = SdkProviderSession(client=client)
            with pytest.raises(Exception):
                await session.upload_file("test-nb", pdf_file, "application/pdf", "sample.pdf")
            assert len(recorder.upload_mutation_requests) == 1
    finally:
        httpx.AsyncClient.send = orig_send


@pytest.mark.asyncio
async def test_sdk_http_transport_distinct_pdf_and_steps_upload_phases(tmp_path):
    """Legitimate PDF and steps.txt upload phases are counted separately."""
    state_file = tmp_path / "storage_state.json"
    _create_synthetic_storage_state(state_file)

    pdf_file = tmp_path / "original.pdf"
    pdf_file.write_bytes(b"%PDF-1.4 unit3 test content")
    steps_file = tmp_path / "steps.txt"
    steps_file.write_bytes(b"steps.txt approved content")

    recorder = MockTransportRecorder()
    orig_send = httpx.AsyncClient.send
    httpx.AsyncClient.send = recorder.handle
    try:
        async with NotebookLMClient.from_storage(
            path=state_file,
            rate_limit_max_retries=0,
            server_error_max_retries=0,
            allow_headless=False,
        ) as client:
            session = SdkProviderSession(client=client)
            r1 = await session.upload_file("test-nb", pdf_file, "application/pdf", "original.pdf")
            r2 = await session.upload_file("test-nb", steps_file, "text/plain", "steps.txt")

            assert r1.source_id == "source-id-1"
            assert r2.source_id == "source-id-2"
            assert len(recorder.upload_mutation_requests) == 2
    finally:
        httpx.AsyncClient.send = orig_send


@pytest.mark.asyncio
async def test_sdk_session_async_close(tmp_path):
    """Closing the SDK session closes the client without errors."""
    mock_client = AsyncMock()
    session = SdkProviderSession(client=mock_client)
    await session.close()
    mock_client.close.assert_awaited_once()


@pytest.mark.asyncio
async def test_sdk_session_provider_lifecycle(tmp_path):
    """SdkSessionProvider opens and yields a ProviderSession bound to the storage state."""
    from v3_engine.notebooklm.sdk_provider import SdkSessionProvider
    state_file = tmp_path / "storage_state.json"
    _create_synthetic_storage_state(state_file)

    recorder = MockTransportRecorder()
    orig_send = httpx.AsyncClient.send
    httpx.AsyncClient.send = recorder.handle
    try:
        provider = SdkSessionProvider(storage_state_path=state_file)
        async with provider.open() as session:
            assert session.files is session
            assert session.conversation is session
            assert callable(session.files.upload_file)
            assert callable(session.conversation.ask_once)
    finally:
        httpx.AsyncClient.send = orig_send


@pytest.mark.asyncio
async def test_sdk_session_provider_normalizes_csrf_bootstrap_failure(tmp_path, monkeypatch):
    """A missing CSRF token is reported as authentication_required, not a traceback."""
    from v3_engine.notebooklm.sdk_provider import SdkSessionProvider

    state_file = tmp_path / "storage_state.json"
    _create_synthetic_storage_state(state_file)

    class BrokenClientContext:
        async def __aenter__(self):
            raise ValueError("CSRF token not found in HTML")

        async def __aexit__(self, *args):
            return False

    monkeypatch.setattr(
        "v3_engine.notebooklm.sdk_provider.NotebookLMClient.from_storage",
        lambda **kwargs: BrokenClientContext(),
    )
    provider = SdkSessionProvider(storage_state_path=state_file)

    with pytest.raises(AuthenticationRequiredError) as exc_info:
        async with provider.open():
            pytest.fail("Session must not be yielded when CSRF bootstrap fails")

    assert exc_info.value.code == "authentication_required"


@pytest.mark.parametrize("origin", ["https://notebook.google.com", "https://notebooklm.google.com"])
@pytest.mark.asyncio
async def test_sdk_session_uses_the_requested_origin_on_the_real_http_boundary(tmp_path, monkeypatch, origin):
    from v3_engine.notebooklm.sdk_provider import SdkSessionProvider
    state_file = tmp_path / "storage_state.json"
    _create_synthetic_storage_state(state_file)
    requested_origins = []
    async def send(_client, request, **kwargs):
        if request.method == "GET":
            requested_origins.append(f"{request.url.scheme}://{request.url.host}")
            html = '<script>window.WIZ_global_data={"SNlM0e":"test-csrf","FdrFJe":"test-session"};</script>'
            return httpx.Response(200, text=html, request=request)
        return httpx.Response(200, text="ok", request=request)
    monkeypatch.setattr(httpx.AsyncClient, "send", send)
    async with SdkSessionProvider(base_url=origin, storage_state_path=state_file).open():
        pass
    assert requested_origins and set(requested_origins) == {origin}
