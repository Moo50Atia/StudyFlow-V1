"""Isolated live-test worker profile: change provider-facing names only."""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT))


def named_source_request(request, profile, terminal_root):
    """Retain a byte-identical worker-owned alias with a readable basename."""
    source = Path(request['source_path'])
    if request['mime_type'] == 'application/pdf':
        match = re.fullmatch(r'hosted-pdf-(\d+)\.pdf', source.name)
        if not match:
            raise ValueError('Naming experiment requires the captured ordered PDF snapshot')
        name = profile['pdf_name_template'].format(ordinal=int(match[1]))
    else:
        name = profile['framework_name']
    if not name or Path(name).name != name or '\\' in name or len(name) > 200:
        raise ValueError('Unsafe provider filename in naming experiment')
    digest = hashlib.sha256(source.read_bytes()).hexdigest()
    if request.get('expected_sha256') != digest:
        raise ValueError('Naming experiment source bytes differ from the captured snapshot')
    folder = Path(terminal_root)/'runs'/request['subject_run_id']/'private'/'named-provider-inputs'
    folder.mkdir(parents=True, exist_ok=True, mode=0o700)
    target = folder/name
    fd, staging_name = tempfile.mkstemp(dir=folder, prefix='.copy-')
    staging = Path(staging_name)
    try:
        with os.fdopen(fd, 'wb') as out, source.open('rb') as src:
            shutil.copyfileobj(src, out)
            out.flush()
            os.fsync(out.fileno())
        if hashlib.sha256(staging.read_bytes()).hexdigest() != digest:
            raise ValueError('Naming experiment alias copy changed input bytes')
        os.replace(staging, target)
    finally:
        staging.unlink(missing_ok=True)
    return {**request, 'source_path': str(target), 'title': name}


def install_profile(profile, terminal_root):
    from temporalio import activity
    from v3_engine.terminal_pipeline import worker
    original_create = worker.create_terminal_notebook
    original_upload = worker.upload_terminal_source

    @activity.defn(name='create_terminal_notebook')
    async def create_named_notebook(request):
        return await original_create({**request, 'title': profile['notebook_title']})

    @activity.defn(name='upload_terminal_source')
    async def upload_named_source(request):
        return await original_upload(named_source_request(request, profile, terminal_root))

    worker.create_terminal_notebook = create_named_notebook
    worker.upload_terminal_source = upload_named_source


def main():
    profile_path = Path(os.environ['NOTEBOOKLM_NAMING_PROFILE_PATH']).resolve()
    terminal_root = Path(os.environ['V3_TERMINAL_ROOT']).resolve()
    if (profile_path.parent != terminal_root.parent or terminal_root.name != 'terminal'
            or os.environ.get('V3_TERMINAL_LOAD_PROJECT_ENV') != '0'):
        raise RuntimeError('Naming profile is restricted to the isolated verification runtime')
    raw = profile_path.read_bytes()
    if hashlib.sha256(raw).hexdigest() != os.environ['NOTEBOOKLM_NAMING_PROFILE_SHA256']:
        raise RuntimeError('Captured naming profile changed before worker startup')
    profile = json.loads(raw)
    install_profile(profile, terminal_root)
    from v3_engine.terminal_pipeline.cli import main as terminal_main
    return terminal_main()


if __name__ == '__main__':
    raise SystemExit(main())
