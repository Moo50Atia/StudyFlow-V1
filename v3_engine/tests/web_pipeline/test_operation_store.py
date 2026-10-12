"""Tests for durable operation store and receipt ledger across restarts (T072)."""

import hashlib
import uuid
import pytest
from pathlib import Path

from v3_engine.web_pipeline.contracts import ContractValidationError
from v3_engine.web_pipeline.operation_store import WebOperationStore


def test_operation_store_restart_preserves_claims_and_commits(tmp_path: Path):
    store_file = tmp_path / "operations.json"
    op_id = str(uuid.uuid4())
    run_id = str(uuid.uuid4())
    req_sha = hashlib.sha256(b"request_payload").hexdigest()

    # Instance 1: Claim operation
    store1 = WebOperationStore(storage_path=store_file)
    record1 = store1.claim_operation(
        operation_id=op_id,
        run_id=run_id,
        kind="lesson_turn",
        item_id=str(uuid.uuid4()),
        request_sha256=req_sha,
    )
    assert record1.operation_id == op_id
    assert record1.status == "pending"

    # Instance 2: Fresh process restart re-reads storage
    store2 = WebOperationStore(storage_path=store_file)
    record2 = store2.claim_operation(
        operation_id=op_id,
        run_id=run_id,
        kind="lesson_turn",
        item_id=record1.item_id,
        request_sha256=req_sha,
    )
    assert record2.operation_id == op_id
    assert record2.status == "pending"

    # Conflicting digest raises ContractValidationError
    with pytest.raises(ContractValidationError):
        store2.claim_operation(
            operation_id=op_id,
            run_id=run_id,
            kind="lesson_turn",
            item_id=record1.item_id,
            request_sha256=hashlib.sha256(b"divergent").hexdigest(),
        )

    # Instance 3: Commit receipt
    store3 = WebOperationStore(storage_path=store_file)
    receipt = store3.commit_receipt(
        operation_id=op_id,
        outcome="completed",
        safe_code="ok",
        counts={"lessons": 1},
    )
    assert receipt["outcome"] == "completed"
    assert receipt["safe_code"] == "ok"

    # Instance 4: Verify receipt survived restart
    store4 = WebOperationStore(storage_path=store_file)
    rec = store4.get_operation(op_id)
    assert rec is not None
    assert rec.status == "completed"
    assert rec.counts == {"lessons": 1}


def test_operation_store_artifacts(tmp_path: Path):
    store_file = tmp_path / "operations.json"
    store = WebOperationStore(storage_path=store_file)

    art_ref = str(uuid.uuid4())
    content = b"Binary artifact content for scene test"
    digest = store.store_artifact(art_ref, content)
    assert digest == hashlib.sha256(content).hexdigest()

    retrieved = store.get_artifact(art_ref)
    assert retrieved == content
