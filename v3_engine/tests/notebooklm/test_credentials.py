import json
import os
import stat
import subprocess
from pathlib import Path
import pytest

from v3_engine.notebooklm.credentials import (
    CredentialsValidationError,
    validate_storage_state,
    bridge_mcp_to_storage_state,
    get_storage_state_path,
)

REQUIRED_COOKIES = {"SID", "HSID", "SSID", "APISID", "SAPISID"}


def create_synthetic_storage_state(path: Path, cookies: dict[str, str], domain: str = ".google.com", secure: bool = True):
    cookie_list = [
        {
            "name": name,
            "value": val,
            "domain": domain,
            "path": "/",
            "expires": 1893456000,
            "httpOnly": True,
            "secure": secure,
            "sameSite": "None",
        }
        for name, val in cookies.items()
    ]
    data = {"cookies": cookie_list, "origins": []}
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data), encoding="utf-8")
    path.chmod(0o600)


def test_n09_valid_storage_state(tmp_path):
    state_file = tmp_path / "storage_state.json"
    synthetic_cookies = {name: f"synthetic_{name}_val" for name in REQUIRED_COOKIES}
    create_synthetic_storage_state(state_file, synthetic_cookies)

    validated = validate_storage_state(state_file)
    assert "cookies" in validated
    found_names = {c["name"] for c in validated["cookies"]}
    assert REQUIRED_COOKIES.issubset(found_names)


def test_n09_missing_storage_file_raises_error(tmp_path):
    missing_file = tmp_path / "does_not_exist.json"
    with pytest.raises(CredentialsValidationError) as exc_info:
        validate_storage_state(missing_file)
    assert exc_info.value.code == "authentication_required"
    # Never leaks secrets or absolute details in error representation
    assert "cookie" not in str(exc_info.value).lower() or "missing" in str(exc_info.value).lower()


def test_n09_symlink_refused(tmp_path):
    target = tmp_path / "target.json"
    synthetic_cookies = {name: f"val_{name}" for name in REQUIRED_COOKIES}
    create_synthetic_storage_state(target, synthetic_cookies)

    link_file = tmp_path / "link.json"
    link_file.symlink_to(target)

    with pytest.raises(CredentialsValidationError) as exc_info:
        validate_storage_state(link_file)
    assert exc_info.value.code == "insecure_storage_credentials"


def test_n09_malformed_json_raises_error(tmp_path):
    state_file = tmp_path / "bad.json"
    state_file.write_text("not json at all", encoding="utf-8")
    state_file.chmod(0o600)

    with pytest.raises(CredentialsValidationError) as exc_info:
        validate_storage_state(state_file)
    assert exc_info.value.code == "authentication_required"


def test_n09_missing_required_cookie_raises_error(tmp_path):
    state_file = tmp_path / "missing_sapisid.json"
    synthetic_cookies = {name: f"val_{name}" for name in REQUIRED_COOKIES if name != "SAPISID"}
    create_synthetic_storage_state(state_file, synthetic_cookies)

    with pytest.raises(CredentialsValidationError) as exc_info:
        validate_storage_state(state_file)
    assert exc_info.value.code == "authentication_required"


def test_n09_disallowed_cookie_domain_rejected(tmp_path):
    state_file = tmp_path / "evil_domain.json"
    synthetic_cookies = {name: f"val_{name}" for name in REQUIRED_COOKIES}
    create_synthetic_storage_state(state_file, synthetic_cookies, domain=".evil.com")

    with pytest.raises(CredentialsValidationError) as exc_info:
        validate_storage_state(state_file)
    assert exc_info.value.code == "invalid_cookie_origin"


def test_n09_bridge_converts_mcp_auth_to_domain_storage_state(tmp_path):
    mcp_file = tmp_path / "mcp_auth.json"
    target_state = tmp_path / "sdk_storage_state.json"

    synthetic_cookies = {name: f"val_{name}" for name in REQUIRED_COOKIES}
    mcp_payload = {
        "cookies": synthetic_cookies,
        "csrf_token": "csrf123",
        "session_id": "sess456",
    }
    mcp_file.write_text(json.dumps(mcp_payload), encoding="utf-8")
    mcp_file.chmod(0o600)

    out_path = bridge_mcp_to_storage_state(mcp_file, target_state, origin="https://notebooklm.google.com")
    assert out_path == target_state
    assert target_state.exists()

    # Permissions must be 0600
    file_mode = stat.S_IMODE(target_state.stat().st_mode)
    assert file_mode == 0o600

    validated = validate_storage_state(target_state)
    names = {c["name"]: c["value"] for c in validated["cookies"]}
    for req in REQUIRED_COOKIES:
        assert names[req] == synthetic_cookies[req]
        # Domain must be google.com
        matching = [c for c in validated["cookies"] if c["name"] == req][0]
        assert "google.com" in matching["domain"]


