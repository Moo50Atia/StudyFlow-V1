#!/usr/bin/env python3
"""Generate a filename-exhaustive map of maintained application trees (no secrets read)."""
from pathlib import Path
from collections import defaultdict
import subprocess, json, html
ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / 'docs/system-map'
TREES = ['app','routes','config','bootstrap','database','resources','v3_engine','scripts','bin','docker','integrations','tests']
ROOT_FILES = ['artisan','compose.yaml','compose.dev.yaml','composer.json','composer.lock','package.json','package-lock.json','pyproject.toml','pytest.ini','phpunit.xml','vite.config.js','vite.config.ts','postcss.config.js','tailwind.config.js','.dockerignore','.gitignore','.editorconfig','.env.example']
SKIP_PARTS = {'node_modules','vendor','__pycache__','.pytest_cache','.git','wheels','chrome_dist','dist','build','.venv'}
SKIP_SUFFIX = {'.pyc','.sqlite','.db','.pdf','.png','.jpg','.jpeg','.webp','.gif','.zip','.whl','.mp4','.log'}
def eligible(p):
    return not (set(p.parts)&SKIP_PARTS or p.suffix.lower() in SKIP_SUFFIX or p.name.startswith('.env') and p.name != '.env.example' or p.name == 'cache' or 'bootstrap/cache/' in p.as_posix())
files = set()
for tree in TREES:
    result = subprocess.run(['rg','--files','--hidden',tree],cwd=ROOT,text=True,capture_output=True,check=True)
    files.update(p for s in result.stdout.splitlines() if eligible(p:=Path(s)))
files.update(Path(p) for p in ROOT_FILES if (ROOT/p).is_file())
# Database runtime files and deployment image pins are configuration, binary databases are excluded.
DOMAINS = {
 'local_worker': ('daemon', 'Local worker, protocol, fencing and device management'),
 'devices': ('devices', 'Device registry, assignment and fencing'),
 'terminal_pipeline': ('terminalworker', 'Terminal orchestration, preparation and export'),
 'notebooklm': ('notebook', 'NotebookLM authentication and processing'),
 'auth': ('auth', 'Shared NotebookLM credential and session authority'),
 'pdf': ('pdf', 'Provider-free PDF preparation and lineage'),
 'execution': ('execution', 'Workflow execution and activity adapters'),
 'acceptance': ('acceptance', 'Result validation and canonical acceptance'),
 'web_pipeline': ('webflow', 'Web subject runs, import and projections'),
 'authoring': ('authoring', 'Scene authoring and generation'),
 'scene_generation': ('authoring', 'Scene authoring and generation'),
 'qualification': ('qualification', 'Scene qualification and repairs'),
 'scenequalification': ('qualification', 'Scene qualification and repairs'),
 'prompts': ('prompts', 'Prompt lifecycle'),
 'review': ('review', 'Teacher review and corrections'),
 'intake': ('intake', 'PDF intake and validation'),
 'source': ('caseflow', 'Source activation and extraction'),
 'sources': ('caseflow', 'Source activation and extraction'),
 'lessons': ('imported', 'Lesson mapping and persisted lesson data'),
 'workflow': ('caseflow', 'Case workflow coordination'),
 'workflows': ('caseflow', 'Case workflow coordination'),
 'access': ('access', 'Access and identity'),
 'auth': ('auth', 'Account authentication'),
 'settings': ('management', 'Settings and templates'),
 'admin': ('management', 'Administration and operations'),
 'evidence': ('evidence', 'Evidence and metrics'),
}
FILE_OWNERS = {
 'v3_engine/notebooklm/credentials.py': 'auth',
 'v3_engine/notebooklm/sdk_provider.py': 'auth',
 'v3_engine/notebooklm/ports.py': 'auth',
 'v3_engine/notebooklm/terminal_service.py': 'notebooklm',
 'v3_engine/sources/mcp_client.py': 'auth',
 'v3_engine/local_worker/providers.py': 'auth',
 'v3_engine/local_worker/runner.py': 'local_worker',
 'v3_engine/local_worker/terminal.py': 'execution',
 'v3_engine/terminal_pipeline/activities.py': 'execution',
 'v3_engine/terminal_pipeline/pdf_preparation.py': 'pdf',
 'v3_engine/web_pipeline/pdf_preparation.py': 'pdf',
 'v3_engine/web_pipeline/activities.py': 'execution',
 'app/Services/V3/LocalWorker/TerminalScenePromptParser.php': 'acceptance',
 'app/Services/V3/LocalWorker/ResultAcceptanceService.php': 'acceptance',
 'app/Support/NotebookArtifactEnvelope.php': 'acceptance',
 'scripts/v3/setup_local_credentials.py': 'auth',
 'scripts/v3/check_notebooklm.py': 'auth',
}
groups=defaultdict(list)
def domain(path):
    file_path=path.as_posix()
    if file_path in FILE_OWNERS:
        return DOMAINS[FILE_OWNERS[file_path]]
    directory=str(path.parent)
    parts=directory.lower().split('/')
    if parts[:4] == ['app','services','v3','localworker'] or parts[:5] == ['app','http','controllers','v3','localworker']:
        return DOMAINS['devices']
    if parts[:2] == ['v3_engine','local_worker']:
        return DOMAINS['local_worker']
    for part in reversed(parts):
        if part in DOMAINS: return DOMAINS[part]
        if part == 'notebooklm-mcp': return DOMAINS['notebooklm']
    if parts[0] in {'docker','bin'} or directory == '.': return ('infrastructure','Runtime, dependencies and deployment')
    if parts[0] in {'database','config','bootstrap'} or 'models' in parts: return ('db','Persistence, configuration and framework bootstrap')
    if parts[0] == 'resources': return ('ui','Application UI, styles and templates')
    if parts[0] == 'routes' or 'middleware' in parts: return ('access','HTTP routing and access boundaries')
    if parts[0] == 'tests' or 'tests' in parts or parts[0] == 'scripts': return ('health','Verification and operational entry points')
    if 'controllers' in parts: return ('curriculum','Application controllers and learning content')
    return ('shared','Shared application and engine support')
