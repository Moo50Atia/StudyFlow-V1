"""Small helpers for securely loading and updating this local V3 .env file."""

from __future__ import annotations

import base64
import os
import re
import secrets
import tempfile
from pathlib import Path
from typing import Mapping

LOCAL_ENV_KEYS = frozenset(
    {
        "V3_NOTEBOOKLM_COOKIES_B64",
        "V3_GEMINI_API_KEY_B64",
        "NOTEBOOKLM_COOKIES",
        "NOTEBOOKLM_ORIGIN",
        "NOTEBOOKLM_ALLOW_BROWSER_REFRESH",
        "NOTEBOOKLM_BROWSER_PATH",
        "NOTEBOOKLM_BROWSER_PROFILE_DIR",
        "NOTEBOOKLM_QUERY_TIMEOUT_MS",
        "GEMINI_API_KEY",
        "V3_BRIDGE_HMAC_KEY",
        "V3_CALLBACK_HMAC_KEY",
    }
)


def _quote_dotenv(value: str) -> str:
    if "\n" in value or "\r" in value or "\x00" in value:
        raise ValueError("Environment values cannot contain line breaks or NUL bytes.")
    escaped = value.replace("\\", "\\\\").replace('"', '\\"').replace("$", "\\$")
    return f'"{escaped}"'


def write_local_env(env_path: Path, updates: Mapping[str, str | None]) -> None:
    """Atomically update only allowlisted keys and restrict the file to its owner."""
    env_path = Path(env_path)
    if env_path.is_symlink():
        raise ValueError("Refusing to write through a symbolic-link .env path.")
    unexpected = set(updates).difference(LOCAL_ENV_KEYS)
    if unexpected:
        raise ValueError("Refusing to write an unsupported environment key.")
    if any(value is not None and not isinstance(value, str) for value in updates.values()):
        raise ValueError("Environment values must be strings.")

    original = env_path.read_text(encoding="utf-8") if env_path.exists() else ""
    lines = original.splitlines()
    pattern = re.compile(r"^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=")
    replaced: set[str] = set()
    output: list[str] = []
    for line in lines:
        match = pattern.match(line)
        key = match.group(1) if match else None
        if key in updates:
            if key not in replaced:
                if updates[key] is not None:
                    output.append(f"{key}={_quote_dotenv(updates[key])}")
                replaced.add(key)
            # Collapse duplicate definitions of keys this tool owns.
            continue
        output.append(line)

    for key, value in updates.items():
        if key not in replaced and value is not None:
            output.append(f"{key}={_quote_dotenv(value)}")

    env_path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary_name = tempfile.mkstemp(prefix=".v3-env-", dir=env_path.parent)
    temporary_path = Path(temporary_name)
    try:
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as handle:
            handle.write("\n".join(output).rstrip("\n") + "\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary_path, env_path)
        os.chmod(env_path, 0o600)
    except BaseException:
        try:
            temporary_path.unlink(missing_ok=True)
        finally:
            raise


def generate_local_hmac_secrets() -> dict[str, str]:
    return {
        "V3_BRIDGE_HMAC_KEY": secrets.token_urlsafe(48),
        "V3_CALLBACK_HMAC_KEY": secrets.token_urlsafe(48),
    }


def load_local_env(env_path: Path) -> None:
    """Load project .env values for Python workers without overriding process env."""
    from dotenv import load_dotenv

    load_dotenv(dotenv_path=Path(env_path), override=False)
    encoded_secrets = {
        "GEMINI_API_KEY": "V3_GEMINI_API_KEY_B64",
    }
    for target, source in encoded_secrets.items():
        if target in os.environ:
            continue
        encoded = os.environ.get(source)
        if not encoded:
            continue
        try:
            os.environ[target] = base64.b64decode(encoded, validate=True).decode("utf-8")
        except (ValueError, UnicodeDecodeError) as exc:
            raise RuntimeError(f"Invalid locally encoded secret: {source}.") from exc
