"""Persistent index and execution store for Terminal Pipeline (Spec 018)."""

from __future__ import annotations

import contextlib
import fcntl
import json
import sqlite3
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterator

from v3_engine.terminal_pipeline.contracts import (
    ArtifactRef,
    ControlAction,
    ControlState,
    SafeCode,
    SubjectLifecycle,
    validate_canonical_uuid,
)


class LifecycleTransitionError(Exception):
    def __init__(self, message: str, safe_code: SafeCode = SafeCode.control_conflict):
        super().__init__(message)
        self.safe_code = safe_code


class RunLockError(Exception):
    def __init__(self, message: str, safe_code: SafeCode = SafeCode.lock_busy):
        super().__init__(message)
        self.safe_code = safe_code


@dataclass(frozen=True)
class ExecutionOwnerMarker:
    schema_version: int
    subject_run_id: str
    generation_root_ref: str
    current_owner_workflow_id: str
    owner_epoch: int
    status: str
    active_recovery_id: str | None = None

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> ExecutionOwnerMarker:
        return cls(**data)


@dataclass(frozen=True)
class ControlRecord:
    subject_run_id: str
    command_id: str
    command_digest: str
    action: str
    expected_version: int
    state: str
    result_ref: str | None = None
    created_at: str | None = None
    updated_at: str | None = None


