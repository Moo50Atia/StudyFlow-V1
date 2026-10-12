"""Per-PDF isolation uses production capture, archive and scene boundaries."""

import hashlib
import json
import uuid
from pathlib import Path
from unittest.mock import AsyncMock, MagicMock
from contextlib import asynccontextmanager
from types import SimpleNamespace
import asyncio

import pytest

from v3_engine.terminal_pipeline import activities
from v3_engine.terminal_pipeline.cli import run_cli
from v3_engine.terminal_pipeline.manifest import capture_manifest, SubmissionManifest, ManifestCaptureError
from v3_engine.notebooklm.ports import SourceReceipt
from v3_engine.notebooklm.sdk_provider import SdkProviderSession
from v3_engine.notebooklm.terminal_service import NotebookTerminalService
from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT, EXECUTION_PROMPT


class NotebookDouble:
    """Only the external SDK client is doubled; all application boundaries run normally."""
    def __init__(self):
        self.files = {}
        self.histories = {}
        self.current = None
        self.sequence = 0
        self.trace = []
        self.storage_paths = []
        self.active_pdf = 0
        self.reuse_conversation_id = False
        self.client = SimpleNamespace(
            notebooks=SimpleNamespace(create=self.create, get=self.get),
            sources=SimpleNamespace(add_file=self.upload, list=self.list_sources),
            chat=SimpleNamespace(ask=self.ask, configure=self.configure, get_history=self.history,
                                 get_conversation_id=self.current_id, delete_conversation=self.delete),
        )

    async def create(self, title):
        self.trace.append(("create", title))
        return SimpleNamespace(id="one-notebook", title=title)

    async def get(self, notebook):
        from notebooklm.types import SharePermission
        return SimpleNamespace(role=SharePermission.OWNER)

    async def upload(self, notebook, path, mime_type, wait, title):
        source_id = f"source-{len(self.files) + 1}"
        source = SimpleNamespace(id=source_id, title=title, is_ready=True, is_error=False, content_mime=mime_type)
        self.files[source_id] = source
        self.trace.append(("upload", mime_type, source_id))
        return source

    async def list_sources(self, notebook):
        return list(self.files.values())

    async def configure(self, notebook, **kwargs):
        self.trace.append(("configure",))

    async def ask(self, notebook, question, source_ids, conversation_id=None):
        if self.current is None:
            self.sequence += 1
            self.current = "conv-reused" if self.reuse_conversation_id else f"conv-{self.sequence}"
            self.histories[self.current] = []
        assert conversation_id in (None, self.current)
        if question == DIVIDE_PROMPT:
            self.active_pdf += 1
            answer = ("Chapter 1: First PDF\n├── Lesson 1: Velocity\n└── Lesson 2: Acceleration" if self.active_pdf == 1
                      else "Chapter 1: Second PDF\n└── Lesson 1: Force")
        elif question == EXECUTION_PROMPT:
            answer = "System Ready"
        else:
            lesson = 1 + sum(q in ("Continue", "Start with First lesson") for q, _ in self.histories[self.current])
            answer = lesson_answer(lesson, scene_count=lesson if self.active_pdf == 1 else 3)
        self.histories[self.current].append((question, answer))
        self.trace.append(("ask", self.active_pdf, question, tuple(source_ids)))
        return SimpleNamespace(answer=answer, conversation_id=self.current, turn_number=len(self.histories[self.current]))

    async def history(self, notebook, conversation_id=None, limit=100):
        return list(self.histories.get(conversation_id or self.current, []))

    async def current_id(self, notebook):
        return self.current

    async def delete(self, notebook, conversation):
        assert conversation == self.current
        self.trace.append(("reset", conversation))
        self.current = None

    @asynccontextmanager
    async def open(self, profile_ref=None, storage_path=None):
        self.storage_paths.append(storage_path)
        yield SdkProviderSession(self.client)


