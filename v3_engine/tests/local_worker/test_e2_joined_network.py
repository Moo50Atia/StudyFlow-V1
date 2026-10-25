"""Real HTTP + production TaskRunner/terminal-adapter boundary.

The default lane doubles only the external provider and Temporal admission.
The explicitly gated live lane uses actual CLI subprocesses, Temporal and Google.
Scene files are transport fixtures, or an existing scene replayed verbatim in the
live lane. Neither lane claims newly generated or pedagogically validated scenes.
"""

from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import uuid
import zipfile
import pytest
import httpx
import re

from v3_engine.local_worker.client import LocalWorkerHttpClient


def get_free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def test_e2_joined_network_boundary(monkeypatch):
    run_joined_network_boundary(monkeypatch)


@pytest.mark.live
def test_live_existing_terminal_manager(monkeypatch):
    if os.environ.get('V3_LOCAL_WORKER_LIVE') != '1':
        pytest.skip('Explicitly enable V3_LOCAL_WORKER_LIVE=1 for real NotebookLM authoring; no paid scene API calls')
    run_joined_network_boundary(monkeypatch,
        live_pdf=Path(os.environ['V3_LOCAL_WORKER_LIVE_PDF']),
        live_template=Path(os.environ['V3_LOCAL_WORKER_LIVE_TEMPLATE']),
        live_scene=Path(os.environ['V3_LOCAL_WORKER_LIVE_SCENE']),
        evidence_dir=Path(os.environ['V3_LOCAL_WORKER_LIVE_OUTPUT']))