class TerminalIndexStore:
    def __init__(self, terminal_root: Path):
        p = Path(terminal_root)
        if p.name.endswith(".sqlite") or p.suffix == ".sqlite":
            self.terminal_root = p.parent
            self.db_path = p
        else:
            self.terminal_root = p
            self.db_path = p / "index.sqlite"

    def _get_connection(self) -> sqlite3.Connection:
        self.terminal_root.mkdir(parents=True, exist_ok=True)
        conn = sqlite3.connect(self.db_path, timeout=5.0)
        conn.row_factory = sqlite3.Row
        conn.execute("PRAGMA foreign_keys = ON;")
        conn.execute("PRAGMA busy_timeout = 5000;")
        cursor = conn.cursor()
        cursor.execute("PRAGMA user_version;")
        ver = cursor.fetchone()[0]
        if ver == 0:
            self._create_schema(conn)
        return conn

    def initialize_schema(self) -> None:
        with self._get_connection() as conn:
            pass

    def _create_schema(self, conn: sqlite3.Connection) -> None:
        conn.execute("PRAGMA journal_mode = WAL;")
        conn.execute("PRAGMA synchronous = FULL;")
        conn.executescript(
            """
            CREATE TABLE IF NOT EXISTS submissions (
                        submission_id TEXT PRIMARY KEY,
                        manifest_digest TEXT NOT NULL,
                        manifest_ref TEXT NOT NULL,
                        state TEXT NOT NULL,
                        accepted_receipt_json TEXT,
                        created_at TEXT NOT NULL,
                        updated_at TEXT NOT NULL
                    );

                    CREATE TABLE IF NOT EXISTS subjects (
                        subject_run_id TEXT PRIMARY KEY,
                        submission_id TEXT NOT NULL REFERENCES submissions(submission_id),
                        acceptance_seq INTEGER NOT NULL UNIQUE,
                        subject_key TEXT NOT NULL,
                        workflow_id TEXT NOT NULL,
                        manifest_ref TEXT NOT NULL,
                        run_ref TEXT NOT NULL,
                        generation_root_ref TEXT NOT NULL,
                        lifecycle TEXT NOT NULL,
                        started_run_id TEXT,
                        final_report_ref TEXT,
                        close_confirmation_ref TEXT,
                        cancellation_receipt_ref TEXT,
                        created_at TEXT NOT NULL,
                        updated_at TEXT NOT NULL
                    );

                    CREATE TABLE IF NOT EXISTS controls (
                        subject_run_id TEXT NOT NULL,
                        command_id TEXT NOT NULL,
                        command_digest TEXT NOT NULL,
                        action TEXT NOT NULL,
                        expected_version INTEGER NOT NULL,
                        state TEXT NOT NULL,
                        result_ref TEXT,
                        created_at TEXT NOT NULL,
                        updated_at TEXT NOT NULL,
                        PRIMARY KEY (subject_run_id, command_id)
                    );

                    CREATE TABLE IF NOT EXISTS recoveries (
                        recovery_id TEXT PRIMARY KEY,
                        original_generation_ref TEXT NOT NULL,
                        recovery_subject_id TEXT NOT NULL,
                        mode TEXT NOT NULL,
                        command_digest TEXT NOT NULL,
                        state TEXT NOT NULL,
                        created_at TEXT NOT NULL
                    );
                    CREATE UNIQUE INDEX IF NOT EXISTS idx_active_recovery_per_gen_root 
                    ON recoveries (original_generation_ref) WHERE state = 'active';

                    CREATE TABLE IF NOT EXISTS events (
                        seq INTEGER PRIMARY KEY AUTOINCREMENT,
                        event_id TEXT UNIQUE NOT NULL,
                        occurred_at TEXT NOT NULL,
                        submission_id TEXT,
                        subject_run_id TEXT,
                        workflow_id TEXT,
                        run_id TEXT,
                        operation_id TEXT,
                        attempt INTEGER,
                        phase TEXT,
                        kind TEXT NOT NULL,
                        outcome TEXT,
                        duration_ms INTEGER,
                        safe_code TEXT,
                        http_status INTEGER,
                        artifact_ref TEXT,
                        counts_json TEXT
                    );
                    """
        )
        conn.execute("PRAGMA user_version = 1;")

    def register_submission(self, submission_id: str, manifest_digest: str, manifest_ref: str) -> dict[str, Any]:
        validate_canonical_uuid(submission_id, "submission_id")
        now = datetime.now(timezone.utc).isoformat()
        with self._get_connection() as conn:
            cursor = conn.cursor()
            cursor.execute("SELECT manifest_digest, accepted_receipt_json FROM submissions WHERE submission_id = ?;", (submission_id,))
            row = cursor.fetchone()
            if row is not None:
                existing_digest = row["manifest_digest"]
                if existing_digest != manifest_digest:
                    raise LifecycleTransitionError(
                        f"Manifest digest conflicts with existing submission {submission_id}",
                        SafeCode.input_changed,
                    )
                receipt_json = row["accepted_receipt_json"]
                return json.loads(receipt_json) if receipt_json else {"status": "accepted", "submission_id": submission_id}

            receipt = {
                "status": "accepted",
                "submission_id": submission_id,
                "manifest_digest": manifest_digest,
                "manifest_ref": manifest_ref,
                "accepted_at": now,
            }
            conn.execute(
                """
                INSERT INTO submissions (submission_id, manifest_digest, manifest_ref, state, accepted_receipt_json, created_at, updated_at)
                VALUES (?, ?, ?, 'accepted', ?, ?, ?);
                """,
                (submission_id, manifest_digest, manifest_ref, json.dumps(receipt), now, now),
            )
            return receipt

    def register_submission_bundle(self, payload: dict[str, Any]) -> dict[str, Any]:
        """Atomically persist submission acceptance and all queued subject identities."""
        submission_id = validate_canonical_uuid(payload["submission_id"], "submission_id")
        digest = payload["submission_digest"]
        manifest_ref = payload["manifest_ref"]
        subjects = payload.get("subjects")
        if not isinstance(subjects, list) or not subjects:
            raise LifecycleTransitionError("Submission must contain subjects", SafeCode.input_invalid)
        now = datetime.now(timezone.utc).isoformat()
        with self._get_connection() as conn:
            row = conn.execute("SELECT manifest_digest, accepted_receipt_json FROM submissions WHERE submission_id=?", (submission_id,)).fetchone()
            if row is not None:
                if row["manifest_digest"] != digest:
                    raise LifecycleTransitionError("Submission ID reused with different manifest bytes", SafeCode.input_changed)
                existing = conn.execute("SELECT COUNT(*) AS n FROM subjects WHERE submission_id=?", (submission_id,)).fetchone()["n"]
                if existing != len(subjects):
                    raise LifecycleTransitionError("Persisted submission subject count conflicts", SafeCode.input_changed)
                old_receipt = json.loads(row["accepted_receipt_json"])
                old_receipt["duplicate"] = True
                return old_receipt

            receipt = {
                "outcome": "accepted", "submission_id": submission_id,
                "manifest_digest": digest, "manifest_ref": manifest_ref,
                "subject_count": len(subjects), "accepted_at": now,
            }
            conn.execute(
                "INSERT INTO submissions(submission_id,manifest_digest,manifest_ref,state,accepted_receipt_json,created_at,updated_at) VALUES(?,?,?,'accepted',?,?,?)",
                (submission_id, digest, manifest_ref, json.dumps(receipt), now, now),
            )
            next_seq = conn.execute("SELECT COALESCE(MAX(acceptance_seq),0)+1 AS n FROM subjects").fetchone()["n"]
            for subject in subjects:
                run_id = validate_canonical_uuid(subject["subject_run_id"], "subject_run_id")
                conn.execute(
                    "INSERT INTO subjects(subject_run_id,submission_id,acceptance_seq,subject_key,workflow_id,manifest_ref,run_ref,generation_root_ref,lifecycle,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?, 'queued', ?,?)",
                    (run_id, submission_id, next_seq, subject["subject_key"], f"v3-terminal-subject-{run_id}", manifest_ref,
                     subject["run_ref"], subject["run_ref"], now, now),
                )
                next_seq += 1
            receipt["duplicate"] = False
            return receipt

    def admit_recovery(self, payload: dict[str, Any]) -> dict[str, Any]:
        """Durably reserve a linked recovery and register its child before ack."""
        recovery_id = validate_canonical_uuid(payload["recovery_id"], "recovery_id")
        recovery_subject_id = validate_canonical_uuid(payload["recovery_subject_id"], "recovery_subject_id")
        generation_ref = str(payload["original_generation_ref"])
        mode = str(payload["mode"])
        digest = str(payload["command_digest"])
        original_subject_id = generation_ref.removeprefix("runs/").rstrip("/").split("/")[-1]
        validate_canonical_uuid(original_subject_id, "original_subject_id")
        if mode not in {"build", "generate"} or len(digest) != 64:
            raise LifecycleTransitionError("Invalid recovery request", SafeCode.input_invalid)

        now = datetime.now(timezone.utc).isoformat()
        with self._get_connection() as conn:
            existing = conn.execute(
                "SELECT recovery_id, command_digest, recovery_subject_id, state FROM recoveries WHERE recovery_id=?",
                (recovery_id,),
            ).fetchone()
            if existing:
                if (existing["command_digest"] != digest or existing["recovery_subject_id"] != recovery_subject_id):
                    raise LifecycleTransitionError("Recovery command identity conflicts", SafeCode.control_conflict)
                return {"outcome": "complete", "duplicate": True, "recovery_id": recovery_id,
                        "recovery_subject_id": recovery_subject_id}

            active = conn.execute(
                "SELECT recovery_id FROM recoveries WHERE original_generation_ref=? AND state='active'",
                (generation_ref,),
            ).fetchone()
            if active:
                raise LifecycleTransitionError("Another recovery is active", SafeCode.recovery_busy)

            original = conn.execute(
                "SELECT submission_id, subject_key, manifest_ref FROM subjects WHERE subject_run_id=?",
                (original_subject_id,),
            ).fetchone()
            if not original:
                raise LifecycleTransitionError("Original subject is not registered", SafeCode.input_invalid)
            if conn.execute("SELECT 1 FROM subjects WHERE subject_run_id=?", (recovery_subject_id,)).fetchone():
                raise LifecycleTransitionError("Recovery subject ID is already registered", SafeCode.control_conflict)

            sequence = conn.execute("SELECT COALESCE(MAX(acceptance_seq),0)+1 AS n FROM subjects").fetchone()["n"]
            conn.execute(
                "INSERT INTO recoveries(recovery_id,original_generation_ref,recovery_subject_id,mode,command_digest,state,created_at) VALUES(?,?,?,?,?,'active',?)",
                (recovery_id, generation_ref, recovery_subject_id, mode, digest, now),
            )
            conn.execute(
                "INSERT INTO subjects(subject_run_id,submission_id,acceptance_seq,subject_key,workflow_id,manifest_ref,run_ref,generation_root_ref,lifecycle,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,'queued',?,?)",
                (recovery_subject_id, original["submission_id"], sequence,
                 f"recovery:{recovery_id}", f"v3-terminal-subject-{recovery_subject_id}",
                 original["manifest_ref"], f"runs/{recovery_subject_id}", generation_ref, now, now),
            )
        return {"outcome": "complete", "duplicate": False, "recovery_id": recovery_id,
                "recovery_subject_id": recovery_subject_id}

    def enqueue_subject(
        self,
        subject_run_id: str,
        submission_id: str,
        subject_key: str,
        workflow_id: str,
        manifest_ref: str,
        run_ref: str,
        generation_root_ref: str,
    ) -> int:
        validate_canonical_uuid(subject_run_id, "subject_run_id")
        now = datetime.now(timezone.utc).isoformat()
        with self._get_connection() as conn:
            cursor = conn.cursor()
            cursor.execute("SELECT COALESCE(MAX(acceptance_seq), 0) + 1 AS next_seq FROM subjects;")
            next_seq = cursor.fetchone()["next_seq"]

            conn.execute(
                """
                INSERT INTO subjects (
                    subject_run_id, submission_id, acceptance_seq, subject_key, workflow_id,
                    manifest_ref, run_ref, generation_root_ref, lifecycle, created_at, updated_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'queued', ?, ?);
                """,
                (
                    subject_run_id,
                    submission_id,
                    next_seq,
                    subject_key,
                    workflow_id,
                    manifest_ref,
                    run_ref,
                    generation_root_ref,
                    now,
                    now,
                ),
            )
            return next_seq

    def get_subject(self, subject_run_id: str) -> dict[str, Any] | None:
        with self._get_connection() as conn:
            cursor = conn.cursor()
            cursor.execute("SELECT * FROM subjects WHERE subject_run_id = ?;", (subject_run_id,))
            row = cursor.fetchone()
            return dict(row) if row else None

    def list_queued_subjects(self, limit: int = 64) -> list[dict[str, Any]]:
        with self._get_connection() as conn:
            cursor = conn.cursor()
            cursor.execute("SELECT * FROM subjects WHERE lifecycle = 'queued' ORDER BY acceptance_seq ASC LIMIT ?;", (limit,))
            return [dict(r) for r in cursor.fetchall()]

    def count_active_subjects(self) -> int:
        with self._get_connection() as conn:
            cursor = conn.cursor()
            cursor.execute("SELECT COUNT(*) AS c FROM subjects WHERE lifecycle IN ('launch_pending', 'started');")
            return cursor.fetchone()["c"]

    def transition_subject_lifecycle(
        self,
        subject_run_id: str,
        to_lifecycle: SubjectLifecycle,
        started_run_id: str | None = None,
        final_report_ref: str | None = None,
        close_confirmation_ref: str | None = None,
    ) -> None:
        now = datetime.now(timezone.utc).isoformat()
        with self._get_connection() as conn:
            cursor = conn.cursor()
            cursor.execute("SELECT lifecycle FROM subjects WHERE subject_run_id = ?;", (subject_run_id,))
            row = cursor.fetchone()
            if not row:
                raise LifecycleTransitionError(f"Subject not found: {subject_run_id}", SafeCode.input_invalid)
            current = row["lifecycle"]

            if current == SubjectLifecycle.cancelled_before_start.value:
                raise LifecycleTransitionError(
                    f"Cannot transition from cancelled_before_start for {subject_run_id}",
                    SafeCode.control_conflict,
                )

            if current == SubjectLifecycle.settled.value:
                raise LifecycleTransitionError(
                    f"Cannot transition from settled for {subject_run_id}",
                    SafeCode.control_conflict,
                )

            to_val = to_lifecycle.value if isinstance(to_lifecycle, SubjectLifecycle) else str(to_lifecycle)
            if to_val == SubjectLifecycle.settled.value:
                if not close_confirmation_ref:
                    raise LifecycleTransitionError(
                        "Close confirmation proof required for settlement",
                        SafeCode.receipt_invalid,
                    )

            conn.execute(
                """
                UPDATE subjects
                SET lifecycle = ?,
                    started_run_id = COALESCE(?, started_run_id),
                    final_report_ref = COALESCE(?, final_report_ref),
                    close_confirmation_ref = COALESCE(?, close_confirmation_ref),
                    updated_at = ?
                WHERE subject_run_id = ?;
                """,
                (to_val, started_run_id, final_report_ref, close_confirmation_ref, now, subject_run_id),
            )

    def settle_subject(
        self,
        subject_run_id: str,
        terminal_status: str = "completed",
        final_report_ref: str | None = None,
        close_proof: str | dict[str, Any] | None = None,
        recovery_id: str | None = None,
    ) -> None:
        proof_ref = close_proof if isinstance(close_proof, str) else json.dumps(close_proof) if close_proof else None
        if recovery_id:
            now = datetime.now(timezone.utc).isoformat()
            with self._get_connection() as conn:
                row = conn.execute("SELECT lifecycle, final_report_ref, close_confirmation_ref FROM subjects WHERE subject_run_id=?", (subject_run_id,)).fetchone()
                if not row:
                    raise LifecycleTransitionError(f"Subject not found: {subject_run_id}", SafeCode.input_invalid)
                if row["lifecycle"] == SubjectLifecycle.settled.value:
                    if row["final_report_ref"] != final_report_ref or row["close_confirmation_ref"] != proof_ref:
                        raise LifecycleTransitionError("Conflicting repeated recovery settlement", SafeCode.control_conflict)
                else:
                    if row["lifecycle"] == SubjectLifecycle.cancelled_before_start.value:
                        raise LifecycleTransitionError("Cannot settle cancelled recovery", SafeCode.control_conflict)
                    conn.execute(
                        "UPDATE subjects SET lifecycle=?, final_report_ref=?, close_confirmation_ref=?, updated_at=? WHERE subject_run_id=?",
                        (SubjectLifecycle.settled.value, final_report_ref, proof_ref, now, subject_run_id),
                    )
                updated = conn.execute(
                    "UPDATE recoveries SET state='settled' WHERE recovery_id=? AND recovery_subject_id=?",
                    (recovery_id, subject_run_id),
                )
                if updated.rowcount != 1:
                    raise LifecycleTransitionError("Recovery reservation missing during settlement", SafeCode.control_conflict)
            return
        self.transition_subject_lifecycle(
            subject_run_id=subject_run_id,
            to_lifecycle=SubjectLifecycle.settled,
            final_report_ref=final_report_ref,
            close_confirmation_ref=proof_ref,
        )

    def list_unsettled_subjects(self) -> list[dict[str, Any]]:
        with self._get_connection() as conn:
            cursor = conn.cursor()
            cursor.execute(
                """
                SELECT * FROM subjects
                WHERE lifecycle IN ('queued', 'launch_pending', 'started', 'cancelled_before_start')
                ORDER BY acceptance_seq ASC;
                """
            )
            return [dict(r) for r in cursor.fetchall()]

    def cancel_queued_subject(
        self,
        subject_run_id: str,
        command_id: str,
        cancellation_receipt_ref: str,
    ) -> None:
        now = datetime.now(timezone.utc).isoformat()
        with self._get_connection() as conn:
            cursor = conn.cursor()
            cursor.execute("SELECT lifecycle FROM subjects WHERE subject_run_id = ?;", (subject_run_id,))
            row = cursor.fetchone()
            if not row:
                raise LifecycleTransitionError(f"Subject not found: {subject_run_id}", SafeCode.input_invalid)
            if row["lifecycle"] != SubjectLifecycle.queued.value:
                raise LifecycleTransitionError(
                    f"Subject {subject_run_id} is not queued (current: {row['lifecycle']})",
                    SafeCode.control_conflict,
                )

            conn.execute(
                """
                UPDATE subjects
                SET lifecycle = 'cancelled_before_start',
                    cancellation_receipt_ref = ?,
                    updated_at = ?
                WHERE subject_run_id = ? AND lifecycle = 'queued';
                """,
                (cancellation_receipt_ref, now, subject_run_id),
            )

    def reserve_recovery(
        self,
        recovery_id: str,
        original_generation_ref: str,
        recovery_subject_id: str,
        mode: str,
        command_digest: str,
    ) -> bool:
        now = datetime.now(timezone.utc).isoformat()
        with self._get_connection() as conn:
            cursor = conn.cursor()
            cursor.execute(
                "SELECT recovery_id, command_digest FROM recoveries WHERE original_generation_ref = ? AND state = 'active';",
                (original_generation_ref,),
            )
            row = cursor.fetchone()
            if row is not None:
                if row["recovery_id"] == recovery_id and row["command_digest"] == command_digest:
                    return True  # Idempotent re-entry
                raise LifecycleTransitionError(
                    f"recovery_busy: Another active recovery ({row['recovery_id']}) exists for {original_generation_ref}",
                    SafeCode.recovery_busy,
                )

            conn.execute(
                """
                INSERT INTO recoveries (recovery_id, original_generation_ref, recovery_subject_id, mode, command_digest, state, created_at)
                VALUES (?, ?, ?, ?, ?, 'active', ?);
                """,
                (recovery_id, original_generation_ref, recovery_subject_id, mode, command_digest, now),
            )
            return True


