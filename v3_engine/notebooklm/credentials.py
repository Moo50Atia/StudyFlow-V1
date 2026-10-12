from __future__ import annotations

import json
import os
import stat
import tempfile
from pathlib import Path
from typing import Any, Optional

import time
from urllib.parse import urlparse

ALLOWED_COOKIE_DOMAINS = {".google.com", "google.com", "notebooklm.google.com", "notebook.google.com"}
REQUIRED_COOKIES = {"SID", "HSID", "SSID", "APISID", "SAPISID"}


def _is_allowed_domain(domain: str) -> bool:
    if not domain:
        return False
    d = domain.lower()
    if d in ALLOWED_COOKIE_DOMAINS:
        return True
    return d == "google.com" or d.endswith(".google.com")


class CredentialsValidationError(Exception):
    def __init__(self, code: str, message: str):
        super().__init__(f"{code}: {message}")
        self.code = code
        self.message = message

    def __str__(self) -> str:
        return f"{self.code}: {self.message}"


def get_storage_state_path() -> Path:
    env_path = os.environ.get("V3_NOTEBOOKLM_STORAGE_STATE")
    if env_path:
        return Path(env_path).expanduser().resolve()
    return (Path.home() / ".notebooklm" / "storage_state_v3_local.json").resolve()


def get_terminal_run_storage_state(run_dir: Path) -> Path:
    """Find an existing run's isolated session without selecting another run or the master."""
    run_dir = run_dir.expanduser().resolve()
    reference = run_dir / "private" / "notebooklm-credentials.json"
    try:
        recorded = json.loads(reference.read_text(encoding="utf-8"))
        path = Path(recorded["storage_state_path"]).expanduser().resolve()
    except (OSError, ValueError, KeyError, TypeError):
        raise CredentialsValidationError("authentication_required", "Selected run has no valid session reference.") from None
    expected = run_dir / "private" / "notebooklm-sessions" / run_dir.name / "storage_state.json"
    if path != expected.resolve() or not path.is_file():
        raise CredentialsValidationError("authentication_required", "Selected run's isolated session is missing or mismatched.")
    return path


def sync_storage_state(source: Path, target: Path) -> None:
    """Replace an explicitly selected run's session atomically after a manual import."""
    validate_storage_state(source)
    fd, temporary = tempfile.mkstemp(dir=target.parent, prefix=".session-import-", suffix=".tmp")
    staging = Path(temporary)
    try:
        with os.fdopen(fd, "wb") as handle:
            handle.write(source.read_bytes())
            handle.flush()
            os.fsync(handle.fileno())
        staging.chmod(0o600)
        staging.replace(target)
    finally:
        staging.unlink(missing_ok=True)


def validate_storage_state(path: Path) -> dict[str, Any]:
    """Validate that the given Playwright storage state file exists, is secure, and has valid Google cookies."""
    if os.path.islink(path) or path.is_symlink():
        raise CredentialsValidationError("insecure_storage_credentials", "Storage state cannot be a symbolic link.")
    path = path.resolve()
    if os.path.islink(path) or path.is_symlink():
        raise CredentialsValidationError("insecure_storage_credentials", "Storage state cannot be a symbolic link.")

    if not path.is_file():
        raise CredentialsValidationError("authentication_required", f"Storage state file does not exist at {path.name}.")

    # On POSIX systems check that file is not world/group readable
    if hasattr(os, "stat") and os.name == "posix":
        mode = stat.S_IMODE(path.stat().st_mode)
        if mode & 0o077 != 0:
            # Try to fix permissions or refuse
            try:
                path.chmod(0o600)
            except OSError:
                raise CredentialsValidationError("insecure_storage_credentials", "Storage state permissions too open.")

    try:
        content = path.read_text(encoding="utf-8")
        data = json.loads(content)
    except Exception as e:
        raise CredentialsValidationError("authentication_required", "Storage state file contains invalid JSON.") from e

    if not isinstance(data, dict) or "cookies" not in data or not isinstance(data["cookies"], list):
        raise CredentialsValidationError("authentication_required", "Storage state format is invalid.")

    # Validate origins if present
    origins = data.get("origins", [])
    if isinstance(origins, list):
        for o in origins:
            if isinstance(o, dict):
                origin_url = o.get("origin", "")
                if origin_url:
                    parsed = urlparse(origin_url)
                    host = (parsed.hostname or "").lower()
                    if not (host == "google.com" or host.endswith(".google.com")):
                        raise CredentialsValidationError("invalid_cookie_origin", "Storage state contains unauthorized origin.")

    now = time.time()
    cookie_map: dict[str, dict[str, Any]] = {}
    for c in data["cookies"]:
        if not isinstance(c, dict):
            continue
        name = c.get("name")
        domain = c.get("domain", "")
        path_val = c.get("path", "/")
        expires = c.get("expires")
        value = c.get("value", "")

        if not name or not isinstance(name, str):
            continue

        # Check domain validity strictly
        if domain and not _is_allowed_domain(domain):
            raise CredentialsValidationError("invalid_cookie_origin", f"Cookie {name} has unauthorized domain.")

        # Ignore cookies not scoped to root path for required session identification
        if path_val != "/":
            continue

        # Check expiration: if timestamp is positive and in the past, it is expired
        if expires is not None and isinstance(expires, (int, float)) and 0 < expires < now:
            continue

        # Detect conflicting same-name cookies on the same domain and root path (cross-account ambiguity)
        if name in cookie_map:
            prev = cookie_map[name]
            if prev.get("domain") == domain and prev.get("value") != value:
                raise CredentialsValidationError("insecure_storage_credentials", f"Storage state contains ambiguous conflicting cookies for {name}.")
        else:
            cookie_map[name] = c

    missing = REQUIRED_COOKIES - set(cookie_map.keys())
    if missing:
        raise CredentialsValidationError("authentication_required", f"Storage state missing required cookies: {', '.join(sorted(missing))}")

    return data


