import hashlib
import json
import shutil
from pathlib import Path
import pytest
from v3_engine.qualification.container_checks import run_isolated_browser_checks

FIXTURE = Path(__file__).parent / 'fixtures/valid_scene'


@pytest.mark.container
@pytest.mark.asyncio
async def test_exact_manifest_bytes_checked_in_isolated_image(tmp_path):
    shutil.copytree(FIXTURE, tmp_path / 'candidate')
    root = tmp_path / 'candidate'
    raw = (root / 'manifest.json').read_bytes()
    report = await run_isolated_browser_checks(str(root), '00000000-0000-0000-0000-000000000001', json.loads(raw), hashlib.sha256(raw).hexdigest())
    assert (root / 'manifest.json').read_bytes() == raw
    assert report['screenshot_png_base64']
    assert all(check['status'] == 'pass' for check in report['checks'])


@pytest.mark.asyncio
async def test_isolated_adapter_rejects_digest_drift_and_symlink(tmp_path):
    shutil.copytree(FIXTURE, tmp_path / 'candidate')
    root = tmp_path / 'candidate'
    raw = (root / 'manifest.json').read_bytes()
    with pytest.raises(ValueError, match='manifest_integrity'):
        await run_isolated_browser_checks(str(root), 'candidate', json.loads(raw), '0' * 64)
    asset = root / 'app.js'
    asset.unlink()
    asset.symlink_to(tmp_path / 'outside.js')
    (tmp_path / 'outside.js').write_text('private data')
    with pytest.raises(ValueError, match='candidate_path'):
        await run_isolated_browser_checks(str(root), 'candidate', json.loads(raw), hashlib.sha256(raw).hexdigest())
