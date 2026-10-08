<?php

namespace Tests\Integration\V3\NotebookLM;

use App\Models\V3\Document;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\LessonCursor;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\LessonQuery;
use App\Models\V3\MapActivation;
use App\Models\V3\NotebookFrameworkVersion;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\WorkflowLink;
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

class DirectClaimConcurrencyTest extends TestCase
{
    protected string $tempDb;
    protected string $tempStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDb = sys_get_temp_dir() . '/v3_direct_claim_conc_' . uniqid() . '.sqlite';
        touch($this->tempDb);

        $this->tempStorage = sys_get_temp_dir() . '/v3_direct_claim_storage_' . uniqid();
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
            'name' => 'Teacher Direct Conc',
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
            'run_id' => 'run-conc-direct-' . Str::random(4),
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

        return [
            'teacher_id' => $teacherId,
            'case_id' => $caseId,
            'binding_id' => $bindingId,
            'map_id' => $mapId,
            'framework_sha256' => $frameworkSha256,
        ];
    }

    public function test_n16_two_overlapping_claims_authorize_one_send_and_concurrent_commits_create_one_bundle(): void
    {
        $caseData = $this->createDirectCase('teacher_direct_conc@example.com');
        $caseId = $caseData['case_id'];
        $bindingId = $caseData['binding_id'];
        $frameworkSha256 = $caseData['framework_sha256'];

        // 1. Two concurrent claims
        $barrierClaim = sys_get_temp_dir() . '/v3_claim_barrier_' . uniqid();
        $workerScript = base_path('tests/Support/V3/ConcurrentDirectClaimWorker.php');

        $claimPayload = [
            'kind' => 'lesson_start',
            'work_ordinal' => 1,
            'binding_ref' => $bindingId,
            'expected_cursor_version' => 2,
            'conversation_in' => 'convo-global-1',
            'request_sha256' => \App\Services\V3\NotebookLM\DirectQueryLedger::computeRequestDigest(
                $caseId, $bindingId, 'lesson_start', 1, 2, 'convo-global-1',
                ['educational_pdf', 'framework_steps'], $frameworkSha256,
                FrameworkCatalog::RESPONSE_FORMAT_SHA256, hash('sha256', 'Start with First lesson')
            ),
            'history_length_before' => 2,
            'history_anchor_sha256' => hash('sha256', 'anchor-conc'),
        ];
        $claimPayloadJson = json_encode($claimPayload);

        $p1 = new Process(
            ['php', $workerScript, $this->tempDb, 'claim', $caseId, $barrierClaim, $claimPayloadJson],
            env: ['V3_PRIVATE_STORAGE_ROOT' => $this->tempStorage]
        );
        $p2 = new Process(
            ['php', $workerScript, $this->tempDb, 'claim', $caseId, $barrierClaim, $claimPayloadJson],
            env: ['V3_PRIVATE_STORAGE_ROOT' => $this->tempStorage]
        );

        $p1->start();
        $p2->start();

        // Wait for both to be ready
        $p1Pid = $p1->getPid();
        $p2Pid = $p2->getPid();
        $timeout = time() + 5;
        while ((! file_exists($barrierClaim . '.' . $p1Pid) || ! file_exists($barrierClaim . '.' . $p2Pid)) && time() < $timeout) {
            usleep(10000);
        }

        // Release barrier
        touch($barrierClaim);

        $p1->wait();
        $p2->wait();

        @unlink($barrierClaim);
        @unlink($barrierClaim . '.' . $p1Pid);
        @unlink($barrierClaim . '.' . $p2Pid);

        $out1 = json_decode($p1->getOutput(), true);
        $out2 = json_decode($p2->getOutput(), true);

        $this->assertTrue($out1['success']);
        $this->assertTrue($out2['success']);

        $disp1 = $out1['result']['disposition'];
        $disp2 = $out2['result']['disposition'];

        // Exactly one gets send_now, the other gets reconcile_only or already_recorded
        $sendNowCount = ($disp1 === 'send_now' ? 1 : 0) + ($disp2 === 'send_now' ? 1 : 0);
        $this->assertSame(1, $sendNowCount);

        $queryRef = $out1['result']['query_ref'];
        $this->assertSame($queryRef, $out2['result']['query_ref']);

        // Verify exactly 1 query in DB
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->assertSame(1, DB::table('v3_lesson_queries')->where('case_id', $caseId)->count());

        // 2. Two concurrent commits racing with pre-commit barrier
        $validCombinedText = file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/valid_combined_lesson_1.txt'));
        $answerSha = hash('sha256', $validCombinedText);

        $preCommitBarrier = sys_get_temp_dir() . '/v3_commit_pre_barrier_' . uniqid();

        $commitPayload = [
            'query_ref' => $queryRef,
            'result_payload' => [
                'schema_version' => 1,
                'expected_cursor_version' => 3,
                'answer' => $validCombinedText,
                'answer_sha256' => $answerSha,
                'conversation_out' => 'convo-global-1',
                'pre_commit_barrier' => $preCommitBarrier,
            ],
        ];
        $commitPayloadJson = json_encode($commitPayload);

        $startBarrierCommit = sys_get_temp_dir() . '/v3_commit_start_barrier_' . uniqid();

        $c1 = new Process(
            ['php', $workerScript, $this->tempDb, 'result', $caseId, $startBarrierCommit, $commitPayloadJson],
            env: ['V3_PRIVATE_STORAGE_ROOT' => $this->tempStorage]
        );
        $c2 = new Process(
            ['php', $workerScript, $this->tempDb, 'result', $caseId, $startBarrierCommit, $commitPayloadJson],
            env: ['V3_PRIVATE_STORAGE_ROOT' => $this->tempStorage]
        );

        $c1->start();
        $c2->start();

        $c1Pid = $c1->getPid();
        $c2Pid = $c2->getPid();
        $timeout = time() + 5;
        while ((! file_exists($startBarrierCommit . '.' . $c1Pid) || ! file_exists($startBarrierCommit . '.' . $c2Pid)) && time() < $timeout) {
            usleep(10000);
        }

        // Release start barrier
        touch($startBarrierCommit);

        // Wait until at least one worker finishes result validation and hits pre-commit barrier
        $timeout = time() + 5;
        while (! file_exists($preCommitBarrier . '.reached') && time() < $timeout) {
            usleep(10000);
        }
        $this->assertFileExists($preCommitBarrier . '.reached');

        // Release pre-commit barrier so both race into DB::transaction
        touch($preCommitBarrier . '.release');

        $c1->wait();
        $c2->wait();

        @unlink($startBarrierCommit);
        @unlink($startBarrierCommit . '.' . $c1Pid);
        @unlink($startBarrierCommit . '.' . $c2Pid);
        @unlink($preCommitBarrier . '.reached');
        @unlink($preCommitBarrier . '.release');

        $cOut1 = json_decode($c1->getOutput(), true);
        $cOut2 = json_decode($c2->getOutput(), true);

        $this->assertTrue($cOut1['success']);
        $this->assertTrue($cOut2['success']);

        // One gets status 201 (committed), one gets status 200 (idempotent replay)
        $statuses = [$cOut1['result']['status'], $cOut2['result']['status']];
        sort($statuses);
        $this->assertSame([200, 201], $statuses);

        // Exactly one bundle and one completion
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->assertSame(1, DB::table('v3_notebook_lesson_bundles')->where('case_id', $caseId)->count());
        $this->assertSame(1, DB::table('v3_lesson_completions')->where('case_id', $caseId)->count());
        $this->assertSame(2, DB::table('v3_teacher_sections')->where('case_id', $caseId)->count());

        $cursor = DB::table('v3_lesson_cursors')->where('case_id', $caseId)->first();
        $this->assertSame(4, (int) $cursor->version);
        $this->assertSame('ready_to_continue', $cursor->phase);
    }

    public function test_n16_race_changed_duplicate_callback_against_inflight_commit_rejects_with_409(): void
    {
        $caseData = $this->createDirectCase('teacher_changed_dup@example.com');
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

        $validCombinedTextA = file_get_contents(base_path('tests/Fixtures/V3/NotebookLM/valid_combined_lesson_1.txt'));
        $answerShaA = hash('sha256', $validCombinedTextA);

        // Answer B is valid format for W1 but different content/SHA
        $validCombinedTextB = str_replace(
            'Every real number corresponds to a unique point on a continuous line.',
            'Every real number represents a unique continuous coordinate on the axis.',
            $validCombinedTextA
        );
        $answerShaB = hash('sha256', $validCombinedTextB);
        $this->assertNotSame($answerShaA, $answerShaB);

        $workerScript = base_path('tests/Support/V3/ConcurrentDirectClaimWorker.php');
        $preCommitBarrier = sys_get_temp_dir() . '/v3_changed_pre_barrier_' . uniqid();
        $startBarrier = sys_get_temp_dir() . '/v3_changed_start_barrier_' . uniqid();

        $payloadA = [
            'query_ref' => $queryRef,
            'result_payload' => [
                'schema_version' => 1,
                'expected_cursor_version' => 3,
                'answer' => $validCombinedTextA,
                'answer_sha256' => $answerShaA,
                'conversation_out' => 'convo-global-1',
                'pre_commit_barrier' => $preCommitBarrier,
            ],
        ];

        $payloadB = [
            'query_ref' => $queryRef,
            'result_payload' => [
                'schema_version' => 1,
                'expected_cursor_version' => 3,
                'answer' => $validCombinedTextB,
                'answer_sha256' => $answerShaB,
                'conversation_out' => 'convo-global-1',
                'pre_commit_barrier' => $preCommitBarrier,
            ],
        ];

        $pA = new Process(
            ['php', $workerScript, $this->tempDb, 'result', $caseId, $startBarrier, json_encode($payloadA)],
            env: ['V3_PRIVATE_STORAGE_ROOT' => $this->tempStorage]
        );
        $pB = new Process(
            ['php', $workerScript, $this->tempDb, 'result', $caseId, $startBarrier, json_encode($payloadB)],
            env: ['V3_PRIVATE_STORAGE_ROOT' => $this->tempStorage]
        );

        $pA->start();
        $pB->start();

        $timeout = time() + 5;
        while ((! file_exists($startBarrier . '.' . $pA->getPid()) || ! file_exists($startBarrier . '.' . $pB->getPid())) && time() < $timeout) {
            usleep(10000);
        }

        touch($startBarrier);

        // Wait until pre-commit barrier is reached
        $timeout = time() + 5;
        while (! file_exists($preCommitBarrier . '.reached') && time() < $timeout) {
            usleep(10000);
        }

        touch($preCommitBarrier . '.release');

        $pA->wait();
        $pB->wait();

        @unlink($startBarrier);
        @unlink($startBarrier . '.' . $pA->getPid());
        @unlink($startBarrier . '.' . $pB->getPid());
        @unlink($preCommitBarrier . '.reached');
        @unlink($preCommitBarrier . '.release');

        $outA = json_decode($pA->getOutput(), true);
        $outB = json_decode($pB->getOutput(), true);

        // Exactly one winner commits (201), the other with changed answer is rejected (409)
        $successCount = ($outA['success'] ? 1 : 0) + ($outB['success'] ? 1 : 0);
        $this->assertSame(1, $successCount);

        if ($outA['success']) {
            $this->assertSame(201, $outA['result']['status']);
            $this->assertFalse($outB['success']);
            $this->assertSame(409, (int) $outB['code']);
            $this->assertTrue(str_contains($outB['error'], 'Query conflict') || str_contains($outB['error'], 'Query result changed'));
        } else {
            $this->assertSame(201, $outB['result']['status']);
            $this->assertFalse($outA['success']);
            $this->assertSame(409, (int) $outA['code']);
            $this->assertTrue(str_contains($outA['error'], 'Query conflict') || str_contains($outA['error'], 'Query result changed'));
        }

        // Exactly 1 bundle committed
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->assertSame(1, DB::table('v3_notebook_lesson_bundles')->where('case_id', $caseId)->count());
        $cursor = DB::table('v3_lesson_cursors')->where('case_id', $caseId)->first();
        $this->assertSame(4, (int) $cursor->version);
    }
}