class TerminalRunStore:
    def __init__(self, terminal_root: Path, subject_run_id: str):
        self.terminal_root = terminal_root
        self.subject_run_id = subject_run_id
        self.run_dir = terminal_root / "runs" / subject_run_id
        self.lock_file = self.run_dir / ".run.lock"
        self.marker_file = self.run_dir / ".execution-owner.json"

    def initialize_run_dir(self) -> None:
        self.run_dir.mkdir(parents=True, exist_ok=True)

    def is_managed_root(self) -> bool:
        return self.marker_file.is_file()

    def check_standalone_access_allowed(self) -> bool:
        return not self.is_managed_root()

    @contextlib.contextmanager
    def acquire_run_lock(
        self,
        owner_workflow_id: str,
        owner_epoch: int,
        status: str = "running",
        active_recovery_id: str | None = None,
    ) -> Iterator[ExecutionOwnerMarker]:
        self.initialize_run_dir()
        lock_fd = None
        try:
            lock_fd = open(self.lock_file, "w")
            try:
                fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except (BlockingIOError, OSError) as e:
                raise RunLockError(
                    f"lock_busy: Another process holds the run lock for {self.subject_run_id}",
                    SafeCode.lock_busy,
                ) from e

            marker = ExecutionOwnerMarker(
                schema_version=1,
                subject_run_id=self.subject_run_id,
                generation_root_ref=f"runs/{self.subject_run_id}",
                current_owner_workflow_id=owner_workflow_id,
                owner_epoch=owner_epoch,
                status=status,
                active_recovery_id=active_recovery_id,
            )
            temp_marker = self.run_dir / ".execution-owner.json.tmp"
            with open(temp_marker, "w", encoding="utf-8") as f:
                json.dump(marker.to_dict(), f, indent=2)
                f.flush()
                fcntl.fdatasync(f.fileno()) if hasattr(fcntl, "fdatasync") else None
            temp_marker.replace(self.marker_file)

            yield marker
        finally:
            if lock_fd is not None:
                try:
                    fcntl.flock(lock_fd, fcntl.LOCK_UN)
                except OSError:
                    pass
                lock_fd.close()
