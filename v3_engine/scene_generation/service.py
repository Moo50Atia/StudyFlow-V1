"""Scene generation service orchestrating prompts, persistence, and builds (Spec 017)."""

from __future__ import annotations

import json
import hashlib
from typing import Any, Callable, Mapping, Optional

from v3_engine.scene_generation.artifact_store import ArtifactCorruptError, SceneArtifactStore
from v3_engine.scene_generation.builder import SceneBuilder
from v3_engine.scene_generation.contracts import (
    AIProvider,
    HandoffInventory,
    SceneGenerationRequest,
    SceneInput,
)
from v3_engine.scene_generation.source_package import (
    HtmlSceneSourceV1,
    SourcePackageError,
    parse_html_scene_source,
)
from v3_engine.scene_generation.providers import decode_generation_response

OUTPUT_CONTRACT_TEXT = """Return exactly one JSON object: {\"schema_version\":1,\"files\":[{\"path\":\"index.html\",\"content\":\"...\"},{\"path\":\"styles.css\",\"content\":\"...\"},{\"path\":\"script.js\",\"content\":\"...\"}]}. Include exactly those three paths, each with nonempty UTF-8 content. index.html must reference ./styles.css and ./script.js. Use only plain HTML, CSS, and browser JavaScript: no React, TypeScript, JSX, package manifests, external libraries, remote scripts, or remote stylesheets. Return no prose or Markdown fence. Escape every quote, backslash, and newline correctly inside JSON strings."""