@pytest.mark.parametrize("origin", ["https://notebook.google.com", "https://notebooklm.google.com"])
def test_manual_bridge_preserves_the_app_binding_cookie_scope(tmp_path, origin):
    mcp = tmp_path / "auth.json"
    values = {name: f"session-{name}" for name in REQUIRED_COOKIES}
    values.update({"OSID": "product-binding", "__Secure-OSID": "secure-binding", "LSID": "login-binding"})
    mcp.write_text(json.dumps({"cookies": values}))
    target = tmp_path / "state.json"
    bridge_mcp_to_storage_state(mcp, target, origin=origin)
    cookies = {c["name"]: c for c in validate_storage_state(target)["cookies"]}
    from urllib.parse import urlparse
    assert cookies["OSID"]["domain"] == cookies["__Secure-OSID"]["domain"] == urlparse(origin).hostname
    assert cookies["LSID"]["domain"] == "accounts.google.com"
    assert cookies["SID"]["domain"] == ".google.com"
    assert {name: c["value"] for name, c in cookies.items()} == values


def test_n09_no_browser_launched_on_validation_failure(tmp_path, monkeypatch):
    """Verify runtime validation failure never launches browser or external processes."""
    called_commands = []

    orig_popen = subprocess.Popen
    def mock_popen(*args, **kwargs):
        called_commands.append(args)
        raise RuntimeError("Browser process spawn attempted!")

    monkeypatch.setattr(subprocess, "Popen", mock_popen)

    missing_file = tmp_path / "nonexistent.json"
    with pytest.raises(CredentialsValidationError):
        validate_storage_state(missing_file)

    assert len(called_commands) == 0


def test_n09_suffix_domain_collision_rejected(tmp_path):
    """Ensure evilgoogle.com and notgoogle.com cannot bypass domain validation."""
    state_file = tmp_path / "suffix_collision.json"
    synthetic_cookies = {name: f"val_{name}" for name in REQUIRED_COOKIES}
    create_synthetic_storage_state(state_file, synthetic_cookies, domain="evilgoogle.com")

    with pytest.raises(CredentialsValidationError) as exc_info:
        validate_storage_state(state_file)
    assert exc_info.value.code == "invalid_cookie_origin"


def test_n09_same_name_cookie_wrong_domain_rejected(tmp_path):
    """If any cookie has an unauthorized domain, rejection is immediate."""
    state_file = tmp_path / "mixed_domains.json"
    cookie_list = [
        {
            "name": name,
            "value": f"val_{name}",
            "domain": ".google.com",
            "path": "/",
            "expires": 1893456000,
            "httpOnly": True,
            "secure": True,
            "sameSite": "None",
        }
        for name in REQUIRED_COOKIES
    ]
    # Add an injected same-name cookie on evil domain
    cookie_list.append({
        "name": "SID",
        "value": "evil_sid_val",
        "domain": "evil.com",
        "path": "/",
        "expires": 1893456000,
        "httpOnly": True,
        "secure": True,
        "sameSite": "None",
    })
    state_file.write_text(json.dumps({"cookies": cookie_list, "origins": []}), encoding="utf-8")
    state_file.chmod(0o600)

    with pytest.raises(CredentialsValidationError) as exc_info:
        validate_storage_state(state_file)
    assert exc_info.value.code == "invalid_cookie_origin"


