"""Task runner, stage context resolver, heartbeat loop, and pre-send dispatch fence."""

from __future__ import annotations

import hashlib
import json
import logging
import os
from pathlib import Path
import threading
import time
from typing import Any, Dict, List, Optional
import uuid

from v3_engine.local_worker.client import LocalWorkerClientError, LocalWorkerHttpClient
from v3_engine.local_worker.journal import LocalEffectJournal
from v3_engine.local_worker.providers import OfflineProviderDouble, ProviderAdapter, NotebookLMProviderAdapter
from v3_engine.local_worker.protocol import (
    LocalWorkerProtocolError,
    strict_json_decode,
    validate_authoring_result,
    validate_input_manifest,
    validate_scene_result,
    validate_static_three_file_package,
    validate_scene_package,
    validate_runtime_settings,
    validate_captured_protocol_bundle,
)
from v3_engine.notebooklm.ports import (
    AuthenticationRequiredError,
    ProviderError,
    ProviderRateLimitError,
)

logger = logging.getLogger("v3_engine.local_worker.runner")


from v3_engine.local_worker.execution_context import (
    HeartbeatManager,
    HeartbeatStaleError,
    LeaseExpiredError,
    PermitExpiredError,
    ReconciliationRequiredError,
    StageContext,
)
class TaskRunner:
    def __init__(
        self,
        client: LocalWorkerHttpClient,
        journal: LocalEffectJournal,
        workspace_dir: Path,
        provider_adapter: Optional[ProviderAdapter] = None,
    ):
        self.client = client
        self.journal = journal
        self.workspace_dir = Path(workspace_dir).expanduser().resolve()
        self.provider_adapter = provider_adapter or OfflineProviderDouble()
        # A PID can be reused after process exit or machine reboot. Use a
        # random incarnation token so an old dispatch receipt can never be
        # replayed by a new worker process that happens to have the same PID.
        self.process_incarnation_id = str(uuid.uuid4())

    def _retry_saved_result(self, claim_data: Dict[str, Any]) -> Optional[Dict[str, Any]]:
        """Replay a durable result before any new work after a worker restart."""
        task_id = str(claim_data.get("task_id"))
        result_path = self.workspace_dir / "tasks" / task_id / "result_manifest.json"
        if not result_path.is_file():
            return None

        payload = strict_json_decode(result_path.read_bytes(), max_size=64 * 1024 * 1024)
        if not isinstance(payload, dict):
            raise LocalWorkerProtocolError("Saved result must be a JSON object", code="invalid_saved_result")

        expected_kind = {
            "author_prompts": "local_worker_authoring_result",
            "return_scenes": "local_worker_scene_result",
        }.get(claim_data.get("task_kind"))
        if not expected_kind or payload.get("kind") != expected_kind:
            raise LocalWorkerProtocolError("Saved result kind does not match the claimed task", code="saved_result_task_mismatch")

        expected = {
            "task_id": task_id,
            "job_id": str(claim_data.get("job_id")),
            "run_id": str(claim_data.get("run_id")),
            "input_manifest_sha256": str(claim_data.get("input_manifest_sha256")),
        }
        for field, value in expected.items():
            if payload.get(field) != value:
                raise LocalWorkerProtocolError(
                    f"Saved result {field} does not match the claimed task",
                    code="saved_result_task_mismatch",
                )

        if (payload.get('assignment_id') != claim_data['assignment_id']
                or payload.get('assignment_epoch') != claim_data['assignment_epoch']):
            if (claim_data.get('assignment_status') != 'active' or claim_data.get('task_status') not in ('leased', 'running')
                    or int(claim_data['assignment_epoch']) <= int(payload['assignment_epoch'])):
                raise LocalWorkerProtocolError('Saved result requires a newer active assignment', code='saved_result_task_mismatch')
            # A completed result is never requeued by the host. An uncommitted
            # retained result may be fenced to a fresh assignment without any
            # provider work or changes to its actual output/artifact bytes.
            payload['assignment_id'] = claim_data['assignment_id']
            payload['assignment_epoch'] = claim_data['assignment_epoch']
            if expected_kind == 'local_worker_scene_result':
                payload['control_version'] = claim_data['control_version']
            from v3_engine.notebooklm.terminal_service import atomic_json
            atomic_json(result_path, payload)

        if expected_kind == "local_worker_authoring_result":
            validate_authoring_result(payload)
        else:
            validate_scene_result(payload)

        return self.client.submit_result(
            task_id=task_id,
            result_payload=payload,
            assignment_id=str(claim_data["assignment_id"]),
            assignment_epoch=int(claim_data["assignment_epoch"]),
        )

    def execute_effect(
        self,
        ctx: StageContext,
        hb: HeartbeatManager,
        operation_kind: str,
        logical_effect_key: str,
        request_payload: Dict[str, Any],
        inputs: Dict[str, Any],
        pdf_ordinal: Optional[int] = None,
        lesson_ordinal: Optional[int] = None,
        scene_ordinal: Optional[int] = None,
        drain_check: Optional[Any] = None,
    ) -> Dict[str, Any]:
        if drain_check and drain_check():
            raise RuntimeError("Drain requested; stopping before starting the next provider effect")

        # 1. Enforce lease and heartbeat health before dispatch
        if not hb.is_lease_healthy():
            if hb.is_paused:
                raise RuntimeError("Task is paused on server; halting effect dispatch")
            if hb.is_cancelled:
                raise RuntimeError("Task is cancelled on server; halting effect dispatch")
            raise LeaseExpiredError("Server lease or heartbeat unhealthy (must have >=30s lease and <=30s heartbeat)")

        request_bytes = json.dumps(request_payload, sort_keys=True).encode("utf-8")
        request_digest = hashlib.sha256(request_bytes).hexdigest()

        if operation_kind != 'terminal_pipeline_export':
            for effect in self.journal.list_pending_effects(ctx.task_id):
                if effect.logical_effect_key == logical_effect_key and effect.status in ('may_have_been_sent', 'unknown', 'dispatching'):
                    raise ReconciliationRequiredError('Existing provider effect requires reconciliation')

        client_effect_uuid = str(uuid.uuid4())

        # Step 1: Record intent in local SQLite journal
        journal_eff = self.journal.record_intent(
            effect_id=client_effect_uuid,
            task_id=ctx.task_id,
            logical_effect_key=logical_effect_key,
            operation_kind=operation_kind,
            request_digest=request_digest,
        )
        effect_id = journal_eff.effect_id

        # Step 2: Reserve on server
        reserve_res = self.client.reserve_effect(
            task_id=ctx.task_id,
            effect_id=effect_id,
            logical_effect_key=logical_effect_key,
            operation_kind=operation_kind,
            request_digest=request_digest,
            pdf_ordinal=pdf_ordinal,
            lesson_ordinal=lesson_ordinal,
            scene_ordinal=scene_ordinal,
        )
        server_effect_id = reserve_res.get("effect_id", effect_id)
        if server_effect_id != effect_id:
            with self.journal._get_connection() as conn:
                conn.execute(
                    "UPDATE local_effects SET effect_id = ? WHERE effect_id = ?",
                    (server_effect_id, effect_id),
                )
                conn.commit()
            effect_id = server_effect_id

        recovery = reserve_res.get('terminal_recovery')
        if recovery:
            if (operation_kind != 'terminal_pipeline_export' or recovery.get('request_digest') != request_digest
                    or recovery.get('input_manifest_sha256') != ctx.manifest_sha256
                    or recovery.get('terminal_run_id') != request_payload.get('terminal_run_id')):
                raise ReconciliationRequiredError('Terminal recovery is not bound to this execution')
            inputs = {**inputs, 'terminal_recovery': recovery}
        elif journal_eff.status in ('may_have_been_sent', 'unknown', 'dispatching'):
            raise ReconciliationRequiredError(f"Effect '{logical_effect_key}' requires owner reconciliation.")

        # A completed provider effect may be revisited when a later stage of
        # the same task is resumed. Reuse its exact local response and stable
        # upload identities; never issue a second external request.
        if reserve_res.get("status") == "succeeded":
            if journal_eff.status != "succeeded":
                raise ReconciliationRequiredError(
                    f"Server reports completed effect '{logical_effect_key}' but the local response checkpoint is missing."
                )
            raw_file = ctx.artifacts_dir / f"{server_effect_id}.raw.txt"
            if not raw_file.is_file():
                raise ReconciliationRequiredError(
                    f"Completed effect '{logical_effect_key}' has no local response bytes."
                )
            raw_bytes = raw_file.read_bytes()
            raw_sha256 = hashlib.sha256(raw_bytes).hexdigest()
            raw_text = raw_bytes.decode("utf-8")
            upload_id = str(uuid.uuid5(uuid.NAMESPACE_URL, f"local-worker:{server_effect_id}:raw-upload"))
            raw_artifact_id = str(uuid.uuid5(uuid.NAMESPACE_URL, f"local-worker:{server_effect_id}:raw-artifact"))
            slot = self.client.create_upload_slot(
                task_id=ctx.task_id,
                upload_id=upload_id,
                artifact_id=raw_artifact_id,
                purpose="authoring_raw_response",
                media_type="text/plain",
                expected_bytes=len(raw_bytes),
                expected_sha256=raw_sha256,
            )
            if slot.get("status") != "uploaded":
                self.client.upload_bytes(ctx.task_id, upload_id, raw_bytes, media_type="text/plain")
            return {
                "effect_id": server_effect_id,
                "raw_text": raw_text,
                "raw_artifact_id": raw_artifact_id,
                "raw_sha256": raw_sha256,
                "raw_bytes": len(raw_bytes),
            }
        if journal_eff.status == "succeeded":
            raise ReconciliationRequiredError(
                f"Local journal reports completed effect '{logical_effect_key}' but the server does not."
            )

        # Step 3: Check permit conditions and dispatch on server
        dispatch_res = self.client.dispatch_effect(
            task_id=ctx.task_id,
            effect_id=server_effect_id,
            worker_process_id=self.process_incarnation_id,
        )
        permit_id = dispatch_res["permit_id"]
        attempt_id = dispatch_res["attempt_id"]
        attempt_number = int(dispatch_res.get("attempt_number", 1))
        send_not_after = str(dispatch_res["send_not_after"])

        # Step 4: Record permit in journal
        self.journal.record_permit(
            attempt_id=attempt_id,
            effect_id=server_effect_id,
            attempt_number=attempt_number,
            permit_id=permit_id,
            permit_expires_at=send_not_after,
        )

        # Step 5: Verify permit deadline before send (<= 10s from now) (LW-R07)
        from datetime import datetime, timezone
        try:
            dt = datetime.fromisoformat(send_not_after.replace("Z", "+00:00"))
            deadline_ts = dt.timestamp()
        except Exception as e:
            raise PermitExpiredError(f"Invalid permit deadline '{send_not_after}': {e}")

        if time.time() > deadline_ts:
            raise PermitExpiredError(f"Dispatch permit expired before external send (deadline {send_not_after})")

        # Immediately before send, re-check live lease and heartbeat health
        if not hb.is_lease_healthy():
            if hb.is_paused:
                raise RuntimeError("Task is paused on server; halting effect dispatch")
            if hb.is_cancelled:
                raise RuntimeError("Task is cancelled on server; halting effect dispatch")
            raise LeaseExpiredError("Server lease or heartbeat unhealthy immediately before external send")

        # Step 6: Atomically mark may_have_been_sent
        self.journal.mark_may_have_been_sent(attempt_id)

        # Step 7: Provider execution (LW-R08)
        try:
            raw_text = self.provider_adapter.execute_operation(operation_kind, request_payload, inputs)
        except AuthenticationRequiredError:
            self.journal.record_outcome(attempt_id, "authentication_required", outcome_ref="auth_required")
            try:
                self.client.outcome_effect(ctx.task_id, server_effect_id, outcome_ref="auth_required", status="authentication_required")
            except Exception:
                pass
            raise
        except ProviderRateLimitError:
            self.journal.record_outcome(attempt_id, "rate_limited", outcome_ref="rate_limited")
            try:
                self.client.outcome_effect(ctx.task_id, server_effect_id, outcome_ref="rate_limited", status="rate_limited")
            except Exception:
                pass
            raise
        except Exception as e:
            # Ambiguous failure after mark_may_have_been_sent - classify as unknown
            logger.warning("Provider call failed with ambiguous error type: %s", type(e).__name__)
            self.journal.record_outcome(attempt_id, "unknown", outcome_ref='provider_execution_uncertain')
            try:
                self.client.outcome_effect(ctx.task_id, server_effect_id, outcome_ref='provider_execution_uncertain', status="unknown")
            except Exception as client_err:
                logger.warning("Failed to report unknown outcome to server: %s", client_err)
            raise

        raw_bytes = raw_text.encode("utf-8")
        raw_sha256 = hashlib.sha256(raw_bytes).hexdigest()
        raw_file = ctx.artifacts_dir / f"{server_effect_id}.raw.txt"
        raw_file.write_bytes(raw_bytes)

        # Step 8 & 9: Record outcome in journal
        self.journal.record_outcome(
            attempt_id=attempt_id,
            status="succeeded",
            outcome_ref=server_effect_id,
            raw_response_path=str(raw_file),
        )

        # Step 10: Submit outcome to server
        self.client.outcome_effect(
            task_id=ctx.task_id,
            effect_id=server_effect_id,
            outcome_ref=server_effect_id,
            status="succeeded",
            evidence_sha256=raw_sha256,
        )

        # Step 11: Upload raw artifact to staging slot
        upload_id = str(uuid.uuid5(uuid.NAMESPACE_URL, f"local-worker:{server_effect_id}:raw-upload"))
        raw_artifact_id = str(uuid.uuid5(uuid.NAMESPACE_URL, f"local-worker:{server_effect_id}:raw-artifact"))
        self.client.create_upload_slot(
            task_id=ctx.task_id,
            upload_id=upload_id,
            artifact_id=raw_artifact_id,
            purpose="authoring_raw_response",
            media_type="text/plain",
            expected_bytes=len(raw_bytes),
            expected_sha256=raw_sha256,
        )
        self.client.upload_bytes(
            task_id=ctx.task_id,
            upload_id=upload_id,
            data=raw_bytes,
            media_type="text/plain",
        )

        return {
            "effect_id": server_effect_id,
            "raw_text": raw_text,
            "raw_artifact_id": raw_artifact_id,
            "raw_sha256": raw_sha256,
            "raw_bytes": len(raw_bytes),
        }

    def run_authoring_stage(
        self,
        claim_data: Dict[str, Any],
        drain_check: Optional[Any] = None,
    ) -> Dict[str, Any]:
        from v3_engine.local_worker.authoring_stage import AuthoringStageExecutor

        executor = AuthoringStageExecutor(
            client=self.client,
            journal=self.journal,
            workspace_dir=self.workspace_dir,
            provider_adapter=self.provider_adapter,
            process_incarnation_id=self.process_incarnation_id,
            effect_executor=self.execute_effect,
            saved_result_replayer=self._retry_saved_result,
        )
        return executor.run(claim_data, drain_check)

    def run_scene_return_stage(
        self,
        claim_data: Dict[str, Any],
        packages_by_scene_id=None,
        operator_provenance=None,
    ) -> Dict[str, Any]:
        from v3_engine.local_worker.scene_return_stage import SceneReturnStageExecutor

        executor = SceneReturnStageExecutor(
            client=self.client,
            workspace_dir=self.workspace_dir,
            saved_result_replayer=self._retry_saved_result,
        )
        return executor.run(claim_data, packages_by_scene_id, operator_provenance)

    def reconcile_task(self, task_id: str) -> Dict[str, Any]:
        """Read-first reconciliation: check journal and server effect states."""
        pending_effects = self.journal.list_pending_effects(task_id)
        from v3_engine.local_worker.terminal import TerminalPipelineAdapter
        if isinstance(self.provider_adapter, TerminalPipelineAdapter):
            task_dir = self.workspace_dir / 'tasks' / task_id
            raw_manifest = (task_dir / 'input_manifest.json').read_bytes()
            manifest_sha = hashlib.sha256(raw_manifest).hexdigest()
            if (task_dir / 'input_manifest.sha256').read_text().strip() != manifest_sha:
                raise ReconciliationRequiredError('Local input manifest changed')
            manifest = strict_json_decode(raw_manifest, max_size=2097152)
            validate_input_manifest(manifest)
            assignment = self.client.get_assignment(task_id)
            if assignment['assignment_status'] != 'reconciliation_only':
                raise ReconciliationRequiredError('Begin owner reconciliation before submitting terminal evidence')
            ctx = StageContext(task_id, manifest['job_id'], manifest['run_id'], manifest['task_kind'],
                assignment['assignment_id'], assignment['assignment_epoch'], assignment['control_version'],
                manifest, manifest_sha, self.workspace_dir)
            terminal_effects = [row for row in self.journal.list_effects(task_id) if row.operation_kind == 'terminal_pipeline_export']
            if len(terminal_effects) != 1:
                raise ReconciliationRequiredError('Expected one retained terminal effect')
            effect = terminal_effects[0]
            observed = self.provider_adapter.recovery_evidence(ctx, effect.request_digest)
            receipt = self.client.submit_recovery_evidence(task_id, observed)
            return {'task_id': task_id, 'effect_id': effect.effect_id, 'terminal_run_id': observed['terminal_run_id'],
                'evidence_artifact_id': receipt['evidence_artifact_id'], 'requires_owner_override': True,
                'next_action': 'Owner: resolve-terminal with this effect/evidence ID, then restart local-worker run.'}
        reconciled = []
        unknown_effects = []

        for eff in pending_effects:
            server_eff = self.client.get_effect(task_id, eff.effect_id)
            server_status = server_eff.get("status")

            if eff.status == "succeeded" and server_status == "succeeded":
                reconciled.append({"effect_id": eff.effect_id, "status": "resolved"})
            elif eff.status in ("may_have_been_sent", "unknown"):
                unknown_effects.append(eff.effect_id)

        return {
            "task_id": task_id,
            "total_pending": len(pending_effects),
            "reconciled": reconciled,
            "unknown_effects": unknown_effects,
            "requires_owner_override": len(unknown_effects) > 0,
        }
