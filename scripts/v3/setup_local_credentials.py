#!/usr/bin/env python3
"""One-time local provider credential setup for V3; never prints credential values."""

from __future__ import annotations

import argparse
import base64
import getpass
import json
import os
import secrets
import subprocess
import shutil
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

from dotenv import dotenv_values  # noqa: E402

from v3_engine.local_env import (  # noqa: E402
    generate_local_hmac_secrets,
    write_local_env,
)

GEMINI_KEY_URL = "https://aistudio.google.com/app/apikey"


def _secret_prompt(label: str, url: str) -> str:
    print(f"\nOpen this provider page in your browser: {url}")
    return getpass.getpass(f"Paste {label} (input is hidden): ").strip()


def _notebooklm_auth_cache_is_structurally_valid(auth_path: Path) -> bool:
    try:
        payload = json.loads(auth_path.read_text(encoding="utf-8"))
        cookies = payload.get("cookies") if isinstance(payload, dict) else None
        required = {"SID", "HSID", "SSID", "APISID", "SAPISID"}
        return (
            isinstance(cookies, dict)
            and required.issubset(cookies)
            and all(isinstance(cookies[name], str) and cookies[name] for name in required)
        )
    except (OSError, ValueError):
        return False


def _ensure_notebooklm_auth(*, manual: bool = False, browser: bool = False, browser_path: str | None = None, origin: str = "https://notebooklm.google.com") -> bool:
    cached_auth = Path.home() / ".notebooklm-mcp" / "auth.json"
    try:
        if _run_notebooklm_auth(cached_auth, manual=manual, browser=browser, browser_path=browser_path, origin=origin):
            return True
    except (OSError, subprocess.SubprocessError):
        print("The MCP authentication process could not complete; check its diagnostic output above.", file=sys.stderr)
        return False
    print(
        "No session was imported. Existing credentials and browser profiles were preserved.",
        file=sys.stderr,
    )
    return False


