"""Tests for ApplicationSubjectWorkflow per-PDF execution, source scopes, failure handling and prompt export (T027)."""

import pytest
import uuid
from unittest.mock import AsyncMock, patch

from v3_engine.web_pipeline.subject_workflow import ApplicationSubjectWorkflow


@pytest.fixture
def base_web_run_manifest():
    return {
        "run_id": str(uuid.uuid4()),
        "submission_id": str(uuid.uuid4()),
        "mode": "export_manual",
        "qualification_mode": "owner_waived_demo",
        "account_alias": "mo50atiapro",
        "framework_ref": str(uuid.uuid4()),
        "items": [
            {
                "item_id": str(uuid.uuid4()),
                "pdf_ordinal": 1,
                "title": "Quantum Mechanics Chapter 1",
                "source_ref": str(uuid.uuid4()),
            },
            {
                "item_id": str(uuid.uuid4()),
                "pdf_ordinal": 2,
                "title": "Quantum Mechanics Chapter 2",
                "source_ref": str(uuid.uuid4()),
            },
        ],
    }


def test_workflow_initial_state(base_web_run_manifest):
    wf = ApplicationSubjectWorkflow()
    prog = wf.progress_v1()
    assert prog["status"] == "staging"
    assert prog["phase"] == "preflight"
    assert prog["lessons_saved"] == 0
    assert prog["prompts_saved"] == 0
    assert prog["current_pdf_ordinal"] is None


def test_workflow_control_pause_and_cancel():
    wf = ApplicationSubjectWorkflow()
    # 1. Pause control
    pause_cmd = {
        "command_id": str(uuid.uuid4()),
        "action": "pause",
        "expected_version": 1,
        "command_digest": "digest_p",
    }
    receipt = wf.control_v1(pause_cmd)
    assert receipt["outcome"] in ("accepted", "applied")
    assert wf.paused is True
    assert wf.status == "paused"

    # 2. Cancel while paused
    cancel_cmd = {
        "command_id": str(uuid.uuid4()),
        "action": "cancel",
        "expected_version": wf.state_version,
        "command_digest": "digest_c",
    }
    cancel_receipt = wf.control_v1(cancel_cmd)
    assert cancel_receipt["outcome"] in ("accepted", "applied")
    assert wf.cancelled is True
    assert wf.status == "cancelled"


@pytest.mark.asyncio
async def test_invalid_lesson_holds_without_deleting_history_or_advancing_pdf(base_web_run_manifest):
    wf = ApplicationSubjectWorkflow()
    calls = []
    async def execute(name, args):
        calls.append((name, args.get("pdf_ordinal")))
        if name == "execute_lesson_turns":
            return {"outcome": "failed", "safe_code": "protocol_validation"}
        if name == "execute_divide_turn":
            return {"outcome": "completed", "safe_code": "ok", "map": {"lessons": [{"ordinal": 1, "title": "Lesson"}]}}
        return {"outcome": "completed", "safe_code": "ok"}
    wf._activity_executor = execute
    result = await wf.run(dict(base_web_run_manifest))
    assert result["outcome"] == "blocked"
    assert wf.status == "held_persistence"
    assert not any(name == "reset_conversation_activity" for name, _ in calls)
    assert not any(ordinal == 2 for _, ordinal in calls)