def setup_run(tmp_path, monkeypatch, scene_mode="export_prompts"):
    raw = manifest_input(tmp_path)
    root = tmp_path / "terminal"
    monkeypatch.setenv("V3_TERMINAL_ROOT", str(root))
    monkeypatch.setenv("GEMINI_API_KEY", "offline-test-key")
    master = tmp_path / "master-state.json"
    master.write_text('{"cookies":[],"origins":[]}')
    monkeypatch.setenv("V3_NOTEBOOKLM_STORAGE_STATE", str(master))
    snapshot, manifest_path = capture_manifest(raw, root, tmp_path, scene_generation_mode=scene_mode)
    subject = snapshot.subjects[0]
    run_dir = root / "runs" / subject.subject_run_id
    run_dir.mkdir(parents=True)
    (run_dir / "subject-config.json").write_text(json.dumps(subject.to_dict()))
    double = NotebookDouble()
    service = NotebookTerminalService(double)
    monkeypatch.setattr(activities, "NotebookTerminalService", lambda: service)
    rows = [{"path": str(row.snapshot_ref.resolve(root)), "mime_type": "application/pdf" if row.kind == "pdf" else "text/plain",
             "title": Path(row.original_path).name, "role": "educational_pdf" if row.kind == "pdf" else "framework_steps",
             "sha256": row.sha256, "ordinal": row.ordinal} for row in subject.inputs]
    return snapshot, manifest_path, subject, run_dir, double, rows


async def stage_pdf(subject, ordinal, conversation, source_id="source-pdf"):
    common = {"subject_run_id": subject, "notebook_id": "one-notebook", "pdf_ordinal": ordinal,
              "pdf_source_ids": [source_id], "framework_source_id": "source-steps"}
    for kind, question, sources, local_ordinal in [
        ("divide", DIVIDE_PROMPT, [source_id], None),
        ("execution", EXECUTION_PROMPT, [source_id, "source-steps"], None),
        ("lesson", "Start with First lesson", [source_id, "source-steps"], 1),
    ]:
        turn = await activities.send_terminal_turn({**common, "operation_id": f"pdf-{ordinal:04d}:{kind}",
            "source_ids": sources, "question": question, "conversation_id": None if kind == "divide" else conversation})
        await activities.capture_terminal_turn({**common, "turn_kind": kind, "lesson_ordinal": local_ordinal,
            "receipt_ref": turn["receipt_ref"], "conversation_id": turn["result_ref"]})


def _write_test_pdf(path: Path, text: str):
    from reportlab.pdfgen import canvas
    c = canvas.Canvas(str(path))
    c.drawString(100, 700, text)
    c.showPage()
    c.save()


def manifest_input(tmp_path):
    pdfs = tmp_path / "pdfs"
    pdfs.mkdir()
    _write_test_pdf(pdfs / "01.pdf", "first topic")
    _write_test_pdf(pdfs / "02.pdf", "second topic")
    steps = tmp_path / "steps.txt"
    steps.write_text("The approved framework", encoding="utf-8")
    return {"schema_version": 1, "submission_id": str(uuid.uuid4()), "subjects": [{
        "subject_key": "two-pdfs", "mode": "new", "pdf_dir": str(pdfs), "steps": str(steps),
        "provider": "gemini", "model": "gemini-2.5-flash", "account_check_notebook_id": "proof",
        "pdf_processing": "per_pdf",
    }]}


def lesson_answer(ordinal=1, scene_count=1):
    scenes = "\n".join(f'Lec {ordinal} (Sec {i}): "Any custom type\nPrompt {i}"'
                       for i in range(1, scene_count + 1))
    return f"=== FIRST ===\nRaw first\n=== SECOND ===\nRaw second\n=== THIRD ===\n{scenes}\n=== END ==="


