"""Run one NotebookLM notebook from a directory of PDFs to lesson JSON handoffs.

This standalone integration runner does not start Laravel or Temporal. It uses
the already provisioned notebooklm-py storage state, never opens a browser,
and keeps an atomic checkpoint so an interrupted run cannot silently resend a
Continue turn. Run with --help for the exact command-line contract.
"""

from __future__ import annotations

import argparse
import asyncio
import hashlib
import json
import os
import re
import sys
import tempfile
import uuid
from datetime import datetime, timezone
from pathlib import Path

PROJECT_ROOT = Path(__file__).resolve().parents[2]
if str(PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(PROJECT_ROOT))

from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT, EXECUTION_PROMPT
from v3_engine.notebooklm.bundle_parser import parse_combined_bundle
from v3_engine.notebooklm.credentials import get_storage_state_path
from v3_engine.notebooklm.protocol import (
    COMBINED_RESPONSE_FORMAT_TEXT,
    parse_direct_divide_map,
)
from v3_engine.notebooklm.sdk_provider import SdkSessionProvider
from v3_engine.notebooklm.terminal_service import NotebookTerminalService


THIRD_HEADER = re.compile(r"^Lec\s*\d+\s*\(\s*Sec\s*\d+\s*\)\s*:", re.I)
LOOSE_THIRD_HEADER = re.compile(
    r"^Lec\s*\d+\s*\(\s*Sec\s*(\d+)\s*\)\s*:\s*(.*)$", re.I | re.M
)
W_LABEL = re.compile(r"^W(\d+)\b")
MARKER = re.compile(r"✅\s*Lesson\s*\[?(\d+)\]?\s*Completed")


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def atomic_json(path: Path, data: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    fd, name = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            json.dump(data, stream, ensure_ascii=False, indent=2)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(name, 0o600)
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def local_sources(pdf_dir: Path, steps: Path, expected_pdfs: int) -> list[dict]:
    paths = sorted(
        pdf_dir.glob("*.pdf"),
        key=lambda path: (int(re.search(r"(\d+)(?=\.pdf$)", path.name, re.I).group(1))
                          if re.search(r"(\d+)(?=\.pdf$)", path.name, re.I)
                          else 10**9, path.name),
    )
    if len(paths) != expected_pdfs:
        raise RuntimeError(f"Expected {expected_pdfs} PDFs, found {len(paths)} in {pdf_dir}")
    if not steps.is_file():
        raise RuntimeError(f"Framework file missing: {steps}")
    sources = []
    for index, path in enumerate(paths, 1):
        if not path.is_file() or path.stat().st_size == 0:
            raise RuntimeError(f"PDF missing or empty: {path}")
        number_match = re.search(r"(\d+)(?=\.pdf$)", path.name, re.I)
        number = int(number_match.group(1)) if number_match else index
        sources.append({
            "role": "educational_pdf",
            "path": str(path.resolve()),
            "title": f"Lecture {number:02d} - {path.name}",
            "sha256": sha256_file(path),
            "bytes": path.stat().st_size,
            "source_id": None,
            "status": "pending",
        })
    sources.append({
        "role": "framework_steps",
        "path": str(steps.resolve()),
        "title": "steps.txt",
        "sha256": sha256_file(steps),
        "bytes": steps.stat().st_size,
        "source_id": None,
        "status": "pending",
    })
    return sources


def normalize_map(raw: str) -> str:
    lines = []
    for line in raw.splitlines():
        stripped = line.strip()
        if not stripped or stripped == "---":
            continue
        if stripped.startswith("#"):
            stripped = re.sub(r"^#+\s*", "", stripped)
            if not stripped.lower().startswith(("chapter ", "mini chapter ", "lesson ")):
                continue
            lines.append(stripped)
        else:
            lines.append(line)
    return "\n".join(lines)


def loose_work_nodes(raw: str) -> list[dict]:
    """Derive a Continue count from a noncanonical map without judging its quality."""
    nodes = []
    for line in raw.splitlines():
        match = re.search(
            r"\bLesson(?:\s+[0-9]+(?:\.[0-9]+)*)?\s*(?::|=>|-)\s*(.+)$",
            line.strip(), re.I,
        )
        if match:
            title = match.group(1).strip().strip("* ")
            if title:
                nodes.append({"kind": "lesson", "title": title,
                              "work_ordinal": len(nodes) + 1})
    if nodes:
        return nodes
    for line in raw.splitlines():
        match = re.search(
            r"\bMini\s+Chapter(?:\s+[0-9]+(?:\.[0-9]+)*)?\s*(?::|=>|-)\s*(.+)$",
            line.strip(), re.I,
        )
        if match:
            title = match.group(1).strip().strip("* ")
            if title:
                nodes.append({"kind": "mini_chapter", "title": title,
                              "work_ordinal": len(nodes) + 1})
    return nodes


def raw_artifacts(answer: str) -> dict[str, str | None]:
    """Keep every byte in raw_answer; slices here are best-effort conveniences."""
    markers = [(name, answer.find(marker), len(marker)) for name, marker in (
        ("first", "=== FIRST ==="), ("second", "=== SECOND ==="),
        ("third", "=== THIRD ==="), ("end", "=== END ==="),
    )]
    result: dict[str, str | None] = {"first": None, "second": None, "third": None}
    if all(position >= 0 for _, position, _ in markers) and all(
        markers[i][1] < markers[i + 1][1] for i in range(3)
    ):
        for i in range(3):
            result[markers[i][0]] = answer[
                markers[i][1] + markers[i][2]:markers[i + 1][1]
            ].strip()
    return result


def loose_scene_prompts(third: str | None) -> list[dict]:
    if not third:
        return []
    headers = list(LOOSE_THIRD_HEADER.finditer(third))
    scenes = []
    for index, header in enumerate(headers):
        stop = headers[index + 1].start() if index + 1 < len(headers) else len(third)
        prompt = (header.group(2) + "\n" + third[header.end():stop]).strip()
        if prompt.startswith(('"', "'")):
            quote = prompt[0]
            prompt = prompt[1:]
            if prompt.endswith(quote):
                prompt = prompt[:-1]
        prompt = prompt.strip()
        first_line = next((line.strip() for line in prompt.splitlines() if line.strip()), "")
        scene_type = re.sub(r"^Scene\s+Type\s*[:.]\s*", "", first_line, flags=re.I)
        scenes.append({
            "scene_ordinal": int(header.group(1)),
            "scene_type": scene_type.split(":", 1)[0].strip(),
            "generated_prompt": prompt,
        })
    return scenes


def normalize_third_quotes(raw: str) -> tuple[str, int]:
    lines = raw.splitlines()
    try:
        start = lines.index("=== THIRD ===") + 1
        end = lines.index("=== END ===")
    except ValueError:
        return raw, 0
    headers = [index for index in range(start, end) if THIRD_HEADER.match(lines[index].strip())]
    changed = 0
    for position, header in enumerate(headers):
        following = headers[position + 1] if position + 1 < len(headers) else end
        last = following - 1
        while last > header and not lines[last].strip():
            last -= 1
        first = lines[header].split(":", 1)[1].strip()
        if not first:
            first_line = next((index for index in range(header + 1, following) if lines[index].strip()), None)
            first = lines[first_line].strip() if first_line is not None else ""
        else:
            first_line = header
        if first and not first.startswith('"') and first_line is not None:
            if first_line == header:
                prefix, body = lines[header].split(":", 1)
                lines[header] = prefix + ': "' + body.strip()
            else:
                lines[first_line] = '"' + lines[first_line].lstrip()
            changed += 1
        if first and not lines[last].rstrip().endswith('"'):
            lines[last] = lines[last].rstrip() + '"'
            changed += 1
    return "\n".join(lines), changed


class Run:
    def __init__(self, args: argparse.Namespace, sources: list[dict]):
        self.args = args
        self.checkpoint = args.output_dir / "checkpoint.json"
        self.handoff_dir = args.output_dir / "lesson_handoffs"
        if self.checkpoint.exists():
            self.data = json.loads(self.checkpoint.read_text(encoding="utf-8"))
            for old, current in zip(self.data["sources"], sources, strict=True):
                if any(old[key] != current[key] for key in ("role", "path", "title", "sha256", "bytes")):
                    raise RuntimeError("Input files changed since checkpoint; use a new output directory")
            if self.data["account_proof_notebook"] != args.account_check_notebook_id:
                raise RuntimeError("Account proof changed since checkpoint")
            saved_mode = bool(self.data.get("capture_raw_output", False))
            if saved_mode and not args.capture_raw_output:
                raise RuntimeError("This checkpoint uses raw capture; rerun with --capture-raw-output")
            if args.capture_raw_output and not saved_mode:
                self.data["capture_raw_output"] = True
                self.save()
        else:
            run_id = str(uuid.uuid4())
            self.data = {
                "schema_version": 1,
                "kind": "notebooklm_multi_pdf_run",
                "run_id": run_id,
                "account_proof_notebook": args.account_check_notebook_id,
                "notebook_title": f"{args.title} {run_id[:8]}",
                "notebook_id": None,
                "sources": sources,
                "conversation_id": None,
                "divide": None,
                "execution": None,
                "lessons": [],
                "status": "initialized",
                "capture_raw_output": args.capture_raw_output,
            }
            self.save()

    def save(self) -> None:
        self.data["updated_at"] = utc_now()
        atomic_json(self.checkpoint, self.data)

    def source_ids(self, include_framework: bool) -> list[str]:
        return [source["source_id"] for source in self.data["sources"]
                if include_framework or source["role"] == "educational_pdf"]

    async def notebook_and_sources(self, session) -> None:
        data = self.data
        notebooks = await session.files.list_notebooks()
        if self.args.account_check_notebook_id not in {item.id for item in notebooks}:
            raise RuntimeError("Credential cache does not contain the expected Pro-account notebook")
        matches = [item for item in notebooks if item.title == data["notebook_title"]]
        if data["notebook_id"]:
            if not any(item.id == data["notebook_id"] for item in matches):
                raise RuntimeError("Saved notebook is absent from authenticated account")
        elif len(matches) == 1:
            data["notebook_id"] = matches[0].id
            self.save()
            print("notebook_recovered", data["notebook_id"], flush=True)
        elif matches:
            raise RuntimeError("Multiple notebooks have the same run title")
        else:
            data["status"] = "notebook_pending"
            self.save()
            notebook = await session.files.create_notebook(data["notebook_title"])
            data["notebook_id"] = notebook.id
            self.save()
            print("notebook_created", notebook.id, flush=True)

        notebook_id = data["notebook_id"]
        for source in data["sources"]:
            if sha256_file(Path(source["path"])) != source["sha256"]:
                raise RuntimeError(f"Input changed: {Path(source['path']).name}")
            inventory = await session.files.get_notebook(notebook_id)
            known = {item["source_id"] for item in data["sources"] if item["source_id"]}
            unknown = set(inventory.source_ids) - known
            if source["source_id"]:
                if source["source_id"] not in inventory.source_ids:
                    raise RuntimeError("Saved source absent from notebook")
            elif unknown:
                if hasattr(session.files, "list_sources"):
                    provider_sources = await session.files.list_sources(notebook_id)
                else:
                    provider_sources = await session.client.sources.list(notebook_id)
                accepted_titles = {source["title"], Path(source["path"]).name}
                candidates = [
                    item for item in provider_sources
                    if getattr(item, "source_id", getattr(item, "id", None)) in unknown and item.title in accepted_titles
                ]
                if len(unknown) != 1 or len(candidates) != 1:
                    raise RuntimeError("Unknown notebook source; inspect before retrying upload")
                source["source_id"] = getattr(candidates[0], "source_id", getattr(candidates[0], "id", None))
                self.save()
                print("source_recovered", source["title"], flush=True)
            else:
                data["status"] = "upload_pending"
                self.save()
                mime = "application/pdf" if source["role"] == "educational_pdf" else "text/plain"
                receipt = await session.files.upload_file(
                    notebook_id, Path(source["path"]), mime, source["title"]
                )
                source["source_id"] = receipt.source_id
                source["status"] = receipt.status
                self.save()
                print("source_uploaded", source["title"], flush=True)

        deadline = asyncio.get_running_loop().time() + self.args.source_timeout
        while True:
            waiting = 0
            for source in data["sources"]:
                receipt = await session.files.get_source(notebook_id, source["source_id"])
                accepted_titles = {source["title"], Path(source["path"]).name}
                if receipt.source_id != source["source_id"] or receipt.title not in accepted_titles:
                    raise RuntimeError(
                        f"Source identity changed during processing: {source['source_id']}"
                    )
                expected_mime = (
                    {"application/pdf"} if source["role"] == "educational_pdf"
                    else {"text/plain", "text/markdown"}
                )
                if (receipt.mime_type or "").split(";", 1)[0] not in expected_mime:
                    raise RuntimeError(f"Source MIME changed: {source['source_id']}")
                source["observed_title"] = receipt.title
                if receipt.status == "error":
                    source["status"] = "error"
                    self.save()
                    raise RuntimeError(f"NotebookLM could not process {source['title']}")
                source["status"] = receipt.status
                waiting += receipt.status != "ready"
            self.save()
            if waiting == 0:
                break
            if asyncio.get_running_loop().time() >= deadline:
                raise RuntimeError(f"Source processing timed out with {waiting} pending")
            print("sources_ready", len(data["sources"]) - waiting, "of", len(data["sources"]), flush=True)
            await asyncio.sleep(10)
        inventory = await session.files.get_notebook(notebook_id)
        if set(inventory.source_ids) != set(self.source_ids(True)):
            raise RuntimeError("Notebook source inventory differs from checkpoint")
        if data["status"] in {"initialized", "notebook_pending", "upload_pending", "sources_ready"}:
            data["status"] = "sources_ready"
            self.save()
        print("all_sources_ready", len(data["sources"]), flush=True)

    async def history(self, session):
        conversation_id = self.data["conversation_id"]
        if not conversation_id:
            if hasattr(session.conversation, "current_conversation_id"):
                conversation_id = await session.conversation.current_conversation_id(self.data["notebook_id"])
            elif hasattr(session, "client"):
                conversation_id = await session.client.chat.get_conversation_id(self.data["notebook_id"])
        if not conversation_id:
            return []
        return await session.conversation.history(self.data["notebook_id"], conversation_id, limit=1000)

    async def ask_or_recover(self, session, key: str, question: str, ids: list[str], expected_turns: int) -> dict:
        data = self.data
        history = await self.history(session)
        if len(history) == expected_turns + 1 and history[-1][0] == question:
            answer = history[-1][1]
            conversation_id = data["conversation_id"]
            if not conversation_id:
                if hasattr(session.conversation, "current_conversation_id"):
                    conversation_id = await session.conversation.current_conversation_id(data["notebook_id"])
                elif hasattr(session, "client"):
                    conversation_id = await session.client.chat.get_conversation_id(data["notebook_id"])
            print("turn_recovered", key, flush=True)
            result = {"question": question, "raw_answer": answer, "conversation_id": conversation_id, "turn_number": None}
        elif len(history) == expected_turns:
            if data["status"] == f"{key}_pending":
                raise RuntimeError(f"{key} delivery uncertain; rerun later to check history, do not resend")
            if expected_turns and history[-1][0] != (DIVIDE_PROMPT if expected_turns == 1 else EXECUTION_PROMPT if expected_turns == 2 else data["lessons"][-1]["question"]):
                raise RuntimeError("Conversation history differs from checkpoint")
            data["status"] = f"{key}_pending"
            self.save()
            print("turn_sent", key, flush=True)
            active_conv_id = data["conversation_id"]
            if not active_conv_id:
                if hasattr(session.conversation, "current_conversation_id"):
                    active_conv_id = await session.conversation.current_conversation_id(data["notebook_id"])
                elif hasattr(session, "client"):
                    active_conv_id = await session.client.chat.get_conversation_id(data["notebook_id"])
            response = await session.conversation.ask_once(
                data["notebook_id"], question, ids, active_conv_id
            )
            result = {"question": question, "raw_answer": response.text,
                      "conversation_id": response.conversation_id, "turn_number": response.turn_number}
        else:
            raise RuntimeError("Unexpected conversation history; refusing duplicate prompt")
        if data["conversation_id"] and result["conversation_id"] != data["conversation_id"]:
            raise RuntimeError("NotebookLM changed conversation ID")
        data["conversation_id"] = result["conversation_id"]
        return result

    async def prompts(self, session) -> None:
        data = self.data
        if data["status"] == "complete":
            return
        await session.conversation.configure_format(data["notebook_id"], COMBINED_RESPONSE_FORMAT_TEXT)
        settings = await session.conversation.get_format(data["notebook_id"])
        if settings.custom_prompt != COMBINED_RESPONSE_FORMAT_TEXT:
            raise RuntimeError("NotebookLM response format was not retained")
        if not data["divide"]:
            result = await self.ask_or_recover(session, "divide", DIVIDE_PROMPT, self.source_ids(False), 0)
            data["divide"] = result
            data["status"] = "divide_received"
            self.save()
        if "nodes" not in data["divide"]:
            service = NotebookTerminalService()
            captured = service.capture_turn(
                turn_kind="divide",
                raw_answer=data["divide"]["raw_answer"],
                capture_raw=self.args.capture_raw_output,
            )
            data["divide"]["nodes"] = captured["nodes"]
            data["divide"]["map_parse_mode"] = captured["map_parse_mode"]
            if "map_parse_warning" in captured:
                data["divide"]["map_parse_warning"] = captured["map_parse_warning"]
            data["divide"]["work_count"] = captured["work_count"]
            data["status"] = "divide_parsed"
            self.save()
            print("divide_parsed", data["divide"]["work_count"], "work_items", flush=True)
        if data["divide"]["work_count"] < 1:
            raise RuntimeError("Divide response contains no identifiable work items; Continue count is unknown")
        if not data["execution"]:
            result = await self.ask_or_recover(session, "execution", EXECUTION_PROMPT, self.source_ids(True), 1)
            data["execution"] = result
            data["status"] = "execution_received"
            self.save()
        service = NotebookTerminalService()
        captured_exec = service.capture_turn(
            turn_kind="execution",
            raw_answer=data["execution"]["raw_answer"],
            capture_raw=self.args.capture_raw_output,
        )
        if "output_warning" in captured_exec:
            data["execution"]["output_warning"] = captured_exec["output_warning"]
            self.save()
        if data["status"] == "execution_received":
            data["status"] = "execution_ready"
            self.save()
            print("execution_ready", flush=True)
        for entry in data["lessons"]:
            if not entry.get("handoff_path"):
                self.export_lesson(entry)
        for ordinal in range(len(data["lessons"]) + 1, data["divide"]["work_count"] + 1):
            question = "Start with First lesson" if ordinal == 1 else "Continue"
            result = await self.ask_or_recover(session, f"lesson_{ordinal}", question,
                                               self.source_ids(True), 1 + ordinal)
            node = next(item for item in data["divide"]["nodes"] if item["work_ordinal"] == ordinal)
            result.update({"lesson_ordinal": ordinal, "mapped_title": node["title"], "captured_at": utc_now()})
            data["lessons"].append(result)
            data["status"] = "lesson_received"
            self.save()  # Raw answer is durable before parsing.
            self.export_lesson(result)
        data["status"] = "complete"
        self.save()
        print("complete", len(data["lessons"]), "lessons", flush=True)

    def export_lesson(self, entry: dict) -> None:
        ordinal = entry["lesson_ordinal"]
        raw = entry["raw_answer"]
        service = NotebookTerminalService()
        result = service.capture_turn(
            turn_kind="lesson",
            raw_answer=raw,
            capture_raw=self.args.capture_raw_output,
            lesson_ordinal=ordinal,
            mapped_title=entry["mapped_title"],
            output_dir=self.handoff_dir,
            notebook_id=self.data["notebook_id"],
            conversation_id=self.data["conversation_id"],
            pdf_source_ids=self.source_ids(False),
            framework_source_id=self.data["sources"][-1]["source_id"],
        )
        entry["handoff_path"] = str(Path(result["handoff_path"]).relative_to(self.args.output_dir))
        entry["scene_count"] = result["scene_count"]
        entry["terminal_marker_ordinal"] = result["terminal_marker_ordinal"]
        if self.args.capture_raw_output:
            entry["output_warnings"] = result["output_warnings"]
            self.data["status"] = "lesson_saved"
            self.save()
            print("lesson_saved", ordinal, "scenes", result["scene_count"],
                  "raw_capture", len(result["output_warnings"]), "warnings", flush=True)
        else:
            self.data["status"] = "lesson_saved"
            self.save()
            print("lesson_saved", ordinal, "scenes", result["scene_count"], flush=True)

    async def execute(self) -> None:
        provider = SdkSessionProvider(
            base_url="https://notebook.google.com",
            storage_state_path=get_storage_state_path(),
        )
        async with provider.open("mo50atiapro") as session:
            await self.notebook_and_sources(session)
            await self.prompts(session)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--pdf-dir", required=True, type=Path)
    parser.add_argument("--output-dir", required=True, type=Path)
    parser.add_argument("--steps", type=Path, default=PROJECT_ROOT / "steps.txt")
    parser.add_argument("--expected-pdfs", type=int, default=8)
    parser.add_argument("--title", default="V3 Mechanics 8 PDFs")
    parser.add_argument("--account-check-notebook-id", required=True,
                        help="Read-only account check: ID of an existing notebook in the intended account; a NEW notebook is created for this run")
    parser.add_argument("--source-timeout", type=int, default=900)
    parser.add_argument("--check-only", action="store_true",
                        help="Validate local inputs without provider calls or notebook creation")
    parser.add_argument("--capture-raw-output", action="store_true",
                        help="Save every lesson response and continue without First/Second/Third output validation")
    args = parser.parse_args()
    args.pdf_dir = args.pdf_dir.expanduser().resolve()
    args.output_dir = args.output_dir.expanduser().resolve()
    args.steps = args.steps.expanduser().resolve()
    if args.steps != (PROJECT_ROOT / "steps.txt").resolve():
        args.capture_raw_output = True
    sources = local_sources(args.pdf_dir, args.steps, args.expected_pdfs)
    print("inputs_verified", args.expected_pdfs, "PDFs", "plus", args.steps.name, flush=True)
    if args.check_only:
        return 0
    args.output_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
    run = Run(args, sources)
    try:
        asyncio.run(run.execute())
    except Exception as exc:
        run.data["last_error"] = f"{type(exc).__name__}: {str(exc)[:500]}"
        run.save()
        print("stopped", run.data["last_error"], file=sys.stderr, flush=True)
        print("checkpoint", run.checkpoint, file=sys.stderr, flush=True)
        return 1
    print("checkpoint", run.checkpoint, flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
