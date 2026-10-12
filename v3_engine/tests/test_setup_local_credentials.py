import json
from pathlib import Path
import pytest
from scripts.v3 import setup_local_credentials as setup

COOKIES = 'SID=a; HSID=b; SSID=c; APISID=d; SAPISID=e'

def test_manual_import_keeps_profile_and_never_runs_browser(tmp_path, monkeypatch):
    auth = tmp_path / 'auth.json'
    profile = tmp_path / 'chrome-profile'
    profile.mkdir()
    marker = profile / 'preserve'
    marker.write_text('existing-profile')
    monkeypatch.setattr(setup.getpass, 'getpass', lambda _: COOKIES)
    monkeypatch.setattr(setup.subprocess, 'run', lambda *a, **kw: pytest.fail('No subprocess needed for import'))
    assert setup._run_notebooklm_auth(auth, manual=True)
    assert json.loads(auth.read_text())['cookies']['SID'] == 'a'
    assert auth.stat().st_mode & 0o777 == 0o600
    assert marker.read_text() == 'existing-profile'

def test_invalid_import_preserves_cache(tmp_path, monkeypatch):
    auth = tmp_path / 'auth.json'
    auth.write_text('original')
    monkeypatch.setattr(setup.getpass, 'getpass', lambda _: 'SID=incomplete')
    with pytest.raises(ValueError):
        setup._run_notebooklm_auth(auth, manual=True)
    assert auth.read_text() == 'original'


def test_identical_cookie_header_is_imported_again_without_comparison_gate(tmp_path, monkeypatch):
    auth = tmp_path / 'auth.json'
    monkeypatch.setattr(setup.getpass, 'getpass', lambda _: 'Cookie: ' + COOKIES)
    assert setup._run_notebooklm_auth(auth, manual=True, origin='https://notebook.google.com')
    first = json.loads(auth.read_text())
    assert setup._run_notebooklm_auth(auth, manual=True, origin='https://notebook.google.com')
    second = json.loads(auth.read_text())
    assert first['cookies'] == second['cookies']
    assert second['origin'] == 'https://notebook.google.com'


def test_manual_import_updates_the_configured_master_and_selected_run_copy(tmp_path, monkeypatch):
    import sys
    from scripts.v3 import check_notebooklm
    from v3_engine.notebooklm.credentials import get_terminal_run_storage_state
    from types import SimpleNamespace

    master = tmp_path / 'configured-master.json'
    default = tmp_path / '.notebooklm/storage_state_v3_local.json'
    default.parent.mkdir()
    default.write_text('preserve unrelated default')
    (tmp_path / '.env').write_text(f'V3_NOTEBOOKLM_STORAGE_STATE="{master}"\nNOTEBOOKLM_ORIGIN=https://notebook.google.com\n')
    run = tmp_path / 'runs/test-run'
    isolated = run / 'private/notebooklm-sessions/test-run/storage_state.json'
    isolated.parent.mkdir(parents=True)
    isolated.write_text('{"cookies":[],"origins":[]}')
    reference = run / 'private/notebooklm-credentials.json'
    reference.write_text(json.dumps({'storage_state_path': str(isolated)}))
    monkeypatch.setattr(setup, 'REPO_ROOT', tmp_path)
    monkeypatch.setattr(setup.Path, 'home', lambda: tmp_path)
    monkeypatch.delenv('V3_NOTEBOOKLM_STORAGE_STATE', raising=False)
    monkeypatch.setattr(setup.sys.stdin, 'isatty', lambda: True)
    monkeypatch.setattr(setup.getpass, 'getpass', lambda _: COOKIES + '; OSID=product-binding')
    monkeypatch.setattr(setup, '_read_gemini_key', lambda: pytest.fail('NotebookLM import must not ask for a Gemini key'))
    monkeypatch.setattr(sys, 'argv', ['setup', '--refresh-notebooklm', '--manual-notebooklm', '--bridge-sdk', '--run-dir', str(run)])
    assert setup.main() == 0
    assert master.read_bytes() == isolated.read_bytes()
    assert default.read_text() == 'preserve unrelated default'
    assert get_terminal_run_storage_state(run) == isolated
    assert isolated.stat().st_mode & 0o777 == 0o600
    cookie = next(c for c in json.loads(isolated.read_text())['cookies'] if c['name'] == 'OSID')
    assert cookie['domain'] == 'notebook.google.com'

    # The real checker selects the same isolated file, then delegates only its
    # network boundary. No NotebookLM mutation is needed for this check.
    selected_paths = []
    class CheckedSession:
        def __init__(self, **kwargs):
            selected_paths.append(kwargs['storage_state_path'])
        def open(self, _):
            class Context:
                async def __aenter__(self):
                    async def get_notebook(nid):
                        return SimpleNamespace(id=nid)
                    return SimpleNamespace(files=SimpleNamespace(get_notebook=get_notebook))
                async def __aexit__(self, *args):
                    pass
            return Context()
    monkeypatch.setattr(check_notebooklm, 'SdkSessionProvider', CheckedSession)
    import asyncio
    assert asyncio.run(check_notebooklm.check('https://notebook.google.com', 'processing-notebook', 'notebooklm_py', run)) == 0
    assert selected_paths == [isolated]

