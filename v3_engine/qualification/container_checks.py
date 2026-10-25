"""Adapt the browser-check contract to the existing isolated, stdin-only checker."""
from __future__ import annotations
import asyncio
import hashlib
import io
import json
from pathlib import Path
import tarfile
from v3_engine.qualification.container_runner import run_container_check


async def run_isolated_browser_checks(candidate_dir: str, candidate_ref: str, manifest: dict, manifest_sha256: str | None) -> dict:
    root = Path(candidate_dir).resolve(strict=True)
    manifest_file = root / 'manifest.json'
    if manifest_file.is_symlink():
        raise ValueError('manifest_symlink')
    if manifest_file.exists():
        raw = manifest_file.read_bytes()
        if json.loads(raw) != manifest:
            raise ValueError('manifest_mismatch')
    else:
        # Unsigned local callers use the same canonical encoding as browser_checks.
        raw = json.dumps(manifest, sort_keys=True).encode('utf-8')
    digest = hashlib.sha256(raw).hexdigest()
    if manifest_sha256 and digest != manifest_sha256:
        raise ValueError('manifest_integrity_mismatch')
    payload = io.BytesIO()
    total = len(raw)
    names = set()
    with tarfile.open(fileobj=payload, mode='w:gz') as archive:
        def add(name: str, body: bytes) -> None:
            member = tarfile.TarInfo(name)
            member.size = len(body)
            member.mode = 0o644
            archive.addfile(member, io.BytesIO(body))
        add('manifest.json', raw)
        files = manifest.get('files', [])
        if not files or len(files) > 200:
            raise ValueError('manifest_files_invalid')
        for entry in files:
            name = entry['path']
            if not isinstance(name, str) or Path(name).is_absolute() or '..' in Path(name).parts:
                raise ValueError('candidate_path_invalid')
            path = root / name
            if name in names or name == 'manifest.json' or path.is_symlink() or not path.resolve(strict=True).is_relative_to(root):
                raise ValueError('candidate_path_invalid')
            names.add(name)
            body = path.read_bytes()
            total += len(body)
            if total > 32 * 1024 * 1024 or len(body) != entry.get('bytes', entry.get('size_bytes')) or hashlib.sha256(body).hexdigest() != entry['sha256']:
                raise ValueError('candidate_integrity_mismatch')
            add(name, body)
    result = await asyncio.to_thread(run_container_check, payload.getvalue(), candidate_ref, digest)
    if not result.get('success') or not isinstance(result.get('report'), dict):
        raise RuntimeError('checker_unavailable')
    return result['report']
