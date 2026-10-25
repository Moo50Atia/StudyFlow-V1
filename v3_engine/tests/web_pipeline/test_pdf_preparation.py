import hashlib
import re
import subprocess
import uuid
from pathlib import Path

import pytest
from reportlab.pdfgen.canvas import Canvas

from v3_engine.terminal_pipeline.pdf_preparation import (
    PreparationPolicy, PreparationError, plan_ranges, prepare_pdf_sources, verify_preparation,
)
from v3_engine.web_pipeline.pdf_preparation import prepare_web_pdfs, verify_web_manifest_preparation, WEB_POLICY


def make_pdf(path: Path, pages: int, label: str = "source") -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    canvas = Canvas(str(path), invariant=1)
    for number in range(1, pages + 1):
        canvas.setPageRotation((number % 4) * 90)
        canvas.drawString(80, 300, f"Physical page {number} from {label}")
        canvas.showPage()
    canvas.save()


@pytest.mark.parametrize("pages", [1, 50, 51, 75, 100, 101, 114, 200, 1000])
def test_web_policy_preserves_every_page_in_order_and_bounds_every_part(pages):
    ranges = plan_ranges(pages, WEB_POLICY)
    assert [page for start, end in ranges for page in range(start, end + 1)] == list(range(1, pages + 1))
    assert all(end - start + 1 <= 50 for start, end in ranges)
    if pages == 114:
        assert ranges == [(1, 28), (29, 57), (58, 85), (86, 114)]


def test_legacy_policy_keeps_existing_captures_and_new_policy_is_distinct(tmp_path):
    source = tmp_path / "input" / "source.pdf"
    make_pdf(source, 75)
    package = tmp_path / "package"
    old = prepare_pdf_sources([source], package)
    assert old.policy == PreparationPolicy() and len(old.prepared_pdfs) == 1
    assert (package / old.prepared_pdfs[0].artifact_ref.path).read_bytes() == source.read_bytes()
    assert verify_preparation(package).inventory_sha256 == old.inventory_sha256
    with pytest.raises(PreparationError):
        prepare_pdf_sources([source], package, WEB_POLICY)


def build_web_package(root):
    key = str(uuid.uuid4())
    payload = {"owner_user_id": 7, "submission_key": key, "originals": []}
    for ordinal, (name, count) in enumerate([("Zulu.pdf", 114), ("Alpha.pdf", 2)], start=1):
        path = root / f"web-preparations/7/{key}/input/{ordinal:04d}/{name}"
        make_pdf(path, count, name)
        payload["originals"].append({"filename": name, "sha256": hashlib.sha256(path.read_bytes()).hexdigest(), "bytes": path.stat().st_size})
    result = prepare_web_pdfs(root, payload)
    package = root / result["inventory_ref"]
    inventory = verify_preparation(package)
    items = []
    for part in inventory.prepared_pdfs:
        source = inventory.originals[part.source_ordinal - 1]
        items.append({"item_id": str(uuid.uuid4()), "pdf_ordinal": part.upload_ordinal,
            "original_filename": part.filename, "input_sha256": part.artifact_ref.sha256,
            "input_bytes": part.artifact_ref.bytes,
            "pdf_lineage": {"source_ordinal": part.source_ordinal, "part_ordinal": part.part_ordinal,
                "original_filename": source.original_filename, "original_sha256": source.sha256,
                "original_page_count": source.page_count, "start_page": part.start_page,
                "end_page": part.end_page, "page_count": part.page_count}})
    manifest = {"owner_user_id": 7, "submission_key": key, "items": items,
        "pdf_preparation": {k: result[k] for k in ["inventory_ref", "inventory_file_sha256", "policy"]}}
    return payload, result, manifest


def test_web_package_names_preserve_input_order_ranges_and_actual_rotated_page_content(tmp_path):
    payload, result, manifest = build_web_package(tmp_path)
    verify_web_manifest_preparation(tmp_path, manifest)
    parts = result["prepared_pdfs"]
    assert [(p['start_page'], p['end_page']) for p in parts[:4]] == [(1, 28), (29, 57), (58, 85), (86, 114)]
    names = [p['filename'] for p in parts]
    assert names == sorted(names)
    assert all(name.startswith('0001--Zulu--pages-') for name in names[:4])
    assert names[4] == '0002--Alpha.pdf'
    package = (tmp_path / result['inventory_ref']).parent
    page_numbers = []
    for part in parts[:4]:
        text = subprocess.check_output(['pdftotext', str(package / part['artifact_ref']['path']), '-'], text=True)
        page_numbers.extend(int(x) for x in re.findall(r'Physical page (\d+)', text))
    assert page_numbers == list(range(1, 115))
    reused = prepare_web_pdfs(tmp_path, payload)
    assert reused == result
    assert all((package / o['retained_original_ref']['path']).is_file() for o in result['originals'])


