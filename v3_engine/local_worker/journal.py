"""Durable local SQLite provider effect journal and crash window tracking."""

from __future__ import annotations

import os
import sqlite3
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Optional


@dataclass
class JournalEffect:
    effect_id: str
    task_id: str
    logical_effect_key: str
    operation_kind: str
    request_digest: str
    status: str
    attempt_count: int
    current_attempt_id: Optional[str]


@dataclass
class JournalAttempt:
    attempt_id: str
    effect_id: str
    attempt_number: int
    permit_id: str
    permit_expires_at: str
    status: str
    outcome_ref: Optional[str]
    raw_response_path: Optional[str]


class LocalEffectJournal:
    def __init__(self, db_path: Path):
        self.db_path = Path(db_path).expanduser().resolve()
        self.db_path.parent.mkdir(parents=True, exist_ok=True)
        self._init_db()

    def _get_connection(self) -> sqlite3.Connection:
        conn = sqlite3.connect(str(self.db_path), timeout=30.0)
        conn.row_factory = sqlite3.Row
        conn.execute("PRAGMA journal_mode=WAL;")
        conn.execute("PRAGMA synchronous=FULL;")
        return conn

    def _init_db(self) -> None:
        with self._get_connection() as conn:
            conn.executescript("""
                CREATE TABLE IF NOT EXISTS local_effects (
                    effect_id TEXT PRIMARY KEY,
                    task_id TEXT NOT NULL,
                    logical_effect_key TEXT NOT NULL UNIQUE,
                    operation_kind TEXT NOT NULL,
                    request_digest TEXT NOT NULL,
                    status TEXT NOT NULL,
                    attempt_count INTEGER NOT NULL DEFAULT 0,
                    current_attempt_id TEXT,
                    created_at REAL NOT NULL,
                    updated_at REAL NOT NULL
                );

                CREATE TABLE IF NOT EXISTS local_attempts (
                    attempt_id TEXT PRIMARY KEY,
                    effect_id TEXT NOT NULL,
                    attempt_number INTEGER NOT NULL,
                    permit_id TEXT NOT NULL UNIQUE,
                    permit_expires_at TEXT NOT NULL,
                    status TEXT NOT NULL,
                    outcome_ref TEXT,
                    raw_response_path TEXT,
                    created_at REAL NOT NULL,
                    updated_at REAL NOT NULL,
                    FOREIGN KEY(effect_id) REFERENCES local_effects(effect_id)
                );

                CREATE INDEX IF NOT EXISTS idx_effects_task ON local_effects(task_id, status);
                CREATE INDEX IF NOT EXISTS idx_attempts_effect ON local_attempts(effect_id);
            """)

    def record_intent(
        self,
        effect_id: str,
        task_id: str,
        logical_effect_key: str,
        operation_kind: str,
        request_digest: str,
    ) -> JournalEffect:
        now = time.time()
        with self._get_connection() as conn:
            # Check existing
            cur = conn.execute(
                "SELECT * FROM local_effects WHERE logical_effect_key = ?",
                (logical_effect_key,),
            )
            row = cur.fetchone()
            if row:
                if row["request_digest"] != request_digest:
                    raise ValueError("Logical effect key already exists with different request digest")
                return JournalEffect(
                    effect_id=row["effect_id"],
                    task_id=row["task_id"],
                    logical_effect_key=row["logical_effect_key"],
                    operation_kind=row["operation_kind"],
                    request_digest=row["request_digest"],
                    status=row["status"],
                    attempt_count=row["attempt_count"],
                    current_attempt_id=row["current_attempt_id"],
                )

            conn.execute(
                """
                INSERT INTO local_effects (
                    effect_id, task_id, logical_effect_key, operation_kind,
                    request_digest, status, attempt_count, current_attempt_id,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, 'reserved', 0, NULL, ?, ?)
                """,
                (effect_id, task_id, logical_effect_key, operation_kind, request_digest, now, now),
            )
            conn.commit()

        return JournalEffect(
            effect_id=effect_id,
            task_id=task_id,
            logical_effect_key=logical_effect_key,
            operation_kind=operation_kind,
            request_digest=request_digest,
            status="reserved",
            attempt_count=0,
            current_attempt_id=None,
        )

    def record_permit(
        self,
        attempt_id: str,
        effect_id: str,
        attempt_number: int,
        permit_id: str,
        permit_expires_at: str,
    ) -> JournalAttempt:
        now = time.time()
        with self._get_connection() as conn:
            conn.execute(
                """
                INSERT INTO local_attempts (
                    attempt_id, effect_id, attempt_number, permit_id,
                    permit_expires_at, status, outcome_ref, raw_response_path,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, 'issued', NULL, NULL, ?, ?)
                """,
                (attempt_id, effect_id, attempt_number, permit_id, permit_expires_at, now, now),
            )
            conn.execute(
                """
                UPDATE local_effects
                SET status = 'dispatching',
                    current_attempt_id = ?,
                    attempt_count = ?,
                    updated_at = ?
                WHERE effect_id = ?
                """,
                (attempt_id, attempt_number, now, effect_id),
            )
            conn.commit()

        return JournalAttempt(
            attempt_id=attempt_id,
            effect_id=effect_id,
            attempt_number=attempt_number,
            permit_id=permit_id,
            permit_expires_at=permit_expires_at,
            status="issued",
            outcome_ref=None,
            raw_response_path=None,
        )

    def mark_may_have_been_sent(self, attempt_id: str) -> None:
        now = time.time()
        with self._get_connection() as conn:
            cur = conn.execute("SELECT effect_id FROM local_attempts WHERE attempt_id = ?", (attempt_id,))
            row = cur.fetchone()
            effect_id = row["effect_id"] if row else None

            conn.execute(
                "UPDATE local_attempts SET status = 'may_have_been_sent', updated_at = ? WHERE attempt_id = ?",
                (now, attempt_id),
            )
            if effect_id:
                conn.execute(
                    "UPDATE local_effects SET status = 'may_have_been_sent', updated_at = ? WHERE effect_id = ?",
                    (now, effect_id),
                )
            conn.commit()

    def record_outcome(
        self,
        attempt_id: str,
        status: str,
        outcome_ref: Optional[str] = None,
        raw_response_path: Optional[str] = None,
    ) -> None:
        now = time.time()
        with self._get_connection() as conn:
            cur = conn.execute("SELECT effect_id FROM local_attempts WHERE attempt_id = ?", (attempt_id,))
            row = cur.fetchone()
            if not row:
                raise ValueError(f"Attempt '{attempt_id}' not found in journal")

            effect_id = row["effect_id"]

            conn.execute(
                """
                UPDATE local_attempts
                SET status = ?, outcome_ref = ?, raw_response_path = ?, updated_at = ?
                WHERE attempt_id = ?
                """,
                (status, outcome_ref, raw_response_path, now, attempt_id),
            )
            conn.execute(
                "UPDATE local_effects SET status = ?, updated_at = ? WHERE effect_id = ?",
                (status, now, effect_id),
            )
            conn.commit()

    def get_effect(self, effect_id: str) -> Optional[JournalEffect]:
        with self._get_connection() as conn:
            cur = conn.execute("SELECT * FROM local_effects WHERE effect_id = ?", (effect_id,))
            row = cur.fetchone()
            if not row:
                return None
            return JournalEffect(
                effect_id=row["effect_id"],
                task_id=row["task_id"],
                logical_effect_key=row["logical_effect_key"],
                operation_kind=row["operation_kind"],
                request_digest=row["request_digest"],
                status=row["status"],
                attempt_count=row["attempt_count"],
                current_attempt_id=row["current_attempt_id"],
            )

    def list_pending_effects(self, task_id: str) -> List[JournalEffect]:
        return [row for row in self.list_effects(task_id)
                if row.status in ('reserved', 'dispatching', 'unknown', 'may_have_been_sent')]

    def list_effects(self, task_id: str) -> List[JournalEffect]:
        with self._get_connection() as conn:
            cur = conn.execute(
                "SELECT * FROM local_effects WHERE task_id = ? ORDER BY created_at, effect_id",
                (task_id,),
            )
            results = []
            for row in cur.fetchall():
                results.append(
                    JournalEffect(
                        effect_id=row["effect_id"],
                        task_id=row["task_id"],
                        logical_effect_key=row["logical_effect_key"],
                        operation_kind=row["operation_kind"],
                        request_digest=row["request_digest"],
                        status=row["status"],
                        attempt_count=row["attempt_count"],
                        current_attempt_id=row["current_attempt_id"],
                    )
                )
            return results
