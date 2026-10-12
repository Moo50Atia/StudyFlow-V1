"""Scene builder and local compilation orchestration (Spec 017)."""

from __future__ import annotations

from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import signal
import subprocess
import tempfile
import time
from typing import Any, Callable, Mapping, Optional

from v3_engine.scene_generation.contracts import SceneInput
from v3_engine.scene_generation.source_package import HtmlSceneSourceV1, ReactSceneSourceV1

ALLOWLIST_ENV_KEYS = frozenset(
    {
        "PATH",
        "HOME",
        "USER",
        "LOGNAME",
        "TMPDIR",
        "TMP",
        "TEMP",
        "XDG_CACHE_HOME",
        "LANG",
        "LC_ALL",
    }
)

MAX_DIST_BYTES = 64 * 1024 * 1024  # 64 MiB
MAX_DIST_FILES = 1024
SUBPROCESS_TIMEOUT_SECONDS = 300.0


def _rfc3339_now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def _atomic_json_write(path: Path, document: Mapping[str, Any]) -> None:
    """Publish JSON by fsynced temp file and atomic rename."""
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    fd, name = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
    temp = Path(name)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            json.dump(document, stream, ensure_ascii=False, indent=2)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temp, path)
    except Exception:
        temp.unlink(missing_ok=True)
        raise


def get_host_toolchain_info(scaffold_dir: Optional[Path] = None) -> dict[str, str]:
    """Identify the static browser artifact contract; no compiler is required."""
    contract = "HtmlCssJsSceneV1"
    digest = hashlib.sha256(contract.encode("utf-8")).hexdigest()
    return {
        "sha256": digest,
        "node_version": "not_required",
        "npm_version": "not_required",
        "catalog_sha256": "not_used",
        "source_contract": contract,
    }


def toolchain_prerequisite_error(info: Mapping[str, Any], scaffold_dir: Optional[Path] = None) -> Optional[str]:
    """Return a fixed safe explanation unless supported Node/npm and scaffold exist."""
    if info.get("source_contract") == "HtmlCssJsSceneV1":
        return None
    import re
    def version(value: Any) -> Optional[tuple[int, ...]]:
        if not isinstance(value, str):
            return None
        match = re.match(r"^v?(\d+)\.(\d+)(?:\.(\d+))?", value)
        return tuple(int(part or 0) for part in match.groups()) if match else None
    node = version(info.get("node_version"))
    npm = version(info.get("npm_version"))
    if node is None or node < (22, 12, 0):
        return "Node.js 22.12 or newer is required."
    if npm is None or npm < (10, 0, 0):
        return "npm 10 or newer is required."
    root = scaffold_dir or Path(__file__).parent / "scaffold"
    required = ("index.html", "main.tsx", "vite-env.d.ts", "tsconfig.json", "vite.config.mjs", "package-catalog.json", "validate-source.mjs")
    if any(not (root / name).is_file() or (root / name).is_symlink() for name in required):
        return "Required trusted scene scaffold files are unavailable."
    return None


def check_toolchain_drift(
    saved_toolchain: Mapping[str, Any],
    scaffold_dir: Optional[Path] = None,
) -> Optional[str]:
    """Check if host toolchain has drifted from recorded toolchain."""
    current = get_host_toolchain_info(scaffold_dir)
    for field in ("sha256", "node_version", "npm_version", "catalog_sha256", "source_contract"):
        saved = saved_toolchain.get(field)
        actual = current.get(field)
        if saved and actual and saved != "unknown" and actual != "unknown" and saved != actual:
            return f"Toolchain drifted: {field} differs from captured run"

    return None


def sanitize_child_env(env: Optional[Mapping[str, str]] = None) -> dict[str, str]:
    """Construct sanitized subprocess environment from strict allowlist."""
    source_env = env if env is not None else os.environ
    sanitized: dict[str, str] = {}
    for key in ALLOWLIST_ENV_KEYS:
        if key in source_env:
            sanitized[key] = source_env[key]
    sanitized["CI"] = "true"
    return sanitized