@pytest.mark.parametrize('tamper', ['part_bytes', 'reverse_order', 'lineage', 'symlink'])
def test_web_preparation_rejects_tamper_before_provider_work(tmp_path, tamper):
    _, result, manifest = build_web_package(tmp_path)
    if tamper == 'part_bytes':
        part = (tmp_path / result['inventory_ref']).parent / result['prepared_pdfs'][0]['artifact_ref']['path']
        part.write_bytes(part.read_bytes() + b'changed')
    elif tamper == 'reverse_order':
        manifest['items'].reverse()
    elif tamper == 'lineage':
        manifest['items'][0]['pdf_lineage']['start_page'] = 2
    else:
        part = (tmp_path / result['inventory_ref']).parent / result['prepared_pdfs'][0]['artifact_ref']['path']
        target = tmp_path / 'elsewhere.pdf'
        part.rename(target)
        part.symlink_to(target)
    with pytest.raises((ValueError, PreparationError)):
        verify_web_manifest_preparation(tmp_path, manifest)


@pytest.mark.asyncio
async def test_resolver_verifies_private_parts_and_exposes_only_ordered_opaque_refs(tmp_path, monkeypatch):
    from unittest.mock import AsyncMock
    from v3_engine.web_pipeline import activities, bridge
    _, result, manifest = build_web_package(tmp_path)
    run_id, manifest_ref = str(uuid.uuid4()), str(uuid.uuid4())
    manifest.update(run_id=run_id, protocol="notebooklm_per_pdf_web_v3", framework_version_id=str(uuid.uuid4()))
    client = AsyncMock()
    client.get_manifest.return_value = manifest
    monkeypatch.setattr(activities, "get_application_client", lambda: client)
    monkeypatch.setattr(bridge, "get_storage_root", lambda: tmp_path)
    monkeypatch.setattr(activities, "_context_path", lambda key: tmp_path / "context.json")
    saved = {}
    monkeypatch.setattr(activities, "_save_context", lambda key, ctx: saved.update(ctx))
    resolved = await activities.resolve_application_run_activity({"run_id": run_id, "manifest_ref": manifest_ref})
    assert resolved["outcome"] == "completed"
    assert resolved["items"] == [{"item_id": i["item_id"], "pdf_ordinal": i["pdf_ordinal"]} for i in manifest["items"]]
    first = manifest["items"][0]
    assert saved["items"][first["item_id"]]["upload_title"] == result["prepared_pdfs"][0]["filename"]
    manifest["items"][0]["pdf_lineage"]["start_page"] = 2
    saved.clear()
    failed = await activities.resolve_application_run_activity({"run_id": run_id, "manifest_ref": manifest_ref})
    assert failed["outcome"] == "failed" and failed["safe_code"] == "artifact_corrupt"
    assert saved == {}


@pytest.mark.asyncio
async def test_manifest_replay_retains_committed_progress_and_rejects_changed_capture(tmp_path, monkeypatch):
    from unittest.mock import AsyncMock
    from v3_engine.web_pipeline import activities
    run_id, ref, item = str(uuid.uuid4()), str(uuid.uuid4()), str(uuid.uuid4())
    manifest = {"run_id": run_id, "protocol": "notebooklm_per_pdf_web_v2", "framework_version_id": str(uuid.uuid4()),
        "items": [{"item_id": item, "pdf_ordinal": 1}], "mode": "export_manual"}
    client = AsyncMock()
    client.get_manifest.return_value = manifest
    monkeypatch.setattr(activities, "get_application_client", lambda: client)
    monkeypatch.setattr(activities, "_context_path", lambda key: tmp_path / "context.json")
    args = {"run_id": run_id, "manifest_ref": ref}
    assert (await activities.resolve_application_run_activity(args))["outcome"] == "completed"
    context = activities._read_context(run_id)
    context["items"][item].update(source_id="verified-provider-source", cursor_version=7, completed_work_ordinals=[1, 2])
    activities._save_context(run_id, context)
    assert (await activities.resolve_application_run_activity(args))["outcome"] == "completed"
    assert activities._read_context(run_id) == context
    manifest["mode"] = "api_generate"
    rejected = await activities.resolve_application_run_activity(args)
    assert rejected["safe_code"] == "artifact_corrupt"
    assert activities._read_context(run_id) == context
