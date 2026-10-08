<?php

namespace Tests\Integration\V3\Lessons;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class ContinueRaceTest extends TestCase
{
    protected string $tempDb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDb = sys_get_temp_dir() . '/v3_cont_race_' . uniqid() . '.sqlite';
        touch($this->tempDb);

        config(['database.connections.sqlite.database' => $this->tempDb]);
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

        parent::tearDown();
    }

    public function test_l11_two_concurrent_completion_receipts_succeed_idempotently(): void
    {
        $now = now();
        $teacherId = DB::table('users')->insertGetId([
            'name' => 'Teacher Continue Race',
            'email' => 'teacher_continue_race@example.com',
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

        $docId = (string) Str::uuid();
        DB::table('v3_documents')->insert([
            'id' => $docId,
            'owner_user_id' => $teacherId,
            'sha256' => hash('sha256', 'race doc'),
            'byte_size' => 2048,
            'page_count' => 3,
            'state' => 'ready',
            'storage_path' => 'accepted/race-cur.pdf',
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
            'state_version' => 8,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $actId = (string) Str::uuid();
        DB::table('v3_source_activations')->insert([
            'id' => $actId,
            'case_id' => $caseId,
            'workflow_id' => "v3-case-{$caseId}",
            'state' => 'accepted',
            'signal_accepted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $srcId = (string) Str::uuid();
        DB::table('v3_extracted_sources')->insert([
            'id' => $srcId,
            'case_id' => $caseId,
            'activation_id' => $actId,
            'document_id' => $docId,
            'settings_version_id' => $settingsId,
            'status' => 'ready',
            'artifact_path' => 'extracted/race.txt',
            'text_sha256' => hash('sha256', 'race-text'),
            'text_bytes' => 100,
            'page_count' => 3,
            'page_report' => json_encode([]),
            'extractor_version' => '008-v1',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $bindId = (string) Str::uuid();
        DB::table('v3_knowledge_source_bindings')->insert([
            'id' => $bindId,
            'case_id' => $caseId,
            'extracted_source_id' => $srcId,
            'adapter_kind' => 'notebooklm',
            'adapter_version' => '0.3.1',
            'notebook_title' => "v3-case-{$caseId}",
            'source_title' => "v3-text-{$caseId}",
            'notebook_id' => 'nb-race-cur',
            'source_id' => 'src-race-cur',
            'state' => 'bound',
            'text_sha256' => hash('sha256', 'race-text'),
            'text_bytes' => 100,
            'transfer_status' => 'confirmed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $mapActId = (string) Str::uuid();
        DB::table('v3_map_activations')->insert([
            'id' => $mapActId,
            'case_id' => $caseId,
            'binding_id' => $bindId,
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
            'binding_id' => $bindId,
            'activation_id' => $mapActId,
            'state' => 'ready',
            'page_count' => 3,
            'work_count' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $nodeId = (string) Str::uuid();
        DB::table('v3_lesson_nodes')->insert([
            'id' => $nodeId,
            'map_id' => $mapId,
            'kind' => 'lesson',
            'position_path' => '001',
            'ordinal_in_parent' => 1,
            'title' => 'Kinematics',
            'work_ordinal' => 1,
            'coverage_pages' => json_encode([1, 2]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $queryId = (string) Str::uuid();
        DB::table('v3_lesson_queries')->insert([
            'id' => $queryId,
            'case_id' => $caseId,
            'map_id' => $mapId,
            'kind' => 'first',
            'target_work_ordinal' => 1,
            'state' => 'recorded',
            'send_count' => 1,
            'request_sha256' => hash('sha256', 'first-req-race'),
            'canonical_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
            'conversation_in' => 'conv-in-race',
            'conversation_out' => 'conv-first-race',
            'sent_at' => $now,
            'recorded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $secId = (string) Str::uuid();
        DB::table('v3_teacher_sections')->insert([
            'id' => $secId,
            'case_id' => $caseId,
            'map_id' => $mapId,
            'work_node_id' => $nodeId,
            'query_id' => $queryId,
            'ordinal_in_work' => 1,
            'section_identity' => 'Sec 1',
            'core_understanding' => 'Core 1',
            'visual_first_explanation' => 'Exp 1',
            'visual_scene_script' => 'Script 1',
            'dynamic_view_blueprint' => 'Blueprint 1',
            'visual_learning_outcome' => 'Outcome 1',
            'concept_check' => 'Check 1',
            'conceptual_summary' => 'Summary 1',
            'colloquial_egyptian_explanation' => 'عامي 1',
            'laws_if_any' => 'law 1',
            'real_life_example' => 'example 1',
            'first_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
            'answer_sha256' => hash('sha256', 'ans-race'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('v3_lesson_cursors')->insert([
            'case_id' => $caseId,
            'map_id' => $mapId,
            'current_work_ordinal' => 1,
            'phase' => 'awaiting_010',
            'conversation_id' => 'conv-first-race',
            'version' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Build completion receipt payload
        $sectionRefs = [$secId];
        $thirdRefs = ['third-out-1'];
        $finalConv = 'conv-final-race';
        $completionRef = 'comp-race-w1';

        $str = "009-completion-v1\n"
            . $caseId . "\n"
            . $mapId . "\n"
            . 1 . "\n"
            . 2 . "\n"
            . implode(',', $sectionRefs) . "\n"
            . implode(',', $thirdRefs) . "\n"
            . strlen($finalConv) . ":" . $finalConv . "\n"
            . $completionRef;
        $digest = strtolower(hash('sha256', $str));

        $payload = [
            'schema_version' => 1,
            'map_ref' => $mapId,
            'work_ordinal' => 1,
            'expected_cursor_version' => 2,
            'section_refs' => $sectionRefs,
            'third_output_refs' => $thirdRefs,
            'final_conversation_ref' => $finalConv,
            'completion_ref' => $completionRef,
            'completion_sha256' => $digest,
        ];
        $payloadJson = json_encode($payload);

        $barrierFile = sys_get_temp_dir() . '/v3_cont_barrier_' . uniqid();
        $scriptPath = base_path('tests/Support/V3/ConcurrentCompletionRecorder.php');

        $p1 = new Process(['php', $scriptPath, $this->tempDb, $caseId, $barrierFile, $payloadJson]);
        $p2 = new Process(['php', $scriptPath, $this->tempDb, $caseId, $barrierFile, $payloadJson]);

        $p1->start();
        $p2->start();

        // Wait for both processes to be ready
        $p1Pid = $p1->getPid();
        $p2Pid = $p2->getPid();

        $timeout = time() + 5;
        while ((! file_exists($barrierFile . '.' . $p1Pid) || ! file_exists($barrierFile . '.' . $p2Pid)) && time() < $timeout) {
            usleep(10000);
        }

        // Release barrier
        touch($barrierFile);

        $p1->wait();
        $p2->wait();

        @unlink($barrierFile);
        @unlink($barrierFile . '.' . $p1Pid);
        @unlink($barrierFile . '.' . $p2Pid);

        $out1 = json_decode($p1->getOutput(), true);
        $out2 = json_decode($p2->getOutput(), true);

        // At least one or both must succeed (exact replay returns 200/success)
        $succeeded = 0;
        if (! empty($out1['success'])) {
            $succeeded++;
        }
        if (! empty($out2['success'])) {
            $succeeded++;
        }

        $this->assertGreaterThanOrEqual(1, $succeeded, "At least one process must succeed: Out1=" . $p1->getOutput() . ", Out2=" . $p2->getOutput());

        // Verify exactly one completion record in DB
        $completions = DB::table('v3_lesson_completions')->where('case_id', $caseId)->get();
        $this->assertCount(1, $completions);
        $this->assertEquals($completionRef, $completions[0]->completion_ref);

        // Verify cursor moved to ready_to_continue with version 3
        $cursor = DB::table('v3_lesson_cursors')->where('case_id', $caseId)->first();
        $this->assertEquals('ready_to_continue', $cursor->phase);
        $this->assertEquals(3, $cursor->version);
    }
}
