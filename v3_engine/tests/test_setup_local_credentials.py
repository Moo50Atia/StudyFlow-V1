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


def test_manual_import_updates_the_shared_profile_and_leaves_run_copies_for_verified_adoption(tmp_path, monkeypatch):
    import sys
    from scripts.v3 import check_notebooklm
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
    assert master.exists()
    assert isolated.read_text() == '{"cookies":[],"origins":[]}'
    assert default.read_text() == 'preserve unrelated default'
    assert master.stat().st_mode & 0o777 == 0o600

    # The checker verifies the shared profile; the compatibility flag does not
    # select or mutate an individual run.
    selected_paths = []
    class CheckedSession:
        def __init__(self, **kwargs):
            selected_paths.append(kwargs['storage_state_path'])
        def open(self, profile_ref=None, storage_path=None):
            assert storage_path == master
            class Context:
                async def __aenter__(self):
                    async def verify_owner(nid):
                        assert nid == 'processing-notebook'
                        return 'owner'
                    return SimpleNamespace(files=SimpleNamespace(verify_owner=verify_owner))
                async def __aexit__(self, *args):
                    pass
            return Context()
    monkeypatch.setattr(check_notebooklm, 'SdkSessionProvider', CheckedSession)
    monkeypatch.setenv('V3_NOTEBOOKLM_STORAGE_STATE', str(master))
    import asyncio
    assert asyncio.run(check_notebooklm.check('https://notebook.google.com', 'processing-notebook', 'notebooklm_py')) == 0
    assert selected_paths == [master]

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


@pytest.mark.parametrize('include_run', [False, True])
def test_credentials_only_preserves_env_and_signing_keys(tmp_path, monkeypatch, include_run):
    import sys
    master = tmp_path / '.notebooklm/master.json'
    master.parent.mkdir()
    env_file = tmp_path / '.env'
    original = f'V3_NOTEBOOKLM_STORAGE_STATE="{master}"\nAPP_KEY=existing\nV3_BRIDGE_HMAC_KEY=existing\n'
    env_file.write_text(original)
    monkeypatch.setattr(setup, 'REPO_ROOT', tmp_path)
    monkeypatch.setattr(setup.Path, 'home', lambda: tmp_path)
    monkeypatch.delenv('V3_NOTEBOOKLM_STORAGE_STATE', raising=False)
    monkeypatch.setattr(setup.sys.stdin, 'isatty', lambda: True)
    monkeypatch.setattr(setup.getpass, 'getpass', lambda _: COOKIES)
    monkeypatch.setattr(setup, '_read_gemini_key', lambda: pytest.fail('No Gemini prompt'))
    monkeypatch.setattr(setup, 'generate_local_hmac_secrets', lambda: pytest.fail('No signing keys'))
    monkeypatch.setattr(setup, 'write_local_env', lambda *a: pytest.fail('No environment rewrite'))
    argv = ['setup', '--credentials-only', '--refresh-notebooklm', '--manual-notebooklm']
    if include_run:
        run = tmp_path / 'runs/test-run'
        isolated = run / 'private/notebooklm-sessions/test-run/storage_state.json'
        isolated.parent.mkdir(parents=True)
        isolated.write_text('{"cookies":[],"origins":[]}')
        isolated.chmod(0o600)
        (run / 'private/notebooklm-credentials.json').write_text(json.dumps({'storage_state_path': str(isolated)}))
        argv.extend(['--bridge-sdk', '--run-dir', str(run)])
    monkeypatch.setattr(sys, 'argv', argv)
    assert setup.main() == 0
    assert env_file.read_text() == original
    assert json.loads(master.read_text())['cookies']
    assert master.stat().st_mode & 0o777 == 0o600
    if include_run:
        assert isolated.read_text() == '{"cookies":[],"origins":[]}'
        assert isolated.stat().st_mode & 0o777 == 0o600