class SceneGenerationService:
    """Orchestrates scene prompt generation, artifact persistence, and local builds."""

    def __init__(
        self,
        provider: AIProvider,
        store: SceneArtifactStore,
        builder: SceneBuilder,
        template_text: str,
        template_sha: str,
    ) -> None:
        self.provider = provider
        self.store = store
        self.builder = builder
        self.template_text = template_text
        self.template_sha = template_sha

    def initialize_run(
        self,
        inventory: HandoffInventory,
        config: Any = None,
        input_raw_bytes: list[bytes] | None = None,
    ) -> None:
        cfg = config or getattr(self.provider, "config", None)
        self.store.initialize_run(
            inventory=inventory,
            config=cfg,
            template_text=self.template_text,
            template_sha=self.template_sha,
            input_raw_bytes=input_raw_bytes or [],
        )

    async def generate_one(
        self,
        scene: SceneInput,
        explicit_retry_of: Optional[int] = None,
        progress_cb: Optional[Callable[[str], None]] = None,
    ) -> dict[str, Any]:
        """Dispatch a single scene generation request and record raw receipts."""
        attempt = self.store.start_attempt(scene, explicit_retry_of=explicit_retry_of)
        if progress_cb:
            progress_cb(f"scene_sent {scene.scene_key} attempt {attempt.ordinal}")

        host_context = {
            "entrypoint": "index.html",
            "styles": "styles.css",
            "behavior": "script.js",
            "runtime": "browser-native HTML CSS JavaScript",
        }
        request = SceneGenerationRequest(
            system_text=self.template_text,
            prompt=scene.prompt or "",
            output_contract=OUTPUT_CONTRACT_TEXT,
            host_context=host_context,
        )

        async def receipt_sink(body_bytes: bytes, meta: Mapping[str, Any]) -> str:
            return self.store.save_raw_receipt(
                scene=scene,
                attempt_ordinal=attempt.ordinal,
                body_bytes=body_bytes,
                http_status=meta.get("http_status"),
                complete=bool(meta.get("complete")),
                truncated=bool(meta.get("truncated")),
            )

        resp = await self.provider.generate_scene(request, receipt_sink=receipt_sink)

        if resp.outcome != "complete":
            final_status = "uncertain" if resp.outcome == "uncertain" else "failed"
            self.store.update_item_status(
                scene_key=scene.scene_key,
                status=final_status,
                error_stage="provider",
                error_code=resp.safe_code,
            )
            if progress_cb:
                progress_cb(f"scene_{final_status} {scene.scene_key} {resp.safe_code}")
            return {
                "outcome": resp.outcome,
                "safe_code": resp.safe_code,
                "attempt_ordinal": attempt.ordinal,
                "raw_receipt_ref": resp.raw_receipt_ref,
            }

        if resp.text is not None:
            self.store.save_response_text(scene, attempt.ordinal, resp.text)

        provider_result_doc = {
            "outcome": resp.outcome,
            "safe_code": resp.safe_code,
            "http_status": resp.http_status,
            "requested_model": resp.requested_model,
            "returned_model": resp.returned_model,
            "model_reported": resp.model_reported,
            "response_id": resp.response_id,
            "finish_reason": resp.finish_reason,
            "usage": resp.usage,
            "raw_receipt_ref": resp.raw_receipt_ref,
            "duration_ms": resp.duration_ms,
        }
        self.store.save_provider_result(scene, attempt.ordinal, provider_result_doc)

        return {
            "outcome": "complete",
            "safe_code": resp.safe_code,
            "attempt_ordinal": attempt.ordinal,
            "text": resp.text,
            "raw_receipt_ref": resp.raw_receipt_ref,
        }

    def _recover_provider_attempt(self, scene: SceneInput, attempt_ordinal: int) -> dict[str, Any]:
        """Reconcile one interrupted dispatch using only its verified immutable receipt."""
        try:
            loaded = self.store.load_verified_provider_receipt(scene, attempt_ordinal)
        except ArtifactCorruptError:
            self.store.update_item_status(scene.scene_key, "failed", "artifact", "artifact_corrupt")
            return {"outcome": "failed", "safe_code": "artifact_corrupt"}
        if loaded is None:
            self.store.update_item_status(scene.scene_key, "uncertain", "provider", "provider_uncertain")
            return {"outcome": "uncertain", "safe_code": "provider_uncertain"}

        receipt, body = loaded
        http_status = receipt.get("http_status")
        complete = receipt.get("complete") is True and receipt.get("truncated") is False
        try:
            run_doc = json.loads((self.store.output_dir / "run.json").read_text(encoding="utf-8"))
            provider_doc = run_doc.get("provider", {})
        except (OSError, ValueError, TypeError):
            provider_doc = {}
        provider_name = {
            "gemini_api": "gemini", "glm_api": "glm",
            "deepseek_api": "deepseek", "qwen_api": "qwen",
        }.get(provider_doc.get("id")) if isinstance(provider_doc, dict) else None
        requested_model = provider_doc.get("requested_model") if isinstance(provider_doc, dict) else None
        if not isinstance(provider_name, str) or not isinstance(requested_model, str):
            self.store.update_item_status(scene.scene_key, "failed", "artifact", "artifact_corrupt")
            return {"outcome": "failed", "safe_code": "artifact_corrupt"}

        receipt_ref = f"{self.store._scene_rel_dir(scene.lesson_ordinal, scene.scene_ordinal)}/attempt-{attempt_ordinal:04d}/response-receipt.json"
        if not complete or receipt.get("truncated") is True:
            self.store.update_item_status(scene.scene_key, "uncertain", "provider", "provider_uncertain")
            return {"outcome": "uncertain", "safe_code": "provider_uncertain"}
        if complete and http_status == 200:
            response = decode_generation_response(provider_name, requested_model, body, http_status, receipt_ref)
        elif isinstance(http_status, int) and 400 <= http_status < 500:
            response = decode_generation_response(provider_name, requested_model, body, http_status, receipt_ref)
        else:
            self.store.update_item_status(scene.scene_key, "uncertain", "provider", "provider_uncertain")
            return {"outcome": "uncertain", "safe_code": "provider_uncertain"}

        if response.outcome != "complete":
            status = "uncertain" if response.outcome == "uncertain" else "failed"
            stage = "provider" if status == "uncertain" else "provider"
            self.store.update_item_status(scene.scene_key, status, stage, response.safe_code)
            return {"outcome": status, "safe_code": response.safe_code}

        attempt_dir = self.store.get_attempt_dir(scene, attempt_ordinal)
        response_file = attempt_dir / "response.txt"
        if response_file.exists():
            if response_file.is_symlink() or response_file.read_bytes() != (response.text or "").encode("utf-8"):
                self.store.update_item_status(scene.scene_key, "failed", "artifact", "artifact_corrupt")
                return {"outcome": "failed", "safe_code": "artifact_corrupt"}
        else:
            self.store.save_response_text(scene, attempt_ordinal, response.text or "")

        provider_result_doc = {
            "outcome": response.outcome,
            "safe_code": response.safe_code,
            "http_status": response.http_status,
            "requested_model": response.requested_model,
            "returned_model": response.returned_model,
            "model_reported": response.model_reported,
            "response_id": response.response_id,
            "finish_reason": response.finish_reason,
            "usage": response.usage,
            "raw_receipt_ref": response.raw_receipt_ref,
            "duration_ms": response.duration_ms,
        }
        result_file = attempt_dir / "provider-result.json"
        if result_file.exists():
            try:
                saved = json.loads(result_file.read_text(encoding="utf-8"))
            except (OSError, ValueError):
                saved = None
            # Timing is measured at dispatch and cannot be reconstructed from
            # the response bytes. Validate every provider-derived field while
            # retaining the committed measurement verbatim.
            comparable = {key: value for key, value in provider_result_doc.items() if key != "duration_ms"}
            if not isinstance(saved, dict) or any(saved.get(key) != value for key, value in comparable.items()):
                self.store.update_item_status(scene.scene_key, "failed", "artifact", "artifact_corrupt")
                return {"outcome": "failed", "safe_code": "artifact_corrupt"}
            saved_duration = saved.get("duration_ms")
            if saved_duration is not None and (
                isinstance(saved_duration, bool) or not isinstance(saved_duration, int) or saved_duration < 0
            ):
                self.store.update_item_status(scene.scene_key, "failed", "artifact", "artifact_corrupt")
                return {"outcome": "failed", "safe_code": "artifact_corrupt"}
            provider_result_doc["duration_ms"] = saved_duration
        else:
            self.store.save_provider_result(scene, attempt_ordinal, provider_result_doc)
        return {
            "outcome": "complete",
            "safe_code": "ok",
            "attempt_ordinal": attempt_ordinal,
            "text": response.text,
            "raw_receipt_ref": receipt_ref,
        }

    def restore_receipt(self, scene: SceneInput, attempt_ordinal: int) -> Optional[dict[str, Any]]:
        """Restore normalized text only from a verified, complete provider receipt."""
        attempt_dir = self.store.get_attempt_dir(scene, attempt_ordinal)
        resp_file = attempt_dir / "response.txt"
        loaded = self.store.load_verified_provider_receipt(scene, attempt_ordinal)
        if loaded is None:
            return None
        receipt_meta, body = loaded
        if receipt_meta.get("complete") is not True or receipt_meta.get("truncated") is not False or receipt_meta.get("http_status") != 200:
            raise ArtifactCorruptError("artifact_corrupt")
        try:
            run_doc = json.loads((self.store.output_dir / "run.json").read_text(encoding="utf-8"))
            provider = run_doc.get("provider")
        except (OSError, ValueError, TypeError):
            raise ArtifactCorruptError("artifact_corrupt") from None
        if not isinstance(provider, dict):
            raise ArtifactCorruptError("artifact_corrupt")
        provider_name = {
            "gemini_api": "gemini", "glm_api": "glm",
            "deepseek_api": "deepseek", "qwen_api": "qwen",
        }.get(provider.get("id"))
        requested_model = provider.get("requested_model")
        if not isinstance(provider_name, str) or not isinstance(requested_model, str):
            raise ArtifactCorruptError("artifact_corrupt")
        receipt_ref = f"{self.store._scene_rel_dir(scene.lesson_ordinal, scene.scene_ordinal)}/attempt-{attempt_ordinal:04d}/response-receipt.json"
        response = decode_generation_response(provider_name, requested_model, body, 200, receipt_ref)
        if response.outcome != "complete" or response.text is None:
            raise ArtifactCorruptError("artifact_corrupt")
        if resp_file.exists():
            if resp_file.is_symlink() or resp_file.read_bytes() != response.text.encode("utf-8"):
                raise ArtifactCorruptError("artifact_corrupt")
        else:
            self.store.save_response_text(scene, attempt_ordinal, response.text)
        return {"text": response.text, "receipt": receipt_meta}

    def materialize_source(
        self,
        scene: SceneInput,
        attempt_ordinal: int,
        raw_text: Optional[str] = None,
    ) -> Any:
        """Parse source from retained text and save project package without any provider requests."""
        if raw_text is None:
            restored = self.restore_receipt(scene, attempt_ordinal)
            if not restored or not restored.get("text"):
                raise ValueError(f"No response text available for {scene.scene_key} attempt {attempt_ordinal}")
            raw_text = restored["text"]

        source_pkg = parse_html_scene_source(raw_text)
        scaffold_files: dict[str, str] = {}
        self.store.save_source_package(scene, attempt_ordinal, source_pkg, scaffold_files)
        return source_pkg

    def build_one(
        self,
        scene: SceneInput,
        attempt_ordinal: int,
        source_pkg: Optional[Any] = None,
        build_ordinal: Optional[int] = None,
        cancel_check: Optional[Callable[[], bool]] = None,
        heartbeat_cb: Optional[Callable[[float], None]] = None,
    ) -> dict[str, Any]:
        """Execute local build in an isolated subprocess group with responsive cancellation."""
        if cancel_check and cancel_check():
            return {"outcome": "failed", "safe_code": "build_cancelled"}

        if not self.store.verify_source_package(scene, attempt_ordinal):
            self.store.update_item_status(scene.scene_key, "failed", "artifact", "source_artifact_invalid")
            return {"outcome": "failed", "safe_code": "source_artifact_invalid"}

        build_ordinal = self.store.reserve_build(scene, attempt_ordinal)

        attempt_dir = self.store.get_attempt_dir(scene, attempt_ordinal)
        if source_pkg is None:
            pkg_file = attempt_dir / "source-package.json"
            if not pkg_file.exists():
                return {"outcome": "failed", "safe_code": "source_package_missing"}
            try:
                data = json.loads(pkg_file.read_text(encoding="utf-8"))
                from v3_engine.scene_generation.source_package import GeneratedFile, HtmlSceneSourceV1
                project_dir = attempt_dir / "project"
                files = []
                for f in data.get("files", []):
                    content = f.get("content")
                    if content is None:
                        f_path = project_dir / f["path"]
                        content = f_path.read_text(encoding="utf-8") if f_path.exists() else ""
                    body = content.encode("utf-8")
                    files.append(GeneratedFile(path=f["path"], content=content, bytes=len(body), sha256=hashlib.sha256(body).hexdigest()))
                source_pkg = HtmlSceneSourceV1(schema_version=data.get("schema_version", 1), files=files)
            except Exception:
                return {"outcome": "failed", "safe_code": "source_package_invalid"}

        self.store.update_item_status(scene_key=scene.scene_key, status="building")
        self.store.mark_build_running(scene, attempt_ordinal, build_ordinal)
        build_res = self.builder.build_scene(
            attempt_dir=attempt_dir,
            scene=scene,
            source_pkg=source_pkg,
            build_ordinal=build_ordinal,
            cancel_check=cancel_check,
            heartbeat_cb=heartbeat_cb,
        )

        if build_res.get("outcome") == "complete":
            self.store.finish_build(scene, attempt_ordinal, build_ordinal, "complete", build_res.get("manifest_path"))
            self.store.update_item_status(
                scene_key=scene.scene_key,
                status="generated",
                successful_build=build_ordinal,
                manifest_path=build_res.get("manifest_path"),
            )
        else:
            self.store.finish_build(scene, attempt_ordinal, build_ordinal, "failed")
            self.store.update_item_status(
                scene_key=scene.scene_key,
                status="failed",
                error_stage="build",
                error_code=build_res.get("safe_code"),
            )
        return build_res

    def build_only_scene(
        self,
        scene: SceneInput,
        attempt_ordinal: int = 1,
        cancel_check: Optional[Callable[[], bool]] = None,
        heartbeat_cb: Optional[Callable[[float], None]] = None,
    ) -> dict[str, Any]:
        """Perform build-only recovery on a scene with retained source without provider calls or credentials."""
        attempt_dir = self.store.get_attempt_dir(scene, attempt_ordinal)
        builds_dir = attempt_dir / "builds"

        # Only the complete pointer/manifest/output verifier can establish a
        # healthy build. A synthetic manifest status flag is not evidence.
        checkpoint_item = next(
            (row for row in self.store.load_checkpoint().get("items", []) if row.get("scene_key") == scene.scene_key),
            None,
        )
        if checkpoint_item is not None and self.store.verify_current_build(scene, checkpoint_item):
            raise ValueError(f"Intact healthy output cannot be rebuilt: {scene.scene_key}")

        # Find next build ordinal
        next_build_ordinal = 1
        if builds_dir.exists():
            existing_builds = [p.name for p in builds_dir.iterdir() if p.is_dir() and p.name.startswith("build-")]
            if existing_builds:
                ordinals = []
                for name in existing_builds:
                    try:
                        ordinals.append(int(name.split("-")[1]))
                    except (IndexError, ValueError):
                        pass
                if ordinals:
                    next_build_ordinal = max(ordinals) + 1

        return self.build_one(
            scene=scene,
            attempt_ordinal=attempt_ordinal,
            build_ordinal=next_build_ordinal,
            cancel_check=cancel_check,
            heartbeat_cb=heartbeat_cb,
        )

    def summarize(self) -> dict[str, Any]:
        ckpt = self.store.load_checkpoint()
        return {
            "status": ckpt["status"],
            "counts": ckpt["counts"],
            "run_id": ckpt["run_id"],
        }

    async def execute_run(
        self,
        inventory: HandoffInventory,
        limit: Optional[int] = None,
        retry_scenes: Optional[list[str]] = None,
        progress_cb: Optional[Callable[[str], None]] = None,
    ) -> dict[str, Any]:
        """Execute generation across all eligible scenes in handoff inventory."""
        ckpt = self.store.load_checkpoint()
        scene_map: dict[str, SceneInput] = {s.scene_key: s for s in inventory.scene_inputs}
        retry_set = set(retry_scenes or [])

        new_sends = 0

        for item in ckpt.get("items", []):
            if limit is not None and new_sends >= limit and item.get("status") not in ("source_saved", "building") and not (item.get("status") == "failed" and item.get("error_stage") == "build"):
                break
            scene_key = item["scene_key"]
            scene = scene_map.get(scene_key)
            if scene is None:
                continue

            current_status = item.get("status")

            # Check if already generated and valid
            if current_status == "generated":
                if self.store.verify_current_build(scene, item):
                    if progress_cb:
                        progress_cb(f"scene_skipped {scene_key}")
                    continue
                active = item.get("active_attempt")
                if isinstance(active, int) and not isinstance(active, bool) and self.store.verify_source_package(scene, active):
                    self.store.update_item_status(scene_key, "failed", "build", "compiled_artifact_invalid")
                    item["status"] = "failed"
                    item["error_stage"] = "build"
                    current_status = "failed"
                else:
                    self.store.update_item_status(scene_key, "failed", "artifact", "artifact_corrupt")
                    if progress_cb:
                        progress_cb(f"scene_failed {scene_key} artifact_corrupt")
                    continue

            # Source and local build states are never permission to buy another
            # provider response. Local recovery is added by the next task.
            local_recovery = current_status in ("source_saved", "building") or (
                current_status == "failed" and item.get("error_stage") == "build"
            )

            recovered_attempt = None
            recovered_text = None
            if current_status in ("sending", "response_saved"):
                active_attempt = item.get("active_attempt")
                if isinstance(active_attempt, bool) or not isinstance(active_attempt, int) or active_attempt < 1:
                    self.store.update_item_status(scene_key, "uncertain", "provider", "provider_uncertain")
                    continue
                recovered = self._recover_provider_attempt(scene, active_attempt)
                if recovered.get("outcome") != "complete":
                    if progress_cb:
                        progress_cb(f"scene_{recovered.get('outcome')} {scene_key} {recovered.get('safe_code')}")
                    continue
                recovered_attempt = active_attempt
                recovered_text = recovered.get("text")

            # Check if item failed or uncertain
            if current_status in ("failed", "uncertain"):
                if scene_key not in retry_set and not local_recovery:
                    continue
                if current_status == "failed" and not local_recovery and item.get("error_stage") not in {"provider", "package"}:
                    if progress_cb:
                        progress_cb(f"scene_retry_rejected {scene_key} retry_not_eligible")
                    continue
                if scene_key in retry_set and not local_recovery and progress_cb:
                    progress_cb(f"retry_may_duplicate_provider_work {scene_key}")

            # Check if prompt was defective from input
            if scene.input_error is not None:
                if progress_cb:
                    progress_cb(f"scene_failed {scene_key} {scene.input_error}")
                continue

            if local_recovery:
                active_attempt = item.get("active_attempt")
                try:
                    if isinstance(active_attempt, bool) or not isinstance(active_attempt, int) or active_attempt < 1:
                        raise ValueError("source_attempt_missing")
                    attempt_dir = self.store.get_attempt_dir(scene, active_attempt)
                    if not self.store.verify_source_package(scene, active_attempt):
                        raise ValueError("source_artifact_invalid")
                    package_doc = json.loads((attempt_dir / "source-package.json").read_text(encoding="utf-8"))
                    package_files = package_doc.get("files")
                    if not isinstance(package_files, list) or not package_files:
                        raise ValueError("source_package_invalid")
                    from v3_engine.scene_generation.source_package import GeneratedFile, HtmlSceneSourceV1
                    files = []
                    for entry in package_files:
                        if not isinstance(entry, dict) or not isinstance(entry.get("path"), str):
                            raise ValueError("source_package_invalid")
                        path = entry["path"]
                        content = (attempt_dir / "project" / path).read_text(encoding="utf-8")
                        files.append(GeneratedFile(path, content, len(content.encode("utf-8")), hashlib.sha256(content.encode("utf-8")).hexdigest()))
                    source_pkg = HtmlSceneSourceV1(schema_version=package_doc.get("schema_version", 1), files=files)
                    result = self.build_one(scene, active_attempt, source_pkg)
                    if progress_cb:
                        progress_cb(f"scene_local_recovery_{result.get('outcome')} {scene_key} {result.get('safe_code')}")
                except (OSError, ValueError, TypeError, json.JSONDecodeError) as exc:
                    self.store.update_item_status(scene_key, "failed", "artifact", "source_artifact_invalid")
                    if progress_cb:
                        progress_cb(f"scene_failed {scene_key} source_artifact_invalid {type(exc).__name__}")
                continue

            # A new provider request is made only for queued items or an
            # explicitly authorized provider retry. Recovered sends reuse the
            # exact active attempt and its verified receipt.
            if recovered_attempt is None:
                if limit is not None and new_sends >= limit:
                    break
                new_sends += 1
                generated = await self.generate_one(
                    scene,
                    explicit_retry_of=item.get("active_attempt") if scene_key in retry_set else None,
                    progress_cb=progress_cb,
                )
                if generated.get("outcome") != "complete":
                    continue
                attempt_ordinal = generated["attempt_ordinal"]
                response_text = generated.get("text")
            else:
                attempt_ordinal = recovered_attempt
                response_text = recovered_text

            # -------------------------------------------------------------
            # Parse a browser-native HTML/CSS/JavaScript deliverable.
            # -------------------------------------------------------------
            try:
                source_pkg = parse_html_scene_source(response_text or "")
            except SourcePackageError as exc:
                self.store.update_item_status(
                    scene_key=scene_key,
                    status="failed",
                    error_stage="package",
                    error_code=exc.code,
                )
                if progress_cb:
                    progress_cb(f"scene_failed {scene_key} {exc.code}")
                continue

            # -------------------------------------------------------------
            # Save source package & project
            # -------------------------------------------------------------
            scaffold_files: dict[str, str] = {}
            self.store.save_source_package(scene, attempt_ordinal, source_pkg, scaffold_files)

            if progress_cb:
                progress_cb(f"scene_source_saved {scene_key}")

            # -------------------------------------------------------------
            # Local compilation & build
            # -------------------------------------------------------------
            self.store.update_item_status(scene_key=scene_key, status="building")
            attempt_dir = self.store.get_attempt_dir(scene, attempt_ordinal)
            build_res = self.builder.build_scene(
                attempt_dir=attempt_dir,
                scene=scene,
                source_pkg=source_pkg,
                build_ordinal=1,
            )

            if build_res.get("outcome") == "complete":
                self.store.update_item_status(
                    scene_key=scene_key,
                    status="generated",
                    successful_build=1,
                    manifest_path=build_res.get("manifest_path"),
                )
                if progress_cb:
                    progress_cb(f"scene_generated {scene_key}")
            else:
                self.store.update_item_status(
                    scene_key=scene_key,
                    status="failed",
                    error_stage="build",
                    error_code=build_res.get("safe_code"),
                )
                if progress_cb:
                    progress_cb(f"scene_failed {scene_key} {build_res.get('safe_code')}")

        final_ckpt = self.store.load_checkpoint()
        return {
            "status": final_ckpt["status"],
            "counts": final_ckpt["counts"],
            "run_id": final_ckpt["run_id"],
        }