def test_manifest_captures_per_pdf_and_fences_changed_mode(tmp_path):
    raw = manifest_input(tmp_path)
    captured, _ = capture_manifest(raw, tmp_path / "terminal", tmp_path)
    assert captured.to_dict()["subjects"][0].get("pdf_processing") == "per_pdf"
    assert SubmissionManifest.from_dict(captured.to_dict()).verify_digest()
    raw["subjects"][0]["pdf_processing"] = "joint"
    with pytest.raises(ManifestCaptureError, match="changed subject configuration"):
        capture_manifest(raw, tmp_path / "terminal", tmp_path)


@pytest.mark.asyncio
async def test_capture_namespaces_repeated_lesson_one(tmp_path, monkeypatch):
    monkeypatch.setenv("V3_TERMINAL_ROOT", str(tmp_path))
    for pdf in (1, 2):
        await activities.capture_terminal_turn({
            "subject_run_id": "subject", "turn_kind": "lesson", "raw_answer": lesson_answer(scene_count=pdf),
            "notebook_id": "one-notebook", "conversation_id": f"conv-{pdf}",
            "pdf_ordinal": pdf, "lesson_ordinal": 1, "mapped_title": f"PDF {pdf} topic",
            "pdf_source_ids": [f"source-{pdf}"], "framework_source_id": "framework",
        })
    root = tmp_path / "runs" / "subject" / "pdfs"
    first = json.loads((root / "pdf-0001" / "lesson_handoffs" / "lesson-001.json").read_text())
    second = json.loads((root / "pdf-0002" / "lesson_handoffs" / "lesson-001.json").read_text())
    assert first["conversation_id"] == "conv-1" and len(first["scenes"]) == 1
    assert second["conversation_id"] == "conv-2" and len(second["scenes"]) == 2


@pytest.mark.asyncio
async def test_poll_scopes_only_active_pdf_and_framework(tmp_path, monkeypatch):
    monkeypatch.setenv("V3_TERMINAL_ROOT", str(tmp_path))
    service = MagicMock()
    service.poll_sources = AsyncMock(return_value=[
        SourceReceipt("old", "previous PDF", "error"),
        SourceReceipt("active", "current PDF", "ready"),
        SourceReceipt("framework", "steps.txt", "ready"),
    ])
    monkeypatch.setattr(activities, "NotebookTerminalService", lambda: service)
    result = await activities.poll_terminal_sources({
        "subject_run_id": "subject", "notebook_id": "one-notebook", "pdf_ordinal": 2,
        "source_ids": ["active", "framework"],
    })
    assert result["outcome"] == "complete"
    assert result["counts"] == {"ready": 2, "total": 2}


def test_cli_captures_per_pdf_flag_and_source_identity(tmp_path, monkeypatch):
    raw = manifest_input(tmp_path)
    raw["subjects"][0].pop("pdf_processing")
    path = tmp_path / "submission.json"
    path.write_text(json.dumps(raw))
    monkeypatch.setenv("V3_TERMINAL_ROOT", str(tmp_path / "terminal"))
    update = MagicMock()
    update.result = AsyncMock(return_value={"outcome": "accepted"})
    client = MagicMock()
    client.start_update_with_start_workflow = AsyncMock(return_value=update)
    code, _ = run_cli(["submit", "--manifest", str(path), "--pdf-processing", "per-pdf", "--export-prompts"], client)
    assert code == 0
    payload = client.start_update_with_start_workflow.call_args.args[1]["subjects"][0]
    assert payload["subject_config"]["pdf_processing"] == "per_pdf"
    assert [row["ordinal"] for row in payload["sources"] if row["role"] == "educational_pdf"] == [1, 2]
    assert all(len(row["sha256"]) == 64 for row in payload["sources"])


