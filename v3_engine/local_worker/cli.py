"""Command-line interface for local processing worker (Feature 021)."""

from __future__ import annotations

import argparse
import getpass
import hashlib
import json
import os
from pathlib import Path
import platform
import shutil
import sys
import time
from typing import Any, Dict, List, Optional
import uuid
import httpx

from v3_engine.local_worker.client import LocalWorkerClientError, LocalWorkerHttpClient
from v3_engine.local_worker.config import (
    WorkerConfig,
    get_default_config_path,
    load_config,
    save_config,
)
from v3_engine.local_worker.journal import LocalEffectJournal
from v3_engine.local_worker.terminal import TerminalPipelineAdapter
from v3_engine.local_worker.lock import ExclusiveWorkspaceLock, WorkerLockError
from v3_engine.local_worker.runner import (
    NotebookLMProviderAdapter,
    OfflineProviderDouble,
    TaskRunner,
)
from v3_engine.local_worker.protocol import (
    validate_static_three_file_package,
    LocalWorkerProtocolError,
    strict_json_decode,
    validate_input_manifest,
)
from v3_engine.local_worker.execution_context import WorkspaceCapacityError, ensure_workspace_capacity


def cmd_enroll(args: argparse.Namespace) -> int:
    server_origin = args.server.rstrip("/")
    pairing_code = args.pairing_code
    if not pairing_code:
        pairing_code = getpass.getpass("Enter pairing secret: ").strip()

    if not pairing_code:
        print("Error: pairing code is required", file=sys.stderr)
        return 1

    device_name = args.device_name or platform.node() or "local-worker-device"
    device_uuid = str(uuid.uuid4())
    os_family = platform.system().lower()

    client = LocalWorkerHttpClient(server_origin=server_origin)
    try:
        res = client.redeem_enrollment(
            pairing_secret=pairing_code,
            device_name=device_name,
            device_uuid=device_uuid,
            os_family=os_family,
        )
    except LocalWorkerClientError as e:
        print(f"Enrollment failed: {e}", file=sys.stderr)
        return 1

    config = WorkerConfig(
        server_origin=server_origin,
        device_id=res["device_id"],
        device_token=res["device_token"],
        workspace_dir=str(Path(args.workspace or (Path.home() / ".local_worker" / "workspace")).resolve()),
        capabilities=res.get("allowed_capabilities", ["notebooklm_authoring_v1", "scene_return_v1"]),
        terminal_manifest_path=str(Path(args.terminal_manifest).expanduser().resolve()) if getattr(args, 'terminal_manifest', None) else None,
    )

    config_path = Path(args.config) if args.config else get_default_config_path()
    save_config(config, config_path)

    out = {
        "status": "enrolled",
        "device_id": config.device_id,
        "server_origin": config.server_origin,
        "config_path": str(config_path),
        "capabilities": config.capabilities,
    }
    print(json.dumps(out, indent=2))
    return 0