def bridge_mcp_to_storage_state(
    mcp_auth_path: Path,
    target_storage_state_path: Path,
    origin: str = "https://notebooklm.google.com",
) -> Path:
    """Bridge a flat MCP auth.json cache into a domain/path-aware Playwright storage state file."""
    parsed_origin = urlparse(origin)
    if origin not in {"https://notebooklm.google.com", "https://notebook.google.com"}:
        raise CredentialsValidationError("invalid_cookie_origin", "Use the NotebookLM app origin where the Cookie header was copied.")
    mcp_auth_path = mcp_auth_path.resolve()
    if not mcp_auth_path.is_file():
        raise CredentialsValidationError("authentication_required", "MCP auth file does not exist.")

    try:
        mcp_data = json.loads(mcp_auth_path.read_text(encoding="utf-8"))
    except Exception as e:
        raise CredentialsValidationError("authentication_required", "Malformed MCP auth JSON.") from e

    cookies_dict = mcp_data.get("cookies", {})
    if not isinstance(cookies_dict, dict):
        raise CredentialsValidationError("authentication_required", "MCP auth file contains invalid cookies structure.")

    missing = REQUIRED_COOKIES - set(cookies_dict.keys())
    if missing:
        raise CredentialsValidationError("authentication_required", f"MCP auth missing required cookies: {', '.join(sorted(missing))}")

    # A request header omits domain metadata. Preserve the known product binding
    # on the selected app host; OSID is not an account-wide .google.com cookie.
    cookie_list = []
    for name, value in cookies_dict.items():
        if not isinstance(value, str) or not value:
            continue
        cookie_list.append({
            "name": name,
            "value": value,
            "domain": (parsed_origin.hostname if name in {"OSID", "__Secure-OSID"}
                       else "accounts.google.com" if name == "LSID" else ".google.com"),
            "path": "/",
            "expires": -1,
            "httpOnly": True,
            "secure": True,
            "sameSite": "None",
        })

    payload = {
        "cookies": cookie_list,
        "origins": [],
    }

    target_storage_state_path = target_storage_state_path.resolve()
    target_storage_state_path.parent.mkdir(parents=True, mode=0o700, exist_ok=True)

    # Atomic write
    temp_dir = target_storage_state_path.parent
    temp_fd, temp_path_str = tempfile.mkstemp(dir=temp_dir, prefix=".state_", suffix=".tmp")
    temp_path = Path(temp_path_str)

    try:
        with os.fdopen(temp_fd, "w", encoding="utf-8") as f:
            json.dump(payload, f)
            f.flush()
            os.fsync(f.fileno())

        temp_path.chmod(0o600)
        temp_path.replace(target_storage_state_path)
    finally:
        if temp_path.exists():
            temp_path.unlink()

    return target_storage_state_path
