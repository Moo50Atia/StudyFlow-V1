#!/usr/bin/env python3
"""Read-only NotebookLM connectivity check. Never launches a browser or prints content."""
from __future__ import annotations
import argparse
import asyncio
import os
import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from v3_engine.sources.mcp_client import NotebookLmMcpClient, McpToolError
from v3_engine.notebooklm.credentials import NotebookCredentialAuthority, get_storage_state_path, get_terminal_run_storage_state, validate_storage_state, CredentialsValidationError
from v3_engine.notebooklm.ports import ProviderError
from v3_engine.notebooklm.sdk_provider import SdkSessionProvider
from v3_engine.notebooklm.terminal_service import NotebookTerminalService

async def check(origin: str, notebook_id: str | None, adapter: str = "notebooklm_mcp", run_dir: Path | None = None) -> int:
    if adapter == "notebooklm_py":
        if notebook_id is None:
            notebook_id = NotebookCredentialAuthority().proof_notebook_id()
        if notebook_id is None:
            print(f"{origin}: profile_unverified. Supply --notebook-id once to bind and verify this local profile; no browser login was attempted.")
            return 2
        try:
            storage_path = get_terminal_run_storage_state(run_dir) if run_dir else get_storage_state_path()
            validate_storage_state(storage_path)
        except CredentialsValidationError as exc:
            print(f"{origin}: {exc.code}. No browser login was attempted.")
            return 2
        except Exception:
            print(f"{origin}: credential_storage_unavailable. The saved NotebookLM session could not be read. No browser login was attempted.")
            return 2

        provider = SdkSessionProvider(base_url=origin, storage_state_path=storage_path)
        try:
            # Use exactly the same ownership/authentication operation as
            # the terminal activity, through its shared service boundary.
            await asyncio.wait_for(NotebookTerminalService(session_provider=provider).verify_session(
                notebook_id, storage_path=storage_path), 35)
            print(f"{origin}: requested notebook ownership verification succeeded (adapter: notebooklm_py). Content was not printed.")
            return 0
        except (ProviderError, CredentialsValidationError) as exc:
            print(f"{origin}: {exc.code}. No browser login was attempted.")
            guidance = {
                "authentication_required": "NotebookLM rejected the session or could not establish an authenticated session. Refresh the NotebookLM cookie and check again.",
                "account_mismatch": "The signed-in account does not own the configured proof notebook. Check the account and notebook access.",
                "notebook_verification_failed": "The configured proof notebook could not be read. Check its link and access.",
                "ownership_unverified": "NotebookLM did not return ownership information. Ownership verification did not pass.",
                "ownership_check_failed": "NotebookLM ownership verification failed unexpectedly. Try the read-only check again.",
                "provider_unavailable": "NotebookLM is unavailable or the connection failed. Try the check again when connectivity recovers.",
                "provider_rate_limit": "NotebookLM rate limited the check. Wait before checking again.",
            }.get(exc.code)
            if guidance:
                print(guidance)
            return 2
        except (OSError, RuntimeError, TimeoutError):
            print(f"{origin}: transport unavailable or timed out. Check network connectivity.")
            return 2

    # Prefer the explicitly imported cache over potentially stale shell overrides.
    env = dict(os.environ, NOTEBOOKLM_ORIGIN=origin)
    for key in ('NOTEBOOKLM_COOKIES', 'NOTEBOOKLM_CSRF_TOKEN', 'NOTEBOOKLM_SESSION_ID', 'NOTEBOOKLM_BL'):
        env.pop(key, None)
    if not (Path.home() / '.notebooklm-mcp/auth.json').is_file():
        print('authentication_required: no saved NotebookLM session; no browser was opened.')
        return 2
    client = NotebookLmMcpClient(env=env)
    try:
        if notebook_id:
            notebook = await asyncio.wait_for(client.notebook_get(notebook_id), 35)
            if notebook.get('id') != notebook_id:
                raise McpToolError('invalid_response')
            print(f'{origin}: requested notebook read succeeded. Content was not printed.')
        else:
            notebooks = await asyncio.wait_for(client.notebook_list(), 35)
            print(f'{origin}: library read succeeded ({len(notebooks)} entries). Account identity not verified.')
        return 0
    except McpToolError as exc:
        print(f'{origin}: {exc.code}. No browser login was attempted.')
        return 2
    except (OSError, RuntimeError, TimeoutError):
        print(f'{origin}: transport unavailable or timed out. Check local MCP build and connectivity.')
        return 2
    finally:
        await client.close()

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    from v3_engine.local_env import load_local_env
    load_local_env(Path(__file__).resolve().parents[2] / '.env')
    configured_origin = os.getenv('NOTEBOOKLM_ORIGIN') or os.getenv('NOTEBOOKLM_BASE_URL') or 'https://notebooklm.google.com'
    parser.add_argument('--origin', choices=['https://notebooklm.google.com', 'https://notebook.google.com'], default=configured_origin)
    parser.add_argument('--notebook-id', help='Existing notebook to verify; never creates one')
    parser.add_argument('--adapter', choices=['notebooklm_mcp', 'notebooklm_py'], default='notebooklm_py')
    parser.add_argument('--run-dir', type=Path, help='Check this existing terminal run\'s isolated session rather than the main imported session')
    args = parser.parse_args()
    if args.run_dir and args.adapter != 'notebooklm_py':
        parser.error('--run-dir requires --adapter notebooklm_py')
    raise SystemExit(asyncio.run(check(args.origin, args.notebook_id, args.adapter, args.run_dir)))