def cmd_doctor(args: argparse.Namespace) -> int:
    config_path = Path(args.config) if args.config else get_default_config_path()
    checks: Dict[str, Any] = {}
    healthy = True

    # 1. Config check
    if not config_path.is_file():
        checks["config_file"] = {"status": "fail", "detail": f"Missing config file at {config_path}"}
        healthy = False
    else:
        st = config_path.stat()
        perm = oct(st.st_mode)[-3:]
        # Strict 0600 on POSIX
        if os.name == "posix" and (st.st_mode & 0o077) != 0:
            checks["config_file"] = {
                "status": "warning",
                "detail": f"Config permissions are {perm}, expected 0600",
            }
        else:
            checks["config_file"] = {"status": "ok", "path": str(config_path)}

    try:
        config = load_config(config_path) if config_path.is_file() else None
    except Exception as e:
        config = None
        checks["config_parse"] = {"status": "fail", "detail": str(e)}
        healthy = False

    # 2. Workspace check
    workspace_dir = Path(config.workspace_dir) if config else (Path.home() / ".local_worker" / "workspace")
    try:
        workspace_dir.mkdir(parents=True, exist_ok=True)
        # Check write permissions
        test_file = workspace_dir / ".doctor_test"
        test_file.write_text("ok", encoding="utf-8")
        test_file.unlink()

        # Disk space check (>= 1GB free)
        free_bytes = shutil.disk_usage(workspace_dir).free
        checks["workspace"] = {
            "status": "ok" if free_bytes > 1024 * 1024 * 1024 else "warning",
            "path": str(workspace_dir),
            "free_bytes": free_bytes,
        }
    except Exception as e:
        checks["workspace"] = {"status": "fail", "detail": str(e)}
        healthy = False

    # 3. Journal DB check
    journal_path = workspace_dir / "journal.sqlite"
    try:
        journal = LocalEffectJournal(journal_path)
        checks["journal"] = {"status": "ok", "path": str(journal_path)}
    except Exception as e:
        checks["journal"] = {"status": "fail", "detail": str(e)}
        healthy = False

    # 4. Server connectivity check (secret-free)
    if config:
        client = LocalWorkerHttpClient(server_origin=config.server_origin, device_token=config.device_token)
        try:
            device_info = client.get_device()
            checks["server_connection"] = {
                "status": "ok",
                "server_origin": config.server_origin,
                "server_time": device_info.get("server_time"),
                "status_code": 200,
            }
        except Exception as e:
            checks["server_connection"] = {"status": "fail", "detail": str(e)}
            healthy = False
    else:
        checks["server_connection"] = {"status": "skipped", "detail": "No valid config"}

    # 5. Local provider credentials check (secret-free)
    from v3_engine.notebooklm.credentials import NotebookCredentialAuthority, get_storage_state_path, validate_storage_state
    cred_file = get_storage_state_path()
    if cred_file.is_file():
        try:
            validate_storage_state(cred_file)
            profile = NotebookCredentialAuthority(cred_file).profile_status()
            checks["provider_credentials"] = {
                "status": "ok" if profile["verified"] else "warning",
                "detail": "NotebookLM profile ownership was verified" if profile["verified"] else "Session structure is valid; verify the intended NotebookLM account before provider work",
                "profile_generation": profile["generation"],
                "verified_at": profile["verified_at"],
            }
        except Exception:
            checks["provider_credentials"] = {"status": "fail", "detail": "Existing terminal session file is invalid; use the existing terminal credential setup"}
            healthy = False
    else:
        checks["provider_credentials"] = {"status": "info", "detail": "Existing terminal session file not found; use the terminal credential setup"}

    if args.check_provider_live:
        checks["provider_live"] = {"status": "not_run", "detail": "Live provider check requires user authorization"}

    out = {
        "healthy": healthy,
        "checks": checks,
    }
    print(json.dumps(out, indent=2))
    return 0 if healthy else 2


def collect_requested_recovery(client, runner, workspace_dir: Path, task_id: str) -> bool:
    """An owner-issued read-only assignment lets the daemon collect evidence.

    Continuing still requires the owner's separate resolve-terminal request.
    """
    assignment = client.get_assignment(task_id)
    if assignment.get('assignment_status') != 'reconciliation_only':
        return False
    marker = workspace_dir / 'tasks' / task_id / 'reconciliation_assignment.json'
    identity = {key: assignment[key] for key in ('assignment_id', 'assignment_epoch')}
    if marker.is_file() and json.loads(marker.read_text()) == identity:
        return False
    runner.reconcile_task(task_id)
    from v3_engine.notebooklm.terminal_service import atomic_json
    atomic_json(marker, identity)
    return True