@pytest.mark.asyncio
@pytest.mark.parametrize("crash_after_delete", [False, True])
async def test_reset_archives_first_and_reconciles_without_deleting_next_chat(tmp_path, monkeypatch, crash_after_delete):
    _, _, subject, run_dir, double, _ = setup_run(tmp_path, monkeypatch)
    double.active_pdf = 1  # One mapped lesson for this focused recovery test.
    await stage_pdf(subject.subject_run_id, 1, "conv-1")
    request = {"subject_run_id": subject.subject_run_id, "pdf_ordinal": 1,
               "notebook_id": "one-notebook", "conversation_id": "conv-1"}
    assert (await activities.reset_terminal_conversation(request))["outcome"] == "failed"
    assert not any(row[0] == "reset" for row in double.trace)
    archived = await activities.archive_terminal_pdf(request)
    assert archived["outcome"] == "complete"
    before = {str(p): p.read_bytes() for p in (run_dir / "pdfs").rglob("*.json")}
    if crash_after_delete:
        original = double.client.chat.delete_conversation
        async def interrupted(*args):
            await original(*args)
            raise RuntimeError("connection lost after remote deletion")
        double.client.chat.delete_conversation = interrupted
        assert (await activities.reset_terminal_conversation(request))["outcome"] == "uncertain"
    assert (await activities.reset_terminal_conversation(request))["outcome"] == "complete"
    double.current = "new-conversation-must-survive"
    assert (await activities.reset_terminal_conversation(request))["outcome"] == "complete"
    assert double.current == "new-conversation-must-survive"
    assert sum(row[0] == "reset" for row in double.trace) == 1
    assert all(Path(p).read_bytes() == content for p, content in before.items())


@pytest.mark.asyncio
async def test_corrupt_archive_blocks_reset_and_freeze(tmp_path, monkeypatch):
    _, _, subject, run_dir, double, _ = setup_run(tmp_path, monkeypatch)
    double.active_pdf = 1
    await stage_pdf(subject.subject_run_id, 1, "conv-1")
    request = {"subject_run_id": subject.subject_run_id, "pdf_ordinal": 1,
               "notebook_id": "one-notebook", "conversation_id": "conv-1"}
    assert (await activities.archive_terminal_pdf(request))["outcome"] == "complete"
    handoff = run_dir / "pdfs/pdf-0001/lesson_handoffs/lesson-001.json"
    handoff.write_text("{}")
    assert (await activities.reset_terminal_conversation(request))["outcome"] == "failed"
    assert not any(row[0] == "reset" for row in double.trace)
    with pytest.raises(ValueError, match="archive_corrupt"):
        await activities.freeze_terminal_handoffs({"subject_run_id": subject.subject_run_id,
            "pdf_processing": "per_pdf", "expected_pdf_count": 2, "expected_lesson_count": 2})


@pytest.mark.asyncio
async def test_uncertain_reset_never_resends_delete(tmp_path, monkeypatch):
    _, _, subject, _, double, _ = setup_run(tmp_path, monkeypatch)
    double.active_pdf = 1
    await stage_pdf(subject.subject_run_id, 1, "conv-1")
    request = {"subject_run_id": subject.subject_run_id, "pdf_ordinal": 1,
               "notebook_id": "one-notebook", "conversation_id": "conv-1"}
    await activities.archive_terminal_pdf(request)
    calls = []
    async def uncertain(*args):
        calls.append(args)
        raise RuntimeError("timeout before response")
    double.client.chat.delete_conversation = uncertain
    assert (await activities.reset_terminal_conversation(request))["outcome"] == "uncertain"
    assert (await activities.reset_terminal_conversation(request))["outcome"] == "uncertain"
    assert len(calls) == 1 and double.current == "conv-1"


@pytest.mark.asyncio
async def test_reset_accepts_deleted_empty_chat_with_stale_latest_id(tmp_path, monkeypatch):
    _, _, subject, _, double, _ = setup_run(tmp_path, monkeypatch)
    double.active_pdf = 1
    await stage_pdf(subject.subject_run_id, 1, "conv-1")
    request = {"subject_run_id": subject.subject_run_id, "pdf_ordinal": 1,
               "notebook_id": "one-notebook", "conversation_id": "conv-1"}
    await activities.archive_terminal_pdf(request)
    calls = []
    async def delete_with_stale_pointer(notebook, conversation):
        calls.append(conversation)
        double.histories[conversation] = []
        # Real server keeps returning this ID even after deleting its turns.
    double.client.chat.delete_conversation = delete_with_stale_pointer
    assert (await activities.reset_terminal_conversation(request))["outcome"] == "complete"
    assert double.current == "conv-1" and calls == ["conv-1"]
    assert (await activities.reset_terminal_conversation(request))["outcome"] == "complete"
    assert calls == ["conv-1"]


