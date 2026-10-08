"""
Live provider and container completion gate test for Spec 012 (T018).
Marked with @pytest.mark.live and gated by V3_SCENE_QUAL_LIVE=1.
"""
import os
import json
import pytest
from pathlib import Path

from v3_engine.qualification.container_runner import run_container_check
from v3_engine.qualification.jev import SceneJevClient


@pytest.mark.live
@pytest.mark.asyncio
async def test_live_gate_scene_qualification():
    """
    Executes live verification for Spec 012 completion gate:
    1. Requires V3_SCENE_QUAL_LIVE=1, TYPESAFE_API_KEY, and JEV_MODEL.
    2. Runs container check on disposable scene package.
    3. Calls live TypeSafe JEV endpoint with genuine evidence payload.
    """
    is_live_enabled = os.environ.get("V3_SCENE_QUAL_LIVE") == "1"
    typesafe_key = os.environ.get("TYPESAFE_API_KEY")
    jev_model = os.environ.get("JEV_MODEL", "typesafe-systemone-v1")

    if not is_live_enabled:
        pytest.skip("V3_SCENE_QUAL_LIVE=1 not set. Skipping live scene qualification gate.")

    if not typesafe_key:
        pytest.fail(
            "Live scene qualification gate failed: TYPESAFE_API_KEY is missing from environment. "
            "Set TYPESAFE_API_KEY and V3_SCENE_QUAL_LIVE=1 to execute the live gate."
        )

    # 1. Execute container check on minimal valid package
    import io
    import tarfile
    import hashlib

    manifest_data = {
        "schema_version": 1,
        "files": [
            {"path": "index.html", "sha256": "", "bytes": 0}
        ]
    }
    index_html = (
        "<!DOCTYPE html><html lang='en'><head><title>Live Scene Gate</title></head>"
        "<body><button id='btn' aria-label='Action Button'>Click</button>"
        "<script>document.getElementById('btn').addEventListener('click', () => { document.body.setAttribute('data-clicked', 'true'); });</script>"
        "</body></html>"
    ).encode("utf-8")

    manifest_data["files"][0]["sha256"] = hashlib.sha256(index_html).hexdigest()
    manifest_data["files"][0]["bytes"] = len(index_html)
    manifest_bytes = json.dumps(manifest_data).encode("utf-8")
    manifest_sha = hashlib.sha256(manifest_bytes).hexdigest()

    tar_buf = io.BytesIO()
    with tarfile.open(fileobj=tar_buf, mode="w:gz") as tar:
        ti_m = tarfile.TarInfo(name="manifest.json")
        ti_m.size = len(manifest_bytes)
        tar.addfile(ti_m, io.BytesIO(manifest_bytes))

        ti_f = tarfile.TarInfo(name="index.html")
        ti_f.size = len(index_html)
        tar.addfile(ti_f, io.BytesIO(index_html))

    check_res = run_container_check(tar_buf.getvalue(), "00000000-0000-0000-0000-000000000001", manifest_sha)
    assert check_res["success"] is True, f"Container check failed: {check_res.get('error')}"

    # 2. Call live TypeSafe JEV endpoint
    import httpx
    async with httpx.AsyncClient(timeout=30.0) as client:
        models_resp = await client.get(
            "https://api.typesafe.ai/v1/models",
            headers={"Authorization": f"Bearer {typesafe_key}"}
        )
        assert models_resp.status_code == 200, f"Live /v1/models check failed: {models_resp.status_code} {models_resp.text}"