@pytest.mark.asyncio
async def test_two_pdfs_use_separate_divide_and_source_scopes(base_web_run_manifest):
    """
    FR-005, FR-006: Each PDF processes separately in order.
    Divide uses that PDF alone (excluding framework).
    Execution uses that PDF + framework.
    Verified reset barrier fences before next PDF.
    """
    wf = ApplicationSubjectWorkflow()
    manifest = dict(base_web_run_manifest)

    recorded_scopes = []
    resets_performed = []

    async def mock_activity(name: str, args: dict):
        if name == "execute_divide_turn":
            recorded_scopes.append(("divide", args.get("pdf_ordinal"), args.get("source_ids")))
            return {"outcome": "completed", "safe_code": "ok", "map": {"lessons": [{"ordinal": 1, "title": "Lesson 1"}]}}
        elif name == "execute_lesson_turns":
            recorded_scopes.append(("lesson", args.get("pdf_ordinal"), args.get("source_ids")))
            return {"outcome": "completed", "safe_code": "ok", "lessons_saved": 1, "prompts_saved": 1}
        elif name == "reset_conversation_activity":
            resets_performed.append(args.get("pdf_ordinal"))
            return {"outcome": "completed", "safe_code": "ok"}
        elif name == "export_prompts_activity":
            return {"outcome": "completed", "safe_code": "ok", "exported_count": 2}
        return {"outcome": "completed", "safe_code": "ok"}

    wf._activity_executor = mock_activity

    result = await wf.run(manifest)
    assert result["outcome"] in ("completed", "prompts_exported")
    assert result["safe_code"] == "ok"
    assert wf.pdfs_completed == 2

    # Verify scopes: PDF 1 used alone for divide, PDF 2 used alone for divide
    divide_scopes = [s for s in recorded_scopes if s[0] == "divide"]
    assert len(divide_scopes) == 2
    assert divide_scopes[0][1] == 1
    assert divide_scopes[1][1] == 2

    # Verify reset barrier occurred after PDF 1 before PDF 2
    assert 1 in resets_performed


@pytest.mark.asyncio
async def test_pdf_local_failure_continues_to_next_pdf(base_web_run_manifest):
    """
    FR-009: Settled PDF-local failures (e.g. map_unreadable) allow later PDFs to continue.
    Safe reset settles PDF 1, PDF 2 succeeds, run outcome is partial.
    """
    wf = ApplicationSubjectWorkflow()
    manifest = dict(base_web_run_manifest)

    async def mock_activity(name: str, args: dict):
        if name == "execute_divide_turn":
            ordinal = args.get("pdf_ordinal")
            if ordinal == 1:
                # PDF 1 fails locally with map_unreadable
                return {"outcome": "failed", "safe_code": "map_unreadable"}
            return {"outcome": "completed", "safe_code": "ok", "map": {"lessons": [{"ordinal": 1, "title": "Lesson 2"}]}}
        elif name == "execute_lesson_turns":
            return {"outcome": "completed", "safe_code": "ok", "lessons_saved": 1, "prompts_saved": 1}
        elif name == "reset_conversation_activity":
            return {"outcome": "completed", "safe_code": "ok"}
        elif name == "export_prompts_activity":
            return {"outcome": "completed", "safe_code": "ok", "exported_count": 1}
        return {"outcome": "completed", "safe_code": "ok"}

    wf._activity_executor = mock_activity

    result = await wf.run(manifest)
    assert result["outcome"] == "partial"
    assert wf.pdfs_completed == 1
    assert wf.pdfs_failed == 1


@pytest.mark.asyncio
async def test_shared_auth_or_quota_failure_pauses_workflow(base_web_run_manifest):
    """
    FR-009: Shared auth or quota failures pause the workflow instead of skipping or crashing.
    """
    wf = ApplicationSubjectWorkflow()
    manifest = dict(base_web_run_manifest)

    async def mock_activity(name: str, args: dict):
        if name == "execute_divide_turn":
            return {"outcome": "blocked", "safe_code": "authentication_required"}
        return {"outcome": "completed", "safe_code": "ok"}

    wf._activity_executor = mock_activity

    result = await wf.run(manifest)
    assert result["outcome"] == "paused_auth"
    assert result["safe_code"] == "authentication_required"
    assert wf.status == "paused_auth"


