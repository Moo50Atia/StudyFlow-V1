<?php

namespace Tests\Integration\V3\NotebookLM;

use App\Models\V3\CaseOperationControl;
use App\Models\V3\Document;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\LessonCursor;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\LessonQuery;
use App\Models\V3\MapActivation;
use App\Models\V3\NotebookFrameworkVersion;
use App\Models\V3\NotebookLessonBundle;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\TeacherSection;
use App\Models\V3\WorkflowLink;
use App\Services\V3\NotebookLM\AcceptCombinedLesson;
use App\Services\V3\NotebookLM\DirectQueryLedger;
use App\Services\V3\NotebookLM\FrameworkCatalog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class DirectControlRaceTest extends TestCase
{
    protected string $tempDb;
    protected string $tempStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDb = sys_get_temp_dir() . '/v3_direct_ctrl_race_' . uniqid() . '.sqlite';
        touch($this->tempDb);

        $this->tempStorage = sys_get_temp_dir() . '/v3_direct_ctrl_storage_' . uniqid();
        @mkdir($this->tempStorage, 0777, true);

        config([
            'database.connections.sqlite.database' => $this->tempDb,
            'filesystems.disks.v3_private.root' => $this->tempStorage,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::statement('PRAGMA journal_mode = WAL;');
        DB::statement('PRAGMA busy_timeout = 5000;');

        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }

        if (is_dir($this->tempStorage)) {
            File::deleteDirectory($this->tempStorage);
        }

        parent::tearDown();
    }

    protected function createDirectCase(string $email, int $workCount = 2): array
    {
        $now = now();
        $teacherId = DB::table('users')->insertGetId([
            'name' => 'Teacher Control Race',
            'email' => $email,
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
        $canonical = \App\Services\V3\Settings\CanonicalSettings::canonicalize($payload);
        $checksum = hash('sha256', \App\Services\V3\Settings\CanonicalSettings::encode($canonical));

        $settingsId = DB::table('v3_settings_versions')->insertGetId([
            'version' => 1,
            'schema_version' => 1,
            'payload' => json_encode($canonical),
            'checksum' => $checksum,
            'published_by_user_id' => $teacherId,
            'published_at' => $now,
        ]);

        $frameworkBytes = file_get_contents(base_path('steps.txt'));
        $frameworkSha256 = hash('sha256', $frameworkBytes);
        $frameworkId = (string) Str::uuid();

        DB::table('v3_notebook_framework_versions')->insert([
            'id' => $frameworkId,
            'profile' => 'expanded-root-v1',
            'body_path' => 'frameworks/steps.txt',
            'body_sha256' => $frameworkSha256,
            'body_bytes' => strlen($frameworkBytes),
            'first_sha256' => hash('sha256', 'first'),
            'second_sha256' => hash('sha256', 'second'),
            'third_sha256' => hash('sha256', 'third'),
            'response_format_sha256' => FrameworkCatalog::RESPONSE_FORMAT_SHA256,
            'approved_by_user_id' => $teacherId,
            'approved_at' => $now,
            'created_at' => $now,
        ]);

        $docId = (string) Str::uuid();
        DB::table('v3_documents')->insert([
            'id' => $docId,
            'owner_user_id' => $teacherId,
            'sha256' => hash('sha256', 'doc-' . Str::random()),
            'byte_size' => 2048,
            'page_count' => 10,
            'state' => 'ready',
            'storage_path' => 'accepted/test.pdf',
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
            'state' => 'running',
            'state_version' => 1,
            'pipeline_version' => 'notebooklm_combined_v1',
            'notebook_framework_version_id' => $frameworkId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('v3_workflow_links')->insert([
            'case_id' => $caseId,
            'workflow_id' => "v3-case-{$caseId}",
            'run_id' => 'run-ctrl-direct-' . Str::random(4),
            'settings_version_id' => $settingsId,
            'settings_checksum' => $checksum,
            'start_state' => 'started',
            'stage_code' => 'sourcing',
            'status_code' => 'running',
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $bindingId = (string) Str::uuid();
        DB::table('v3_knowledge_source_bindings')->insert([
            'id' => $bindingId,
            'case_id' => $caseId,
            'extracted_source_id' => null,
            'source_mode' => 'original_pdf',
            'adapter_kind' => 'notebooklm_py',
            'adapter_version' => '0.8.4',
            'notebook_title' => "v3-case-{$caseId}",
            'notebook_id' => 'nb-direct-' . Str::random(4),
            'state' => 'bound',
            'chat_configuration_state' => 'configured',
            'document_id' => $docId,
            'framework_version_id' => $frameworkId,
            'transfer_status' => 'confirmed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $actId = (string) Str::uuid();
        DB::table('v3_map_activations')->insert([
            'id' => $actId,
            'case_id' => $caseId,
            'binding_id' => $bindingId,
            'workflow_id' => "v3-case-{$caseId}",
            'state' => 'accepted',
            'signal_accepted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $mapId = (string) Str::uuid();
        DB::table('v3_lesson_maps')->insert([
            'id' => $mapId,
            'case_id' => $caseId,
            'binding_id' => $bindingId,
            'activation_id' => $actId,
            'state' => 'ready',
            'coverage_mode' => 'not_requested_direct_pdf',
            'page_count' => 10,
            'work_count' => $workCount,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $nodeId = (string) Str::uuid();
        DB::table('v3_lesson_nodes')->insert([
            'id' => $nodeId,
            'map_id' => $mapId,
            'kind' => 'lesson',
            'title' => 'The Number Line and Real Numbers',
            'position_path' => '001.001',
            'work_ordinal' => 1,
            'ordinal_in_parent' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('v3_lesson_cursors')->insert([
            'case_id' => $caseId,
            'map_id' => $mapId,
            'current_work_ordinal' => 1,
            'phase' => 'ready_to_start',
            'conversation_id' => 'convo-global-1',
            'version' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('v3_case_operation_controls')->insert([
            'case_id' => $caseId,
            'mode' => 'normal',
            'control_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'teacher_id' => $teacherId,
            'case_id' => $caseId,
            'binding_id' => $bindingId,
            'map_id' => $mapId,
            'framework_sha256' => $frameworkSha256,
        ];
    }

    public function test_n18_initial_hold_prevents_claim_until_unheld(): void
    {
        $caseData = $this->createDirectCase('teacher_initial_hold@example.com');
        $caseId = $caseData['case_id'];
        $bindingId = $caseData['binding_id'];

        // Place on hold
        DB::table('v3_case_operation_controls')->where('case_id', $caseId)->update(['mode' => 'held']);

        $ledger = app(DirectQueryLedger::class);
        $claimRejected = false;
        try {
            $ledger->claimQuery(
                caseId: $caseId,
                kind: 'lesson_start',
                workOrdinal: 1,
                bindingRef: $bindingId,
                expectedCursorVersion: 2,
                conversationIn: 'convo-global-1',
            );
        } catch (\DomainException $e) {
            $claimRejected = true;
        }
        $this->assertTrue($claimRejected, 'Claim should be rejected when case is held');

        // Unhold
        DB::table('v3_case_operation_controls')->where('case_id', $caseId)->update(['mode' => 'normal']);

        $claimRes = $ledger->claimQuery(
            caseId: $caseId,
            kind: 'lesson_start',
            workOrdinal: 1,
            bindingRef: $bindingId,
            expectedCursorVersion: 2,
            conversationIn: 'convo-global-1',
        );
        $this->assertSame('send_now', $claimRes['disposition']);
    }

    public function test_n18_race_hold_against_inflight_commit_prevents_advance_and_retains_evidence(): void
    {
        $caseData = $this->createDirectCase('teacher_hold_race@example.com');
        $caseId = $caseData['case_id'];
        $bindingId = $caseData['binding_id'];

        $ledger = app(DirectQueryLedger::class);
        $claimRes = $ledger->claimQuery(
            caseId: $caseId,
            kind: 'lesson_start',
            workOrdinal: 1,
            bindingRef: $bindingId,
            expectedCursorVersion: 2,
            conversationIn: 'convo-global-1',
        );
        $this->assertSame('send_now', $claimRes['disposition']);
        $queryRef = $claimRes['query_ref'];

        $validCombinedText = file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/valid_combined_lesson_1.txt'));
        $answerSha = hash('sha256', $validCombinedText);

        $barrier = sys_get_temp_dir() . '/v3_hold_barrier_' . uniqid();
        $workerScript = base_path('tests/Support/V3/ConcurrentDirectClaimWorker.php');

        $commitPayload = [
            'query_ref' => $queryRef,
            'result_payload' => [
                'schema_version' => 1,
                'expected_cursor_version' => 3,
                'answer' => $validCombinedText,
                'answer_sha256' => $answerSha,
                'conversation_out' => 'convo-global-1',
                'pre_commit_barrier' => $barrier,
            ],
        ];

        // Process 1: Starts commit in independent process
        $p1 = new Process(
            ['php', $workerScript, $this->tempDb, 'result', $caseId, 'none', json_encode($commitPayload)],
            env: ['V3_PRIVATE_STORAGE_ROOT' => $this->tempStorage]
        );
        $p1->start();

        // Wait until Process 1 finishes validation and staging and reaches the pre-commit barrier
        $timeout = time() + 5;
        while (! file_exists($barrier . '.reached') && time() < $timeout) {
            usleep(10000);
        }
        $this->assertFileExists($barrier . '.reached', 'Worker did not reach pre-commit barrier');

        // Process 2: Independent process mutates control to 'held'
        $p2 = new Process(
            ['php', $workerScript, $this->tempDb, 'control', $caseId, 'none', json_encode(['mode' => 'held'])],
            env: ['V3_PRIVATE_STORAGE_ROOT' => $this->tempStorage]
        );
        $p2->run();
        $this->assertTrue($p2->isSuccessful(), 'Control process failed');

        // Release barrier to let Process 1 proceed into DB::transaction
        touch($barrier . '.release');

        $p1->wait();
        @unlink($barrier . '.reached');
        @unlink($barrier . '.release');

        $out1 = json_decode($p1->getOutput(), true);
        $this->assertFalse($out1['success'], 'In-flight commit should have been rejected');
        $this->assertSame(409, (int) $out1['code']);
        $this->assertStringContainsString('held or cancelled', $out1['error']);

        // Assert no progression and 0 bundles
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->assertSame(0, DB::table('v3_notebook_lesson_bundles')->where('case_id', $caseId)->count());
        $cursor = DB::table('v3_lesson_cursors')->where('case_id', $caseId)->first();
        $this->assertSame(3, (int) $cursor->version);
        $this->assertSame('processing_bundle', $cursor->phase);

        // Assert fenced result evidence is retained on disk
        $answerPath = $this->tempStorage . "/answers/{$caseId}/{$queryRef}_{$answerSha}.txt";
        $this->assertFileExists($answerPath);

        // Resume case: unhold and resubmit the same result
        DB::table('v3_case_operation_controls')->where('case_id', $caseId)->update(['mode' => 'normal']);

        $acceptService = app(AcceptCombinedLesson::class);
        $res = $acceptService->acceptCombinedLesson(
            queryRef: $queryRef,
            payload: [
                'schema_version' => 1,
                'expected_cursor_version' => 3,
                'answer' => $validCombinedText,
                'answer_sha256' => $answerSha,
                'conversation_out' => 'convo-global-1',
            ],
        );
        $this->assertSame(201, $res['status']);
        $this->assertSame(1, DB::table('v3_notebook_lesson_bundles')->where('case_id', $caseId)->count());
        $cursorAfter = DB::table('v3_lesson_cursors')->where('case_id', $caseId)->first();
        $this->assertSame(4, (int) $cursorAfter->version);
        $this->assertSame('ready_to_continue', $cursorAfter->phase);
    }

    public function test_n18_race_cancel_against_inflight_commit_aborts_and_retains_evidence(): void
    {
        $caseData = $this->createDirectCase('teacher_cancel_race@example.com');
        $caseId = $caseData['case_id'];
        $bindingId = $caseData['binding_id'];

        $ledger = app(DirectQueryLedger::class);
        $claimRes = $ledger->claimQuery(
            caseId: $caseId,
            kind: 'lesson_start',
            workOrdinal: 1,
            bindingRef: $bindingId,
            expectedCursorVersion: 2,
            conversationIn: 'convo-global-1',
        );
        $queryRef = $claimRes['query_ref'];

        $validCombinedText = file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/valid_combined_lesson_1.txt'));
        $answerSha = hash('sha256', $validCombinedText);

        $barrier = sys_get_temp_dir() . '/v3_cancel_barrier_' . uniqid();
        $workerScript = base_path('tests/Support/V3/ConcurrentDirectClaimWorker.php');

        $commitPayload = [
            'query_ref' => $queryRef,
            'result_payload' => [
                'schema_version' => 1,
                'expected_cursor_version' => 3,
                'answer' => $validCombinedText,
                'answer_sha256' => $answerSha,
                'conversation_out' => 'convo-global-1',
                'pre_commit_barrier' => $barrier,
            ],
        ];

        $p1 = new Process(
            ['php', $workerScript, $this->tempDb, 'result', $caseId, 'none', json_encode($commitPayload)],
            env: ['V3_PRIVATE_STORAGE_ROOT' => $this->tempStorage]
        );
        $p1->start();

        $timeout = time() + 5;
        while (! file_exists($barrier . '.reached') && time() < $timeout) {
            usleep(10000);
        }
        $this->assertFileExists($barrier . '.reached');

        // Process 2: Cancels case
        $p2 = new Process(
            ['php', $workerScript, $this->tempDb, 'cancel', $caseId, 'none'],
            env: ['V3_PRIVATE_STORAGE_ROOT' => $this->tempStorage]
        );
        $p2->run();
        $this->assertTrue($p2->isSuccessful());

        touch($barrier . '.release');
        $p1->wait();

        @unlink($barrier . '.reached');
        @unlink($barrier . '.release');

        $out1 = json_decode($p1->getOutput(), true);
        $this->assertFalse($out1['success']);
        $this->assertSame(409, (int) $out1['code']);

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->assertSame(0, DB::table('v3_notebook_lesson_bundles')->where('case_id', $caseId)->count());
        $cursor = DB::table('v3_lesson_cursors')->where('case_id', $caseId)->first();
        $this->assertSame(3, (int) $cursor->version);
        $this->assertSame('processing_bundle', $cursor->phase);

        $answerPath = $this->tempStorage . "/answers/{$caseId}/{$queryRef}_{$answerSha}.txt";
        $this->assertFileExists($answerPath);
        $this->assertSame('cancelled', DB::table('v3_processing_cases')->where('id', $caseId)->value('state'));
    }

    public function test_n18_race_teacher_suspension_against_inflight_commit_aborts_and_resumes_when_reactivated(): void
    {
        $caseData = $this->createDirectCase('teacher_suspend_race@example.com');
        $caseId = $caseData['case_id'];
        $bindingId = $caseData['binding_id'];
        $teacherId = $caseData['teacher_id'];

        $ledger = app(DirectQueryLedger::class);
        $claimRes = $ledger->claimQuery(
            caseId: $caseId,
            kind: 'lesson_start',
            workOrdinal: 1,
            bindingRef: $bindingId,
            expectedCursorVersion: 2,
            conversationIn: 'convo-global-1',
        );
        $queryRef = $claimRes['query_ref'];

        $validCombinedText = file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/valid_combined_lesson_1.txt'));
        $answerSha = hash('sha256', $validCombinedText);

        $barrier = sys_get_temp_dir() . '/v3_suspend_barrier_' . uniqid();
        $workerScript = base_path('tests/Support/V3/ConcurrentDirectClaimWorker.php');

        $commitPayload = [
            'query_ref' => $queryRef,
            'result_payload' => [
                'schema_version' => 1,
                'expected_cursor_version' => 3,
                'answer' => $validCombinedText,
                'answer_sha256' => $answerSha,
                'conversation_out' => 'convo-global-1',
                'pre_commit_barrier' => $barrier,
            ],
        ];

        $p1 = new Process(
            ['php', $workerScript, $this->tempDb, 'result', $caseId, 'none', json_encode($commitPayload)],
            env: ['V3_PRIVATE_STORAGE_ROOT' => $this->tempStorage]
        );
        $p1->start();

        $timeout = time() + 5;
        while (! file_exists($barrier . '.reached') && time() < $timeout) {
            usleep(10000);
        }
        $this->assertFileExists($barrier . '.reached');

        // Process 2: Suspends teacher account access
        $p2 = new Process(
            ['php', $workerScript, $this->tempDb, 'suspend', $caseId, 'none', json_encode(['user_id' => $teacherId])],
            env: ['V3_PRIVATE_STORAGE_ROOT' => $this->tempStorage]
        );
        $p2->run();
        $this->assertTrue($p2->isSuccessful());

        touch($barrier . '.release');
        $p1->wait();

        @unlink($barrier . '.reached');
        @unlink($barrier . '.release');

        $out1 = json_decode($p1->getOutput(), true);
        $this->assertFalse($out1['success']);
        $this->assertSame(409, (int) $out1['code']);

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->assertSame(0, DB::table('v3_notebook_lesson_bundles')->where('case_id', $caseId)->count());
        $cursor = DB::table('v3_lesson_cursors')->where('case_id', $caseId)->first();
        $this->assertSame(3, (int) $cursor->version);

        $answerPath = $this->tempStorage . "/answers/{$caseId}/{$queryRef}_{$answerSha}.txt";
        $this->assertFileExists($answerPath);

        // Reactivate teacher and resubmit
        DB::table('v3_account_access')->where('user_id', $teacherId)->update(['is_active' => true]);

        $acceptService = app(AcceptCombinedLesson::class);
        $res = $acceptService->acceptCombinedLesson(
            queryRef: $queryRef,
            payload: [
                'schema_version' => 1,
                'expected_cursor_version' => 3,
                'answer' => $validCombinedText,
                'answer_sha256' => $answerSha,
                'conversation_out' => 'convo-global-1',
            ],
        );
        $this->assertSame(201, $res['status']);
        $this->assertSame(1, DB::table('v3_notebook_lesson_bundles')->where('case_id', $caseId)->count());
        $cursorAfter = DB::table('v3_lesson_cursors')->where('case_id', $caseId)->first();
        $this->assertSame(4, (int) $cursorAfter->version);
        $this->assertSame('ready_to_continue', $cursorAfter->phase);
    }
}