for p in sorted(files):
    did,dlabel=domain(p)
    groups[(str(p.parent),did,dlabel)].append(p.as_posix())
OUT.mkdir(exist_ok=True,parents=True)
manifest=[]
all_lines=['flowchart LR','  system["Complete implementation file map"]']
seen_domains=set()
chapters=[]
for gidx,((directory,did,dlabel),paths) in enumerate(sorted(groups.items())):
    gid=f'g{gidx}'
    group_label=f'{directory} · {dlabel}'
    lines=['flowchart LR',f'  {gid}["{html.escape(group_label,quote=True)}"]']
    if did not in seen_domains:
        all_lines.extend([f'  {did}["{dlabel}"]', f'  system --> {did}'])
        seen_domains.add(did)
    all_lines += [f'  {gid}["{html.escape(group_label,quote=True)}"]', f'  {did} --> {gid}']
    for idx,path in enumerate(paths):
        nid=f'f{gidx}_{idx}'
        leaf=f'  {nid}["{html.escape(path,quote=True)}"]'
        edge=f'  {gid} --> {nid}'
        lines.extend([leaf,edge]); all_lines.extend([leaf,edge])
        manifest.append({'path':path,'directory':directory,'diagram_node':nid,'service_node':did,'service_name':dlabel})
    chapters.append(f'### {group_label}\n\n```mermaid\n'+ '\n'.join(lines)+'\n```\n')
(OUT/'02-all-files.mmd').write_text('\n'.join(all_lines)+'\n')
(OUT/'02-file-atlas.md').write_text('# Implementation file atlas\n\nEvery leaf is an actual repository-relative filename. Each file is assigned to one service owner; the service-to-file grouping is curated for key boundaries and inferred for secondary files. These are smaller views of the complete second diagram in `02-all-files.mmd`.\n\n'+ '\n'.join(chapters))
(OUT/'file-coverage.json').write_text(json.dumps({'scope_trees':TREES,'root_candidates':ROOT_FILES,'excluded_directory_names':sorted(SKIP_PARTS),'excluded_suffixes':sorted(SKIP_SUFFIX),'file_count':len(files),'directory_count':len({str(p.parent) for p in files}),'service_group_count':len(groups),'files':manifest},indent=2)+'\n')
assert len(manifest)==len(files)
assert all((ROOT/item['path']).is_file() for item in manifest)
print(json.dumps({'files':len(files),'directories':len({str(p.parent) for p in files}),'service_groups':len(groups),'output':str(OUT)}))