def cmd_run(args: argparse.Namespace) -> int:
    config_path = Path(args.config) if args.config else get_default_config_path()
    try:
        config = load_config(config_path)
    except Exception as e:
        print(f"Error loading configuration: {e}", file=sys.stderr)
        return 1

    workspace_dir = Path(config.workspace_dir).expanduser().resolve()
    workspace_dir.mkdir(parents=True, exist_ok=True)
    os.environ["LOCAL_WORKER_CONFIG"] = str(config_path.expanduser().resolve())
    os.environ["LOCAL_WORKER_WORKSPACE_BUDGET_BYTES"] = str(config.workspace_budget_bytes)
    os.environ["LOCAL_WORKER_WORKSPACE_SAFETY_MARGIN_BYTES"] = str(config.workspace_safety_margin_bytes)

    try:
        lock = ExclusiveWorkspaceLock(workspace_dir)
        lock.acquire()
    except WorkerLockError as e:
        print(f"Lock error: {e}", file=sys.stderr)
        return 3

    drain_file = workspace_dir / "drain.signal"
    if drain_file.exists():
        drain_file.unlink()

    pending_claim_file = workspace_dir / "pending_claim.json"

    def drain_check() -> bool:
        return drain_file.exists()

    client = LocalWorkerHttpClient(server_origin=config.server_origin, device_token=config.device_token)
    journal = LocalEffectJournal(workspace_dir / "journal.sqlite")

    if args.offline_double:
        provider_adapter = OfflineProviderDouble()
    else:
        template = getattr(args, 'terminal_manifest', None) or config.terminal_manifest_path or os.environ.get('V3_LOCAL_WORKER_TERMINAL_MANIFEST')
        if not template:
            print('Pass --terminal-manifest with your existing working terminal submission manifest.', file=sys.stderr)
            lock.release()
            return 1
        try:
            provider_adapter = TerminalPipelineAdapter(Path(template))
            provider_adapter.validate_local_template()
        except (OSError, ValueError, RuntimeError) as exc:
            print(f'Invalid local terminal template: {exc}', file=sys.stderr)
            lock.release()
            return 1
    runner = TaskRunner(client=client, journal=journal, workspace_dir=workspace_dir, provider_adapter=provider_adapter)

    print(f"Local worker daemon started for device {config.device_id}. Origin: {config.server_origin}")
    exit_code = 0
    capacity_retry_after = 0.0

    try:
        while True:
            if drain_check():
                print("Drain signal detected; shutting down cleanly.")
                drain_file.unlink()
                break

            if time.monotonic() < capacity_retry_after:
                time.sleep(min(5.0, capacity_retry_after - time.monotonic()))
                continue

            try:
                # Do not take a lease on a device whose configured workspace
                # is already exhausted. The manifest-sized reservation runs
                # again after claim and before any input download.
                ensure_workspace_capacity(
                    workspace_dir, budget_bytes=config.workspace_budget_bytes,
                    safety_margin_bytes=config.workspace_safety_margin_bytes,
                )
            except WorkspaceCapacityError as exc:
                print(str(exc), file=sys.stderr)
                if args.once:
                    exit_code = 4
                    break
                time.sleep(args.poll_interval or 5.0)
                continue

            # Recover pending claim or generate fresh one (LW-R12)
            claim_id = None
            if pending_claim_file.is_file():
                try:
                    pending_data = json.loads(pending_claim_file.read_text(encoding="utf-8"))
                    claim_id = pending_data.get("claim_id")
                    if not isinstance(claim_id, str) or not claim_id:
                        raise ValueError("missing claim_id")
                except Exception as e:
                    print(f"Pending claim checkpoint is invalid; refusing a new claim: {e}", file=sys.stderr)
                    return 1

            if not claim_id:
                claim_id = str(uuid.uuid4())
                temp_claim_file = pending_claim_file.with_suffix(".tmp")
                temp_claim_file.write_text(json.dumps({"claim_id": claim_id}), encoding="utf-8")
                os.chmod(temp_claim_file, 0o600)
                temp_claim_file.replace(pending_claim_file)

            try:
                claim_res = client.claim(claim_id=claim_id, capabilities=config.capabilities)
            except (LocalWorkerClientError, httpx.TransportError) as e:
                print(f"Claim request error: {e}", file=sys.stderr)
                if args.once:
                    exit_code = 2
                    break
                time.sleep(5.0)
                continue

            if not claim_res:
                pending_claim_file.unlink(missing_ok=True)
                if args.once:
                    print("No task available (204). Exiting (--once).")
                    break
                time.sleep(args.poll_interval or 5.0)
                continue

            task_id = claim_res["task_id"]
            task_kind = claim_res["task_kind"]
            print(f"Claimed task {task_id} ({task_kind})")
            if claim_res.get('task_status') == 'queued' and claim_res.get('assignment_status') in ('expired', 'released'):
                pending_claim_file.unlink(missing_ok=True)
                continue
            if claim_res.get('task_status') in ('reconciliation_required', 'paused_auth', 'paused_quota', 'paused', 'cancelled'):
                if claim_res.get('task_status') in ('reconciliation_required', 'paused_auth', 'paused_quota'):
                    try:
                        if collect_requested_recovery(client, runner, workspace_dir, task_id):
                            print(f'Saved terminal evidence submitted for task {task_id}; waiting for owner Continue.')
                    except Exception as exc:
                        print(f'Worker recovery check remains held: {exc}', file=sys.stderr)
                print(f"Task held: {claim_res['task_status']}. Inspect the existing run; no processing was started.", file=sys.stderr)
                if claim_res.get('task_status') == 'cancelled':
                    # Cancellation is terminal. Keep local receipts/journal for
                    # audit, but do not pin the daemon to this claim checkpoint.
                    pending_claim_file.unlink(missing_ok=True)
                if args.once:
                    exit_code = 4
                    break
                time.sleep(args.poll_interval or 5.0)
                continue

            try:
                if task_kind == "author_prompts":
                    receipt = runner.run_authoring_stage(claim_res, drain_check=drain_check)
                    print(f"Task {task_id} authoring stage completed: {receipt.get('result_id')}")
                    handoff_path = workspace_dir / 'runs' / claim_res['run_id'] / 'run.json'
                    if handoff_path.is_file():
                        print(f'Manual generation handoff: {handoff_path}')
                elif task_kind == "return_scenes":
                    receipt = runner.run_scene_return_stage(claim_res, operator_provenance={
                        "operator_id": getattr(args, "operator_id", None) or claim_res.get('platform_owner_id'),
                        "provider": getattr(args, "provider", None),
                        "model": getattr(args, "model", None),
                        "effort": getattr(args, "effort", None),
                    })
                    print(f"Task {task_id} scene return stage completed: {receipt.get('result_id')}")
                else:
                    print(f"Unknown task kind '{task_kind}', releasing.", file=sys.stderr)
                    client.release(task_id, reason="unsupported_task_kind", assignment_id=claim_res["assignment_id"], assignment_epoch=claim_res["assignment_epoch"])
                    pending_claim_file.unlink(missing_ok=True)
                    if args.once:
                        break
                    continue
                # Remove the claim checkpoint only after the server acknowledges
                # the durable result receipt.
                pending_claim_file.unlink(missing_ok=True)
                exit_code = 0
            except Exception as e:
                if drain_check():
                    print(f'Task {task_id} stopped at a drain boundary; its checkpoint was retained.')
                    break
                if isinstance(e, WorkspaceCapacityError):
                    print(str(e), file=sys.stderr)
                    try:
                        client.release(task_id, reason="workspace_capacity",
                                       assignment_id=claim_res["assignment_id"],
                                       assignment_epoch=claim_res["assignment_epoch"])
                        pending_claim_file.unlink(missing_ok=True)
                    except Exception as release_error:
                        print(f"Workspace hold release was not acknowledged; retaining the claim checkpoint: {release_error}", file=sys.stderr)
                    capacity_retry_after = time.monotonic() + 30.0
                    exit_code = 4
                    if args.once:
                        break
                    continue
                print(f"Error executing task {task_id}: {e}", file=sys.stderr)
                if task_kind == 'return_scenes' and isinstance(e, LocalWorkerProtocolError) and e.code == 'missing_packages':
                    client.release(task_id, reason='manual_scenes_missing', assignment_id=claim_res['assignment_id'], assignment_epoch=claim_res['assignment_epoch'])
                    pending_claim_file.unlink(missing_ok=True)
                    print('Run is waiting for generated scene files. Request Pull again after generation.', file=sys.stderr)
                    if args.once:
                        break
                    continue
                print("Keeping the claim checkpoint for an idempotent retry; assignment was not released.", file=sys.stderr)
                exit_code = 2
                if not args.once:
                    time.sleep(args.poll_interval or 5.0)

            if args.once:
                break

    finally:
        lock.release()

    return exit_code


