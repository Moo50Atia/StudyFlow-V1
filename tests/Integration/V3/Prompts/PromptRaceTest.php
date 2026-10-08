<?php

namespace Tests\Integration\V3\Prompts;

use App\Models\V3\PromptTurn;
use App\Models\V3\PromptWorkItem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class PromptRaceTest extends TestCase
{
    protected string $tempDb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDb = sys_get_temp_dir() . '/v3_prompt_race_' . uniqid() . '.sqlite';
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

    public function test_p11_two_concurrent_claims_yield_exactly_one_send_now(): void
    {
        $now = now();
        $teacherId = DB::table('users')->insertGetId([
            'name' => 'Teacher Prompt Race',
            'email' => 'teacher_prompt_race@example.com',
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
            'sha256' => hash('sha256', 'prompt race doc'),
            'byte_size' => 2048,
            'page_count' => 3,
            'state' => 'ready',
            'storage_path' => 'accepted/prompt-race.pdf',
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
            'artifact_path' => 'extracted/race-src.txt',
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
            'notebook_id' => 'nb-race-turn',
            'source_id' => 'src-race-turn',
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
            'work_count' => 1,
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
            'title' => 'Thermodynamics',
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
            'section_identity' => 'Heat and Work',
            'core_understanding' => 'Energy is conserved.',
            'visual_first_explanation' => 'Piston compressing gas.',
            'visual_scene_script' => 'Show heat flow into system.',
            'dynamic_view_blueprint' => 'PV diagram.',
            'visual_learning_outcome' => 'Internal energy change.',
            'concept_check' => 'Is heat work?',
            'conceptual_summary' => 'First law.',
            'colloquial_egyptian_explanation' => 'الشغل والحرارة وجهان لعملة واحدة.',
            'laws_if_any' => 'delta_U = Q - W',
            'real_life_example' => 'Car engine cylinder.',
            'first_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
            'answer_sha256' => hash('sha256', 'ans-first-race'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $workItemId = (string) Str::uuid();
        DB::table('v3_prompt_work_items')->insert([
            'id' => $workItemId,
            'case_id' => $caseId,
            'map_id' => $mapId,
            'work_node_id' => $nodeId,
            'work_ordinal' => 1,
            'expected_cursor_version' => 2,
            'section_query_id' => $queryId,
            'state' => 'awaiting_execution',
            'section_refs' => json_encode([$secId]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $turnPayload = [
            'schema_version' => 1,
            'origin' => 'sent',
            'conversation_in' => 'conv-first-race',
            'canonical_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_EXECUTION_SHA256,
            'origin_query_ref' => null,
            'origin_turn_ref' => null,
        ];
        $payloadJson = json_encode($turnPayload);

        $barrierFile = sys_get_temp_dir() . '/v3_prompt_barrier_' . uniqid();
        $scriptPath = base_path('tests/Support/V3/ConcurrentPromptWorker.php');

        $p1 = new Process(['php', $scriptPath, $this->tempDb, $caseId, $barrierFile, 'claim_turn', $workItemId, 'execution', $payloadJson]);
        $p2 = new Process(['php', $scriptPath, $this->tempDb, $caseId, $barrierFile, 'claim_turn', $workItemId, 'execution', $payloadJson]);

        $p1->start();
        $p2->start();

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

        $this->assertTrue(! empty($out1['success']), "P1 failed: " . $p1->getOutput());
        $this->assertTrue(! empty($out2['success']), "P2 failed: " . $p2->getOutput());

        // Exactly one should be 'send_now', and the other should be 'reconcile'
        $dispositions = [$out1['disposition'], $out2['disposition']];
        $this->assertContains('send_now', $dispositions);
        $this->assertContains('reconcile', $dispositions);

        // Verify exactly one turn row in DB with send_count = 1
        $turns = DB::table('v3_prompt_turns')->where('work_item_id', $workItemId)->get();
        $this->assertCount(1, $turns);
        $this->assertEquals(1, $turns[0]->send_count);
        $this->assertEquals('claimed', $turns[0]->state);
    }

    public function test_p11_concurrent_changed_claim_fails_with_conflict(): void
    {
        $now = now();
        $teacherId = DB::table('users')->insertGetId([
            'name' => 'Teacher Prompt Conflict Race',
            'email' => 'teacher_conflict_race@example.com',
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
            'sha256' => hash('sha256', 'conflict race doc'),
            'byte_size' => 2048,
            'page_count' => 3,
            'state' => 'ready',
            'storage_path' => 'accepted/conflict-race.pdf',
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
            'artifact_path' => 'extracted/conflict-src.txt',
            'text_sha256' => hash('sha256', 'conflict-text'),
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
            'notebook_id' => 'nb-conflict-turn',
            'source_id' => 'src-conflict-turn',
            'state' => 'bound',
            'text_sha256' => hash('sha256', 'conflict-text'),
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
            'work_count' => 1,
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
            'title' => 'Fluid Mechanics',
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
            'request_sha256' => hash('sha256', 'first-req-conf'),
            'canonical_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
            'conversation_in' => 'conv-in-conf',
            'conversation_out' => 'conv-first-conf',
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
            'section_identity' => 'Bernoulli Principle',
            'core_understanding' => 'Pressure drops as velocity increases.',
            'visual_first_explanation' => 'Venturi meter visual.',
            'visual_scene_script' => 'Fluid speeding up in narrow tube.',
            'dynamic_view_blueprint' => 'Pressure gauges.',
            'visual_learning_outcome' => 'Visualizing continuity and pressure.',
            'concept_check' => 'Where is pressure lowest?',
            'conceptual_summary' => 'Continuity and Bernoulli.',
            'colloquial_egyptian_explanation' => 'السرعة تزيد فالضغط يقل.',
            'laws_if_any' => 'P + 0.5*rho*v^2 = const',
            'real_life_example' => 'Airplane wing lift.',
            'first_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
            'answer_sha256' => hash('sha256', 'ans-conf'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $workItemId = (string) Str::uuid();
        DB::table('v3_prompt_work_items')->insert([
            'id' => $workItemId,
            'case_id' => $caseId,
            'map_id' => $mapId,
            'work_node_id' => $nodeId,
            'work_ordinal' => 1,
            'expected_cursor_version' => 2,
            'section_query_id' => $queryId,
            'state' => 'awaiting_execution',
            'section_refs' => json_encode([$secId]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $payload1 = [
            'schema_version' => 1,
            'origin' => 'sent',
            'conversation_in' => 'conv-first-conf',
            'canonical_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_EXECUTION_SHA256,
            'origin_query_ref' => null,
            'origin_turn_ref' => null,
        ];
        $payload2 = $payload1;
        $payload2['conversation_in'] = 'conv-DIFFERENT-conf';

        $barrierFile = sys_get_temp_dir() . '/v3_prompt_barrier_conf_' . uniqid();
        $scriptPath = base_path('tests/Support/V3/ConcurrentPromptWorker.php');

        $p1 = new Process(['php', $scriptPath, $this->tempDb, $caseId, $barrierFile, 'claim_turn', $workItemId, 'execution', json_encode($payload1)]);
        $p2 = new Process(['php', $scriptPath, $this->tempDb, $caseId, $barrierFile, 'claim_turn', $workItemId, 'execution', json_encode($payload2)]);

        $p1->start();
        $p2->start();

        $p1Pid = $p1->getPid();
        $p2Pid = $p2->getPid();

        $timeout = time() + 5;
        while ((! file_exists($barrierFile . '.' . $p1Pid) || ! file_exists($barrierFile . '.' . $p2Pid)) && time() < $timeout) {
            usleep(10000);
        }

        touch($barrierFile);

        $p1->wait();
        $p2->wait();

        @unlink($barrierFile);
        @unlink($barrierFile . '.' . $p1Pid);
        @unlink($barrierFile . '.' . $p2Pid);

        $out1 = json_decode($p1->getOutput(), true);
        $out2 = json_decode($p2->getOutput(), true);

        // One must succeed with send_now, and the other must fail with conflict
        $successCount = (! empty($out1['success']) ? 1 : 0) + (! empty($out2['success']) ? 1 : 0);
        $this->assertEquals(1, $successCount, "Exactly one process must succeed: P1=" . $p1->getOutput() . ", P2=" . $p2->getOutput());

        $failedOut = empty($out1['success']) ? $out1 : $out2;
        $this->assertStringContainsString('PromptConflictException', $failedOut['class'] ?? '');
    }
}