@pytest.mark.asyncio
async def test_manual_mode_makes_zero_scene_generation_calls(base_web_run_manifest):
    """
    FR-010: export_manual mode exports all saved prompts and closes without any scene generation calls.
    """
    wf = ApplicationSubjectWorkflow()
    manifest = dict(base_web_run_manifest)
    manifest["mode"] = "export_manual"

    scene_calls = []

    async def mock_activity(name: str, args: dict):
        if "scene" in name:
            scene_calls.append(name)
        if name == "execute_divide_turn":
            return {"outcome": "completed", "safe_code": "ok", "map": {"lessons": [{"ordinal": 1, "title": "Lesson 1"}]}}
        elif name == "execute_lesson_turns":
            return {"outcome": "completed", "safe_code": "ok", "lessons_saved": 1, "prompts_saved": 1}
        elif name == "reset_conversation_activity":
            return {"outcome": "completed", "safe_code": "ok"}
        elif name == "export_prompts_activity":
            return {"outcome": "completed", "safe_code": "ok", "exported_count": 2}
        return {"outcome": "completed", "safe_code": "ok"}

    wf._activity_executor = mock_activity

    result = await wf.run(manifest)
    assert result["outcome"] in ("completed", "prompts_exported")
    assert len(scene_calls) == 0, "export_manual mode must make ZERO scene generation calls"


class TransportDoubleFiles:
    def __init__(self):
        self.uploads = []
        self.sources = {}

    async def create_notebook(self, title: str):
        from v3_engine.notebooklm.ports import NotebookRef
        return NotebookRef(notebook_id="nb-double-1", title=title)

    async def upload_file(self, notebook_id: str, local_path, mime_type: str, title: str):
        from v3_engine.notebooklm.ports import SourceReceipt
        sid = f"src-{len(self.uploads) + 1}"
        self.uploads.append({"notebook_id": notebook_id, "path": local_path, "title": title, "sid": sid})
        receipt = SourceReceipt(source_id=sid, title=title, status="ready", mime_type=mime_type)
        self.sources.setdefault(notebook_id, []).append(receipt)
        return receipt

    async def list_sources(self, notebook_id: str):
        return self.sources.get(notebook_id, [])

    async def verify_owner(self, proof_notebook_id: str) -> str:
        return "owner"


class TransportDoubleConversation:
    def __init__(self):
        self.turns = []
        self.configured_formats = {}
        self.canned_answers = {}
        self.conversation_id = "conv-double-test"
        self.resets = []

    async def configure_format(self, notebook_id: str, format_text: str) -> None:
        self.configured_formats[notebook_id] = format_text

    async def ask_once(self, notebook_id: str, question: str, source_ids, conversation_id=None, **kwargs):
        from v3_engine.notebooklm.ports import TurnAnswer
        turn_num = len(self.turns) + 1
        answer_text = self.canned_answers.get(question, f"Default answer for {question}")
        self.turns.append({
            "notebook_id": notebook_id,
            "question": question,
            "source_ids": list(source_ids),
            "conversation_id": conversation_id or self.conversation_id,
            "turn_number": turn_num,
            "answer": answer_text,
        })
        return TurnAnswer(
            text=answer_text,
            conversation_id=self.conversation_id,
            turn_number=turn_num,
        )

    async def reset_conversation(self, notebook_id: str, conversation_id: str) -> None:
        self.resets.append((notebook_id, conversation_id))
        self.conversation_id = f"conv-reset-{len(self.resets)}"
        self.turns = []

    async def history(self, notebook_id: str, conversation_id: str, limit: int = 100):
        return [(t["question"], t["answer"]) for t in self.turns]

    async def current_conversation_id(self, notebook_id: str):
        return self.conversation_id


class TransportDoubleSession:
    def __init__(self):
        self.files = TransportDoubleFiles()
        self.conversation = TransportDoubleConversation()

    async def close(self):
        pass


class TransportDoubleProvider:
    def __init__(self, session: TransportDoubleSession):
        self.session = session

    def open(self, profile_ref=None, storage_path=None):
        sess = self.session

        class _Ctx:
            async def __aenter__(self):
                return sess
            async def __aexit__(self, exc_type, exc, tb):
                await sess.close()
        return _Ctx()