def cmd_status(args: argparse.Namespace) -> int:
    config_path = Path(args.config) if args.config else get_default_config_path()
    try:
        config = load_config(config_path)
    except Exception as e:
        print(f"Error loading configuration: {e}", file=sys.stderr)
        return 1

    workspace_dir = Path(config.workspace_dir).expanduser().resolve()
    lock_file = workspace_dir / "worker.lock"
    is_running = False
    if lock_file.exists():
        try:
            with open(lock_file, "r") as f:
                pid = f.read().strip()
                if pid:
                    os.kill(int(pid), 0)
                    is_running = True
        except (OSError, ValueError):
            is_running = False

    journal_path = workspace_dir / "journal.sqlite"
    pending_effects_count = 0
    if journal_path.exists():
        try:
            j = LocalEffectJournal(journal_path)
            # count pending
            with j._get_connection() as conn:
                cur = conn.execute("SELECT COUNT(*) FROM local_effects WHERE status NOT IN ('succeeded', 'failed_no_effect')")
                pending_effects_count = cur.fetchone()[0]
        except Exception:
            pass

    out = {
        "device_id": config.device_id,
        "server_origin": config.server_origin,
        "workspace_dir": str(workspace_dir),
        "daemon_running": is_running,
        "pending_effects_in_journal": pending_effects_count,
    }
    print(json.dumps(out, indent=2))
    return 0


