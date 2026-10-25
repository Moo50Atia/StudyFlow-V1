"""Scene return transfer and result assembly for assigned worker tasks."""
from __future__ import annotations

import hashlib
import json
import logging
import os
from pathlib import Path
import time
from typing import Any, Dict, List, Optional
import uuid

from v3_engine.local_worker.client import LocalWorkerClientError, LocalWorkerHttpClient
from v3_engine.local_worker.execution_context import (
    HeartbeatManager,
    LocalWorkerProtocolError,
    StageContext,
)
from v3_engine.local_worker.journal import LocalEffectJournal
from v3_engine.local_worker.providers import NotebookLMProviderAdapter, ProviderAdapter
from v3_engine.local_worker.protocol import (
    strict_json_decode,
    validate_authoring_result,
    validate_scene_result,
    validate_scene_package,
)
from v3_engine.notebooklm.ports import (
    AuthenticationRequiredError,
    ProviderError,
    ProviderRateLimitError,
)

logger = logging.getLogger("v3_engine.local_worker.runner")

class SceneReturnStageExecutor:
    def __init__(self, client, workspace_dir, saved_result_replayer):
        self.client = client
        self.workspace_dir = Path(workspace_dir).expanduser().resolve()
        self._saved_result_replayer = saved_result_replayer

    def run(
        self, claim_data, packages_by_scene_id=None, operator_provenance=None,
    ):
        if not operator_provenance:
            raise LocalWorkerProtocolError('Scene return requires explicit operator provenance', code='missing_operator_provenance')
        from datetime import datetime
        lease = datetime.fromisoformat(claim_data['lease_expires_at'].replace('Z', '+00:00')).timestamp()
        hb = HeartbeatManager(self.client, claim_data['task_id'], claim_data['assignment_id'],
                              claim_data['assignment_epoch'], claim_data['control_version'], lease)
        hb.start()
        try:
            return self._run_scene_return_stage(claim_data, packages_by_scene_id, operator_provenance)
        finally:
            hb.stop()

    def _run_scene_return_stage(
        self,
        claim_data: Dict[str, Any],
        packages_by_scene_id: Optional[Dict[str, Dict[str, Any]]] = None,
        operator_provenance: Optional[Dict[str, Any]] = None,
    ) -> Dict[str, Any]:
        saved_receipt = self._saved_result_replayer(claim_data)
        if saved_receipt is not None:
            return saved_receipt

        if not operator_provenance:
            raise LocalWorkerProtocolError(
                "Scene return requires explicit operator provenance; pass the assigned teacher and reported provider details",
                code="missing_operator_provenance",
            )
        operator_id = operator_provenance.get("operator_id")
        if isinstance(operator_id, bool) or not isinstance(operator_id, int) or operator_id < 1:
            raise LocalWorkerProtocolError("operator_id must be a positive teacher user ID", code="invalid_operator_id")
        device_id = claim_data.get("device_id")
        if not device_id:
            raise LocalWorkerProtocolError("Claim is missing the authenticated device_id", code="missing_device_identity")

        ctx = StageContext.resolve_and_download(self.client, claim_data, self.workspace_dir)

        submissions: List[Dict[str, Any]] = []
        expected_scenes = ctx.manifest.get("required_scenes") or ctx.manifest.get("expected_scenes", [])

        # For each expected scene target
        scene_targets = expected_scenes or []

        # A retry must send the same bytes even if the operator edits a file
        # while an upload is interrupted. Freeze the entire batch before I/O.
        frozen_batch_path = ctx.task_dir / 'scene_transfer.json'
        frozen_batch = None
        if frozen_batch_path.is_file():
            frozen_batch = strict_json_decode(frozen_batch_path.read_bytes(), max_size=2097152)
            if frozen_batch['input_manifest_sha256'] != ctx.manifest_sha256 or [row['target'] for row in frozen_batch['packages']] != scene_targets:
                raise LocalWorkerProtocolError('Frozen scene transfer does not match the task', code='input_changed')
            operator_provenance = frozen_batch['operator_provenance']
            operator_id = operator_provenance['operator_id']

        prepared_packages = []
        missing_scene_ids = []
        total_package_bytes = 0
        budget = ctx.manifest.get('resource_budget', {})
        task_budget = min(536870912, budget.get('max_total_task_bytes', budget.get('max_output_bytes', 536870912)))
        for target in scene_targets:
            s_id = target.get("scene_id")
            p_vid = target.get("prompt_version_id")
            if not s_id or not p_vid:
                raise LocalWorkerProtocolError('Scene target has no stable identity', code='missing_scene_identity')

            if frozen_batch is not None:
                row = next(row for row in frozen_batch['packages'] if row['target']['scene_id'] == s_id)
                frozen = ctx.artifacts_dir / row['file']
                if frozen.name != row['file'] or not frozen.resolve().is_relative_to(ctx.artifacts_dir.resolve()):
                    raise LocalWorkerProtocolError('Invalid frozen package path', code='invalid_scene_path')
                if hashlib.sha256(frozen.read_bytes()).hexdigest() != row['sha256']:
                    raise LocalWorkerProtocolError('Frozen scene bytes changed', code='artifact_mismatch')
                total_package_bytes += frozen.stat().st_size
                if total_package_bytes > task_budget:
                    raise LocalWorkerProtocolError('Scene transfer budget exceeded', code='bounds_exceeded')
                prepared_packages.append((target, frozen, row['sha256']))
                continue

            pkg = (packages_by_scene_id or {}).get(s_id)
            if not pkg:
                # Check scenes directory for actual operator files (LW-R22)
                possible_dirs = [
                    ctx.workspace_dir / 'runs' / ctx.run_id / 'scenes' / s_id,
                    ctx.scenes_dir / s_id,
                    ctx.workspace_dir / "scenes" / ctx.job_id / s_id,
                    ctx.workspace_dir / "scenes" / s_id,
                ]
                for pdir in possible_dirs:
                    if (pdir / "index.html").is_file():
                        if not pdir.resolve().is_relative_to(ctx.workspace_dir) or any(
                            (pdir / name).is_symlink() for name in ('index.html', 'styles.css', 'script.js')
                        ):
                            raise LocalWorkerProtocolError('Scene files must remain inside the local run workspace', code='invalid_scene_path')
                        pkg = {
                            "schema_version": 1,
                            "profile": "manual_scene_files_v1",
                            "files": [
                                {"path": name, "content": (pdir / name).read_text(encoding="utf-8")}
                                for name in ('index.html', 'styles.css', 'script.js') if (pdir / name).is_file()
                            ],
                        }
                        break

            if not pkg:
                # Check the complete file set before uploading anything, so a
                # premature Pull never leaves an unfinishable partial receipt.
                missing_scene_ids.append(s_id)
                continue

            validate_scene_package(pkg)
            pkg_bytes = json.dumps(pkg, ensure_ascii=False, separators=(',', ':')).encode('utf-8')
            total_package_bytes += len(pkg_bytes)
            if total_package_bytes > task_budget:
                raise LocalWorkerProtocolError('Scene return exceeds the captured task transfer budget', code='bounds_exceeded')
            pkg_sha256 = hashlib.sha256(pkg_bytes).hexdigest()
            frozen = ctx.artifacts_dir / f'manual-scene-{s_id}-{pkg_sha256}.json'
            frozen.write_bytes(pkg_bytes)
            prepared_packages.append((target, frozen, pkg_sha256))

        if missing_scene_ids:
            raise LocalWorkerProtocolError(
                'Scene output is missing for ' + ', '.join(missing_scene_ids)
                + '; finish generation and request Pull again.', code='missing_packages')

        if frozen_batch is None:
            from v3_engine.notebooklm.terminal_service import atomic_json
            atomic_json(frozen_batch_path, {
                'input_manifest_sha256': ctx.manifest_sha256,
                'operator_provenance': operator_provenance,
                'packages': [{'target': target, 'file': path.name, 'sha256': digest}
                             for target, path, digest in prepared_packages],
            })

        for target, frozen, pkg_sha256 in prepared_packages:
            s_id, p_vid = target['scene_id'], target['prompt_version_id']
            pkg_bytes = frozen.read_bytes()
            if hashlib.sha256(pkg_bytes).hexdigest() != pkg_sha256:
                raise LocalWorkerProtocolError('Frozen scene transfer bytes changed', code='artifact_mismatch')

            # Upload package
            pkg_artifact_id = str(uuid.uuid5(uuid.UUID(ctx.task_id), s_id + ':' + pkg_sha256))
            upload_id = str(uuid.uuid5(uuid.UUID(pkg_artifact_id), 'upload'))
            slot = self.client.create_upload_slot(
                task_id=ctx.task_id,
                upload_id=upload_id,
                artifact_id=pkg_artifact_id,
                purpose="scene_package",
                media_type="application/json",
                expected_bytes=len(pkg_bytes),
                expected_sha256=pkg_sha256,
            )
            if slot.get('status') != 'uploaded':
                self.client.upload_bytes(
                    task_id=ctx.task_id, upload_id=upload_id, data=pkg_bytes, media_type='application/json',
                )

            prompt_sha256 = target['prompt_sha256']

            submissions.append({
                "submission_id": str(uuid.uuid5(uuid.UUID(pkg_artifact_id), 'submission')),
                "scene_id": s_id,
                "prompt_version_id": p_vid,
                "prompt_sha256": prompt_sha256,
                "package_artifact_id": pkg_artifact_id,
                "package_sha256": pkg_sha256,
                "provenance": {
                    "mode": "manual_web",
                    "operator_id": operator_id,
                    "device_id": device_id,
                    "provider": operator_provenance.get("provider"),
                    "model": operator_provenance.get("model"),
                    "effort": operator_provenance.get("effort"),
                    "measurement_status": "not_recorded",
                },
            })

        if not submissions:
            raise LocalWorkerProtocolError(
                f"No operator scene packages found to submit for task {ctx.task_id}",
                code="missing_packages"
            )

        result_manifest = {
            "schema_version": 1,
            "kind": "local_worker_scene_result",
            "result_id": str(uuid.uuid5(uuid.UUID(ctx.task_id), 'scene-return-result-v1')),
            "task_id": ctx.task_id,
            "job_id": ctx.job_id,
            "run_id": ctx.run_id,
            "assignment_id": ctx.assignment_id,
            "assignment_epoch": ctx.assignment_epoch,
            "control_version": ctx.control_version,
            "input_manifest_sha256": ctx.manifest_sha256,
            "submissions": submissions,
        }

        validate_scene_result(result_manifest)

        manifest_json = json.dumps(result_manifest, indent=2)
        (ctx.task_dir / "result_manifest.json").write_text(manifest_json, encoding="utf-8")
        job_dir = ctx.workspace_dir / "jobs" / ctx.job_id
        job_dir.mkdir(parents=True, exist_ok=True)
        (job_dir / "scene_result.json").write_text(manifest_json, encoding="utf-8")

        commit_receipt = self.client.submit_result(
            task_id=ctx.task_id,
            result_payload=result_manifest,
            assignment_id=ctx.assignment_id,
            assignment_epoch=ctx.assignment_epoch,
        )
        return commit_receipt