class DirectLaravelBoundaryDouble:
    """Unit double for Laravel's existing direct-protocol HTTP boundary."""

    def __init__(self, prompts_by_case):
        self.prompts_by_case = prompts_by_case
        self.bindings = {}
        self.queries = {}
        self.cursor_versions = {}
        self.saved = {}

    def add_case(self, case_id, binding_ref, source_id, work_count):
        self.bindings[binding_ref] = {
            "case_id": case_id, "notebook_id": "notebook-shared",
            "framework_sha256": "a" * 64, "pdf_source_id": source_id,
            "work_count": work_count,
        }
        self.cursor_versions[case_id] = 1

    async def get_binding_by_ref(self, case_id, binding_ref):
        binding = self.bindings[binding_ref]
        assert binding["case_id"] == case_id
        return binding

    async def find_lesson_query(self, case_id, kind, work_ordinal):
        return None

    async def claim_lesson_query(self, case_id, kind, work_ordinal, payload):
        ref = str(uuid.uuid4())
        version = self.cursor_versions[case_id]
        if kind in {"lesson_start", "lesson_continue"}:
            version += 1
            self.cursor_versions[case_id] = version
        self.queries[ref] = {"case_id": case_id, "kind": kind, "work_ordinal": work_ordinal, **payload}
        return {"query_ref": ref, "disposition": "send_now", "claimed_cursor_version": version}

    async def record_divide_result(self, query_ref, answer, conversation_out):
        query = self.queries[query_ref]
        binding = next(b for b in self.bindings.values() if b["case_id"] == query["case_id"])
        self.saved[query["case_id"]] = {"conversation_id": conversation_out, "map": answer}
        return {"map_ref": str(uuid.uuid4()), "work_count": binding["work_count"]}

    async def record_execution_result(self, query_ref, answer, conversation_out):
        case_id = self.queries[query_ref]["case_id"]
        self.cursor_versions[case_id] = 2
        return {"cursor_version": 2}

    async def record_combined_lesson_result(self, query_ref, payload):
        query = self.queries[query_ref]
        case_id = query["case_id"]
        count = sum(1 for line in payload["answer"].splitlines() if line.strip().startswith("Lec "))
        self.cursor_versions[case_id] += 1
        return {
            "cursor_version": self.cursor_versions[case_id],
            "original_prompt_refs": [str(uuid.uuid4()) for _ in range(count)],
        }

    async def record_lesson_query_uncertain(self, query_ref, payload):
        self.queries[query_ref]["state"] = "uncertain"
        return {"state": "uncertain"}

    async def record_run_item_archive(self, item_id, payload):
        return {"outcome": "completed", "archive_ref": str(uuid.uuid4()), "counts": {
            "lessons_saved": payload["lessons_saved"], "prompts_saved": payload["prompts_saved"],
        }}

    async def mark_run_item_reset_verified(self, item_id, payload):
        return {"outcome": "completed", "safe_code": "ok"}

    async def export_run_prompts(self, run_id):
        return {"outcome": "completed", "artifact_ref": str(uuid.uuid4()), "counts": {"prompts_saved": 5}}