def cmd_stop(args: argparse.Namespace) -> int:
    config_path = Path(args.config) if args.config else get_default_config_path()
    try:
        config = load_config(config_path)
    except Exception as e:
        print(f"Error loading configuration: {e}", file=sys.stderr)
        return 1

    workspace_dir = Path(config.workspace_dir).expanduser().resolve()
    drain_file = workspace_dir / "drain.signal"
    drain_file.write_text("drain", encoding="utf-8")
    print(f"Drain signal written to {drain_file}. Daemon will complete current step and stop.")
    return 0


def cmd_export_prompts(args: argparse.Namespace) -> int:
    job_id = args.job
    output_dir = Path(args.output).expanduser().resolve()
    output_dir.mkdir(parents=True, exist_ok=True)

    config_path = Path(args.config) if args.config else get_default_config_path()
    try:
        config = load_config(config_path)
    except Exception as e:
        print(f"Error loading configuration: {e}", file=sys.stderr)
        return 1

    workspace_dir = Path(config.workspace_dir).expanduser().resolve()

    # Only a server-returned scene manifest (created after authoring acceptance)
    # may authorize export. Local authoring checkpoints are not accepted prompts.
    job_dir = workspace_dir / "jobs" / job_id
    scene_manifest_file = job_dir / "scene_return_manifest.json"
    digest_file = job_dir / "scene_return_manifest.sha256"
    if not scene_manifest_file.is_file() or not digest_file.is_file():
        print("No verified server scene-return manifest is available; prompt export is not authorized yet.", file=sys.stderr)
        return 1
    try:
        manifest_bytes = scene_manifest_file.read_bytes()
        expected_sha = digest_file.read_text(encoding="ascii").strip()
        actual_sha = hashlib.sha256(manifest_bytes).hexdigest()
        if expected_sha != actual_sha:
            raise LocalWorkerProtocolError("Scene-return manifest digest mismatch", code="manifest_digest_mismatch")
        s_data = strict_json_decode(manifest_bytes, max_size=2097152)
        validate_input_manifest(s_data)
        if s_data.get("job_id") != job_id or s_data.get("task_kind") != "return_scenes":
            raise LocalWorkerProtocolError("Scene-return manifest does not belong to the selected job", code="manifest_job_mismatch")
        scenes = s_data.get("required_scenes")
        if not isinstance(scenes, list) or not scenes:
            raise LocalWorkerProtocolError("Scene-return manifest has no required scenes", code="empty_required_scenes")
    except Exception as e:
        print(f"Cannot export prompts from the server scene-return manifest: {e}", file=sys.stderr)
        return 1

    prompts_to_export: List[Dict[str, Any]] = []
    seen_scene_ids = set()
    seen_ordinals = set()
    for idx, sc in enumerate(scenes):
        if not isinstance(sc, dict):
            print(f"Invalid scene binding at index {idx}.", file=sys.stderr)
            return 1
        prompt_text = sc.get("prompt_text")
        prompt_sha = sc.get("prompt_sha256")
        scene_id = sc.get("scene_id")
        prompt_version_id = sc.get("prompt_version_id")
        ordinal = sc.get("scene_ordinal", idx)
        order_key = (sc.get('item_id'), sc.get('lesson_ordinal'), ordinal)
        if not isinstance(prompt_text, str) or not prompt_text.strip() or not isinstance(prompt_sha, str):
            print(f"Scene binding {idx} lacks exact prompt text or digest.", file=sys.stderr)
            return 1
        if hashlib.sha256(prompt_text.encode("utf-8")).hexdigest() != prompt_sha:
            print(f"Scene binding {idx} prompt digest mismatch.", file=sys.stderr)
            return 1
        if not isinstance(scene_id, str) or not isinstance(prompt_version_id, str):
            print(f"Scene binding {idx} lacks stable scene/prompt-version IDs.", file=sys.stderr)
            return 1
        if isinstance(ordinal, bool) or not isinstance(ordinal, int) or ordinal < 0 or order_key in seen_ordinals or scene_id in seen_scene_ids:
            print(f"Scene binding {idx} has duplicate or invalid identity/order.", file=sys.stderr)
            return 1
        seen_scene_ids.add(scene_id)
        seen_ordinals.add(order_key)
        prompts_to_export.append({
            "scene_ordinal": ordinal,
            'item_id': sc.get('item_id'), 'lesson_ordinal': sc.get('lesson_ordinal'),
            "scene_id": scene_id,
            "scene_title": sc.get("scene_title") or f"Scene {idx+1}",
            "prompt_text": prompt_text,
            "prompt_sha256": prompt_sha,
            "prompt_version_id": prompt_version_id,
        })

    # The sealed required_scenes inventory already has PDF/lesson/scene order.

    exported_entries = []
    for idx, p in enumerate(prompts_to_export):
        target_name = f"scene_{idx+1:03d}.prompt.txt"
        target_file = output_dir / target_name
        target_file.write_text(p["prompt_text"], encoding="utf-8")
        exported_entries.append({
            "file": target_name,
            "scene_ordinal": p["scene_ordinal"],
            "scene_id": p.get("scene_id"),
            "scene_title": p.get("scene_title"),
            "prompt_sha256": p["prompt_sha256"],
            "prompt_version_id": p.get("prompt_version_id"),
        })

    manifest_file = output_dir / "prompts_manifest.json"
    manifest_file.write_text(
        json.dumps({
            "job_id": job_id,
            "exported_files_count": len(exported_entries),
            "exported_at": time.time(),
            "scenes": exported_entries,
        }, indent=2),
        encoding="utf-8",
    )

    print(json.dumps({
        "status": "exported",
        "job_id": job_id,
        "output_dir": str(output_dir),
        "exported_count": len(exported_entries),
    }, indent=2))
    return 0


