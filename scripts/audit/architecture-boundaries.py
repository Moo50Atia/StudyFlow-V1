#!/usr/bin/env python3
"""Check stable adapter ownership boundaries without importing application code."""
from __future__ import annotations

import ast
from pathlib import Path
import re
import sys


ROOT = Path(__file__).resolve().parents[2]
SDK_OWNER = Path('v3_engine/notebooklm/sdk_provider.py')
SCOPES = (Path('v3_engine'), Path('scripts'), Path('docker'))
PYTHON_OWNERS = {
    'NotebookCredentialAuthority': Path('v3_engine/notebooklm/credentials.py'),
    'NotebookLMProviderAdapter': Path('v3_engine/local_worker/providers.py'),
    'TaskRunner': Path('v3_engine/local_worker/runner.py'),
    'StageContext': Path('v3_engine/local_worker/execution_context.py'),
    'HeartbeatManager': Path('v3_engine/local_worker/execution_context.py'),
    'AuthoringStageExecutor': Path('v3_engine/local_worker/authoring_stage.py'),
    'SceneReturnStageExecutor': Path('v3_engine/local_worker/scene_return_stage.py'),
}
PHP_OWNERS = {
    'ResultAcceptanceService': Path('app/Services/V3/LocalWorker/ResultAcceptanceService.php'),
    'AuthoringResultAcceptor': Path('app/Services/V3/LocalWorker/AuthoringResultAcceptor.php'),
    'SceneResultAcceptor': Path('app/Services/V3/LocalWorker/SceneResultAcceptor.php'),
    'SealedInputManifestReader': Path('app/Services/V3/LocalWorker/SealedInputManifestReader.php'),
}


def _python_files():
    for scope in SCOPES:
        base = ROOT / scope
        if not base.exists():
            continue
        for path in base.rglob('*.py'):
            if any(part in {'.venv', '__pycache__', 'tests'} for part in path.parts):
                continue
            yield path


def _external_sdk_imports(path: Path) -> list[str]:
    try:
        tree = ast.parse(path.read_text(encoding='utf-8'), filename=str(path))
    except (OSError, SyntaxError) as exc:
        return [f'{path.relative_to(ROOT)}: cannot inspect ({type(exc).__name__})']

    found = []
    for node in ast.walk(tree):
        modules = []
        if isinstance(node, ast.Import):
            modules.extend(alias.name for alias in node.names)
        elif isinstance(node, ast.ImportFrom) and node.module:
            modules.append(node.module)
        for module in modules:
            if module == 'notebooklm' or module.startswith('notebooklm.'):
                found.append(f'{path.relative_to(ROOT)}:{node.lineno}: {module}')
    return found


def main() -> int:
    violations = []
    owner = ROOT / SDK_OWNER
    if not owner.is_file():
        violations.append(f'Missing SDK adapter owner: {SDK_OWNER}')

    python_files = list(_python_files())
    definitions: dict[str, list[Path]] = {name: [] for name in PYTHON_OWNERS}
    for path in python_files:
        if path.relative_to(ROOT) == SDK_OWNER:
            tree = ast.parse(path.read_text(encoding='utf-8'), filename=str(path))
        else:
            violations.extend(_external_sdk_imports(path))
            try:
                tree = ast.parse(path.read_text(encoding='utf-8'), filename=str(path))
            except (OSError, SyntaxError) as exc:
                violations.append(f'{path.relative_to(ROOT)}: cannot inspect ({type(exc).__name__})')
                continue
        for node in ast.walk(tree):
            if isinstance(node, ast.ClassDef) and node.name in definitions:
                definitions[node.name].append(path.relative_to(ROOT))

    for name, expected_path in PYTHON_OWNERS.items():
        if definitions[name] != [expected_path]:
            actual = ', '.join(str(path) for path in definitions[name]) or 'missing'
            violations.append(f'{name} must be defined only in {expected_path}; found {actual}')

    php_definitions: dict[str, list[Path]] = {name: [] for name in PHP_OWNERS}
    for path in (ROOT / 'app').rglob('*.php'):
        try:
            source = path.read_text(encoding='utf-8')
        except OSError:
            continue
        for name in php_definitions:
            if re.search(r'\bclass\s+' + re.escape(name) + r'\b', source):
                php_definitions[name].append(path.relative_to(ROOT))
    for name, expected_path in PHP_OWNERS.items():
        if php_definitions[name] != [expected_path]:
            actual = ', '.join(str(path) for path in php_definitions[name]) or 'missing'
            violations.append(f'{name} must be defined only in {expected_path}; found {actual}')

    if violations:
        print('NotebookLM SDK ownership boundary violations:')
        print('\n'.join(f'  - {item}' for item in violations))
        print(f'Only {SDK_OWNER} may import the external notebooklm SDK.')
        return 1

    print('Architecture boundaries OK: shared NotebookLM, worker-stage and result-acceptance owners are unique.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