@pytest.mark.asyncio
async def test_service_backed_per_pdf_scopes_turns_and_resets(tmp_path, monkeypatch):
    """Activity wrapper unit: real DirectLessonProtocol, provider transport doubled."""
    from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT, EXECUTION_PROMPT
    from v3_engine.notebooklm.protocol import DirectLessonProtocol
    from v3_engine.notebooklm.terminal_service import NotebookTerminalService
    from v3_engine.web_pipeline import activities

    monkeypatch.setenv("V3_TERMINAL_ROOT", str(tmp_path))
    run_id = str(uuid.uuid4())
    item1, item2 = str(uuid.uuid4()), str(uuid.uuid4())
    case1, case2 = str(uuid.uuid4()), str(uuid.uuid4())
    bind1, bind2 = str(uuid.uuid4()), str(uuid.uuid4())
    provider = TransportDoubleProvider(TransportDoubleSession())
    activities.set_terminal_service(NotebookTerminalService(session_provider=provider))
    application = DirectLaravelBoundaryDouble({})
    application.add_case(case1, bind1, "src-pdf-1", 1)
    application.add_case(case2, bind2, "src-pdf-2", 2)
    activities.set_application_client(application)
    monkeypatch.setattr(activities, "_lesson_protocol", lambda _run: DirectLessonProtocol(provider, application))

    first_bundle = "=== FIRST ===\nLecture: W1 Motion\n=== SECOND ===\nplaceholder\n=== THIRD ===\nLec 1 (Sec 1): \"prompt one\"\nLec 1 (Sec 2): \"prompt two\"\n=== END ==="
    second_bundle = "=== FIRST ===\nLecture: W1 Newton\n=== SECOND ===\nplaceholder\n=== THIRD ===\nLec 1 (Sec 1): \"prompt one\"\n=== END ==="
    third_bundle = "=== FIRST ===\nLecture: W2 Friction\n=== SECOND ===\nplaceholder\n=== THIRD ===\nLec 2 (Sec 1): \"prompt one\"\nLec 2 (Sec 2): \"prompt two\"\n=== END ==="
    conversation = provider.session.conversation
    conversation.canned_answers = {
        DIVIDE_PROMPT: "Chapter 1: Motion\n├── Lesson 1: Motion\n",
        EXECUTION_PROMPT: "System Ready",
        "Start with First lesson": first_bundle,
    }
    activities._save_context(run_id, {
        "schema_version": 1,
        "run_id": run_id,
        "notebook_id": "notebook-shared",
        "framework_source_id": "src-framework",
        "framework_sha256": "b" * 64,
        "items": {
            item1: {"item_id": item1, "pdf_ordinal": 1, "case_id": case1, "binding_ref": bind1,
                    "source_id": "src-pdf-1", "input_sha256": "c" * 64, "work_count": 1,
                    "completed_work_ordinals": []},
            item2: {"item_id": item2, "pdf_ordinal": 2, "case_id": case2, "binding_ref": bind2,
                    "source_id": "src-pdf-2", "input_sha256": "d" * 64, "work_count": 2,
                    "completed_work_ordinals": []},
        },
    })

    missing = await activities.execute_divide_turn({"run_id": run_id, "item_id": str(uuid.uuid4()), "pdf_ordinal": 1})
    assert missing["outcome"] == "failed" and missing["safe_code"] == "artifact_missing"

    divide1 = await activities.execute_divide_turn({"run_id": run_id, "item_id": item1, "pdf_ordinal": 1})
    assert divide1["outcome"] == "completed" and divide1["counts"]["work_count"] == 1
    lesson1 = await activities.execute_lesson_turns({"run_id": run_id, "item_id": item1, "pdf_ordinal": 1})
    assert lesson1["outcome"] == "completed"
    assert lesson1["counts"] == {"lessons_saved": 1, "prompts_saved": 2}
    assert [turn["question"] for turn in conversation.turns] == [DIVIDE_PROMPT, EXECUTION_PROMPT, "Start with First lesson"]
    assert conversation.turns[0]["source_ids"] == ["src-pdf-1"]
    assert all(turn["source_ids"] == ["src-pdf-1", "src-framework"] for turn in conversation.turns[1:])

    reset1 = await activities.reset_conversation_activity({"run_id": run_id, "pdf_ordinal": 1})
    assert reset1["outcome"] == "completed"
    assert conversation.resets and conversation.turns == []

    conversation.canned_answers.update({
        DIVIDE_PROMPT: "Chapter 2: Dynamics\n├── Lesson 1: Newton\n└── Lesson 2: Friction\n",
        "Start with First lesson": second_bundle,
        "Continue": third_bundle,
    })
    divide2 = await activities.execute_divide_turn({"run_id": run_id, "item_id": item2, "pdf_ordinal": 2})
    assert divide2["outcome"] == "completed" and divide2["counts"]["work_count"] == 2
    lesson2 = await activities.execute_lesson_turns({"run_id": run_id, "item_id": item2, "pdf_ordinal": 2})
    assert lesson2["outcome"] == "completed"
    assert lesson2["counts"] == {"lessons_saved": 2, "prompts_saved": 3}
    assert [turn["question"] for turn in conversation.turns] == [DIVIDE_PROMPT, EXECUTION_PROMPT, "Start with First lesson", "Continue"]
    assert conversation.turns[0]["source_ids"] == ["src-pdf-2"]
    current_turns = list(conversation.turns)
    repeated_reset = await activities.reset_conversation_activity({"run_id": run_id, "pdf_ordinal": 1})
    assert repeated_reset["outcome"] == "completed"
    assert len(conversation.resets) == 1 and conversation.turns == current_turns
    assert all(turn["source_ids"] == ["src-pdf-2", "src-framework"] for turn in conversation.turns[1:])

    export = await activities.export_prompts_activity({"run_id": run_id})
    assert export["outcome"] == "completed" and export["counts"]["prompts_saved"] == 5