def synthesize_package_json(
    selected_dependencies: list[str],
    catalog_path: Optional[Path] = None,
) -> dict[str, Any]:
    """Generate minimal package.json containing core tools plus selected optional closure."""
    cat_path = catalog_path or (Path(__file__).parent / "scaffold" / "package-catalog.json")
    catalog = json.loads(cat_path.read_text(encoding="utf-8"))

    deps: dict[str, str] = dict(catalog.get("core_runtime", {}))
    dev_deps: dict[str, str] = dict(catalog.get("core_dev", {}))

    opt_catalog = catalog.get("optional_packages", {})
    for opt_name in selected_dependencies:
        if opt_name in opt_catalog:
            pkg_info = opt_catalog[opt_name]
            deps.update(pkg_info.get("dependencies", {}))
            dev_deps.update(pkg_info.get("devDependencies", {}))

    return {
        "name": "v3-generated-scene",
        "version": "0.1.0",
        "type": "module",
        "private": True,
        "scripts": {},
        "dependencies": deps,
        "devDependencies": dev_deps,
    }


def _run_step(
    cmd: list[str],
    cwd: Path,
    log_file: Path,
    env: dict[str, str],
    timeout: float = SUBPROCESS_TIMEOUT_SECONDS,
    cancel_check: Optional[Callable[[], bool]] = None,
    heartbeat_cb: Optional[Callable[[float], None]] = None,
) -> tuple[int, bool, int, bool]:
    start_mono = time.monotonic()
    timed_out = False
    cancelled = False
    exit_code = -1
    last_heartbeat = start_mono
    try:
        proc = subprocess.Popen(
            cmd,
            cwd=str(cwd),
            env=env,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            start_new_session=True,
        )
        pgid = os.getpgid(proc.pid)

        while True:
            ret = proc.poll()
            now = time.monotonic()
            elapsed = now - start_mono

            if heartbeat_cb and (now - last_heartbeat) >= 5.0:
                heartbeat_cb(elapsed)
                last_heartbeat = now

            if cancel_check and cancel_check():
                cancelled = True
                try:
                    os.killpg(pgid, signal.SIGTERM)
                except OSError:
                    pass
                term_start = time.monotonic()
                while proc.poll() is None and (time.monotonic() - term_start) < 5.0:
                    time.sleep(0.1)
                if proc.poll() is None:
                    try:
                        os.killpg(pgid, signal.SIGKILL)
                    except OSError:
                        pass
                out, _ = proc.communicate()
                log_file.write_bytes((out or b"") + b"\n[CANCELLED] Build cancelled by operator.")
                exit_code = -1
                break

            if ret is not None:
                exit_code = ret
                out, _ = proc.communicate()
                log_file.write_bytes(out or b"")
                break

            if elapsed > timeout:
                timed_out = True
                try:
                    os.killpg(pgid, signal.SIGKILL)
                except OSError:
                    pass
                out, _ = proc.communicate()
                log_file.write_bytes((out or b"") + b"\n[TIMEOUT] Command timed out.")
                exit_code = -1
                break

            time.sleep(0.05)
    except Exception as exc:
        exit_code = -1
        log_file.write_text(f"[ERROR] Failed to execute command: {exc}", encoding="utf-8")

    duration_ms = int((time.monotonic() - start_mono) * 1000)
    return exit_code, timed_out, duration_ms, cancelled