def cmd_import_scenes(args: argparse.Namespace) -> int:
    job_id = args.job
    input_dir = Path(args.input).expanduser().resolve()
    if not input_dir.is_dir():
        print(f"Input directory does not exist: {input_dir}", file=sys.stderr)
        return 1

    config_path = Path(args.config) if args.config else get_default_config_path()
    try:
        config = load_config(config_path)
    except Exception as e:
        print(f"Error loading configuration: {e}", file=sys.stderr)
        return 1

    workspace_dir = Path(config.workspace_dir).expanduser().resolve()
    staging_dir = workspace_dir / "scenes" / job_id
    staging_dir.mkdir(parents=True, exist_ok=True)

    # Find static three-file packages and stage them for genuine return (LW-R22)
    valid_packages = 0
    candidate_dirs = [input_dir] + [p for p in input_dir.iterdir() if p.is_dir()]

    for cdir in candidate_dirs:
        index_f = cdir / "index.html"
        styles_f = cdir / "styles.css"
        script_f = cdir / "script.js"

        if index_f.is_file() and styles_f.is_file() and script_f.is_file():
            pkg = {
                "schema_version": 1,
                "files": [
                    {"path": "index.html", "content": index_f.read_text(encoding="utf-8", errors="replace")},
                    {"path": "styles.css", "content": styles_f.read_text(encoding="utf-8", errors="replace")},
                    {"path": "script.js", "content": script_f.read_text(encoding="utf-8", errors="replace")},
                ],
            }
            try:
                validate_static_three_file_package(pkg)

                scene_id = None
                if (cdir / "scene_id.txt").is_file():
                    scene_id = (cdir / "scene_id.txt").read_text(encoding="utf-8").strip()
                elif (cdir / "metadata.json").is_file():
                    try:
                        meta = json.loads((cdir / "metadata.json").read_text(encoding="utf-8"))
                        scene_id = meta.get("scene_id")
                    except Exception:
                        pass

                if not scene_id and cdir != input_dir:
                    scene_id = cdir.name
                if not scene_id:
                    scene_id = f"scene_{valid_packages+1:03d}"

                target_dir = staging_dir / scene_id
                target_dir.mkdir(parents=True, exist_ok=True)
                (target_dir / "index.html").write_text(index_f.read_text(encoding="utf-8"), encoding="utf-8")
                (target_dir / "styles.css").write_text(styles_f.read_text(encoding="utf-8"), encoding="utf-8")
                (target_dir / "script.js").write_text(script_f.read_text(encoding="utf-8"), encoding="utf-8")

                valid_packages += 1
            except LocalWorkerProtocolError as e:
                print(f"Warning: {cdir} invalid package: {e}", file=sys.stderr)

    print(json.dumps({
        "status": "imported_and_verified",
        "job_id": job_id,
        "input_dir": str(input_dir),
        "staged_dir": str(staging_dir),
        "valid_packages_found": valid_packages,
    }, indent=2))
    return 0 if valid_packages > 0 else 1


