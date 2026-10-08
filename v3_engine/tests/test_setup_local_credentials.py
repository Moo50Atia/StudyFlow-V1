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
