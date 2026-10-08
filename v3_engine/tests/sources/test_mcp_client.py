import pytest

from v3_engine.sources.mcp_client import NotebookLmMcpClient

@pytest.mark.asyncio
async def test_source_read_uses_notebook_scope_and_unwraps_pinned_text_envelope():
    client = NotebookLmMcpClient(command=['unused'])
    calls = []
    async def response(name, arguments):
        calls.append((name, arguments))
        return {'text': 'Verified source\nwith original line breaks'}
    client.call_tool = response
    result = await client.source_get_content('src-1', 'nb-1')
    assert calls == [('source_get_content', {'source_id': 'src-1', 'notebook_id': 'nb-1'})]
    assert result == {'content': 'Verified source\nwith original line breaks'}


@pytest.mark.asyncio
async def test_k17_unwraps_pinned_notebook_create_and_get_envelopes():
    client = NotebookLmMcpClient(command=["not-used"])
    responses = {
        "notebook_create": {
            "notebook": {"id": "nb-123", "title": "v3-case-fixture", "sources": []}
        },
        "notebook_get": {
            "notebook": {"id": "nb-123", "title": "v3-case-fixture", "sources": []}
        },
    }

    async def call_tool(name, arguments):
        return responses[name]

    client.call_tool = call_tool
    created = await client.notebook_create("v3-case-fixture")
    fetched = await client.notebook_get("nb-123")

    assert created["id"] == "nb-123"
    assert created["title"] == "v3-case-fixture"
    assert fetched["id"] == "nb-123"
    assert fetched["sources"] == []

@pytest.mark.asyncio
@pytest.mark.parametrize('result', [
    {'isError': True, 'content': [{'type': 'text', 'text': 'private provider error'}]},
    {'content': [{'type': 'text', 'text': '{"status":"error","error":"HTTP 401 secret"}'}]},
])
async def test_tool_failure_never_becomes_success_or_leaks_payload(result):
    client = NotebookLmMcpClient(command=['unused'])
    async def nothing(*args): pass
    async def response(): return {'id': 1, 'result': result}
    client._ensure_process = nothing
    client._send_raw = nothing
    client._read_response = response
    with pytest.raises(RuntimeError) as exc:
        await client.notebook_list()
    assert 'secret' not in str(exc.value)
    assert 'private provider' not in str(exc.value)

@pytest.mark.asyncio
@pytest.mark.parametrize('result', [{}, {'status': 'success'}, {'notebooks': 'wrong'}])
async def test_malformed_notebook_list_is_not_an_empty_library(result):
    client = NotebookLmMcpClient(command=['unused'])
    async def response(*args): return result
    client.call_tool = response
    with pytest.raises(RuntimeError):
        await client.notebook_list()

@pytest.mark.asyncio
async def test_valid_empty_library_is_preserved():
    client = NotebookLmMcpClient(command=['unused'])
    async def response(*args): return {'status': 'success', 'notebooks': []}
    client.call_tool = response
    assert await client.notebook_list() == []

@pytest.mark.asyncio
async def test_built_mcp_handshake_has_required_tools_without_provider_calls():
    from pathlib import Path
    if not Path('integrations/notebooklm-mcp/dist/cli.js').is_file():
        pytest.skip('Build local MCP first: npm ci && npm run build in integrations/notebooklm-mcp')
    import os
    env = dict(os.environ)
    env.pop('NOTEBOOKLM_ALLOW_BROWSER_REFRESH', None)
    client = NotebookLmMcpClient(env=env)
    try:
        assert client.env['NOTEBOOKLM_NO_BROWSER'] == '1'
        assert await client.check_tools_schema()
    finally:
        await client.close()

@pytest.mark.parametrize('mode', [[], ['--manual']])
def test_built_auth_command_refuses_interactive_browser_paths(mode):
    import os
    import subprocess
    from pathlib import Path
    cli = Path('integrations/notebooklm-mcp/dist/cli.js')
    if not cli.is_file():
        pytest.skip('Build local MCP first')
    result = subprocess.run(['node', str(cli), 'auth', *mode],
                            env=dict(os.environ, NOTEBOOKLM_NO_BROWSER='1'),
                            capture_output=True, text=True, timeout=5)
    assert result.returncode != 0
    assert 'authentication_required' in result.stderr

@pytest.mark.asyncio
@pytest.mark.parametrize('operation', ['notebook_create', 'notebook_get'])
@pytest.mark.parametrize('response', ['unexpected', {}, {'notebook': {'id': ''}}])
async def test_notebook_identity_cannot_be_fabricated(operation, response):
    client = NotebookLmMcpClient(command=['unused'])
    async def reply(*args): return response
    client.call_tool = reply
    with pytest.raises(RuntimeError):
        await getattr(client, operation)('fixture')

def test_explicit_local_browser_refresh_authorization_is_honored():
    client = NotebookLmMcpClient(command=['unused'], env={'NOTEBOOKLM_ALLOW_BROWSER_REFRESH':'1', 'NOTEBOOKLM_BROWSER_PATH':'/usr/bin/brave-browser'})
    assert client.env['NOTEBOOKLM_NO_BROWSER'] == '0'

def test_configured_query_timeout_reaches_the_real_mcp_command():
    client = NotebookLmMcpClient(env={'NOTEBOOKLM_QUERY_TIMEOUT_MS': '240000'})
    assert client.command[-2:] == ['--query-timeout', '240000']

@pytest.mark.parametrize('timeout', ['0', '-1', 'invalid', '600001'])
def test_invalid_query_timeout_is_rejected_before_starting_the_provider(timeout):
    with pytest.raises(ValueError):
        NotebookLmMcpClient(env={'NOTEBOOKLM_QUERY_TIMEOUT_MS': timeout})

@pytest.mark.parametrize('message',['Authentication expired','authentication_required','HTTP 401 Unauthorized'])
def test_all_authentication_failures_have_correct_safe_category(message):
    from v3_engine.sources.mcp_client import _tool_error
    assert _tool_error({'error':message}).code == 'authentication_required'