def _run_notebooklm_auth(auth_path: Path, *, manual: bool = False, browser: bool = False, browser_path: str | None = None, origin: str = "https://notebooklm.google.com") -> bool:
    """Import cookies or explicitly authenticate using a persistent dedicated Brave profile."""
    if browser:
        cli = REPO_ROOT / "integrations/notebooklm-mcp/dist/cli.js"
        if not cli.is_file():
            raise ValueError("Build the local MCP first: npm run build --prefix integrations/notebooklm-mcp")
        executable = browser_path or shutil.which("brave-browser") or shutil.which("brave-browser-stable")
        if not executable:
            raise ValueError("Brave is not installed; specify --notebooklm-browser /path/to/browser")
        child_env = dict(os.environ, NOTEBOOKLM_NO_BROWSER="0", NOTEBOOKLM_BROWSER_PATH=executable,
                         NOTEBOOKLM_BROWSER_PROFILE_DIR=str(auth_path.parent / "brave-profile"), NOTEBOOKLM_ORIGIN=origin, NOTEBOOKLM_AUTH_TIMEOUT_MS="600000")
        for name in ("NOTEBOOKLM_COOKIES", "NOTEBOOKLM_CSRF_TOKEN", "NOTEBOOKLM_SESSION_ID", "NOTEBOOKLM_BL"):
            child_env.pop(name, None)
        print("Opening the dedicated Brave profile. Sign into the intended Google account once.")
        result = subprocess.run(["node", str(cli), "auth", "--browser-only"], env=child_env, check=False, timeout=660)
        return result.returncode == 0 and _notebooklm_auth_cache_is_structurally_valid(auth_path)
    if not manual:
        print("NotebookLM needs an explicit cookie import: --refresh-notebooklm --manual-notebooklm.")
        return False
    cookie_header = getpass.getpass("Paste the Cookie header (input is hidden): ").strip()
    if not cookie_header or any(c in cookie_header for c in ("\n", "\r", "\x00")):
        raise ValueError("NotebookLM Cookie header is empty or malformed.")
    cookies = {}
    for part in cookie_header.split(";"):
        name, sep, value = part.strip().partition("=")
        if not sep or not name or name in cookies:
            raise ValueError("NotebookLM Cookie header contains malformed or duplicate names.")
        cookies[name] = value
    required = {"SID", "HSID", "SSID", "APISID", "SAPISID"}
    if not required.issubset(cookies) or any(not cookies[k] for k in required):
        raise ValueError("NotebookLM Cookie header is missing required session cookies.")
    import time
    payload = {"cookies": cookies, "csrf_token": "", "session_id": "", "extracted_at": time.time()}
    auth_path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(auth_path.parent, 0o700)
    if auth_path.is_symlink():
        raise ValueError("Refusing a symbolic-link NotebookLM cache.")
    staging = auth_path.parent / f".auth-{secrets.token_hex(8)}.tmp"
    try:
        fd = os.open(staging, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(payload, handle)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(staging, auth_path)
    finally:
        staging.unlink(missing_ok=True)
    print("NotebookLM cookies imported. Live access still requires verification.")
    return True


def _read_gemini_key() -> str:
    print("In Google AI Studio, copy an API key for this local V3 worker.")
    value = _secret_prompt("the Gemini API key", GEMINI_KEY_URL)
    if not value or "\n" in value or "\r" in value or "\x00" in value:
        raise ValueError("Gemini API key input is empty or malformed.")
    return value


def main() -> int:
    parser = argparse.ArgumentParser(
        description=(
            "Configure V3's MCP-managed NotebookLM session and local Gemini/HMAC environment values."
        )
    )
    parser.add_argument("--refresh-notebooklm", action="store_true", help="replace the stored NotebookLM session")
    parser.add_argument(
        "--manual-notebooklm",
        action="store_true",
        help="paste a NotebookLM Cookie header at a hidden prompt instead of opening a browser",
    )
    parser.add_argument("--browser-notebooklm", action="store_true", help="authenticate automatically through a dedicated persistent Brave profile")
    parser.add_argument("--notebooklm-browser", help="browser executable path (defaults to Brave)")
    parser.add_argument("--notebooklm-origin", choices=["https://notebooklm.google.com", "https://notebook.google.com"], default=None)
    parser.add_argument("--refresh-gemini", action="store_true", help="replace the stored Gemini API key")
    parser.add_argument("--bridge-sdk", action="store_true", help="bridge the MCP session cache to Playwright storage-state for notebooklm-py SDK")
    args = parser.parse_args()

    if not sys.stdin.isatty() and not args.bridge_sdk:
        print("Run this setup command in an interactive terminal.", file=sys.stderr)
        return 2

    env_path = REPO_ROOT / ".env"
    if not env_path.exists():
        print(".env is missing. Create it from .env.example, then rerun this setup.", file=sys.stderr)
        return 2
    if env_path.is_symlink():
        print("Refusing to write through a symbolic-link .env path.", file=sys.stderr)
        return 2

    if args.browser_notebooklm and args.manual_notebooklm:
        parser.error("Choose either browser or manual NotebookLM authentication")
    if args.browser_notebooklm and not args.refresh_notebooklm:
        parser.error("--browser-notebooklm requires --refresh-notebooklm")
    current = dotenv_values(env_path)
    updates: dict[str, str | None] = {}
    try:
        auth_path = Path.home() / ".notebooklm-mcp" / "auth.json"
        if args.manual_notebooklm and not args.refresh_notebooklm:
            parser.error("--manual-notebooklm requires --refresh-notebooklm")
        if args.refresh_notebooklm or not _notebooklm_auth_cache_is_structurally_valid(auth_path):
            if not _ensure_notebooklm_auth(manual=args.manual_notebooklm, browser=args.browser_notebooklm, browser_path=args.notebooklm_browser, origin=args.notebooklm_origin or current.get("NOTEBOOKLM_ORIGIN") or "https://notebooklm.google.com"):
                raise ValueError("NotebookLM cookie import did not produce a structurally complete cache.")
        if args.browser_notebooklm:
            updates["NOTEBOOKLM_ALLOW_BROWSER_REFRESH"] = "1"
            updates["NOTEBOOKLM_BROWSER_PATH"] = args.notebooklm_browser or shutil.which("brave-browser") or shutil.which("brave-browser-stable")
            updates["NOTEBOOKLM_BROWSER_PROFILE_DIR"] = str(auth_path.parent / "brave-profile")
        if args.notebooklm_origin:
            updates["NOTEBOOKLM_ORIGIN"] = args.notebooklm_origin
        if (args.refresh_gemini or not current.get("V3_GEMINI_API_KEY_B64")) and not args.bridge_sdk:
            key = _read_gemini_key()
            updates["V3_GEMINI_API_KEY_B64"] = base64.b64encode(key.encode("utf-8")).decode("ascii")
            updates["GEMINI_API_KEY"] = None

        if args.bridge_sdk or args.refresh_notebooklm:
            from v3_engine.notebooklm.credentials import bridge_mcp_to_storage_state, get_storage_state_path
            sdk_storage_path = get_storage_state_path()
            bridge_mcp_to_storage_state(auth_path, sdk_storage_path)
            updates["V3_NOTEBOOKLM_STORAGE_STATE"] = str(sdk_storage_path)

        for key, secret in generate_local_hmac_secrets().items():
            if not current.get(key):
                updates[key] = secret

        # The pinned MCP package uses its owner-only auth.json cache directly.
        # Remove cookies written by the earlier bootstrap so environment values
        # cannot override that MCP-managed session.
        updates["NOTEBOOKLM_COOKIES"] = None
        updates["V3_NOTEBOOKLM_COOKIES_B64"] = None
        if current.get("V3_GEMINI_API_KEY_B64"):
            updates["GEMINI_API_KEY"] = None

        if not updates:
            # Credentials may have been configured manually before this helper
            # was introduced. Apply the same owner-only permissions even when
            # there is nothing to rewrite.
            os.chmod(env_path, 0o600)
            print("Required local credentials and HMAC secrets are already configured; .env was not changed.")
            return 0

        write_local_env(env_path, updates)
    except (EOFError, KeyboardInterrupt):
        print("\nSetup cancelled; .env was not changed.", file=sys.stderr)
        return 130
    except ValueError as exc:
        print(str(exc), file=sys.stderr)
        return 2
    except OSError:
        print("Could not safely update .env; no credential values were printed.", file=sys.stderr)
        return 2

    os.chmod(env_path, 0o600)
    print("Updated local .env and checked the MCP cache structure; live access is not verified. Credential values were not displayed.")
    print("Restart Laravel and the Python worker so both reload the new environment.")
    print("JEV was not configured; its qualification remains owner-waived for the demo.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