def run_joined_network_boundary(monkeypatch, *, live_pdf=None, live_template=None, live_scene=None, evidence_dir=None):
    project_root = Path(__file__).resolve().parents[3]
    if evidence_dir:
        Path(evidence_dir).mkdir(parents=True, exist_ok=True, mode=0o700)
    temp_dir = Path(tempfile.mkdtemp(prefix="lw_e2_", dir=evidence_dir))
    db_file = temp_dir / "e2_database.sqlite"
    db_file.touch()
    storage_dir = temp_dir / "storage"
    storage_dir.mkdir(parents=True, exist_ok=True)
    v3_private_dir = storage_dir / "v3_private"
    v3_private_dir.mkdir(parents=True, exist_ok=True)

    port = get_free_port()

    env = os.environ.copy()
    env["APP_ENV"] = "testing"
    env["APP_KEY"] = "base64:pAeRvprsT7xvgtJnI7RY2CtfRhhwt5HflVxit0LZEH0="
    env["DB_CONNECTION"] = "sqlite"
    env["DB_DATABASE"] = str(db_file)
    env["V3_PRIVATE_ROOT"] = str(v3_private_dir)
    env["CACHE_STORE"] = "array"
    env["SESSION_DRIVER"] = "database"
    env["QUEUE_CONNECTION"] = "sync"

    server_proc = None
    server_log = None
    if live_pdf:
        assert live_template and live_scene and evidence_dir
        env['LW_SMOKE_PDF'] = str(live_pdf)
        env['LW_SMOKE_FRAMEWORK'] = str(project_root / 'steps.txt')
        pages = subprocess.check_output(['qpdf', '--show-npages', str(live_pdf)], text=True).strip()
        assert 0 < int(pages) <= 50, 'Live smoke uses one already-prepared, intact PDF'
        env['LW_SMOKE_PAGES'] = pages
    try:
        # 1. Run migrations on isolated DB
        res_mig = subprocess.run(
            ["php", "artisan", "migrate:fresh", "--force"],
            cwd=str(project_root),
            env=env,
            capture_output=True,
            text=True,
            check=False,
        )
        assert res_mig.returncode == 0, f"Migration failed: {res_mig.stderr}\n{res_mig.stdout}"

        # 2. Seed scenario: Teacher, Subject, Published Settings, Framework, Document, Run, Admission, Pairing
        seed_php = """
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);
$kernel->bootstrap();

use App\\Models\\User;
use App\\Models\\Subject;
use App\\Models\\V3\\NotebookFrameworkVersion;
use App\\Models\\V3\\SettingsVersion;
use App\\Models\\V3\\Document;
use App\\Models\\V3\\SubjectRun;
use App\\Models\\V3\\SubjectRunItem;
use App\\Models\\V3\\ProcessingCase;
use App\\Models\\V3\\KnowledgeSourceBinding;
use App\\Models\\V3\\SourceActivation;
use App\\Models\\V3\\MapActivation;
use App\\Services\\V3\\LocalWorker\\DeviceManager;
use App\\Services\\V3\\LocalWorker\\LocalWorkerAdmissionCoordinator;
use Illuminate\\Support\\Facades\\Storage;
use Illuminate\\Support\\Str;

$teacher = \\Tests\\Support\\V3\\AccessFixtures::createEnrolledTeacher('Prof. E2 Network');

$subject = Subject::forceCreate([
    'name' => 'E2 Kinematics',
    'description' => 'E2 Joined Network Kinematics',
]);

$admin = User::firstOrCreate(
    ['email' => 'admin@test.com'],
    [
        'name' => 'Admin E2',
        'password' => bcrypt('secret123'),
        'role' => 'admin',
    ]
);
\\App\\Models\\V3\\V3AccountAccess::firstOrCreate(
    ['user_id' => $admin->id],
    ['is_active' => true, 'provisioned_at' => now()]
);

\\Tests\\Support\\V3\\SettingsFixtures::registerDefaultTemplates();
$payload = \\Tests\\Support\\V3\\SettingsFixtures::validPayload();
$canonical = \\App\\Services\\V3\\Settings\\CanonicalSettings::canonicalize($payload);
$checksum = hash('sha256', \\App\\Services\\V3\\Settings\\CanonicalSettings::encode($canonical));

$settingsVersion = SettingsVersion::forceCreate([
    'version' => 1,
    'schema_version' => 1,
    'payload' => $canonical,
    'checksum' => $checksum,
    'published_by_user_id' => $admin->id,
    'published_at' => now(),
]);

$frameworkId = (string) Str::uuid();
$frameworkBody = getenv('LW_SMOKE_FRAMEWORK') ? file_get_contents(getenv('LW_SMOKE_FRAMEWORK')) : "Framework steps instructions for notebooklm.";
Storage::disk('v3_private')->put("frameworks/{$frameworkId}.txt", $frameworkBody);

if (getenv('LW_SMOKE_FRAMEWORK')) {
    $frameworkVersion = app(\\App\\Services\\V3\\NotebookLM\\FrameworkCatalog::class)->importFramework(
        getenv('LW_SMOKE_FRAMEWORK'), hash('sha256', $frameworkBody), $admin->id
    );
    $frameworkId = $frameworkVersion->id;
} else {
$frameworkVersion = NotebookFrameworkVersion::forceCreate([
    'id' => $frameworkId,
    'profile' => 'expanded-root-v1',
    'body_path' => "frameworks/{$frameworkId}.txt",
    'body_sha256' => hash('sha256', $frameworkBody),
    'body_bytes' => strlen($frameworkBody),
    'first_sha256' => hash('sha256', 'first'),
    'second_sha256' => hash('sha256', 'second'),
    'third_sha256' => hash('sha256', 'third'),
    'response_format_sha256' => hash('sha256', 'format'),
    'approved_by_user_id' => $admin->id,
    'approved_at' => now(),
]);

}

$docBytes = getenv('LW_SMOKE_PDF') ? file_get_contents(getenv('LW_SMOKE_PDF')) : \\Tests\\Fixtures\\V3\\Pdf\\PdfFixtures::minimalValidPdf(1, 'E2 Kinematics');
$pageCount = (int) (getenv('LW_SMOKE_PAGES') ?: 1);
$docSha = hash('sha256', $docBytes);
$docId = (string) Str::uuid();
$docPath = "accepted/{$docId}.pdf";
Storage::disk('v3_private')->put($docPath, $docBytes);

$doc = Document::forceCreate([
    'id' => $docId,
    'owner_user_id' => $teacher->id,
    'byte_size' => strlen($docBytes),
    'page_count' => $pageCount,
    'sha256' => $docSha,
    'storage_path' => $docPath,
    'state' => 'ready',
]);

$runId = (string) Str::uuid();
$submissionKey = (string) Str::uuid();
$preparationRoot = "web-preparations/{$teacher->id}/{$submissionKey}/package";
Storage::disk('v3_private')->put("{$preparationRoot}/originals/mechanics.pdf", $docBytes);
$inventory = [
    'schema_version' => 1,
    'kind' => 'v3_pdf_preparation',
    'state' => 'complete',
    'policy' => ['version' => 2, 'split_trigger_pages' => 50, 'max_part_pages' => 50],
    'originals' => [[
        'source_ordinal' => 1,
        'original_path' => "{$preparationRoot}/originals/mechanics.pdf",
        'original_filename' => 'mechanics.pdf',
        'sha256' => $docSha,
        'bytes' => strlen($docBytes),
        'page_count' => $pageCount,
        'retained_original_ref' => ['path' => 'originals/mechanics.pdf', 'sha256' => $docSha, 'bytes' => strlen($docBytes)],
        'split_applied' => false,
        'policy_version' => 2,
    ]],
    'prepared_pdfs' => [[
        'upload_ordinal' => 1,
        'source_ordinal' => 1,
        'part_ordinal' => 1,
        'start_page' => 1,
        'end_page' => $pageCount,
        'page_count' => $pageCount,
        'filename' => 'mechanics.pdf',
        'artifact_ref' => ['path' => 'final/mechanics.pdf', 'sha256' => $docSha, 'bytes' => strlen($docBytes)],
    ]],
    'qpdf_version' => 'fixture',
    'verification' => ['verified' => true, 'original_count' => 1, 'prepared_pdf_count' => 1],
    'inventory_sha256' => hash('sha256', 'fixture-inventory'),
];
$inventoryBytes = json_encode($inventory, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
Storage::disk('v3_private')->put("{$preparationRoot}/inventory.json", $inventoryBytes);
$pdfPreparation = [
    'inventory_ref' => "{$preparationRoot}/inventory.json",
    'inventory_file_sha256' => hash('sha256', $inventoryBytes),
    'policy' => $inventory['policy'],
    'original_count' => 1,
    'prepared_count' => 1,
];

$lecture = \\App\\Models\\Lecture::forceCreate([
    'subject_id' => $subject->id,
    'title' => 'Kinematics Lecture',
]);

$run = SubjectRun::forceCreate([
    'id' => $runId,
    'owner_user_id' => $teacher->id,
    'subject_id' => $subject->id,
    'origin' => 'web',
    'protocol' => 'notebooklm_per_pdf_web_v1',
    'settings_version_id' => $settingsVersion->id,
    'framework_version_id' => $frameworkVersion->id,
    'mode' => 'export_manual',
    'qualification_mode' => 'owner_waived_demo',
    'state' => 'queued',
    'state_version' => 1,
    'submission_key' => $submissionKey,
    'pdf_preparation' => $pdfPreparation,
    'request_sha256' => hash('sha256', 'e2_sub'),
    'captured_settings_json' => json_encode(array_merge($canonical, ['execution_mode' => 'local_worker_v1', 'output_scope' => 'prompts_and_scenes'])),
    'capture_sha256' => hash('sha256', json_encode(array_merge($canonical, ['execution_mode' => 'local_worker_v1', 'output_scope' => 'prompts_and_scenes']))),
]);

$case = ProcessingCase::forceCreate([
    'id' => (string) Str::uuid(),
    'owner_user_id' => $teacher->id,
    'settings_version_id' => $settingsVersion->id,
    'document_id' => $doc->id,
    'notebook_framework_version_id' => $frameworkVersion->id,
    'idempotency_key' => (string) Str::uuid(),
    'action' => 'process',
    'state' => 'ready',
]);

$binding = app(\\App\\Services\\V3\\NotebookLM\\DirectSourceCoordinator::class)->prepare($case);

SourceActivation::forceCreate([
    'id' => (string) Str::uuid(),
    'case_id' => $case->id,
    'workflow_id' => 'e2-flow',
    'state' => 'accepted',
    'signal_attempts' => 0,
]);

MapActivation::forceCreate([
    'id' => (string) Str::uuid(),
    'case_id' => $case->id,
    'binding_id' => $binding->id,
    'workflow_id' => 'e2-flow',
    'state' => 'accepted',
    'signal_attempts' => 0,
]);

$item = SubjectRunItem::forceCreate([
    'id' => (string) Str::uuid(),
    'run_id' => $run->id,
    'owner_user_id' => $teacher->id,
    'pdf_ordinal' => 1,
    'display_title' => 'Kinematics Mechanics Item',
    'original_filename' => 'mechanics.pdf',
    'document_id' => $doc->id,
    'pdf_lineage' => [
        'source_ordinal' => 1,
        'part_ordinal' => 1,
        'original_filename' => 'mechanics.pdf',
        'original_sha256' => $docSha,
        'original_page_count' => $pageCount,
        'start_page' => 1,
        'end_page' => $pageCount,
        'page_count' => $pageCount,
    ],
    'lecture_id' => $lecture->id,
    'case_id' => $case->id,
    'state' => 'running',
    'state_version' => 1,
]);

$admission = app(LocalWorkerAdmissionCoordinator::class)->admit($run, 'prompts_and_scenes');
$pairing = app(DeviceManager::class)->createPairingInvitation(
    $admin,
    'E2 Separate Process Worker',
    null,
    ['tenant:all', 'authoring_v1', 'scene_return_v1'],
    ['notebooklm_v1', 'notebooklm_authoring_v1', 'static_three_file_v1', 'authoring_v1', 'scene_return_v1']
);

echo json_encode([
    'pairing_code' => $pairing['pairing_code'],
    'teacher_id' => $teacher->id,
    'run_id' => $run->id,
    'job_id' => $admission->id,
    'case_id' => $case->id,
    'item_id' => $item->id,
]);
"""
        res_seed = subprocess.run(
            ["php", "-r", seed_php],
            cwd=str(project_root),
            env=env,
            capture_output=True,
            text=True,
            check=False,
        )
        assert res_seed.returncode == 0, f"Seed failed: {res_seed.stderr}\n{res_seed.stdout}"
        seed_data = json.loads(res_seed.stdout.strip())
        pairing_code = seed_data["pairing_code"]
        run_id = seed_data["run_id"]
        job_id = seed_data["job_id"]
        case_id = seed_data["case_id"]
        item_id = seed_data["item_id"]
        teacher_id = seed_data["teacher_id"]

        # 3. Start PHP Server in separate process
        server_cmd = [
            "php",
            "-d", "variables_order=EGPCS",
            "-S", f"127.0.0.1:{port}",
            str(project_root / "vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"),
        ]
        server_log = (temp_dir / 'php-server.log').open('w')
        server_proc = subprocess.Popen(
            server_cmd,
            cwd=str(project_root / "public"),
            env=env,
            stdout=server_log,
            stderr=subprocess.STDOUT,
        )

        # Poll until server responds
        server_origin = f"http://127.0.0.1:{port}"
        ready = False
        for _ in range(50):
            try:
                with socket.create_connection(("127.0.0.1", port), timeout=0.2):
                    ready = True
                    break
            except (ConnectionRefusedError, OSError):
                time.sleep(0.1)
        assert ready, "PHP server failed to start within 5 seconds"

        # 4. PYTHON CLIENT EXECUTION OVER REAL HTTP WIRE
        client = LocalWorkerHttpClient(server_origin=server_origin)

        # Step 4.1: Redeem enrollment pairing
        redeem = client.redeem_enrollment(
            pairing_secret=pairing_code,
            device_name="E2 Separate Process Worker",
            device_uuid="e2-worker-uuid-1",
            os_family="linux",
        )
        assert "device_token" in redeem
        client.device_token = redeem["device_token"]
        if evidence_dir:
            from v3_engine.local_worker.config import WorkerConfig, save_config
            save_config(WorkerConfig(server_origin=server_origin, device_id=redeem['device_id'],
                device_token=redeem['device_token'], workspace_dir=str(temp_dir / 'worker'),
                capabilities=['authoring_v1', 'scene_return_v1'], terminal_manifest_path=str(live_template)),
                temp_dir / 'worker-config.json')

        # Verify device details over HTTP
        device_info = client.get_device()
        assert device_info["status"] == "active"
        assert "authoring_v1" in device_info["capabilities"]

        # Step 4.2: Claim task over HTTP wire
        claim = client.claim(
            claim_id=str(uuid.uuid4()),
            capabilities=["authoring_v1", "scene_return_v1"],
        )
        assert claim is not None
        assert claim["task_kind"] == "author_prompts"
        assert claim["device_id"] == device_info["device_id"]
        task_id = claim["task_id"]
        assignment_id = claim["assignment_id"]
        epoch = claim["assignment_epoch"]
        manifest_sha = claim["input_manifest_sha256"]

        # Step 4.3: Heartbeat active lease over HTTP wire
        hb = client.heartbeat(
            task_id=task_id,
            observed_control_version=1,
            assignment_id=assignment_id,
            assignment_epoch=epoch,
        )
        assert hb["task_status"] == "leased"

        # Step 4.4: Stream input manifest over HTTP wire
        manifest_bytes = client.get_input_manifest(task_id, assignment_id, epoch)
        assert hashlib.sha256(manifest_bytes).hexdigest() == manifest_sha
        manifest = json.loads(manifest_bytes)
        assert manifest["job_id"] == job_id
        assert len(manifest["items"]) == 1

        # Step 4.5: Download input artifacts over HTTP wire
        settings_target = temp_dir / "runtime_settings.json"
        client.download_input_artifact(task_id, manifest["runtime_settings_artifact_id"], settings_target, assignment_id, epoch)
        assert hashlib.sha256(settings_target.read_bytes()).hexdigest() == manifest["runtime_settings_sha256"]

        pdf_item = manifest["items"][0]
        pdf_target = temp_dir / "prepared_input.pdf"
        client.download_input_artifact(task_id, pdf_item["prepared_artifact_id"], pdf_target, assignment_id, epoch)
        assert hashlib.sha256(pdf_target.read_bytes()).hexdigest() == pdf_item["prepared_sha256"]

        # Explicit provider response fixture used only by the automated offline lane.
        prompt_text = "Create an interactive 3D simulation of a pendulum with velocity vectors."
        double_output = f"""=== FIRST ===
Section 1: Pendulum Dynamics
Section Identity:
Pendulum Dynamics

Core Understanding:
The restoring force on a pendulum bob is proportional to sin(theta).

Visual-First Explanation:
A oscillating pendulum bob with dynamic velocity and tangential acceleration vectors.

Visual Scene Script:
3D sphere suspended from pivot by a thin cylinder rod swinging with damped harmonic motion.

Dynamic View Blueprint:
Perspective view angled at 30 degrees with real-time vector overlay toggle.

Visual Learning Outcome:
Student grasps phase relationship between velocity and acceleration vectors.

Concept Check:
Where in the swing is velocity maximized?

Conceptual Summary:
Velocity is maximized at lowest point (equilibrium) where potential energy is minimum.

• شرح للجزء بالعامية المصرية:
عند أوطى نقطة السرعة بتكون أعلى ما يمكن والعجلة المماسية بصفر يا دكتور.

القوانين (إن وُجدت):
T = 2*pi*sqrt(L/g)

إسقاط على مثال أو موقف من الحياة اليومية لتثبيت الفهم:
Grandfather clock mechanical escapement regulating timekeeping.
=== SECOND ===
Pendulum Dynamics Breakdown
=== THIRD ===
Lec 1 (Sec 1): "{prompt_text}"
=== END ===
✅ Lesson [1] Completed."""

        # Run the production worker against the real HTTP API, delegating to the
        # existing terminal CLI/capture/archive/export implementation. Only the
        # external provider and local Temporal admission transport are doubles.
        from v3_engine.local_worker.terminal import TerminalPipelineAdapter
        from v3_engine.local_worker.runner import TaskRunner
        from v3_engine.local_worker.journal import LocalEffectJournal
        from v3_engine.tests.local_worker.test_terminal_bridge import make_terminal_command_double

        terminal_root = Path(os.environ['V3_TERMINAL_ROOT']) if live_template else temp_dir / 'terminal'
        monkeypatch.setenv('V3_TERMINAL_ROOT', str(terminal_root))
        if live_template:
            template = Path(live_template)
            terminal_adapter = TerminalPipelineAdapter(template, terminal_root=terminal_root)
            terminal_commands = None
        else:
            template = temp_dir / 'terminal-settings.json'
            template.write_text(json.dumps({'subjects': [{'mode': 'new', 'provider': 'gemini',
                'model': 'gemini-2.5-flash', 'account_check_notebook_id': 'isolated-test-proof'}]}))
            terminal_response = double_output.replace(f'"{prompt_text}"', f'"Interactive Scene\n{prompt_text}"')
            command_double, terminal_commands = make_terminal_command_double(monkeypatch, terminal_root, terminal_response)
            terminal_adapter = TerminalPipelineAdapter(template, terminal_root=terminal_root, command_runner=command_double)
        worker_workspace = temp_dir / 'worker'
        worker = TaskRunner(client, LocalEffectJournal(worker_workspace / 'journal.sqlite'), worker_workspace, terminal_adapter)
        original_submit = client.submit_result
        checked_prompt_binding = False
        def submit_with_negative_binding_probe(task_id, result_payload, assignment_id, assignment_epoch):
            nonlocal checked_prompt_binding
            payload = result_payload
            if payload['kind'] == 'local_worker_authoring_result' and not checked_prompt_binding:
                from copy import deepcopy
                from v3_engine.local_worker.client import LocalWorkerClientError
                forged = deepcopy(payload)
                forged['result_id'] = str(uuid.uuid4())
                scene = forged['lessons'][0]['scenes'][0]
                scene['prompt_text'] += ' altered transport probe'
                scene['prompt_sha256'] = hashlib.sha256(scene['prompt_text'].encode()).hexdigest()
                with pytest.raises(LocalWorkerClientError) as rejected:
                    original_submit(task_id, forged, assignment_id, assignment_epoch)
                assert rejected.value.error_code == 'prompt_mismatch'
                checked_prompt_binding = True
            return original_submit(task_id, payload, assignment_id, assignment_epoch)
        client.submit_result = submit_with_negative_binding_probe
        receipt = worker.run_authoring_stage(claim)
        # Lost-ack/restart path must reuse the exact accepted result receipt.
        assert worker.run_authoring_stage(claim) == receipt
        if terminal_commands is not None:
            assert len(terminal_commands) == 1
        assert (worker_workspace / 'runs' / run_id / 'run.json').is_file()
        prompt_sha = receipt['scene_bindings'][0]['prompt_sha256']
        assert receipt["status"] == "completed"
        assert receipt['accepted_lessons'] > 0
        expected_scene_count = len(receipt['scene_bindings'])
        assert receipt['accepted_scenes'] == expected_scene_count > 0
        prompt_version_id = receipt["prompt_version_ids"][0]

        # Step 4.12: Manual files are requested from the real authenticated Dashboard.
        assert client.claim(claim_id=str(uuid.uuid4()), capabilities=['scene_return_v1']) is None
        with httpx.Client(base_url=f'http://127.0.0.1:{port}', follow_redirects=True) as dashboard:
            login_page = dashboard.get('/login')
            csrf = re.search(r'<meta name="csrf-token" content="([^"]+)"', login_page.text).group(1)
            login = dashboard.post('/login', data={'email': 'admin@test.com', 'password': 'secret123', '_token': csrf})
            assert login.status_code == 200
            page = dashboard.get('/v3/admin/subject-runs')
            assert page.status_code == 200
            csrf = re.search(r'<meta name="csrf-token" content="([^"]+)"', page.text).group(1)
            pull = dashboard.post(f'/v3/admin/subject-runs/{run_id}/pull-scenes',
                                  json={'request_id': str(uuid.uuid4())},
                                  headers={'Accept': 'application/json', 'X-CSRF-TOKEN': csrf})
            assert pull.status_code == 202, pull.text
        # The outbound worker receives the requested return task over HTTP.
        scene_claim = client.claim(
            claim_id=str(uuid.uuid4()),
            capabilities=["scene_return_v1"],
        )
        assert scene_claim is not None
        assert scene_claim["task_kind"] == "return_scenes"
        scene_task_id = scene_claim["task_id"]
        scene_assign_id = scene_claim["assignment_id"]
        scene_epoch = scene_claim["assignment_epoch"]

        # Step 4.13: Stream scene return manifest over HTTP wire
        scene_manifest_bytes = client.get_input_manifest(scene_task_id, scene_assign_id, scene_epoch)
        scene_manifest = json.loads(scene_manifest_bytes)
        assert scene_manifest["task_kind"] == "return_scenes"
        assert "required_scenes" in scene_manifest
        assert len(scene_manifest["required_scenes"]) == expected_scene_count
        req_scene = scene_manifest["required_scenes"][0]
        scene_id = req_scene["scene_id"]
        assert req_scene["prompt_version_id"] == prompt_version_id
        assert req_scene["prompt_sha256"] == prompt_sha

        from v3_engine.local_worker.protocol import LocalWorkerProtocolError
        with pytest.raises(LocalWorkerProtocolError) as missing:
            worker.run_scene_return_stage(scene_claim, operator_provenance={'operator_id': scene_claim['platform_owner_id']})
        assert missing.value.code == 'missing_packages'
        client.release(scene_task_id, 'manual_scenes_missing', scene_assign_id, scene_epoch)
        with httpx.Client(base_url=server_origin, cookies=dashboard.cookies) as retry_dashboard:
            retry_pull = retry_dashboard.post(f'/v3/admin/subject-runs/{run_id}/pull-scenes',
                json={'request_id': str(uuid.uuid4())}, headers={'Accept': 'application/json', 'X-CSRF-TOKEN': csrf})
            assert retry_pull.status_code == 202
            assert retry_pull.json()['return_task_epoch'] == scene_epoch
        scene_claim = client.claim(claim_id=str(uuid.uuid4()), capabilities=['scene_return_v1'])
        assert scene_claim['task_id'] == scene_task_id and scene_claim['assignment_epoch'] > scene_epoch

        # The worker reads actual operator files and uploads them over HTTP.
        # Live smoke deliberately replays one EXISTING scene only to verify byte
        # transport; it never claims these files were generated from this PDF.
        handoff_path = worker_workspace / 'runs' / run_id / 'run.json'
        handoff = json.loads(handoff_path.read_text())
        html = Path(live_scene).read_text(encoding='utf-8') if live_scene else '<!doctype html><html><body>Transport fixture only</body></html>'
        for entry in handoff['generated_prompts']:
            Path(entry['entrypoint']).write_text(html, encoding='utf-8')
        scene_receipt = worker.run_scene_return_stage(scene_claim, operator_provenance={'operator_id': device_info['platform_owner_id']} if 'platform_owner_id' in device_info else {'operator_id': scene_claim['platform_owner_id']})
        assert scene_receipt['status'] == 'completed'
        assert len(scene_receipt['accepted_candidates']) == expected_scene_count
        candidate_id = scene_receipt['accepted_candidates'][0]

        # 5. VERIFY DATABASE EVIDENCE VIA PHP RUNNER
        verify_php = f"""
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);
$kernel->bootstrap();

use App\\Models\\V3\\TeacherSection;
use App\\Models\\V3\\SceneCandidate;
use App\\Models\\V3\\PipelineCommand;
use App\\Models\\V3\\PipelineOutbox;
use App\\Models\\V3\\SubjectRun;
use App\\Services\\V3\\WebPipeline\\RunProgress;

$sec = TeacherSection::where('case_id', '{case_id}')->first();
if (!$sec) throw new Exception("TeacherSection missing");

// Transport correctness does not grade educational field completeness.
if (!$sec->raw_bundle_ref) throw new Exception("retained source binding missing");

// Assert SceneCandidate (LW-R23)
$cand = SceneCandidate::find('{candidate_id}');
if (!$cand) throw new Exception("SceneCandidate missing");
if ($cand->origin !== 'manual_import') throw new Exception("Candidate origin must be manual_import");
if ($cand->case_id !== '{case_id}') throw new Exception("Candidate case_id mismatch");

// Verify the exact uploaded HTML bytes independently on hosted storage.
$allCandidates = SceneCandidate::where('case_id', '{case_id}')->get();
if ($allCandidates->count() !== {expected_scene_count}) throw new Exception("candidate count mismatch");
foreach ($allCandidates as $candidate) {{
    $stored = json_decode(\\Illuminate\\Support\\Facades\\Storage::disk('v3_private')->get($candidate->manifest_path), true);
    $bytes = \\Illuminate\\Support\\Facades\\Storage::disk('v3_private')->get(dirname($candidate->manifest_path) . '/index.html');
    if (hash('sha256', $bytes) !== '{hashlib.sha256(html.encode()).hexdigest()}') throw new Exception("scene bytes changed");
    if ($stored['section_id'] !== $candidate->section_id) throw new Exception("scene identity changed");
}}

// Assert Qualification PipelineCommand enqueued into RunOutbox (LW-R24)
$outbox = PipelineOutbox::whereHas('command', fn ($q) => $q->where('run_id', '{run_id}'))->where('destination', 'qualification_runner')->first();
if (!$outbox) throw new Exception("PipelineOutbox missing qualification_runner command");

$cmd = PipelineCommand::find($outbox->command_id);
if (!$cmd || $cmd->action !== 'check_candidate') throw new Exception("PipelineCommand action mismatch");

// Assert RunProgress projection (LW-R34)
$run = SubjectRun::find('{run_id}');
$proj = app(RunProgress::class)->project($run);
if ($proj['output_scope'] !== 'prompts_and_scenes') throw new Exception("RunProgress output_scope mismatch");
if ($proj['execution_mode'] !== 'local_worker_v1') throw new Exception("RunProgress execution_mode mismatch");
if ($proj['counts']['scenes_built'] !== {expected_scene_count}) throw new Exception("RunProgress scenes_built count mismatch");

echo "OK";
"""
        res_ver = subprocess.run(
            ["php", "-r", verify_php],
            cwd=str(project_root),
            env=env,
            capture_output=True,
            text=True,
            check=False,
        )
        assert res_ver.returncode == 0, f"Verification failed: {res_ver.stderr}\n{res_ver.stdout}"
        assert res_ver.stdout.strip() == "OK"
        if evidence_dir:
            destination = Path(evidence_dir)
            destination.mkdir(parents=True, exist_ok=True)
            # Runtime directories remain private and in place. Do not export
            # terminal session files/cookies or duplicate device tokens.
            (destination / 'verification.json').write_text(json.dumps({
                'run_id': run_id, 'job_id': job_id, 'authoring_receipt': receipt,
                'scene_receipt': scene_receipt, 'scene_transport': 'existing_scene_replay',
                'input_sha256': hashlib.sha256(Path(live_pdf).read_bytes()).hexdigest(),
                'scene_file_sha256': hashlib.sha256(html.encode()).hexdigest(),
                'live_provider': True, 'paid_scene_generation_calls': 0,
                'run_json_path': str(handoff_path), 'terminal_root': str(terminal_root),
            }, indent=2, ensure_ascii=False))

    finally:
        if server_proc is not None:
            server_proc.terminate()
            try:
                server_proc.wait(timeout=3)
            except subprocess.TimeoutExpired:
                server_proc.kill()
                server_proc.wait(timeout=3)
        if server_log is not None:
            server_log.close()
        if not evidence_dir:
            shutil.rmtree(temp_dir, ignore_errors=True)
