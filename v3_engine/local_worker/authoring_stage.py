"""Authoring task execution over immutable task inputs and effect fences."""
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

class AuthoringStageExecutor:
    def __init__(self, client, journal, workspace_dir, provider_adapter, process_incarnation_id,
                 effect_executor, saved_result_replayer):
        self.client = client
        self.journal = journal
        self.workspace_dir = Path(workspace_dir).expanduser().resolve()
        self.provider_adapter = provider_adapter
        self.process_incarnation_id = process_incarnation_id
        self.effect_executor = effect_executor
        self._saved_result_replayer = saved_result_replayer

    def run(
        self,
        claim_data: Dict[str, Any],
        drain_check: Optional[Any] = None,
    ) -> Dict[str, Any]:
        saved_receipt = self._saved_result_replayer(claim_data)
        if saved_receipt is not None:
            self._write_generation_handoff(claim_data, saved_receipt)
            return saved_receipt

        from v3_engine.local_worker.terminal import TerminalPipelineAdapter
        if isinstance(self.provider_adapter, TerminalPipelineAdapter):
            return self._run_terminal_authoring_stage(claim_data, drain_check)

        from v3_engine.notebooklm.protocol import parse_direct_divide_map
        from v3_engine.notebooklm.bundle_parser import parse_combined_bundle

        ctx = StageContext.resolve_and_download(self.client, claim_data, self.workspace_dir)

        # Initialize heartbeat
        initial_lease_ts = time.time() + 120.0
        if "lease_expires_at" in claim_data:
            from datetime import datetime
            try:
                dt = datetime.fromisoformat(str(claim_data["lease_expires_at"]).replace("Z", "+00:00"))
                initial_lease_ts = dt.timestamp()
            except Exception:
                pass

        hb = HeartbeatManager(
            client=self.client,
            task_id=ctx.task_id,
            assignment_id=ctx.assignment_id,
            assignment_epoch=ctx.assignment_epoch,
            initial_control_version=ctx.control_version,
            initial_lease_expires_at=initial_lease_ts,
        )
        hb.start()

        try:
            items = ctx.manifest.get("items", [])
            if not items:
                raise LocalWorkerProtocolError("Manifest items list is empty", code="empty_items")

            result_lessons: List[Dict[str, Any]] = []
            artifact_references: List[Dict[str, Any]] = []
            artifact_by_id = {entry["artifact_id"]: entry for entry in ctx.manifest.get("artifacts", [])}
            framework_artifact = next(
                (entry for entry in ctx.manifest.get("artifacts", [])
                 if entry.get("purpose") == "framework_body" and entry.get("logical_name") == "framework.txt"),
                None,
            )
            if framework_artifact is None:
                raise LocalWorkerProtocolError("Sealed framework artifact is missing", code="invalid_instructions")
            if not ctx.protocol_bundle:
                raise LocalWorkerProtocolError("Captured NotebookLM protocol bundle is unavailable", code="invalid_instructions")
            format_text = ctx.protocol_bundle["combined_response_format"]

            for item_idx, item in enumerate(items):
                item_id = item["item_id"]
                prepared_artifact = artifact_by_id.get(item.get("prepared_artifact_id"))
                if not prepared_artifact or prepared_artifact.get("purpose") != "prepared_pdf":
                    raise LocalWorkerProtocolError("Prepared PDF artifact binding is missing", code="invalid_manifest")

                notebook_key = f"{ctx.job_id}:author_prompts:notebook:{item_id}"
                notebook_result = self.effect_executor(
                    ctx, hb, "notebook_create", notebook_key,
                    {"item_id": item_id, "title": f"Local Worker {ctx.run_id} {item_id}"},
                    {}, pdf_ordinal=item_idx, drain_check=drain_check,
                )
                try:
                    notebook_doc = strict_json_decode(notebook_result["raw_text"].encode("utf-8"), max_size=16384)
                    notebook_id = str(notebook_doc["notebook_id"])
                except Exception as exc:
                    raise LocalWorkerProtocolError("Notebook creation receipt is invalid", code="provider_receipt_invalid") from exc
                if not notebook_id.strip():
                    raise LocalWorkerProtocolError("Notebook creation receipt has no identity", code="provider_receipt_invalid")
                artifact_references.append({"artifact_id": notebook_result["raw_artifact_id"], "digest_sha256": notebook_result["raw_sha256"]})

                source_ids: Dict[str, str] = {}
                for role, source_artifact in (("prepared_pdf", prepared_artifact), ("framework", framework_artifact)):
                    source_artifact_id = str(source_artifact["artifact_id"])
                    source_path = ctx.inputs_dir / str(source_artifact["logical_name"])
                    source_key = f"{ctx.job_id}:author_prompts:source:{item_id}:{source_artifact_id}"
                    upload_result = self.effect_executor(
                        ctx, hb, "source_upload", source_key,
                        {
                            "item_id": item_id,
                            "notebook_id": notebook_id,
                            "artifact_id": source_artifact_id,
                            "artifact_sha256": source_artifact["digest_sha256"],
                            "mime_type": source_artifact["media_type"],
                            "title": f"{role}-{item_id}",
                        },
                        {"source_path": str(source_path)},
                        pdf_ordinal=item_idx, drain_check=drain_check,
                    )
                    try:
                        receipt = strict_json_decode(upload_result["raw_text"].encode("utf-8"), max_size=16384)
                        source_id = str(receipt["source_id"])
                        source_status = str(receipt["status"])
                    except Exception as exc:
                        raise LocalWorkerProtocolError("Source upload receipt is invalid", code="provider_receipt_invalid") from exc
                    if not source_id.strip() or source_status == "error":
                        raise ProviderError("source_upload_failed", "NotebookLM did not accept an uploaded source.")
                    source_ids[role] = source_id
                    artifact_references.append({"artifact_id": upload_result["raw_artifact_id"], "digest_sha256": upload_result["raw_sha256"]})

                wait_ready = getattr(self.provider_adapter, "wait_sources_ready", None)
                if not callable(wait_ready):
                    raise ProviderError("source_poll_unsupported", "Provider adapter cannot confirm source readiness.")
                wait_ready(notebook_id, list(source_ids.values()))

                format_key = f"{ctx.job_id}:author_prompts:format:{item_id}"
                format_result = self.effect_executor(
                    ctx, hb, "chat_configure", format_key,
                    {"item_id": item_id, "notebook_id": notebook_id, "format_text": format_text},
                    {}, pdf_ordinal=item_idx, drain_check=drain_check,
                )
                if format_result["raw_text"].strip() != "System Ready":
                    raise LocalWorkerProtocolError("Provider did not confirm captured chat format", code="provider_format_unconfirmed")
                artifact_references.append({"artifact_id": format_result["raw_artifact_id"], "digest_sha256": format_result["raw_sha256"]})

                # 1. Divide map operation
                divide_key = f"{ctx.job_id}:author_prompts:divide:{item_id}"
                divide_res = self.effect_executor(
                    ctx=ctx,
                    hb=hb,
                    operation_kind="divide_map",
                    logical_effect_key=divide_key,
                    request_payload={
                        "item_id": item_id, "operation": "divide_map", "notebook_id": notebook_id,
                        "source_ids": [source_ids["prepared_pdf"]],
                        "prompt": ctx.protocol_bundle["divide_prompt"],
                    },
                    inputs={"item": item},
                    pdf_ordinal=item_idx,
                    drain_check=drain_check,
                )
                artifact_references.append({
                    "artifact_id": divide_res["raw_artifact_id"],
                    "digest_sha256": divide_res["raw_sha256"],
                })

                # Parse actual map response dynamically (LW-R14)
                nodes = parse_direct_divide_map(divide_res["raw_text"])
                work_nodes = [node for node in nodes if node.work_ordinal is not None]
                if not work_nodes:
                    raise LocalWorkerProtocolError("Divide map produced no work items", code="empty_work_items")

                # Keep the execution-format confirmation as its own fenced chat mutation.
                conversation_id = self.provider_adapter.current_conversation_id(notebook_id)
                if not conversation_id:
                    raise ProviderError("conversation_missing", "NotebookLM did not expose the active conversation after map generation.")
                execution_key = f"{ctx.job_id}:author_prompts:execution:{item_id}"
                execution_res = self.effect_executor(
                    ctx=ctx, hb=hb, operation_kind="execution_ready", logical_effect_key=execution_key,
                    request_payload={
                        "item_id": item_id, "notebook_id": notebook_id,
                        "source_ids": [source_ids["prepared_pdf"], source_ids["framework"]],
                        "conversation_id": conversation_id,
                        "prompt": ctx.protocol_bundle["execution_prompt"],
                    },
                    inputs={"item": item}, pdf_ordinal=item_idx, drain_check=drain_check,
                )
                if execution_res["raw_text"].strip() != "System Ready":
                    raise ProviderError("execution_unconfirmed", "NotebookLM did not confirm the lesson-generation context.")
                artifact_references.append({"artifact_id": execution_res["raw_artifact_id"], "digest_sha256": execution_res["raw_sha256"]})

                # 2. Lesson operations for each parsed work node
                for lesson_idx, node in enumerate(work_nodes):
                    # Check drain before next effect (LW-R33)
                    if drain_check and drain_check():
                        logger.info("Drain requested between lessons; halting new effects")
                        raise RuntimeError("Drain requested; stopping before starting next lesson")

                    lesson_ord = node.work_ordinal
                    lesson_key = f"{ctx.job_id}:author_prompts:lesson:{item_id}:{lesson_ord}"
                    lesson_res = self.effect_executor(
                        ctx=ctx,
                        hb=hb,
                        operation_kind="lesson_bundle",
                        logical_effect_key=lesson_key,
                        request_payload={
                            "item_id": item_id,
                            "lesson_ordinal": lesson_ord,
                            "lesson_title": node.title,
                            "notebook_id": notebook_id,
                            "source_ids": [source_ids["prepared_pdf"], source_ids["framework"]],
                            "conversation_id": conversation_id,
                            "prompt": ctx.protocol_bundle["lesson_start_prompt"] if lesson_idx == 0 else ctx.protocol_bundle["lesson_continue_prompt"],
                        },
                        inputs={"item": item},
                        pdf_ordinal=item_idx,
                        lesson_ordinal=lesson_idx,
                        drain_check=drain_check,
                    )
                    artifact_references.append({
                        "artifact_id": lesson_res["raw_artifact_id"],
                        "digest_sha256": lesson_res["raw_sha256"],
                    })

                    # Parse actual lesson response bundle dynamically (LW-R14)
                    bundle = parse_combined_bundle(lesson_res["raw_text"], expected_work_ordinal=lesson_ord)

                    scenes = []
                    for s_idx, sec in enumerate(bundle.third.sections):
                        prompt_text = sec.prompt_body
                        prompt_sha256 = hashlib.sha256(prompt_text.encode("utf-8")).hexdigest()

                        # Resolve scene title from First section if present
                        sec_identity = None
                        if s_idx < len(bundle.first.sections):
                            sec_identity = bundle.first.sections[s_idx].fields.get("section_identity")
                        scene_title = sec_identity or f"{node.title} - Section {sec.ordinal}"

                        scenes.append({
                            "scene_ordinal": s_idx,
                            "scene_title": scene_title,
                            "prompt_text": prompt_text,
                            "prompt_sha256": prompt_sha256,
                            "generation_context": {
                                "template_version": "v1",
                                "scene_type": sec.scene_type,
                            },
                        })

                    result_lessons.append({
                        "item_id": item_id,
                        "lesson_ordinal": lesson_idx,
                        "lesson_title": node.title,
                        "raw_response_artifact_id": lesson_res["raw_artifact_id"],
                        "raw_response_sha256": lesson_res["raw_sha256"],
                        "scenes": scenes,
                    })

            # Construct result manifest conforming exactly to validate_authoring_result (LW-R17)
            result_manifest = {
                "schema_version": 1,
                "kind": "local_worker_authoring_result",
                "result_id": str(uuid.uuid4()),
                "task_id": ctx.task_id,
                "job_id": ctx.job_id,
                "run_id": ctx.run_id,
                "assignment_id": ctx.assignment_id,
                "assignment_epoch": ctx.assignment_epoch,
                "input_manifest_sha256": ctx.manifest_sha256,
                "protocol_version": ctx.manifest.get("protocol_version", "notebooklm_combined_v1"),
                "settings_sha256": ctx.manifest.get("settings_sha256", "0" * 64),
                "preparation_sha256": ctx.manifest.get("preparation", {}).get("inventory_sha256", "0" * 64),
                "artifact_references": artifact_references,
                "lessons": result_lessons,
                "archive_inventory": [],
                "metadata": {
                    "worker_version": "1.0.0",
                    "worker_process_id": self.process_incarnation_id,
                    "produced_at": time.time(),
                },
            }

            validate_authoring_result(result_manifest)

            manifest_json = json.dumps(result_manifest, indent=2)
            (ctx.task_dir / "result_manifest.json").write_text(manifest_json, encoding="utf-8")
            job_dir = ctx.workspace_dir / "jobs" / ctx.job_id
            job_dir.mkdir(parents=True, exist_ok=True)
            (job_dir / "authoring_result.json").write_text(manifest_json, encoding="utf-8")

            commit_receipt = self.client.submit_result(
                task_id=ctx.task_id,
                result_payload=result_manifest,
                assignment_id=ctx.assignment_id,
                assignment_epoch=ctx.assignment_epoch,
            )
            return commit_receipt

        finally:
            hb.stop()

    def _upload_terminal_bytes(self, ctx, identity: str, data: bytes) -> dict:
        digest = hashlib.sha256(data).hexdigest()
        artifact_id = str(uuid.uuid5(uuid.UUID(ctx.task_id), identity + ':' + digest))
        upload_id = str(uuid.uuid5(uuid.UUID(artifact_id), 'upload'))
        slot = self.client.create_upload_slot(
            task_id=ctx.task_id, upload_id=upload_id, artifact_id=artifact_id,
            purpose='authoring_raw_response', media_type='text/plain',
            expected_bytes=len(data), expected_sha256=digest,
        )
        if slot.get('status') != 'uploaded':
            self.client.upload_bytes(ctx.task_id, upload_id, data, media_type='text/plain')
        return {'artifact_id': artifact_id, 'digest_sha256': digest}

    def _run_terminal_authoring_stage(self, claim_data, drain_check=None):
        """Transfer inputs/results around the existing terminal implementation."""
        from datetime import datetime
        from v3_engine.notebooklm.terminal_service import atomic_json
        from v3_engine.local_worker.terminal import TerminalPipelineError

        lease = datetime.fromisoformat(claim_data['lease_expires_at'].replace('Z', '+00:00')).timestamp()
        hb = HeartbeatManager(self.client, claim_data['task_id'], claim_data['assignment_id'],
                              claim_data['assignment_epoch'], claim_data['control_version'], lease)
        hb.start()
        try:
            ctx = StageContext.resolve_and_download(self.client, claim_data, self.workspace_dir)
            request = self.provider_adapter.prepare(ctx)
            exported = self.effect_executor(
                ctx, hb, 'terminal_pipeline_export', ctx.job_id + ':terminal-export-v1',
                request, {'context': ctx, 'heartbeat': hb, 'drain_check': drain_check},
                drain_check=drain_check,
            )
            export = json.loads(exported['raw_text'])
            link = json.loads((ctx.task_dir / 'terminal_link.json').read_text(encoding='utf-8'))
            # Reverify retained archive bytes even when an effect/result is replayed.
            if export != self.provider_adapter.load_export(ctx, link):
                raise TerminalPipelineError('Retained terminal export changed after the provider-effect receipt.')
            run_dir = Path(link['run_dir'])
            references = [{'artifact_id': exported['raw_artifact_id'], 'digest_sha256': exported['raw_sha256']}]
            archives = []
            for ordinal, item in enumerate(ctx.manifest['items'], 1):
                archive_bytes = (run_dir / f'pdfs/pdf-{ordinal:04d}/private/archive.json').read_bytes()
                ref = self._upload_terminal_bytes(ctx, f'pdf-{ordinal}-archive', archive_bytes)
                references.append(ref)
                archives.append({'item_id': item['item_id'], 'pdf_ordinal': ordinal,
                                 'archive_artifact_id': ref['artifact_id'], 'archive_sha256': ref['digest_sha256']})
            lessons = []
            for lesson in export['lessons']:
                pdf_ordinal = lesson['pdf_ordinal']
                item = ctx.manifest['items'][pdf_ordinal - 1]
                ordinal = lesson['lesson_ordinal']
                capture = json.loads((run_dir / f'pdfs/pdf-{pdf_ordinal:04d}/private/turn-captures/lesson-{ordinal:03d}.json').read_text(encoding='utf-8'))
                receipt_path = run_dir / 'private/turn-receipts' / (capture['receipt_ref'] + '.json')
                if not receipt_path.resolve().is_relative_to(run_dir / 'private/turn-receipts'):
                    raise TerminalPipelineError('Invalid terminal receipt reference.')
                raw = json.loads(receipt_path.read_text(encoding='utf-8'))['raw_answer'].encode('utf-8')
                if hashlib.sha256(raw).hexdigest() != capture['raw_answer_sha256']:
                    raise TerminalPipelineError('Terminal lesson response digest changed.')
                ref = self._upload_terminal_bytes(ctx, f'pdf-{pdf_ordinal}-lesson-{ordinal}', raw)
                references.append(ref)
                scenes = []
                for scene_index, scene in enumerate(lesson['scenes']):
                    prompt = scene['prompt']
                    if not prompt.strip() or hashlib.sha256(prompt.encode('utf-8')).hexdigest() != scene['prompt_sha256']:
                        raise TerminalPipelineError('Terminal prompt bytes do not match their retained digest.')
                    scenes.append({
                        'scene_ordinal': scene_index, 'scene_title': scene['section_title'],
                        'prompt_text': prompt, 'prompt_sha256': scene['prompt_sha256'],
                        'generation_context': {'template_version': 'terminal-export-v1', 'scene_type': scene['scene_type']},
                    })
                lessons.append({'item_id': item['item_id'], 'lesson_ordinal': ordinal - 1,
                                'lesson_title': lesson['lesson_title'],
                                'raw_response_artifact_id': ref['artifact_id'],
                                'raw_response_sha256': ref['digest_sha256'], 'scenes': scenes})
            result = {
                'schema_version': 1, 'kind': 'local_worker_authoring_result',
                'result_id': str(uuid.uuid5(uuid.UUID(ctx.task_id), 'terminal-authoring-result-v1')),
                'task_id': ctx.task_id, 'job_id': ctx.job_id, 'run_id': ctx.run_id,
                'assignment_id': ctx.assignment_id, 'assignment_epoch': ctx.assignment_epoch,
                'input_manifest_sha256': ctx.manifest_sha256,
                'protocol_version': ctx.manifest['protocol_version'],
                'settings_sha256': ctx.manifest['settings_sha256'],
                'preparation_sha256': ctx.manifest['preparation']['inventory_sha256'],
                'artifact_references': references, 'lessons': lessons, 'archive_inventory': archives,
                'metadata': {'authoring_backend': 'run_temporal_pipeline',
                             'terminal_run_id': link['terminal_run_id'],
                             'measurement_status': 'not_recorded'},
            }
            validate_authoring_result(result)
            atomic_json(ctx.task_dir / 'result_manifest.json', result)
            atomic_json(self.workspace_dir / 'jobs' / ctx.job_id / 'authoring_result.json', result)
            receipt = self.client.submit_result(ctx.task_id, result, ctx.assignment_id, ctx.assignment_epoch)
            self._write_generation_handoff(claim_data, receipt)
            return receipt
        finally:
            hb.stop()

    def _write_generation_handoff(self, claim, receipt):
        """One explicit file for a local coding agent; no secrets/private archive."""
        from v3_engine.notebooklm.terminal_service import atomic_json

        bindings = receipt.get('scene_bindings')
        if not bindings:
            return  # Retained receipts created before this adapter may have no bindings.
        run_root = self.workspace_dir / 'runs' / claim['run_id']
        scene_root = run_root / 'scenes'
        task_dir = self.workspace_dir / 'tasks' / claim['task_id']
        input_manifest = json.loads((task_dir / 'input_manifest.json').read_text(encoding='utf-8'))
        generated = []
        for binding in bindings:
            if hashlib.sha256(binding['prompt_text'].encode('utf-8')).hexdigest() != binding['prompt_sha256']:
                raise LocalWorkerProtocolError('Accepted prompt binding digest mismatch', code='digest_mismatch')
            scene_id = str(uuid.UUID(binding['scene_id']))
            destination = scene_root / scene_id
            destination.mkdir(parents=True, exist_ok=True)
            generated.append({**binding, 'output_dir': str(destination),
                              'entrypoint': str(destination / 'index.html')})
        artifacts = {row['artifact_id']: row for row in input_manifest['artifacts']}
        pdfs = [str(task_dir / 'inputs' / artifacts[row['prepared_artifact_id']]['logical_name'])
                for row in input_manifest['items']]
        document = {
            'schema_version': 1, 'kind': 'local_run_generation_handoff',
            'run_id': claim['run_id'], 'job_id': claim['job_id'],
            'subject_title': receipt.get('subject_title'),
            'input': {'pdfs': pdfs}, 'output': {'scenes_dir': str(scene_root)},
            'generated_prompts': generated,
            'instructions': 'Generate each scene from its exact prompt_text into its output_dir. Preserve run/scene/prompt IDs. Save index.html there; styles.css and script.js may be saved separately. After generation, request Pull from the hosted dashboard.',
        }
        link_path = task_dir / 'terminal_link.json'
        if link_path.is_file():
            link = json.loads(link_path.read_text(encoding='utf-8'))
            document['terminal_run_id'] = link['terminal_run_id']
            document['output']['terminal_run_dir'] = link['run_dir']
            export = json.loads((Path(link['run_dir']) / 'private/scene-prompt-exports/scene-prompts.json').read_text(encoding='utf-8'))
            document['generation_context'] = export['generation_context']
        atomic_json(run_root / 'run.json', document)
        logger.info('Manual generation handoff: %s', run_root / 'run.json')
