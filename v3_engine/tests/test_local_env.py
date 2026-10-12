from pathlib import Path
import base64
import os

import pytest

from v3_engine.local_env import (
    generate_local_hmac_secrets,
    load_local_env,
    write_local_env,
)


def test_env_writer_preserves_other_values_replaces_owned_keys_and_sets_private_mode(tmp_path: Path):
    env_path = tmp_path / ".env"
    env_path.write_text('APP_NAME="Local App"\nGEMINI_API_KEY="old"\n', encoding="utf-8")

    env_path.write_text(env_path.read_text(encoding="utf-8") + 'NOTEBOOKLM_COOKIES="stale-session"\n', encoding="utf-8")
    write_local_env(
        env_path,
        {
            "V3_GEMINI_API_KEY_B64": base64.b64encode(b"new-key").decode("ascii"),
            "GEMINI_API_KEY": None,
            "NOTEBOOKLM_COOKIES": None,
        },
    )

    content = env_path.read_text(encoding="utf-8")
    assert 'APP_NAME="Local App"' in content
    assert 'GEMINI_API_KEY="old"' not in content
    assert 'V3_GEMINI_API_KEY_B64="bmV3LWtleQ=="' in content
    assert "NOTEBOOKLM_COOKIES" not in content
    assert env_path.stat().st_mode & 0o777 == 0o600


def test_env_writer_rejects_unsupported_keys_and_symlink_targets(tmp_path: Path):
    env_path = tmp_path / ".env"
    with pytest.raises(ValueError):
        write_local_env(env_path, {"AWS_SECRET_ACCESS_KEY": "not-allowed"})

    target = tmp_path / "real.env"
    target.write_text("SAFE=1\n", encoding="utf-8")
    env_path.symlink_to(target)
    with pytest.raises(ValueError):
        write_local_env(env_path, {"V3_GEMINI_API_KEY_B64": "a2V5"})
    assert target.read_text(encoding="utf-8") == "SAFE=1\n"


def test_generated_hmac_secrets_are_distinct_and_strong():
    first = generate_local_hmac_secrets()
    second = generate_local_hmac_secrets()
    assert set(first) == {"V3_BRIDGE_HMAC_KEY", "V3_CALLBACK_HMAC_KEY"}
    assert first["V3_BRIDGE_HMAC_KEY"] != first["V3_CALLBACK_HMAC_KEY"]
    assert first["V3_BRIDGE_HMAC_KEY"] != second["V3_BRIDGE_HMAC_KEY"]
    assert all(len(value) >= 64 for value in first.values())


def test_local_dotenv_load_does_not_override_existing_process_environment(tmp_path: Path, monkeypatch):
    env_path = tmp_path / ".env"
    env_path.write_text(
        'V3_GEMINI_API_KEY_B64="ZnJvbS1maWxl"\n'
        'V3_NOTEBOOKLM_COOKIES_B64="U0lEPWE7IEhTSUQ9Yg=="\n'
        'CALLBACK_MARKER="loaded"\n',
        encoding="utf-8",
    )
    monkeypatch.setenv("GEMINI_API_KEY", "process-value")
    monkeypatch.delenv("NOTEBOOKLM_COOKIES", raising=False)
    monkeypatch.delenv("CALLBACK_MARKER", raising=False)

    load_local_env(env_path)

    import os

    assert os.environ["GEMINI_API_KEY"] == "process-value"
    assert os.environ["CALLBACK_MARKER"] == "loaded"
    assert "NOTEBOOKLM_COOKIES" not in os.environ

def test_verified_notebooklm_origin_is_persisted(tmp_path):
    env = tmp_path / '.env'
    env.write_text('APP_NAME=Existing\n')
    write_local_env(
        env,
        {
            'NOTEBOOKLM_ORIGIN': 'https://notebook.google.com',
            'V3_NOTEBOOKLM_STORAGE_STATE': '/private/notebooklm/storage-state.json',
        },
    )
    assert 'NOTEBOOKLM_ORIGIN="https://notebook.google.com"' in env.read_text()
    assert 'V3_NOTEBOOKLM_STORAGE_STATE="/private/notebooklm/storage-state.json"' in env.read_text()
    assert 'APP_NAME=Existing' in env.read_text()


def test_existing_brave_profile_selection_can_be_persisted(tmp_path):
    env = tmp_path / '.env'
    env.write_text('APP_NAME=Existing\n')
    write_local_env(
        env,
        {
            'NOTEBOOKLM_BROWSER_PROFILE_DIR': '/home/user/.config/BraveSoftware/Brave-Browser',
            'NOTEBOOKLM_BROWSER_PROFILE_DIRECTORY': 'Default',
            'NOTEBOOKLM_USE_EXISTING_PROFILE': '1',
        },
    )
    content = env.read_text()
    assert 'NOTEBOOKLM_BROWSER_PROFILE_DIRECTORY="Default"' in content
    assert 'NOTEBOOKLM_USE_EXISTING_PROFILE="1"' in content
