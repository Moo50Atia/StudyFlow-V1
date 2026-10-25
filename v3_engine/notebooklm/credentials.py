from __future__ import annotations

import json
import os
import stat
import tempfile
from pathlib import Path
from typing import Any, Optional
from contextlib import contextmanager
from datetime import datetime, timezone
import fcntl

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


def get_storage_state_path(configured_path: str | Path | None = None) -> Path:
    """Resolve the one local NotebookLM profile used by this execution runtime.

    The local worker runtime file is the source of truth for a laptop install.
    A process-level override remains useful for isolated tests and container
    runtimes. Legacy callers may pass the captured project setting explicitly.
    """
    env_path = os.environ.get("V3_NOTEBOOKLM_STORAGE_STATE")
    if env_path:
        return Path(env_path).expanduser().resolve()
    runtime_config = Path.home() / ".local_worker" / "runtime.json"
    try:
        runtime = json.loads(runtime_config.read_text(encoding="utf-8"))
        local_path = runtime.get("session_path") if isinstance(runtime, dict) else None
        if isinstance(local_path, str) and local_path.strip():
            return Path(local_path).expanduser().resolve()
    except (OSError, ValueError):
        pass
    if configured_path:
        return Path(configured_path).expanduser().resolve()
    return (Path.home() / ".notebooklm" / "storage_state_v3_local.json").resolve()


def _credential_fingerprint(path: Path) -> dict[str, int]:
    stat_result = path.stat()
    return {"mtime_ns": stat_result.st_mtime_ns, "size": stat_result.st_size, "inode": stat_result.st_ino}


def _profile_metadata_path(master_path: Path) -> Path:
    return master_path.with_name(master_path.name + ".profile.json")


def _mcp_auth_path() -> Path:
    return (Path.home() / ".notebooklm-mcp" / "auth.json").resolve()


def _mcp_auth_matches_storage_state(storage_path: Path) -> bool:
    """Check the required cookies agree across the MCP and SDK file formats."""
    mcp_path = _mcp_auth_path()
    if not mcp_path.is_file():
        return True
    try:
        mcp = json.loads(mcp_path.read_text(encoding="utf-8"))
        sdk = validate_storage_state(storage_path)
        expected = mcp.get("cookies")
        if not isinstance(expected, dict):
            return False
        actual = {cookie.get("name"): cookie.get("value") for cookie in sdk["cookies"] if isinstance(cookie, dict)}
        return all(
            isinstance(expected.get(name), str)
            and bool(expected[name])
            and actual.get(name) == expected[name]
            for name in REQUIRED_COOKIES
        )
    except (OSError, ValueError, TypeError, CredentialsValidationError):
        return False


@contextmanager
def _profile_lock(master_path: Path):
    lock_path = master_path.with_name(master_path.name + ".profile.lock")
    lock_path.parent.mkdir(parents=True, mode=0o700, exist_ok=True)
    fd = os.open(lock_path, os.O_CREAT | os.O_RDWR, 0o600)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX)
        yield
    finally:
        fcntl.flock(fd, fcntl.LOCK_UN)
        os.close(fd)


def _write_private_json(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, mode=0o700, exist_ok=True)
    fd, temporary = tempfile.mkstemp(dir=path.parent, prefix=".profile-", suffix=".tmp")
    staging = Path(temporary)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(payload, handle, sort_keys=True)
            handle.flush()
            os.fsync(handle.fileno())
        staging.chmod(0o600)
        staging.replace(path)
    finally:
        staging.unlink(missing_ok=True)