@pytest.mark.asyncio
@pytest.mark.parametrize("returned_id", ["fresh-chat", "old-chat"])
async def test_sdk_restores_reset_tombstone_in_a_new_session(returned_id):
    from notebooklm._chat import RecentlyDeletedConversations
    deleted = RecentlyDeletedConversations()
    async def ask(notebook, question, source_ids, conversation_id):
        # notebooklm-py needs this local marker to ignore a stale server ID.
        assert "old-chat" in deleted
        assert conversation_id is None
        return SimpleNamespace(answer="new map", conversation_id=returned_id, turn_number=1)
    client = SimpleNamespace(chat=SimpleNamespace(
        _deleted_conversations=deleted, get_conversation_id=AsyncMock(return_value="old-chat"),
        get_history=AsyncMock(return_value=[]), ask=AsyncMock(side_effect=ask),
    ))
    session = SdkProviderSession(client)
    result = await session.ask_once("notebook", "Divide", ["pdf-2"], fresh_after_conversation_id="old-chat")
    assert result.conversation_id == returned_id
    client.chat.get_history.assert_awaited_once_with("notebook", conversation_id="old-chat", limit=1)


@pytest.mark.asyncio
async def test_fresh_turn_refuses_a_reset_chat_that_still_has_history():
    client = SimpleNamespace(chat=SimpleNamespace(
        get_conversation_id=AsyncMock(return_value="old-chat"),
        get_history=AsyncMock(return_value=[("previous question", "answer")]), ask=AsyncMock(),
    ))
    from v3_engine.notebooklm.ports import ProviderError
    with pytest.raises(ProviderError):
        await SdkProviderSession(client).ask_once("notebook", "Divide", ["pdf"], fresh_after_conversation_id="old-chat")
    client.chat.ask.assert_not_awaited()


@pytest.mark.asyncio
async def test_completed_pdf_turns_and_captures_reuse_without_sending(tmp_path, monkeypatch):
    _, _, subject, run_dir, double, _ = setup_run(tmp_path, monkeypatch)
    double.active_pdf = 1
    await stage_pdf(subject.subject_run_id, 1, "conv-1")
    request = {"subject_run_id": subject.subject_run_id, "pdf_ordinal": 1}
    assert (await activities.archive_terminal_pdf(request))["outcome"] == "complete"
    saved = {path: path.read_bytes() for path in run_dir.rglob("*.json")}
    sends = sum(row[0] == "ask" for row in double.trace)
    double.current = "an-unrelated-later-chat"
    await stage_pdf(subject.subject_run_id, 1, "conv-1")
    assert (await activities.archive_terminal_pdf(request))["outcome"] == "complete"
    assert sum(row[0] == "ask" for row in double.trace) == sends
    assert all(path.read_bytes() == content for path, content in saved.items())


@pytest.mark.asyncio
async def test_interrupted_first_divide_recovers_without_a_second_send(tmp_path, monkeypatch):
    _, _, subject, _, double, _ = setup_run(tmp_path, monkeypatch)
    double.active_pdf = 1
    original = double.client.chat.ask
    async def interrupted(*args, **kwargs):
        await original(*args, **kwargs)
        raise RuntimeError("Connection lost after server saved its answer")
    double.client.chat.ask = interrupted
    request = {"subject_run_id": subject.subject_run_id, "notebook_id": "one-notebook",
               "operation_id": "pdf-0001:divide", "question": DIVIDE_PROMPT, "source_ids": ["pdf-1"]}
    assert (await activities.send_terminal_turn(request))["outcome"] == "uncertain"
    recovered = await activities.send_terminal_turn(request)
    assert recovered["outcome"] == "complete" and recovered["result_ref"] == "conv-1"
    assert sum(row[0] == "ask" for row in double.trace) == 1