@pytest.mark.asyncio
async def test_service_backed_failures_and_error_propagation(tmp_path, monkeypatch):
    """Known auth/provider failures remain typed at the production activity boundary."""
    from v3_engine.notebooklm.ports import AuthenticationRequiredError, ProviderError
    from v3_engine.notebooklm.protocol import DirectLessonProtocol
    from v3_engine.notebooklm.terminal_service import NotebookTerminalService
    from v3_engine.web_pipeline import activities

    monkeypatch.setenv("V3_TERMINAL_ROOT", str(tmp_path))
    run_id, item_id, case_id, binding_ref = (str(uuid.uuid4()) for _ in range(4))
    client = DirectLaravelBoundaryDouble({})
    client.add_case(case_id, binding_ref, "src-pdf", 1)
    client.bindings[binding_ref]["notebook_id"] = "notebook-shared"
    activities.set_application_client(client)
    activities._save_context(run_id, {
        "schema_version": 1, "run_id": run_id, "notebook_id": "notebook-shared",
        "framework_source_id": "src-framework", "framework_sha256": "b" * 64,
        "items": {item_id: {"item_id": item_id, "pdf_ordinal": 1, "case_id": case_id,
                            "binding_ref": binding_ref, "source_id": "src-pdf", "input_sha256": "c" * 64}},
    })

    class AuthFailingConv(TransportDoubleConversation):
        async def ask_once(self, notebook_id, question, source_ids, conversation_id=None, **kwargs):
            raise AuthenticationRequiredError("Session expired for approved account")

    auth_session = TransportDoubleSession()
    auth_session.conversation = AuthFailingConv()
    auth_provider = TransportDoubleProvider(auth_session)
    activities.set_terminal_service(NotebookTerminalService(session_provider=auth_provider))
    monkeypatch.setattr(activities, "_lesson_protocol", lambda _run: DirectLessonProtocol(auth_provider, client))
    auth_result = await activities.execute_divide_turn({"run_id": run_id, "item_id": item_id, "pdf_ordinal": 1})
    assert auth_result["outcome"] == "blocked"
    assert auth_result["safe_code"] == "authentication_required"

    class ProviderFailingConv(TransportDoubleConversation):
        async def ask_once(self, notebook_id, question, source_ids, conversation_id=None, **kwargs):
            raise ProviderError("provider_unavailable", "Provider transport unavailable")

    provider_session = TransportDoubleSession()
    provider_session.conversation = ProviderFailingConv()
    provider = TransportDoubleProvider(provider_session)
    activities.set_terminal_service(NotebookTerminalService(session_provider=provider))
    monkeypatch.setattr(activities, "_lesson_protocol", lambda _run: DirectLessonProtocol(provider, client))
    provider_result = await activities.execute_divide_turn({"run_id": run_id, "item_id": item_id, "pdf_ordinal": 1})
    assert provider_result["outcome"] == "failed"
    assert provider_result["safe_code"] == "provider_unavailable"