class NotebookCredentialAuthority:
    """Own credential profile selection, verification generation and run copies.

    Only session metadata is stored here. Cookie values remain in the existing
    owner-only storage-state file and are never copied into profile records.
    """

    PROFILE_ID = "notebooklm-default"
    RUN_MARKER = "credential-profile.json"

    def __init__(self, master_path: Path | None = None):
        self.master_path = (master_path or get_storage_state_path()).expanduser().resolve()
        self.metadata_path = _profile_metadata_path(self.master_path)

    def _metadata(self) -> dict[str, Any]:
        try:
            value = json.loads(self.metadata_path.read_text(encoding="utf-8"))
            if isinstance(value, dict) and value.get("schema_version") == 1:
                return value
        except (OSError, ValueError):
            pass
        return {
            "schema_version": 1,
            "profile_id": self.PROFILE_ID,
            "generation": 0,
            "verified_fingerprint": None,
            "proof_notebook_id": None,
            "verified_at": None,
        }

    def profile_status(self) -> dict[str, Any]:
        """Return safe profile state, without exposing cookies or their values."""
        with _profile_lock(self.master_path):
            metadata = self._metadata()
            try:
                fingerprint = _credential_fingerprint(self.master_path)
                present = True
            except OSError:
                fingerprint, present = None, False
            try:
                mcp_fingerprint = _credential_fingerprint(_mcp_auth_path())
                mcp_present = True
            except OSError:
                mcp_fingerprint, mcp_present = None, False
            mcp_matches = not mcp_present or (
                metadata.get("mcp_fingerprint") == mcp_fingerprint
                and _mcp_auth_matches_storage_state(self.master_path)
            )
            verified = present and metadata.get("verified_fingerprint") == fingerprint and mcp_matches
            return {
                "profile_id": metadata["profile_id"],
                "generation": int(metadata.get("generation", 0)),
                "verified": bool(verified),
                "proof_notebook_id": metadata.get("proof_notebook_id"),
                "verified_at": metadata.get("verified_at"),
                "available": present,
            }

    def proof_notebook_id(self) -> str | None:
        value = self._metadata().get("proof_notebook_id")
        return value.strip() if isinstance(value, str) and value.strip() else None

    def verification_path(self, requested_path: Path) -> Path:
        """Resolve the source profile for an explicit worker/run session copy."""
        requested_path = requested_path.expanduser().resolve()
        marker_path = requested_path.parent / self.RUN_MARKER
        if not marker_path.is_file():
            return requested_path
        try:
            marker = json.loads(marker_path.read_text(encoding="utf-8"))
            source = Path(marker["source_path"]).expanduser().resolve()
        except (OSError, ValueError, KeyError, TypeError) as exc:
            raise CredentialsValidationError("authentication_required", "Run credential profile binding is invalid.") from exc
        if source != self.master_path or marker.get("profile_id") != self.PROFILE_ID:
            raise CredentialsValidationError("account_mismatch", "Run is bound to a different NotebookLM profile.")
        return source

    def record_verified(self, proof_notebook_id: str, verified_storage_path: Path) -> int:
        """Publish a new profile generation only after provider ownership passed."""
        if not isinstance(proof_notebook_id, str) or not proof_notebook_id.strip():
            raise CredentialsValidationError("authentication_required", "NotebookLM ownership proof is missing.")
        verified_path = verified_storage_path.expanduser().resolve()
        validate_storage_state(verified_path)
        if not _mcp_auth_matches_storage_state(verified_path):
            raise CredentialsValidationError(
                "account_mismatch",
                "The MCP and SDK adapters do not contain the same NotebookLM account session.",
            )
        with _profile_lock(self.master_path):
            if verified_path != self.master_path:
                marker_path = verified_path.parent / self.RUN_MARKER
                try:
                    marker = json.loads(marker_path.read_text(encoding="utf-8"))
                    source_path = Path(marker["source_path"]).expanduser().resolve()
                except (OSError, ValueError, KeyError, TypeError):
                    source_path = verified_path
                if source_path != self.master_path:
                    # An explicitly selected legacy run has no authority to
                    # rotate a different configured profile.
                    return int(self._metadata().get("generation", 0))
            current = _credential_fingerprint(self.master_path)
            try:
                mcp_fingerprint = _credential_fingerprint(_mcp_auth_path())
            except OSError:
                mcp_fingerprint = None
            metadata = self._metadata()
            if metadata.get("verified_fingerprint") != current:
                metadata["generation"] = int(metadata.get("generation", 0)) + 1
            metadata.update({
                "profile_id": self.PROFILE_ID,
                "verified_fingerprint": current,
                "mcp_fingerprint": mcp_fingerprint,
                "proof_notebook_id": proof_notebook_id.strip(),
                "verified_at": datetime.now(timezone.utc).isoformat(),
            })
            _write_private_json(self.metadata_path, metadata)
            return int(metadata["generation"])

    def bind_run_copy(self, source: Path, run_id: str, session_base_dir: Path | None = None) -> Path:
        """Create an isolated session copy and bind it to the selected profile."""
        source = source.expanduser().resolve()
        validate_storage_state(source)
        base_dir = session_base_dir or (Path.home() / ".notebooklm" / "v3-terminal-sessions")
        run_dir = base_dir / run_id
        run_dir.mkdir(parents=True, mode=0o700, exist_ok=True)
        os.chmod(run_dir, 0o700)
        target = run_dir / "storage_state.json"
        with _profile_lock(source):
            sync_storage_state(source, target)
            metadata = NotebookCredentialAuthority(source)._metadata()
            marker = {
                "schema_version": 1,
                "profile_id": metadata.get("profile_id", self.PROFILE_ID),
                "source_path": str(source),
                "generation": int(metadata.get("generation", 0)),
            }
            _write_private_json(run_dir / self.RUN_MARKER, marker)
        return target

    def resolve_run_copy(self, path: Path) -> Path:
        """Adopt a verified profile refresh lazily at an operation boundary."""
        path = path.expanduser().resolve()
        marker_path = path.parent / self.RUN_MARKER
        if not marker_path.is_file():
            return path  # Explicit legacy run copies retain their old behavior.
        try:
            marker = json.loads(marker_path.read_text(encoding="utf-8"))
            source = Path(marker["source_path"]).expanduser().resolve()
        except (OSError, ValueError, KeyError, TypeError) as exc:
            raise CredentialsValidationError("authentication_required", "Run credential profile binding is invalid.") from exc
        if source != self.master_path or marker.get("profile_id") != self.PROFILE_ID:
            raise CredentialsValidationError("account_mismatch", "Run is bound to a different NotebookLM profile.")
        with _profile_lock(source):
            metadata = self._metadata()
            validate_storage_state(source)
            current_fingerprint = _credential_fingerprint(source)
            try:
                mcp_fingerprint = _credential_fingerprint(_mcp_auth_path())
                mcp_present = True
            except OSError:
                mcp_fingerprint, mcp_present = None, False
            mcp_verified = not mcp_present or (
                metadata.get("mcp_fingerprint") == mcp_fingerprint
                and _mcp_auth_matches_storage_state(source)
            )
            if metadata.get("verified_fingerprint") != current_fingerprint or not mcp_verified:
                raise CredentialsValidationError(
                    "authentication_required",
                    "The refreshed NotebookLM profile needs a successful ownership check before runs can adopt it.",
                )
            generation = int(metadata.get("generation", 0))
            if int(marker.get("generation", -1)) != generation:
                sync_storage_state(source, path)
                marker["generation"] = generation
                _write_private_json(marker_path, marker)
        return path


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
