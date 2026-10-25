"""Outbound telemetry companion: observes terminal status, never controls workflow execution."""
from __future__ import annotations
import argparse
import asyncio
import json
import os
from pathlib import Path
import time
import uuid

from v3_engine.local_worker.client import LocalWorkerHttpClient
from v3_engine.local_worker.config import load_config
from v3_engine.local_worker.terminal import TerminalPipelineAdapter
from v3_engine.notebooklm.credentials import CredentialsValidationError, NotebookCredentialAuthority
from v3_engine.notebooklm.ports import AuthenticationRequiredError, ProviderError
from v3_engine.notebooklm.terminal_service import NotebookTerminalService


def check_provider_health_once(client):
    """Answer queued read-only health probes through the shared NotebookLM profile."""
    authority = NotebookCredentialAuthority()
    for probe in client.get_provider_health_requests():
        probe_id = probe.get('probe_id')
        profile = authority.profile_status()
        generation = int(profile.get('generation') or 0)
        proof_id = authority.proof_notebook_id()
        report = {
            'probe_id': probe_id,
            'state': 'authentication_required',
            'safe_code': 'profile_unverified',
            'profile_generation': generation,
        }
        if proof_id:
            try:
                asyncio.run(NotebookTerminalService().verify_session(proof_id))
                profile = authority.profile_status()
                generation = int(profile.get('generation') or 0)
                report.update(state='ready', safe_code='ok', profile_generation=generation)
            except CredentialsValidationError as exc:
                code = exc.code if exc.code in {'account_mismatch', 'authentication_required'} else 'authentication_required'
                report.update(state='authentication_required', safe_code=code, profile_generation=generation)
            except AuthenticationRequiredError as exc:
                code = 'account_mismatch' if exc.code == 'account_mismatch' else 'authentication_required'
                report.update(state='authentication_required', safe_code=code, profile_generation=generation)
            except ProviderError:
                report.update(state='unavailable', safe_code='provider_unavailable', profile_generation=generation)
            except Exception:
                # Never serialize provider exceptions, which can contain request details.
                report.update(state='unavailable', safe_code='provider_unavailable', profile_generation=generation)
        client.report_provider_health(report)


def publish_once(config, client, adapter):
    workspace = Path(config.workspace_dir)
    pending = workspace / 'pending_claim.json'
    if not pending.is_file():
        return False
    claim = client.get_claim(json.loads(pending.read_text())['claim_id'])
    if claim.get('task_kind') != 'author_prompts':
        return False
    task_id = str(uuid.UUID(claim['task_id']))
    link_path = workspace / 'tasks' / task_id / 'terminal_link.json'
    if not link_path.is_file():
        return False
    link = json.loads(link_path.read_text())
    expected_id = str(uuid.uuid5(uuid.UUID(link['job_id']), 'terminal-subject-v1'))
    if link['terminal_run_id'] != expected_id:
        raise ValueError('Terminal telemetry identity mismatch')
    snapshot = adapter.command('status', '--run-id', expected_id, '--json')
    if snapshot.get('freshness') != 'realtime' or snapshot.get('subject_run_id') != expected_id:
        return False
    payload = {'schema_version': 1, 'sequence': time.time_ns(),
               'phase': snapshot['phase'], 'status': snapshot['status'],
               'current_pdf_ordinal': snapshot.get('current_pdf_ordinal')}
    for key in ['lessons_saved', 'scenes_generated', 'scenes_built', 'pdfs_completed', 'total_pdfs']:
        payload[key] = int(snapshot.get(key) or 0)
    client.report_progress(task_id, payload)
    return True


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', type=Path, required=True)
    args = parser.parse_args()
    config = load_config(args.config)
    client = LocalWorkerHttpClient(config.server_origin, config.device_token)
    adapter = TerminalPipelineAdapter(Path(config.terminal_manifest_path or '.'),
                                      terminal_root=Path(os.environ['V3_TERMINAL_ROOT']))
    while True:
        try:
            check_provider_health_once(client)
        except Exception as exc:
            # Health checks are auxiliary and must not stall progress telemetry.
            print(f'NotebookLM health probe unavailable ({type(exc).__name__}); execution was not changed.', flush=True)
        try:
            publish_once(config, client, adapter)
        except Exception as exc:
            # Transport exceptions can embed private headers. Log only type.
            print(f'Progress report unavailable ({type(exc).__name__}); execution was not changed.', flush=True)
        time.sleep(5)


if __name__ == '__main__':
    main()