def test_default_refresh_cannot_open_browser(tmp_path, monkeypatch):
    monkeypatch.setattr(setup.subprocess, 'run', lambda *a, **kw: pytest.fail('No browser allowed'))
    assert setup._run_notebooklm_auth(tmp_path / 'auth.json') is False

def test_explicit_browser_setup_uses_brave_persistent_profile(tmp_path, monkeypatch):
    from types import SimpleNamespace
    monkeypatch.setattr(setup.Path, 'home', lambda: tmp_path)
    auth = tmp_path / '.notebooklm-mcp/auth.json'
    monkeypatch.setattr(setup, '_notebooklm_auth_cache_is_structurally_valid', lambda _: True)
    def run(command, *, env, check, timeout):
        assert command[-2:] == ['auth', '--browser-only']
        assert env['NOTEBOOKLM_BROWSER_PATH'] == '/usr/bin/brave-browser'
        assert env['NOTEBOOKLM_BROWSER_PROFILE_DIR'] == str(auth.parent / 'brave-profile')
        assert env['NOTEBOOKLM_NO_BROWSER'] == '0'
        assert env.get('HOME') == setup.os.environ.get('HOME')
        return SimpleNamespace(returncode=0)
    monkeypatch.setattr(setup.subprocess, 'run', run)
    assert setup._run_notebooklm_auth(auth, browser=True, browser_path='/usr/bin/brave-browser', origin='https://notebook.google.com')

def test_existing_brave_profile_is_selected_without_using_the_dedicated_profile(tmp_path, monkeypatch):
    from types import SimpleNamespace
    profile = tmp_path / 'Brave-Browser' / 'Profile 2'
    profile.mkdir(parents=True)
    auth = tmp_path / '.notebooklm-mcp/auth.json'
    monkeypatch.setattr(setup, '_notebooklm_auth_cache_is_structurally_valid', lambda _: True)

    def run(command, *, env, check, timeout):
        assert env['NOTEBOOKLM_BROWSER_PROFILE_DIR'] == str(profile.parent)
        assert env['NOTEBOOKLM_BROWSER_PROFILE_DIRECTORY'] == 'Profile 2'
        assert env['NOTEBOOKLM_USE_EXISTING_PROFILE'] == '1'
        return SimpleNamespace(returncode=0)

    monkeypatch.setattr(setup.subprocess, 'run', run)
    assert setup._run_notebooklm_auth(
        auth,
        browser=True,
        browser_path='/usr/bin/brave-browser',
        brave_profile_path=str(profile),
    )