def test_n09_same_name_cookie_non_root_path_rejected(tmp_path):
    """Cookies restricted to non-root path cannot satisfy required root session cookies."""
    state_file = tmp_path / "subpath_cookie.json"
    cookie_list = [
        {
            "name": name,
            "value": f"val_{name}",
            "domain": ".google.com",
            "path": "/subpath" if name == "SID" else "/",
            "expires": 1893456000,
            "httpOnly": True,
            "secure": True,
            "sameSite": "None",
        }
        for name in REQUIRED_COOKIES
    ]
    state_file.write_text(json.dumps({"cookies": cookie_list, "origins": []}), encoding="utf-8")
    state_file.chmod(0o600)

    with pytest.raises(CredentialsValidationError) as exc_info:
        validate_storage_state(state_file)
    assert exc_info.value.code == "authentication_required"


def test_n09_expired_cookie_rejected(tmp_path):
    """Expired cookies in storage state cannot satisfy required cookies."""
    import time
    state_file = tmp_path / "expired_cookie.json"
    cookie_list = [
        {
            "name": name,
            "value": f"val_{name}",
            "domain": ".google.com",
            "path": "/",
            "expires": (time.time() - 3600) if name == "SAPISID" else 1893456000,
            "httpOnly": True,
            "secure": True,
            "sameSite": "None",
        }
        for name in REQUIRED_COOKIES
    ]
    state_file.write_text(json.dumps({"cookies": cookie_list, "origins": []}), encoding="utf-8")
    state_file.chmod(0o600)

    with pytest.raises(CredentialsValidationError) as exc_info:
        validate_storage_state(state_file)
    assert exc_info.value.code == "authentication_required"


def test_n09_unauthorized_origin_in_storage_state_rejected(tmp_path):
    """Origins in storage state must be Google domains."""
    state_file = tmp_path / "bad_origin.json"
    synthetic_cookies = {name: f"val_{name}" for name in REQUIRED_COOKIES}
    create_synthetic_storage_state(state_file, synthetic_cookies)

    data = json.loads(state_file.read_text(encoding="utf-8"))
    data["origins"] = [{"origin": "https://attacker.com", "localStorage": []}]
    state_file.write_text(json.dumps(data), encoding="utf-8")

    with pytest.raises(CredentialsValidationError) as exc_info:
        validate_storage_state(state_file)
    assert exc_info.value.code == "invalid_cookie_origin"


def test_n09_ambiguous_conflicting_same_name_cookies_rejected(tmp_path):
    """Conflicting values for the same cookie name on the same domain and root path are rejected."""
    state_file = tmp_path / "conflicting_cookies.json"
    cookie_list = [
        {
            "name": name,
            "value": f"val_{name}",
            "domain": ".google.com",
            "path": "/",
            "expires": 1893456000,
            "httpOnly": True,
            "secure": True,
            "sameSite": "None",
        }
        for name in REQUIRED_COOKIES
    ]
    # Add conflicting SID
    cookie_list.append({
        "name": "SID",
        "value": "conflicting_second_account_sid",
        "domain": ".google.com",
        "path": "/",
        "expires": 1893456000,
        "httpOnly": True,
        "secure": True,
        "sameSite": "None",
    })
    state_file.write_text(json.dumps({"cookies": cookie_list, "origins": []}), encoding="utf-8")
    state_file.chmod(0o600)

    with pytest.raises(CredentialsValidationError) as exc_info:
        validate_storage_state(state_file)
    assert exc_info.value.code == "insecure_storage_credentials"


def test_n09_error_representation_never_leaks_cookie_values(tmp_path):
    """Sensitive secret token values must never leak into exception representations."""
    state_file = tmp_path / "secret_leak_test.json"
    secret_token = "SUPER_SECRET_TOKEN_DO_NOT_LEAK_987654321"
    cookie_list = [
        {
            "name": name,
            "value": secret_token if name == "SID" else f"val_{name}",
            "domain": "evilgoogle.com" if name == "SID" else ".google.com",
            "path": "/",
            "expires": 1893456000,
            "httpOnly": True,
            "secure": True,
            "sameSite": "None",
        }
        for name in REQUIRED_COOKIES
    ]
    state_file.write_text(json.dumps({"cookies": cookie_list, "origins": []}), encoding="utf-8")
    state_file.chmod(0o600)

    with pytest.raises(CredentialsValidationError) as exc_info:
        validate_storage_state(state_file)

    err_str = str(exc_info.value)
    assert secret_token not in err_str
    assert secret_token not in repr(exc_info.value)