class SceneBuilder:
    """Compiles React/TypeScript source packages in an isolated subprocess sandbox."""

    def __init__(self, scaffold_dir: Optional[Path] = None) -> None:
        self.scaffold_dir = scaffold_dir or (Path(__file__).parent / "scaffold")

    def get_scaffold_files(self, selected_dependencies: list[str]) -> dict[str, str]:
        """Load and return all fixed host scaffold files and synthesized package.json."""
        index_html = (self.scaffold_dir / "index.html").read_text(encoding="utf-8")
        main_tsx = (self.scaffold_dir / "main.tsx").read_text(encoding="utf-8")
        vite_env_dts = (self.scaffold_dir / "vite-env.d.ts").read_text(encoding="utf-8")
        tsconfig_json = (self.scaffold_dir / "tsconfig.json").read_text(encoding="utf-8")
        vite_config_mjs = (self.scaffold_dir / "vite.config.mjs").read_text(encoding="utf-8")
        package_json = json.dumps(
            synthesize_package_json(selected_dependencies, self.scaffold_dir / "package-catalog.json"),
            indent=2,
        )

        return {
            "index.html": index_html,
            "src/main.tsx": main_tsx,
            "src/vite-env.d.ts": vite_env_dts,
            "tsconfig.json": tsconfig_json,
            "vite.config.mjs": vite_config_mjs,
            "package.json": package_json,
        }

    def build_scene(
        self,
        attempt_dir: Path,
        scene: SceneInput,
        source_pkg: ReactSceneSourceV1,
        build_ordinal: int = 1,
        cancel_check: Optional[Callable[[], bool]] = None,
        heartbeat_cb: Optional[Callable[[float], None]] = None,
    ) -> dict[str, Any]:
        """Execute install, validation, typecheck, and Vite build stages."""
        if cancel_check and cancel_check():
            return {"outcome": "failed", "safe_code": "build_cancelled"}

        attempt_path = Path(attempt_dir).resolve()
        project_dir = attempt_path / "project"
        builds_dir = attempt_path / "builds"
        build_dir = builds_dir / f"build-{build_ordinal:04d}"
        builds_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
        try:
            build_dir.mkdir(mode=0o700, exist_ok=False)
        except FileExistsError:
            return {"status": "failed", "outcome": "failed", "safe_code": "build_ordinal_collision", "build_ordinal": build_ordinal}
        dist_dir = build_dir / "dist"

        try:
            attempt_doc = json.loads((attempt_path / "attempt.json").read_text(encoding="utf-8"))
            provider_result_path = attempt_path / "provider-result.json"
            source_manifest_path = attempt_path / "source-manifest.json"
            if provider_result_path.is_symlink() or source_manifest_path.is_symlink():
                raise ValueError("provenance_symlink")
            provider_result = json.loads(provider_result_path.read_text(encoding="utf-8"))
            source_manifest_bytes = source_manifest_path.read_bytes()
            if not isinstance(attempt_doc, dict) or not isinstance(provider_result, dict) or provider_result.get("outcome") != "complete":
                raise ValueError("provenance_invalid")
            if provider_result.get("requested_model") != attempt_doc.get("requested_model"):
                raise ValueError("provenance_model_mismatch")
            source_manifest = json.loads(source_manifest_bytes)
            if not isinstance(source_manifest, dict) or source_manifest.get("scene_key") != scene.scene_key or source_manifest.get("attempt_ordinal") != attempt_doc.get("ordinal"):
                raise ValueError("source_manifest_identity")
            source_rows = source_manifest.get("files")
            if not isinstance(source_rows, list) or not source_rows:
                raise ValueError("source_manifest_files")
            project_root = project_dir.resolve(strict=True)
            for row in source_rows:
                if not isinstance(row, dict) or not isinstance(row.get("path"), str):
                    raise ValueError("source_manifest_file_invalid")
                source_file = project_dir / row["path"]
                if source_file.is_symlink():
                    raise ValueError("source_symlink")
                resolved_source = source_file.resolve(strict=True)
                if not resolved_source.is_relative_to(project_root):
                    raise ValueError("source_path_escape")
                source_bytes = resolved_source.read_bytes()
                if len(source_bytes) != row.get("bytes") or hashlib.sha256(source_bytes).hexdigest() != row.get("sha256"):
                    raise ValueError("source_file_digest")
            receipt_path = attempt_path / "response-receipt.json"
            if receipt_path.is_symlink():
                raise ValueError("receipt_symlink")
            receipt = json.loads(receipt_path.read_text(encoding="utf-8"))
            body_rel = receipt.get("body_path") if isinstance(receipt, dict) else None
            if body_rel != "provider-body.bin":
                raise ValueError("receipt_path_invalid")
            body_path = attempt_path / body_rel
            if body_path.is_symlink():
                raise ValueError("receipt_body_symlink")
            raw_body = body_path.read_bytes()
            if (
                receipt.get("schema_version") != 1
                or receipt.get("attempt_ordinal") != attempt_doc.get("ordinal")
                or receipt.get("complete") is not True
                or receipt.get("truncated") is not False
                or receipt.get("http_status") != 200
                or receipt.get("body_bytes") != len(raw_body)
                or receipt.get("body_sha256") != hashlib.sha256(raw_body).hexdigest()
            ):
                raise ValueError("receipt_integrity")
            expected_receipt_ref = str(receipt_path.relative_to(attempt_path.parents[2]))
            if provider_result.get("raw_receipt_ref") != expected_receipt_ref:
                raise ValueError("provider_receipt_reference")
            provider_alias = {"gemini_api": "gemini", "glm_api": "glm", "deepseek_api": "deepseek", "qwen_api": "qwen"}.get(attempt_doc.get("provider_id"))
            if not provider_alias:
                raise ValueError("provider_identity")
            from v3_engine.scene_generation.providers import decode_generation_response
            decoded = decode_generation_response(provider_alias, attempt_doc.get("requested_model"), raw_body, 200, expected_receipt_ref)
            saved_text = (attempt_path / "response.txt").read_text(encoding="utf-8")
            if decoded.outcome != "complete" or decoded.text != saved_text:
                raise ValueError("normalized_response_mismatch")
            if (
                provider_result.get("response_id") != decoded.response_id
                or provider_result.get("returned_model") != decoded.returned_model
                or provider_result.get("model_reported") is not decoded.model_reported
                or provider_result.get("usage") != decoded.usage
                or provider_result.get("http_status") != 200
            ):
                raise ValueError("provider_result_mismatch")
        except (OSError, ValueError, TypeError):
            return {"status": "failed", "outcome": "failed", "safe_code": "build_provenance_invalid", "build_ordinal": build_ordinal}

        env = sanitize_child_env()
        overall_start_wall = _rfc3339_now()
        overall_start_mono = time.monotonic()

        steps_record: list[dict[str, Any]] = [
            {"stage": "install", "started_at": None, "finished_at": None, "duration_ms": None, "exit_code": None, "timed_out": False, "log_path": None},
            {"stage": "validation", "started_at": None, "finished_at": None, "duration_ms": None, "exit_code": None, "timed_out": False, "log_path": None},
            {"stage": "typecheck", "started_at": None, "finished_at": None, "duration_ms": None, "exit_code": None, "timed_out": False, "log_path": None},
            {"stage": "build", "started_at": None, "finished_at": None, "duration_ms": None, "exit_code": None, "timed_out": False, "log_path": None},
        ]

        def record_step(idx: int, s_wall: str, f_wall: str, dur_ms: int, exit_c: int, t_out: bool, log_p: str) -> None:
            steps_record[idx].update({
                "started_at": s_wall,
                "finished_at": f_wall,
                "duration_ms": dur_ms,
                "exit_code": exit_c,
                "timed_out": t_out,
                "log_path": log_p,
            })

        safe_outcome = "complete"
        safe_code = "ok"
        static_html = isinstance(source_pkg, HtmlSceneSourceV1)

        # -------------------------------------------------------------
        # Step 0: Install
        # -------------------------------------------------------------
        step0_start = _rfc3339_now()
        lock_file = project_dir / "package-lock.json"
        if static_html:
            steps_record[0].update({"stage": "static_materialize", "exit_code": 0})
        elif lock_file.exists():
            install_cmd = ["npm", "ci", "--ignore-scripts", "--no-audit", "--no-fund", "--registry=https://registry.npmjs.org"]
        else:
            install_cmd = ["npm", "install", "--ignore-scripts", "--no-audit", "--no-fund", "--registry=https://registry.npmjs.org"]

        if not static_html:
            install_log = build_dir / "install.log"
            code, timed_out, dur, cancelled = _run_step(install_cmd, project_dir, install_log, env, cancel_check=cancel_check, heartbeat_cb=heartbeat_cb)
            step0_finish = _rfc3339_now()
            record_step(0, step0_start, step0_finish, dur, code, timed_out, "install.log")

            if cancelled:
                safe_outcome = "failed"
                safe_code = "build_cancelled"
            elif timed_out:
                safe_outcome = "failed"
                safe_code = "build_timeout"
            elif code != 0:
                safe_outcome = "failed"
                safe_code = "dependency_install_failed"

        # -------------------------------------------------------------
        # Step 1: Validation
        # -------------------------------------------------------------
        if static_html:
            try:
                dist_dir.mkdir(parents=True, exist_ok=False, mode=0o700)
                for generated in source_pkg.files:
                    if generated.path not in {"index.html", "styles.css", "script.js"}:
                        raise ValueError("static_path_invalid")
                    (dist_dir / generated.path).write_text(generated.content, encoding="utf-8")
                steps_record[0].update({"stage": "static_materialize", "exit_code": 0})
            except (OSError, ValueError):
                safe_outcome = "failed"
                safe_code = "build_output_invalid"

        # React projects retain the legacy isolated npm/typecheck/Vite path.
        # HTML/CSS/JavaScript scenes are static deliverables and never enter it.
        if safe_outcome == "complete" and not static_html:
            step1_start = _rfc3339_now()
            val_script = self.scaffold_dir / "validate-source.mjs"
            pkg_data = synthesize_package_json(source_pkg.dependencies, self.scaffold_dir / "package-catalog.json")
            allowed_pkgs = list(source_pkg.dependencies) + list(pkg_data.get("dependencies", {}).keys())
            val_cmd = ["node", str(val_script), str(project_dir), json.dumps(allowed_pkgs)]
            val_log = build_dir / "validation.log"

            code, timed_out, dur, cancelled = _run_step(val_cmd, project_dir, val_log, env, cancel_check=cancel_check, heartbeat_cb=heartbeat_cb)
            step1_finish = _rfc3339_now()
            record_step(1, step1_start, step1_finish, dur, code, timed_out, "validation.log")

            if cancelled:
                safe_outcome = "failed"
                safe_code = "build_cancelled"
            elif timed_out:
                safe_outcome = "failed"
                safe_code = "build_timeout"
            elif code != 0:
                safe_outcome = "failed"
                safe_code = "source_import_invalid"

        # -------------------------------------------------------------
        # Step 2: Typecheck
        # -------------------------------------------------------------
        if safe_outcome == "complete" and not static_html:
            step2_start = _rfc3339_now()
            tsc_bin = project_dir / "node_modules" / "typescript" / "bin" / "tsc"
            tc_cmd = ["node", str(tsc_bin), "--noEmit", "--project", "tsconfig.json"]
            tc_log = build_dir / "typecheck.log"

            code, timed_out, dur, cancelled = _run_step(tc_cmd, project_dir, tc_log, env, cancel_check=cancel_check, heartbeat_cb=heartbeat_cb)
            step2_finish = _rfc3339_now()
            record_step(2, step2_start, step2_finish, dur, code, timed_out, "typecheck.log")

            if cancelled:
                safe_outcome = "failed"
                safe_code = "build_cancelled"
            elif timed_out:
                safe_outcome = "failed"
                safe_code = "build_timeout"
            elif code != 0:
                safe_outcome = "failed"
                safe_code = "typecheck_failed"

        # -------------------------------------------------------------
        # Step 3: Build
        # -------------------------------------------------------------
        if safe_outcome == "complete" and not static_html:
            step3_start = _rfc3339_now()
            vite_bin = project_dir / "node_modules" / "vite" / "bin" / "vite.js"
            b_cmd = [
                "node",
                str(vite_bin),
                "build",
                "--config",
                "vite.config.mjs",
                "--base=./",
                "--outDir",
                str(dist_dir),
                "--emptyOutDir",
            ]
            b_log = build_dir / "build.log"

            code, timed_out, dur, cancelled = _run_step(b_cmd, project_dir, b_log, env, cancel_check=cancel_check, heartbeat_cb=heartbeat_cb)
            step3_finish = _rfc3339_now()
            record_step(3, step3_start, step3_finish, dur, code, timed_out, "build.log")

            if cancelled:
                safe_outcome = "failed"
                safe_code = "build_cancelled"
            elif timed_out:
                safe_outcome = "failed"
                safe_code = "build_timeout"
            elif code != 0:
                safe_outcome = "failed"
                safe_code = "build_failed"

        # -------------------------------------------------------------
        # Step 4: Validate Output Artifacts
        # -------------------------------------------------------------
        dist_files_meta: list[dict[str, Any]] = []
        if safe_outcome == "complete":
            dist_index = dist_dir / "index.html"
            if not dist_index.exists() or dist_index.stat().st_size == 0:
                safe_outcome = "failed"
                safe_code = "build_output_invalid"
            else:
                total_dist_size = 0
                for root, _, files in os.walk(dist_dir):
                    for fname in files:
                        fpath = Path(root) / fname
                        if fpath.is_symlink():
                            safe_outcome = "failed"
                            safe_code = "build_output_invalid"
                            break
                        fbytes = fpath.read_bytes()
                        total_dist_size += len(fbytes)
                        rel = str(fpath.relative_to(dist_dir))
                        dist_files_meta.append({
                            "path": rel,
                            "bytes": len(fbytes),
                            "sha256": hashlib.sha256(fbytes).hexdigest(),
                        })
                if len(dist_files_meta) > MAX_DIST_FILES or total_dist_size > MAX_DIST_BYTES:
                    safe_outcome = "failed"
                    safe_code = "build_output_invalid"

        overall_finish_wall = _rfc3339_now()
        overall_duration_ms = int((time.monotonic() - overall_start_mono) * 1000)

        package_lock_sha: Optional[str] = None
        if lock_file.exists():
            package_lock_sha = hashlib.sha256(lock_file.read_bytes()).hexdigest()

        build_report = {
            "schema_version": 1,
            "build_ordinal": build_ordinal,
            "started_at": overall_start_wall,
            "finished_at": overall_finish_wall,
            "duration_ms": overall_duration_ms,
            "steps": steps_record,
            "outcome": safe_outcome,
            "safe_code": safe_code,
            "package_lock_sha256": package_lock_sha,
        }
        (build_dir / "build-report.json").write_text(json.dumps(build_report, indent=2), encoding="utf-8")

        # Read attempt.json and run.json for provenance if available
        manifest_rel: Optional[str] = None
        if safe_outcome == "complete":
            dist_files_meta.sort(key=lambda x: x["path"])
            manifest_doc = {
                "schema_version": 1,
                "kind": "local_static_html_scene" if static_html else "local_compiled_react_scene",
                "run_id": attempt_doc.get("run_id"),
                "scene_key": scene.scene_key,
                "notebook_id": scene.notebook_id,
                "conversation_id": scene.conversation_id,
                "lesson_ordinal": scene.lesson_ordinal,
                "lesson_title": scene.lesson_title,
                "scene_ordinal": scene.scene_ordinal,
                "section_title": scene.section_title,
                "scene_type": scene.scene_type,
                "prompt_sha256": scene.prompt_sha256,
                "template_sha256": attempt_doc.get("template_sha256"),
                "source_manifest_sha256": hashlib.sha256(source_manifest_bytes).hexdigest(),
                "provider_id": attempt_doc.get("provider_id"),
                "requested_model": attempt_doc.get("requested_model"),
                "returned_model": provider_result.get("returned_model"),
                "model_reported": provider_result.get("model_reported", False),
                "provider_response_id": provider_result.get("response_id"),
                "usage": provider_result.get("usage"),
                "attempt_ordinal": attempt_doc.get("ordinal", 1),
                "build_ordinal": build_ordinal,
                "toolchain_sha256": attempt_doc.get("toolchain_sha256"),
                "package_lock_sha256": package_lock_sha,
                "entrypoint": "index.html",
                "files": dist_files_meta,
                "created_at": overall_finish_wall,
                "qualification": {
                    "mode": "owner_waived_terminal_demo",
                    "jev_called": False,
                },
                "verification": {
                    "typecheck": False if static_html else True,
                    "production_build": False if static_html else True,
                    "static_files_materialized": True if static_html else False,
                    "browser": "not_run",
                    "educational_review": "not_run",
                },
            }
            manifest_file = build_dir / "manifest.json"
            _atomic_json_write(manifest_file, manifest_doc)
            manifest_rel = str(manifest_file.relative_to(attempt_path.parents[2]))

            # Publish current.json in scene directory
            scene_dir = attempt_path.parent
            current_doc = {
                "schema_version": 1,
                "run_id": attempt_doc.get("run_id"),
                "scene_key": scene.scene_key,
                "attempt_ordinal": attempt_doc.get("ordinal", 1),
                "build_ordinal": build_ordinal,
                "manifest_path": str(manifest_file.relative_to(scene_dir)),
                "manifest_sha256": hashlib.sha256(manifest_file.read_bytes()).hexdigest(),
                "published_at": overall_finish_wall,
            }
            _atomic_json_write(scene_dir / "current.json", current_doc)

        return {
            "status": safe_outcome,
            "outcome": safe_outcome,
            "safe_code": safe_code,
            "build_ordinal": build_ordinal,
            "report": build_report,
            "manifest_path": manifest_rel,
        }