def cmd_reconcile(args: argparse.Namespace) -> int:
    job_id = args.job
    config_path = Path(args.config) if args.config else get_default_config_path()
    try:
        config = load_config(config_path)
    except Exception as e:
        print(f"Error loading configuration: {e}", file=sys.stderr)
        return 1

    workspace_dir = Path(config.workspace_dir).expanduser().resolve()
    journal = LocalEffectJournal(workspace_dir / "journal.sqlite")
    client = LocalWorkerHttpClient(server_origin=config.server_origin, device_token=config.device_token)
    task_id = args.task
    if not task_id:
        manifest = workspace_dir / 'jobs' / job_id / 'input_manifest.json'
        if manifest.is_file():
            task_id = json.loads(manifest.read_text())['task_id']

    if not task_id:
        print("No task ID provided or found in journal to reconcile.", file=sys.stderr)
        return 1

    lock = ExclusiveWorkspaceLock(workspace_dir)
    try:
        lock.acquire()
        manifest = json.loads((workspace_dir / 'tasks' / task_id / 'input_manifest.json').read_text())
        if manifest['job_id'] != job_id:
            raise ValueError('Task does not belong to the requested job')
        template = config.terminal_manifest_path
        link_path = workspace_dir / 'tasks' / task_id / 'terminal_link.json'
        adapter = None
        if link_path.is_file():
            link = json.loads(link_path.read_text())
            adapter = TerminalPipelineAdapter(Path(template or link['manifest_path']), terminal_root=Path(link['terminal_root']))
        runner = TaskRunner(client=client, journal=journal, workspace_dir=workspace_dir, provider_adapter=adapter)
        res = runner.reconcile_task(task_id)
    except Exception as exc:
        print(f'Reconciliation remains held: {exc}', file=sys.stderr)
        return 4
    finally:
        lock.release()
    print(json.dumps(res, indent=2))
    return 0 if not res.get("requires_owner_override") else 4


