<?php

namespace Tests\Integration\V3\Workflow;

use App\Models\V3\CaseEvent;
use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\WorkflowLink;
use App\Services\V3\Settings\CanonicalSettings;
use App\Services\V3\Workflow\BridgeClient;
use App\Services\V3\Workflow\StartWorkflow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class LiveBridgeTest extends TestCase
{
    protected string $tempDb;
    protected ?Process $temporalProc = null;
    protected ?Process $uvicornProc = null;
    protected ?Process $phpServerProc = null;
    protected ?Process $workerProc = null;
    protected ?string $testMcpState = null;
    protected ?string $testDocumentPath = null;

    protected int $temporalPort;
    protected int $bridgePort;
    protected int $phpPort;

    protected string $bridgeKey = 'test-bridge-hmac-key-32-chars-long!';
    protected string $callbackKey = 'test-callback-hmac-key-32-chars-long';

    protected function setUp(): void
    {
        parent::setUp();

        // Avoid coupling repeat runs to fixed ports, especially when a local
        // Temporal CLI child outlives its Process wrapper after an interrupted run.
        $ports = [];
        while (count($ports) < 3) {
            $port = $this->allocatePort();
            if (! in_array($port, $ports, true)) {
                $ports[] = $port;
            }
        }
        [$this->temporalPort, $this->bridgePort, $this->phpPort] = $ports;

        $this->tempDb = sys_get_temp_dir() . '/v3_live_' . uniqid() . '.sqlite';
        touch($this->tempDb);

        config(['database.connections.sqlite.database' => $this->tempDb]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::statement('PRAGMA journal_mode = WAL;');
        DB::statement('PRAGMA busy_timeout = 5000;');

        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);

        config([
            'services.v3.temporal_address' => "127.0.0.1:{$this->temporalPort}",
            'services.v3.bridge_url' => "http://127.0.0.1:{$this->bridgePort}",
            'services.v3.bridge_hmac_key' => $this->bridgeKey,
            'services.v3.callback_hmac_key' => $this->callbackKey,
        ]);

        // Start Temporal dev server
        $this->temporalProc = new Process([
            '/home/moahmmed/.local/bin/temporal',
            'server', 'start-dev',
            '--port', (string) $this->temporalPort,
            '--headless',
        ]);
        $this->temporalProc->start();
        $this->waitForPort($this->temporalPort, 10);

        // Start Uvicorn bridge
        $this->uvicornProc = new Process(
            [
                'python3', '-m', 'uvicorn',
                'v3_engine.bridge:app',
                '--host', '127.0.0.1',
                '--port', (string) $this->bridgePort,
            ],
            base_path(),
            [
                'PYTHONPATH' => base_path(),
                'V3_TEMPORAL_ADDRESS' => "127.0.0.1:{$this->temporalPort}",
                'V3_BRIDGE_HMAC_KEY' => $this->bridgeKey,
            ]
        );
        $this->uvicornProc->start();
        $this->waitForPort($this->bridgePort, 5);

        // Start Laravel HTTP callback server
        $this->phpServerProc = new Process(
            [
                'php', '-S', "127.0.0.1:{$this->phpPort}",
                'public/index.php',
            ],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $this->tempDb,
                'QUEUE_CONNECTION' => 'sync',
                'V3_BRIDGE_URL' => "http://127.0.0.1:{$this->bridgePort}",
                'V3_BRIDGE_HMAC_KEY' => $this->bridgeKey,
                'V3_CALLBACK_HMAC_KEY' => $this->callbackKey,
                'JEV_MODEL' => 'typesafe-systemone-v1',
            ]
        );
        $this->phpServerProc->start();
        $this->waitForPort($this->phpPort, 5);
    }

    protected function tearDown(): void
    {
        if ($this->workerProc && $this->workerProc->isRunning()) {
            $this->workerProc->stop();
        }
        if ($this->phpServerProc && $this->phpServerProc->isRunning()) {
            $this->phpServerProc->stop();
        }
        if ($this->uvicornProc && $this->uvicornProc->isRunning()) {
            $this->uvicornProc->stop();
        }
        if ($this->temporalProc && $this->temporalProc->isRunning()) {
            $this->temporalProc->stop();
        }

        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }
        if ($this->testMcpState && file_exists($this->testMcpState)) {
            @unlink($this->testMcpState);
        }
        if ($this->testDocumentPath) {
            Storage::disk('v3_private')->delete($this->testDocumentPath);
        }

        parent::tearDown();
    }

    protected function waitForPort(int $port, int $timeoutSeconds): void
    {
        $start = time();
        while (time() - $start < $timeoutSeconds) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($fp) {
                fclose($fp);
                return;
            }
            usleep(100000);
        }
        throw new \RuntimeException("Port {$port} failed to become available within {$timeoutSeconds}s.");
    }

    protected function allocatePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new \RuntimeException("Could not allocate an integration-test port: {$errstr} ({$errno}).");
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if (! is_string($address) || ! preg_match('/:(\d+)$/', $address, $matches)) {
            throw new \RuntimeException('Could not determine allocated integration-test port.');
        }
        return (int) $matches[1];
    }

    public function test_live_workflow_start_worker_callback_and_describe_recovery(): void
    {
        $now = now();
        $teacherId = DB::table('users')->insertGetId([
            'name' => 'Teacher Live',
            'email' => 'teacher_live@example.com',
            'password' => Hash::make('password'),
            'role' => 'teacher',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('v3_account_access')->insert([
            'user_id' => $teacherId,
            'is_active' => true,
            'version' => 1,
            'provisioned_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        SettingsFixtures::registerDefaultTemplates();
        $payload = SettingsFixtures::validPayload();
        $canonical = CanonicalSettings::canonicalize($payload);
        $encoded = CanonicalSettings::encode($canonical);
        $checksum = hash('sha256', $encoded);

        $settingsId = DB::table('v3_settings_versions')->insertGetId([
            'version' => 1,
            'schema_version' => 1,
            'payload' => json_encode($canonical),
            'checksum' => $checksum,
            'published_by_user_id' => $teacherId,
            'published_at' => $now,
        ]);

        $docId = (string) Str::uuid();
        DB::table('v3_documents')->insert([
            'id' => $docId,
            'owner_user_id' => $teacherId,
            'sha256' => hash('sha256', 'live-doc'),
            'byte_size' => 1024,
            'page_count' => 1,
            'state' => 'ready',
            'storage_path' => "accepted/{$docId}.pdf",
            'accepted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $caseId = (string) Str::uuid();
        DB::table('v3_processing_cases')->insert([
            'id' => $caseId,
            'owner_user_id' => $teacherId,
            'document_id' => $docId,
            'settings_version_id' => $settingsId,
            'idempotency_key' => (string) Str::uuid(),
            'action' => 'process',
            'state' => 'awaiting_workflow',
            'state_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $workflowId = "v3-case-{$caseId}";
        DB::table('v3_workflow_links')->insert([
            'case_id' => $caseId,
            'workflow_id' => $workflowId,
            'run_id' => null,
            'settings_version_id' => $settingsId,
            'settings_checksum' => $checksum,
            'start_state' => 'pending',
            'stage_code' => 'intake',
            'status_code' => 'queued',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var ProcessingCase $caseModel */
        $caseModel = ProcessingCase::find($caseId);
        /** @var WorkflowLink $linkModel */
        $linkModel = WorkflowLink::where('case_id', $caseId)->first();

        // 1. Start Workflow via bridge
        /** @var StartWorkflow $starter */
        $starter = app(StartWorkflow::class);
        $result = $starter->start($caseId);

        $this->assertSame('started', $result['status']);
        $this->assertNotNull($result['run_id']);

        $runId = $result['run_id'];

        // 2. Start Python Worker to process the activity and deliver callback to Laravel
        $this->workerProc = new Process(
            [
                'python3', '-m', 'v3_engine.worker',
            ],
            base_path(),
            [
                'PYTHONPATH' => base_path(),
                'V3_TEMPORAL_ADDRESS' => "127.0.0.1:{$this->temporalPort}",
                'V3_CALLBACK_HMAC_KEY' => $this->callbackKey,
                'V3_LARAVEL_CALLBACK_URL' => "http://127.0.0.1:{$this->phpPort}/api/internal/v1/case-events",
            ]
        );
        $this->workerProc->start();

        // 3. Poll database until callback is committed (max 10s)
        $callbackReceived = false;
        $startWait = time();
        while (time() - $startWait < 10) {
            DB::purge('sqlite');
            DB::reconnect('sqlite');
            DB::statement('PRAGMA busy_timeout = 5000;');

            $eventCount = DB::table('v3_case_events')->where('case_id', $caseId)->count();
            if ($eventCount > 0) {
                $callbackReceived = true;
                break;
            }
            usleep(200000);
        }

        $this->assertTrue($callbackReceived, 'Python worker must deliver workflow_started callback to Laravel.');

        // Reconnect and check invariants
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $caseRow = DB::table('v3_processing_cases')->where('id', $caseId)->first();
        $this->assertSame('queued', $caseRow->state);
        $this->assertSame(2, (int) $caseRow->state_version);

        $linkRow = DB::table('v3_workflow_links')->where('case_id', $caseId)->first();
        $this->assertSame('extraction', $linkRow->stage_code);
        $this->assertSame('queued', $linkRow->status_code);

        // 4. Describe workflow via BridgeClient
        /** @var BridgeClient $client */
        $client = app(BridgeClient::class);
        $desc = $client->describeWorkflow($workflowId);

        $this->assertSame(200, $desc['status']);
        $this->assertTrue($desc['data']['found']);
        $this->assertSame('open', $desc['data']['temporal_status']);
        $this->assertSame($runId, $desc['data']['run_id']);
        $this->assertSame($caseId, $desc['data']['case_id']);
        $this->assertSame($settingsId, $desc['data']['settings_version_id']);
        $this->assertSame($checksum, $desc['data']['settings_checksum']);

        // 5. Worker restart: stop worker and start a second worker
        $this->workerProc->stop();
        $this->assertFalse($this->workerProc->isRunning());

        $worker2 = new Process(
            [
                'python3', '-m', 'v3_engine.worker',
            ],
            base_path(),
            [
                'PYTHONPATH' => base_path(),
                'V3_TEMPORAL_ADDRESS' => "127.0.0.1:{$this->temporalPort}",
                'V3_CALLBACK_HMAC_KEY' => $this->callbackKey,
                'V3_LARAVEL_CALLBACK_URL' => "http://127.0.0.1:{$this->phpPort}/api/internal/v1/case-events",
            ]
        );
        $worker2->start();
        usleep(500000);

        // Verify workflow is still running on Temporal after worker restart
        $descAfterRestart = $client->describeWorkflow($workflowId);
        $this->assertSame('open', $descAfterRestart['data']['temporal_status']);
        $worker2->stop();
    }

    public function test_connected_offline_pdf_to_teacher_approval_and_postapproval_correction_lifecycle(): void
    {
        if (getenv('RUN_V3_CONNECTED_PIPELINE') !== '1') {
            $this->markTestSkipped('Set RUN_V3_CONNECTED_PIPELINE=1 to run the connected local Temporal/Docker pipeline gate.');
        }
        $fixtureRoot = base_path('tests/Fixtures/V3/connected_pipeline');
        $fixtureBin = $fixtureRoot . '/bin';
        chmod($fixtureBin . '/npx', 0755);
        $mcpState = sys_get_temp_dir() . '/v3_mcp_' . uniqid() . '.json';
        $this->testMcpState = $mcpState;
        $now = now();
        $teacherId = DB::table('users')->insertGetId([
            'name' => 'Connected Pipeline Teacher',
            'email' => 'connected_pipeline_' . uniqid() . '@example.com',
            'password' => Hash::make('password'),
            'role' => 'teacher',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('v3_account_access')->insert([
            'user_id' => $teacherId,
            'is_active' => true,
            'version' => 1,
            'provisioned_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        SettingsFixtures::registerDefaultTemplates();
        $canonical = CanonicalSettings::canonicalize(SettingsFixtures::validPayload(overrides: [
            'limits' => ['retry_delay_seconds' => 1],
        ]));
        $checksum = hash('sha256', CanonicalSettings::encode($canonical));
        $settingsId = DB::table('v3_settings_versions')->insertGetId([
            'version' => 1,
            'schema_version' => 1,
            'payload' => json_encode($canonical),
            'checksum' => $checksum,
            'published_by_user_id' => $teacherId,
            'published_at' => $now,
        ]);

        $pdfBytes = \Tests\Fixtures\V3\Pdf\PdfFixtures::minimalValidPdf(1, 'Connected Pipeline Sample: pendulum motion repeats over time.');
        $docId = (string) Str::uuid();
        $docPath = "accepted/connected-{$docId}.pdf";
        $this->testDocumentPath = $docPath;
        Storage::disk('v3_private')->put($docPath, $pdfBytes);
        DB::table('v3_documents')->insert([
            'id' => $docId,
            'owner_user_id' => $teacherId,
            'sha256' => hash('sha256', $pdfBytes),
            'byte_size' => strlen($pdfBytes),
            'page_count' => 1,
            'state' => 'ready',
            'storage_path' => $docPath,
            'accepted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $caseId = (string) Str::uuid();
        DB::table('v3_processing_cases')->insert([
            'id' => $caseId,
            'owner_user_id' => $teacherId,
            'document_id' => $docId,
            'settings_version_id' => $settingsId,
            'idempotency_key' => (string) Str::uuid(),
            'action' => 'process',
            'state' => 'awaiting_workflow',
            'state_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('v3_workflow_links')->insert([
            'case_id' => $caseId,
            'workflow_id' => "v3-case-{$caseId}",
            'run_id' => null,
            'settings_version_id' => $settingsId,
            'settings_checksum' => $checksum,
            'start_state' => 'pending',
            'stage_code' => 'intake',
            'status_code' => 'queued',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $start = app(StartWorkflow::class)->start($caseId);
        $this->assertSame('started', $start['status']);

        $workerEnv = [
            'PYTHONPATH' => $fixtureRoot . PATH_SEPARATOR . base_path(),
            'PATH' => $fixtureBin . PATH_SEPARATOR . getenv('PATH'),
            'V3_TEMPORAL_ADDRESS' => "127.0.0.1:{$this->temporalPort}",
            'V3_CALLBACK_HMAC_KEY' => $this->callbackKey,
            'V3_LARAVEL_CALLBACK_URL' => "http://127.0.0.1:{$this->phpPort}/api/internal/v1/case-events",
            'TYPESAFE_API_KEY' => 'offline-test-fixture-key',
            'JEV_MODEL' => 'typesafe-systemone-v1',
            'GEMINI_API_KEY' => 'offline-test-fixture-key',
            'V3_TEST_MCP_STATE' => $mcpState,
        ];
        $this->workerProc = new Process(['python3', '-m', 'v3_engine.worker'], base_path(), $workerEnv);
        $this->workerProc->start();

        // Real activation and registered activities execute across the Laravel
        // callback server and Temporal worker. Polling also runs the real source
        // and mapping dispatchers that normally wake the durable workflow.
        app(\App\Services\V3\Source\ActivateSource::class)->discoverAndDispatch();
        $deadline = time() + 120;
        $review = null;
        while (time() < $deadline) {
            DB::purge('sqlite');
            DB::reconnect('sqlite');
            DB::statement('PRAGMA busy_timeout = 5000;');
            app(\App\Services\V3\Source\ActivateSource::class)->discoverAndDispatch();
            app(\App\Services\V3\Lessons\ActivateMap::class)->discoverAndDispatch();
            $review = DB::table('v3_teacher_review_items')->where('case_id', $caseId)->first();
            if ($review) {
                break;
            }
            if ($this->workerProc->isTerminated()) {
                $this->fail('Connected Temporal worker exited: ' . $this->workerProc->getErrorOutput() . $this->workerProc->getOutput());
            }
            if ($this->temporalProc?->isTerminated()) {
                $this->fail('Local Temporal exited during connected pipeline: ' . $this->temporalProc->getErrorOutput() . $this->temporalProc->getOutput());
            }
            usleep(250000);
        }
        if (! $review) {
            $link = DB::table('v3_workflow_links')->where('case_id', $caseId)->first();
            $eventSummary = DB::table('v3_case_events')->where('case_id', $caseId)->orderBy('sequence')->get(['event_type', 'stage_code', 'status_code', 'error_code'])->toArray();
            $handoffSummary = [];
            foreach ([
                'v3_lesson_cursors', 'v3_lesson_completions', 'v3_prompt_work_queries',
                'v3_prompt_work_receipts', 'v3_prompt_qualification_results',
                'v3_scene_candidates', 'v3_scene_check_runs', 'v3_teacher_review_items',
            ] as $table) {
                if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                    $handoffSummary[$table] = DB::table($table)->where('case_id', $caseId)->get()->toArray();
                }
            }
            $this->fail('Connected worker did not create a review item. Stage=' . json_encode($link ? [$link->stage_code, $link->status_code] : null)
                . '; events=' . json_encode($eventSummary)
                . '; lesson/prompt handoffs=' . json_encode($handoffSummary)
                . '; worker=' . $this->workerProc->getErrorOutput() . $this->workerProc->getOutput());
        }

        $source = DB::table('v3_extracted_sources')->where('case_id', $caseId)->first();
        $binding = DB::table('v3_knowledge_source_bindings')->where('case_id', $caseId)->first();
        $map = DB::table('v3_lesson_maps')->where('case_id', $caseId)->first();
        $section = DB::table('v3_teacher_sections')->where('case_id', $caseId)->first();
        $third = DB::table('v3_scene_prompt_versions')->where('case_id', $caseId)->where('origin', 'third')->first();
        $candidate = DB::table('v3_scene_candidates')->where('id', $review->candidate_id)->first();
        $check = DB::table('v3_scene_check_runs')->where('candidate_id', $review->candidate_id)->where('verdict', 'pass')->first();
        $this->assertNotNull($source);
        $this->assertNotNull($binding);
        $this->assertNotNull($map);
        $this->assertNotNull($section);
        $this->assertNotNull($third);
        $this->assertNotNull($candidate);
        $this->assertNotNull($check, 'Scene must pass the real browser-container checks before review.');
        $this->assertSame(1, (int) $map->work_count);
        $sectionFields = [
            'section_identity', 'core_understanding', 'visual_first_explanation',
            'visual_scene_script', 'dynamic_view_blueprint', 'visual_learning_outcome',
            'concept_check', 'conceptual_summary', 'colloquial_egyptian_explanation',
            'laws_if_any', 'real_life_example',
        ];
        foreach ($sectionFields as $field) {
            $this->assertNotSame('', (string) $section->{$field}, "First-step field {$field} must persist.");
        }

        $teacher = \App\Models\User::findOrFail($teacherId);
        $case = ProcessingCase::findOrFail($caseId);
        $reviewModel = \App\Models\V3\TeacherReviewItem::findOrFail($review->id);
        $this->actingAs($teacher);
        $startPreview = $this->postJson("/v3/teacher/cases/{$caseId}/reviews/{$review->id}/viewer-sessions", [
            'expected_review_version' => (int) $review->state_version,
        ]);
        $startPreview->assertSuccessful();
        $viewerRef = $startPreview->json('data.viewer_ref');
        $this->assertNotEmpty($viewerRef);
        $frame = $this->get("/v3/teacher/cases/{$caseId}/reviews/{$review->id}/viewer-sessions/{$viewerRef}/frames/0");
        $frame->assertOk();
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $frame->getContent());

        $decision = $this->postJson("/v3/teacher/cases/{$caseId}/reviews/{$review->id}/decisions", [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_review_version' => (int) $reviewModel->state_version,
            'candidate_ref' => $reviewModel->candidate_id,
            'candidate_version' => (int) $reviewModel->candidate_version,
            'decision' => 'approve',
            'feedback_kind' => null,
            'notes' => null,
        ]);
        $decision->assertSuccessful();
        app(\App\Services\V3\Review\DispatchReviewEffects::class)->dispatchPending();
        $decisionRef = $decision->json('data.decision_ref');
        $outbox = DB::table('v3_review_outbox')->where('review_decision_id', $decisionRef)->first();
        $this->assertNotNull($outbox);
        $this->assertSame('delivered', $outbox->state, 'Review effect is delivered only after Temporal confirms exact decision consumption.');
        $this->assertDatabaseHas('v3_approved_scenes', ['review_decision_id' => $decisionRef]);
        $approval = DB::table('v3_approved_scenes')->where('review_decision_id', $decisionRef)->first();
        $this->assertNotNull($approval);

        // Exercise the postapproval correction contract through Laravel's real
        // owner boundary and the registered standalone correction workflow.
        $sectionState = DB::table('v3_review_section_states')->where('section_id', $section->id)->first();
        $correction = $this->actingAs($teacher)->postJson(
            "/v3/teacher/cases/{$caseId}/approved-scenes/{$approval->id}/corrections",
            [
                'schema_version' => 1,
                'idempotency_key' => (string) Str::uuid(),
                'expected_section_version' => (int) $sectionState->state_version,
                'feedback_kind' => 'visual_interaction',
                'notes' => 'Increase the motion label size and retain the current interaction.',
            ]
        );
        $correction->assertCreated();
        $correctionRef = $correction->json('data.correction_ref');
        $this->assertNotEmpty($correctionRef);

        app(\App\Services\V3\Review\DispatchReviewEffects::class)->dispatchPending();
        $correctionOutbox = DB::table('v3_review_outbox')->where('postapproval_correction_id', $correctionRef)->first();
        $this->assertNotNull($correctionOutbox);
        $this->assertSame('delivered', $correctionOutbox->state, 'Correction acceptance requires Temporal start acknowledgement.');

        $correctedReview = null;
        $correctionDeadline = time() + 120;
        while (time() < $correctionDeadline) {
            DB::purge('sqlite');
            DB::reconnect('sqlite');
            DB::statement('PRAGMA busy_timeout = 5000;');
            $correctedReview = DB::table('v3_teacher_review_items')
                ->where('case_id', $caseId)
                ->where('section_id', $section->id)
                ->where('candidate_id', '!=', $reviewModel->candidate_id)
                ->where('status', 'pending')
                ->first();
            if ($correctedReview) {
                break;
            }
            if ($this->workerProc->isTerminated() || $this->temporalProc?->isTerminated()) {
                $this->fail('Correction worker exited before producing a successor review item: '
                    . $this->workerProc->getErrorOutput() . $this->workerProc->getOutput()
                    . $this->temporalProc?->getErrorOutput() . $this->temporalProc?->getOutput());
            }
            usleep(250000);
        }
        $this->assertNotNull(
            $correctedReview,
            'Postapproval correction must create a new review item only after scene qualification passes. '
                . "Correction workflow {$correctionRef}; worker stderr: " . $this->workerProc?->getErrorOutput()
                . " worker stdout: " . $this->workerProc?->getOutput()
                . " bridge stderr: " . $this->uvicornProc?->getErrorOutput()
        );
        $this->assertNotSame($reviewModel->candidate_id, $correctedReview->candidate_id);
        $this->assertDatabaseHas('v3_scene_check_runs', [
            'candidate_id' => $correctedReview->candidate_id,
            'verdict' => 'pass',
        ]);
        $this->assertDatabaseHas('v3_scene_qualifications', [
            'candidate_id' => $correctedReview->candidate_id,
            'decision' => 'pass',
        ]);

        $correctedPreview = $this->postJson(
            "/v3/teacher/cases/{$caseId}/reviews/{$correctedReview->id}/viewer-sessions",
            ['expected_review_version' => (int) $correctedReview->state_version]
        );
        $correctedPreview->assertSuccessful();
        $correctedViewerRef = $correctedPreview->json('data.viewer_ref');
        $this->assertNotEmpty($correctedViewerRef);
        $correctedFrame = $this->get("/v3/teacher/cases/{$caseId}/reviews/{$correctedReview->id}/viewer-sessions/{$correctedViewerRef}/frames/0");
        $correctedFrame->assertOk();
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $correctedFrame->getContent());

        $correctedDecision = $this->postJson("/v3/teacher/cases/{$caseId}/reviews/{$correctedReview->id}/decisions", [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_review_version' => (int) $correctedReview->state_version,
            'candidate_ref' => $correctedReview->candidate_id,
            'candidate_version' => (int) $correctedReview->candidate_version,
            'decision' => 'approve',
            'feedback_kind' => null,
            'notes' => null,
        ]);
        $correctedDecision->assertSuccessful();
        app(\App\Services\V3\Review\DispatchReviewEffects::class)->dispatchPending();
        $correctedDecisionRef = $correctedDecision->json('data.decision_ref');
        $this->assertDatabaseHas('v3_approved_scenes', ['review_decision_id' => $correctedDecisionRef]);
        $this->assertSame(
            'delivered',
            DB::table('v3_review_outbox')->where('review_decision_id', $correctedDecisionRef)->value('state'),
            'The correction workflow must consume the exact successor approval before the review effect is delivered.'
        );

    }
}
