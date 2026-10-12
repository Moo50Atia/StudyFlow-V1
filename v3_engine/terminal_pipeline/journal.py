"""Operational event journal for Terminal Pipeline (Spec 018)."""

from __future__ import annotations

import hashlib
import json
import sqlite3
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from v3_engine.terminal_pipeline.contracts import SafeCode


class JournalError(Exception):
    def __init__(self, message: str, safe_code: SafeCode = SafeCode.storage_unavailable):
        super().__init__(message)
        self.safe_code = safe_code


@dataclass(frozen=True)
class OperationalEvent:
    event_id: str
    kind: str
    occurred_at: str
    submission_id: str | None = None
    subject_run_id: str | None = None
    workflow_id: str | None = None
    run_id: str | None = None
    operation_id: str | None = None
    attempt: int | None = None
    phase: str | None = None
    outcome: str | None = None
    duration_ms: int | None = None
    safe_code: str | None = None
    http_status: int | None = None
    artifact_ref: str | None = None
    counts: dict[str, int] | None = None
    seq: int | None = None

    def to_dict(self) -> dict[str, Any]:
        d = asdict(self)
        if self.seq is None:
            d.pop("seq", None)
        return d

    @classmethod
    def from_row(cls, row: sqlite3.Row) -> OperationalEvent:
        counts = json.loads(row["counts_json"]) if row["counts_json"] else None
        return cls(
            seq=row["seq"],
            event_id=row["event_id"],
            occurred_at=row["occurred_at"],
            submission_id=row["submission_id"],
            subject_run_id=row["subject_run_id"],
            workflow_id=row["workflow_id"],
            run_id=row["run_id"],
            operation_id=row["operation_id"],
            attempt=row["attempt"],
            phase=row["phase"],
            kind=row["kind"],
            outcome=row["outcome"],
            duration_ms=row["duration_ms"],
            safe_code=row["safe_code"],
            http_status=row["http_status"],
            artifact_ref=row["artifact_ref"],
            counts=counts,
        )


def make_stable_event_id(
    logical_op: str,
    attempt: int | None,
    kind: str,
    ordinal: int | None = None,
) -> str:
    key = f"{logical_op}:{attempt or 0}:{kind}:{ordinal or 0}"
    return hashlib.sha256(key.encode("utf-8")).hexdigest()[:32]


class OperationalJournal:
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
        conn.executescript(
            """
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
            CREATE INDEX IF NOT EXISTS idx_events_subject_seq ON events (subject_run_id, seq);
            """
        )
        return conn

    def append_event(self, event: OperationalEvent) -> int:
        now = event.occurred_at or datetime.now(timezone.utc).isoformat()
        counts_json = json.dumps(event.counts) if event.counts else None

        with self._get_connection() as conn:
            cursor = conn.cursor()
            cursor.execute(
                "SELECT seq, kind, safe_code, outcome, artifact_ref FROM events WHERE event_id = ?;",
                (event.event_id,),
            )
            existing = cursor.fetchone()
            if existing is not None:
                # Check for conflict
                if (
                    existing["kind"] != event.kind
                    or existing["safe_code"] != event.safe_code
                    or existing["outcome"] != event.outcome
                ):
                    raise JournalError(
                        f"Conflicting event payload for existing event_id: {event.event_id}",
                        SafeCode.storage_unavailable,
                    )
                return existing["seq"]

            cursor.execute(
                """
                INSERT INTO events (
                    event_id, occurred_at, submission_id, subject_run_id, workflow_id,
                    run_id, operation_id, attempt, phase, kind, outcome, duration_ms,
                    safe_code, http_status, artifact_ref, counts_json
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);
                """,
                (
                    event.event_id,
                    now,
                    event.submission_id,
                    event.subject_run_id,
                    event.workflow_id,
                    event.run_id,
                    event.operation_id,
                    event.attempt,
                    event.phase,
                    event.kind,
                    event.outcome,
                    event.duration_ms,
                    event.safe_code,
                    event.http_status,
                    event.artifact_ref,
                    counts_json,
                ),
            )
            return cursor.lastrowid

    def read_events(self, after_seq: int = 0, limit: int = 100, subject_run_id: str | None = None) -> list[OperationalEvent]:
        with self._get_connection() as conn:
            cursor = conn.cursor()
            if subject_run_id is None:
                cursor.execute("SELECT * FROM events WHERE seq > ? ORDER BY seq ASC LIMIT ?;", (after_seq, limit))
            else:
                cursor.execute(
                    "SELECT * FROM events WHERE seq > ? AND subject_run_id = ? ORDER BY seq ASC LIMIT ?;",
                    (after_seq, subject_run_id, limit),
                )
            return [OperationalEvent.from_row(r) for r in cursor.fetchall()]