def main() -> None:
    parser = argparse.ArgumentParser(prog="local-worker", description="Local Processing Worker CLI (Feature 021)")
    subparsers = parser.add_subparsers(dest="command", required=True)

    # enroll
    p_enroll = subparsers.add_parser("enroll", help="Enroll device with pairing secret")
    p_enroll.add_argument("--server", required=True, help="Server origin URL (HTTPS/HTTP)")
    p_enroll.add_argument("--pairing-code", default=None, help="Masked pairing code")
    p_enroll.add_argument("--device-name", default=None, help="Display name for this device")
    p_enroll.add_argument("--workspace", default=None, help="Durable workspace directory")
    p_enroll.add_argument("--config", default=None, help="Path to config.json")
    p_enroll.add_argument('--terminal-manifest', default=None, help='Existing local terminal submission manifest used as the settings template')
    p_enroll.set_defaults(func=cmd_enroll)

    # doctor
    p_doctor = subparsers.add_parser("doctor", help="Check local environment and connectivity")
    p_doctor.add_argument("--config", default=None, help="Path to config.json")
    p_doctor.add_argument("--check-provider-live", action="store_true", help="Live provider connection check")
    p_doctor.set_defaults(func=cmd_doctor)

    # run
    p_run = subparsers.add_parser("run", help="Run outbound processing daemon")
    p_run.add_argument("--config", default=None, help="Path to config.json")
    p_run.add_argument('--terminal-manifest', default=None, help='Existing local terminal submission manifest; overrides the enrolled local config')
    p_run.add_argument("--once", action="store_true", help="Process at most one task then exit")
    p_run.add_argument("--offline-double", action="store_true", help="Use deterministic offline provider double")
    p_run.add_argument("--poll-interval", type=float, default=5.0, help="Poll interval in seconds")
    p_run.add_argument("--operator-id", type=int, default=None, help="Teacher account ID responsible for manual scene authoring")
    p_run.add_argument("--provider", default=None, help="Operator-reported provider used for manual scene authoring")
    p_run.add_argument("--model", default=None, help="Operator-reported model used for manual scene authoring")
    p_run.add_argument("--effort", default=None, help="Operator-reported effort level used for manual scene authoring")
    p_run.set_defaults(func=cmd_run)

    # status
    p_status = subparsers.add_parser("status", help="Get worker and journal status")
    p_status.add_argument("--config", default=None, help="Path to config.json")
    p_status.set_defaults(func=cmd_status)

    # stop
    p_stop = subparsers.add_parser("stop", help="Stop running worker daemon")
    p_stop.add_argument("--drain", action="store_true", required=True, help="Drain in-flight work and stop")
    p_stop.add_argument("--config", default=None, help="Path to config.json")
    p_stop.set_defaults(func=cmd_stop)

    # export-prompts
    p_export = subparsers.add_parser("export-prompts", help="Export exact prompts for a job")
    p_export.add_argument("--job", required=True, help="Job ID")
    p_export.add_argument("--output", required=True, help="Output directory")
    p_export.add_argument("--config", default=None, help="Path to config.json")
    p_export.set_defaults(func=cmd_export_prompts)

    # import-scenes
    p_import = subparsers.add_parser("import-scenes", help="Verify and import static scene packages")
    p_import.add_argument("--job", required=True, help="Job ID")
    p_import.add_argument("--input", required=True, help="Input directory")
    p_import.add_argument("--config", default=None, help="Path to config.json")
    p_import.set_defaults(func=cmd_import_scenes)

    # reconcile
    p_rec = subparsers.add_parser("reconcile", help="Read-first recovery for task effects")
    p_rec.add_argument("--job", required=True, help="Job ID")
    p_rec.add_argument("--task", default=None, help="Task ID")
    p_rec.add_argument("--config", default=None, help="Path to config.json")
    p_rec.set_defaults(func=cmd_reconcile)

    parsed = parser.parse_args()
    code = parsed.func(parsed)
    sys.exit(code)


if __name__ == "__main__":
    from v3_engine.local_env import load_local_env
    local_env = Path(__file__).resolve().parents[2] / '.env'
    if os.environ.get('V3_TERMINAL_LOAD_PROJECT_ENV', '1') != '0' and local_env.is_file():
        load_local_env(local_env)
    main()