@pytest.mark.asyncio
@pytest.mark.parametrize("changed_kind", ["pdf", "steps"])
async def test_archives_verify_captured_input_bytes_before_reset(tmp_path, monkeypatch, changed_kind):
    _, _, subject, run_dir, double, _ = setup_run(tmp_path, monkeypatch)
    double.active_pdf = 1
    await stage_pdf(subject.subject_run_id, 1, "conv-1")
    request = {"subject_run_id": subject.subject_run_id, "pdf_ordinal": 1,
               "notebook_id": "one-notebook", "conversation_id": "conv-1"}
    assert (await activities.archive_terminal_pdf(request))["outcome"] == "complete"
    captured = next(item for item in subject.inputs if item.kind == changed_kind)
    captured.snapshot_ref.resolve(run_dir.parent.parent).write_bytes(b"changed captured bytes")
    assert (await activities.reset_terminal_conversation(request))["outcome"] == "failed"
    assert not any(row[0] == "reset" for row in double.trace)


@pytest.mark.temporal
@pytest.mark.asyncio
@pytest.mark.parametrize("scene_mode", ["generate", "export_prompts"])
@pytest.mark.parametrize("reuse_conversation_id", [False, True])
async def test_per_pdf_real_temporal_workers_and_artifacts(temporal_local_env, tmp_path, monkeypatch, scene_mode, reuse_conversation_id):
    from temporalio.client import WithStartWorkflowOperation, WorkflowUpdateStage
    from temporalio.common import WorkflowIDConflictPolicy
    from temporalio.worker import Replayer
    from v3_engine.terminal_pipeline.supervisor import TerminalSupervisorWorkflow
    from v3_engine.terminal_pipeline.subject_workflow import TerminalSubjectWorkflow
    from v3_engine.terminal_pipeline.worker import run_workers
    from v3_engine.tests.terminal_pipeline.test_scene_steps import CountingDoubleProvider

    snapshot, manifest_path, subject, run_dir, double, sources = setup_run(tmp_path, monkeypatch, scene_mode)
    double.reuse_conversation_id = reuse_conversation_id
    provider = CountingDoubleProvider()
    original_generate = provider.generate_scene
    async def generate_after_barrier(*args, **kwargs):
        assert len(list((run_dir / "pdfs").glob("pdf-*/private/archive.json"))) == 2
        body = None
        sink = kwargs["receipt_sink"]
        async def retain(raw, metadata):
            nonlocal body
            body = raw
            return await sink(raw, metadata)
        kwargs["receipt_sink"] = retain
        response = await original_generate(*args, **kwargs)
        from v3_engine.scene_generation.providers import decode_generation_response
        return decode_generation_response("gemini", "gemini-2.5-flash", body, 200, response.raw_receipt_ref)
    provider.generate_scene = generate_after_barrier
    monkeypatch.setattr(activities, "create_provider", lambda cfg: provider)
    client = temporal_local_env.client
    stop = asyncio.Event()
    workers = asyncio.create_task(run_workers(client, lock_path=tmp_path / "worker.lock", stop_event=stop))
    payload = {"schema_version": 1, "submission_id": snapshot.submission_id,
               "submission_digest": snapshot.manifest_sha256, "manifest_ref": str(manifest_path),
               "subjects": [{"subject_key": subject.subject_key, "subject_run_id": subject.subject_run_id,
                   "run_ref": f"runs/{subject.subject_run_id}",
                   "title": subject.display_title, "mode": "new", "sources": sources,
                   "proof_notebook_id": "proof", "subject_config": subject.to_dict()}]}
    try:
        update = await client.start_update_with_start_workflow(TerminalSupervisorWorkflow.submit_v1, payload,
            start_workflow_operation=WithStartWorkflowOperation(TerminalSupervisorWorkflow.run, {},
                id="v3-terminal-supervisor", task_queue="v3-terminal-control",
                id_conflict_policy=WorkflowIDConflictPolicy.USE_EXISTING), wait_for_stage=WorkflowUpdateStage.ACCEPTED)
        assert (await update.result())["outcome"] == "accepted"
        handle = client.get_workflow_handle(f"v3-terminal-subject-{subject.subject_run_id}")
        from temporalio.service import RPCError, RPCStatusCode
        async def wait_for_child_start():
            while True:
                try:
                    await handle.describe()
                    return
                except RPCError as error:
                    if error.status != RPCStatusCode.NOT_FOUND:
                        raise
                    await asyncio.sleep(0.05)
        await asyncio.wait_for(wait_for_child_start(), 10)
        result = await asyncio.wait_for(handle.result(), 45)
        await Replayer(workflows=[TerminalSubjectWorkflow]).replay_workflow(await handle.fetch_history())
    finally:
        stop.set()
        await workers
    assert result["lessons_saved"] == 3
    assert sum(row[0] == "create" for row in double.trace) == 1
    assert sum(row[0] == "upload" for row in double.trace) == 3  # Both PDFs retained plus framework.
    assert sum(row[0] == "reset" for row in double.trace) == 1
    resets = [i for i, row in enumerate(double.trace) if row[0] == "reset"]
    uploads = [i for i, row in enumerate(double.trace) if row[0] == "upload"]
    assert resets[0] < uploads[-1]
    asks = [row for row in double.trace if row[0] == "ask"]
    assert [row[2] for row in asks] == [DIVIDE_PROMPT, EXECUTION_PROMPT, "Start with First lesson", "Continue",
                                       DIVIDE_PROMPT, EXECUTION_PROMPT, "Start with First lesson"]
    assert [row[3] for row in asks] == [("source-2",), ("source-2", "source-1"),
        ("source-2", "source-1"), ("source-2", "source-1"), ("source-3",),
        ("source-3", "source-1"), ("source-3", "source-1")]
    assert all(path and str(run_dir) in str(path) for path in double.storage_paths)
    first = json.loads((run_dir / "pdfs/pdf-0001/lesson_handoffs/lesson-001.json").read_text())
    second = json.loads((run_dir / "pdfs/pdf-0002/lesson_handoffs/lesson-001.json").read_text())
    assert first["mapped_title"] == "Velocity" and second["mapped_title"] == "Force"
    assert (first["conversation_id"] == second["conversation_id"]) == reuse_conversation_id
    frozen_keys = json.loads((run_dir / "private/scene-inventory.json").read_text())
    assert len(frozen_keys) == len(set(frozen_keys)) == 6
    assert all(key.startswith(("pdf-0001:", "pdf-0002:")) for key in frozen_keys)
    assert len(first["scenes"]) == 1 and len(second["scenes"]) == 3
    report = json.loads((run_dir / "final-report.json").read_text())
    assert report["counts"]["lessons_saved"] == 3 and report["counts"]["pdfs_completed"] == 2
    if scene_mode == "generate":
        assert result["status"] == "completed" and result["scenes_built"] == 6
        assert provider.send_count == 6
        assert len(list((run_dir / "pdfs").rglob("current.json"))) == 6
        assert report["counts"]["scenes_built"] == 6
        assert report["counts"]["scenes_generated"] == 6
    else:
        assert result["status"] == "prompts_exported" and provider.send_count == 0
        export = json.loads((run_dir / "private/scene-prompt-exports/scene-prompts.json").read_text())
        assert export["scene_count"] == 6 and export["lesson_count"] == 3
        assert [lesson["pdf_ordinal"] for lesson in export["lessons"]] == [1, 1, 2]

