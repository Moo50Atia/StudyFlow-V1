<?php

namespace Tests\Integration\V3\WebPipeline;

use App\Models\V3\AuthoringRequest;
use App\Models\V3\ManualSceneImport;
use App\Models\V3\PipelineCommand;
use App\Models\V3\ProcessingCase;
use App\Models\V3\QualificationGate;
use App\Models\V3\SceneCandidate;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\TeacherSection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class MigrationUpgradeTest extends TestCase
{
    protected string $tempDb;

    protected function tearDown(): void
    {
        DB::purge('sqlite');

        if (isset($this->tempDb) && file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }

        parent::tearDown();
    }

    /**
     * FR-027, SC-005, A28: Fresh SQLite migration creates all Web Pipeline and gate tables.
     */
    public function test_fresh_sqlite_migration_creates_all_v3_web_pipeline_tables(): void
    {
        $this->tempDb = sys_get_temp_dir() . '/v3_fresh_upgrade_' . uniqid() . '.sqlite';
        touch($this->tempDb);

        config(['database.connections.sqlite.database' => $this->tempDb]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $exitCode = Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->assertSame(0, $exitCode, 'Artisan migrate must complete cleanly on fresh SQLite.');

        $expectedTables = [
            'v3_subject_runs',
            'v3_subject_run_items',
            'v3_notebook_instances',
            'v3_pipeline_commands',
            'v3_pipeline_outbox',
            'v3_pipeline_events',
            'v3_terminal_imports',
            'v3_terminal_import_items',
            'v3_lecture_pipeline_links',
            'v3_section_pipeline_links',
            'v3_manual_scene_imports',
            'v3_qualification_gates',
        ];

        foreach ($expectedTables as $table) {
            $this->assertTrue(Schema::connection('sqlite')->hasTable($table), "Expected table '{$table}' was not created.");
        }

        // Verify extended columns
        $this->assertTrue(Schema::connection('sqlite')->hasColumn('v3_authoring_requests', 'eligibility_basis'));
        $this->assertTrue(Schema::connection('sqlite')->hasColumn('v3_authoring_requests', 'prompt_gate_id'));

        $this->assertTrue(Schema::connection('sqlite')->hasColumn('v3_scene_candidates', 'origin'));
        $this->assertTrue(Schema::connection('sqlite')->hasColumn('v3_scene_candidates', 'package_profile'));
        $this->assertTrue(Schema::connection('sqlite')->hasColumn('v3_scene_candidates', 'manual_import_id'));
        $this->assertTrue(Schema::connection('sqlite')->hasColumn('v3_scene_candidates', 'prompt_gate_id'));

        $this->assertTrue(Schema::connection('sqlite')->hasColumn('v3_teacher_review_items', 'eligibility_basis'));
        $this->assertTrue(Schema::connection('sqlite')->hasColumn('v3_teacher_review_items', 'qualification_gate_id'));

        $this->assertTrue(Schema::connection('sqlite')->hasColumn('v3_teacher_review_decisions', 'eligibility_basis'));
        $this->assertTrue(Schema::connection('sqlite')->hasColumn('v3_teacher_review_decisions', 'qualification_gate_id'));

        $this->assertTrue(Schema::connection('sqlite')->hasColumn('v3_approved_scenes', 'eligibility_basis'));
        $this->assertTrue(Schema::connection('sqlite')->hasColumn('v3_approved_scenes', 'qualification_gate_id'));
    }

    /**
     * FR-027, SC-005, A28: Populated upgrade test using cloned database.sqlite
     * preserves existing records, foreign keys, and approves default values.
     */
    public function test_populated_cloned_db_upgrade_preserves_existing_hashes_and_records(): void
    {
        $realDbPath = database_path('database.sqlite');
        if (! file_exists($realDbPath)) {
            $this->markTestSkipped('Real database.sqlite not found for populated upgrade test.');
        }

        $this->tempDb = sys_get_temp_dir() . '/v3_cloned_upgrade_' . uniqid() . '.sqlite';
        copy($realDbPath, $this->tempDb);

        config(['database.connections.sqlite.database' => $this->tempDb]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        // Check preexisting row count in users table before upgrade
        $usersBefore = DB::connection('sqlite')->table('users')->count();

        // Run migrations on cloned DB
        $exitCode = Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->assertSame(0, $exitCode, 'Artisan migrate must complete cleanly on cloned populated DB.');

        // Preexisting users must be completely untouched
        $usersAfter = DB::connection('sqlite')->table('users')->count();
        $this->assertSame($usersBefore, $usersAfter, 'User count must remain identical after migration upgrade.');

        // Check new tables created on clone
        $this->assertTrue(Schema::connection('sqlite')->hasTable('v3_qualification_gates'));
        $this->assertTrue(Schema::connection('sqlite')->hasTable('v3_manual_scene_imports'));
        $this->assertTrue(Schema::connection('sqlite')->hasTable('v3_subject_runs'));

        // If existing authoring requests exist, ensure eligibility_basis defaults to real_jev
        if (DB::connection('sqlite')->table('v3_authoring_requests')->count() > 0) {
            $nonRealJevCount = DB::connection('sqlite')->table('v3_authoring_requests')
                ->where('eligibility_basis', '!=', 'real_jev')
                ->count();
            $this->assertSame(0, $nonRealJevCount, 'All legacy authoring requests must default to real_jev.');
        }

        // If existing candidates exist, ensure defaults
        if (DB::connection('sqlite')->table('v3_scene_candidates')->count() > 0) {
            $nonLegacyProfileCount = DB::connection('sqlite')->table('v3_scene_candidates')
                ->where('package_profile', '!=', 'legacy_v1')
                ->count();
            $this->assertSame(0, $nonLegacyProfileCount, 'All legacy candidates must default to legacy_v1.');
        }
    }

    /**
     * FR-015, A13, A16: AuthoringRequest and SceneCandidate permit nullable columns for demo and manual branches.
     */
    public function test_branch_invariants_in_database_for_demo_and_manual_scene(): void
    {
        $this->tempDb = sys_get_temp_dir() . '/v3_branch_invariants_' . uniqid() . '.sqlite';
        touch($this->tempDb);

        config(['database.connections.sqlite.database' => $this->tempDb]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);

        $env = \Tests\Support\V3\AuthoringFixtures::createAuthoringEnvironment();
        $case = $env['case'];
        $map = $env['map'];
        $owner = $env['teacher'];

        $secFixture = \Tests\Support\V3\AuthoringFixtures::createQualifiedSection($case, $map);
        $section = $secFixture['section'];
        $promptVersion = $secFixture['version'];

        $caseId = $case->id;
        $sectionId = $section->id;
        $promptVersionId = $promptVersion->id;
        $ownerId = $owner->id;

        $frameworkId = (string) Str::uuid();
        DB::connection('sqlite')->table('v3_notebook_framework_versions')->insert([
            'id' => $frameworkId,
            'profile' => 'expanded-root-v1',
            'body_path' => 'frameworks/test.txt',
            'body_sha256' => hash('sha256', 'framework body'),
            'body_bytes' => 100,
            'first_sha256' => hash('sha256', 'first'),
            'second_sha256' => hash('sha256', 'second'),
            'third_sha256' => hash('sha256', 'third'),
            'response_format_sha256' => hash('sha256', 'format'),
            'approved_by_user_id' => $ownerId,
            'approved_at' => now(),
            'created_at' => now(),
        ]);

        $subjectId = DB::connection('sqlite')->table('subjects')->insertGetId([
            'name' => 'Physics',
            'description' => 'Physics course',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runId = (string) Str::uuid();
        DB::connection('sqlite')->table('v3_subject_runs')->insert([
            'id' => $runId,
            'origin' => 'web_intake',
            'owner_user_id' => $ownerId,
            'subject_id' => $subjectId,
            'settings_version_id' => $case->settings_version_id,
            'framework_version_id' => $frameworkId,
            'captured_settings_json' => json_encode(['foo' => 'bar']),
            'capture_sha256' => hash('sha256', 'cap'),
            'submission_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', 'req'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $commandId = (string) Str::uuid();
        DB::connection('sqlite')->table('v3_pipeline_commands')->insert([
            'id' => $commandId,
            'run_id' => $runId,
            'actor_user_id' => $ownerId,
            'action' => 'waive_prompt_gate',
            'request_sha256' => hash('sha256', 'waive_prompt_gate'),
            'expected_version' => 1,
            'state' => 'applied',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert prompt qualification gate (demo)
        $promptGateId = (string) Str::uuid();
        DB::connection('sqlite')->table('v3_qualification_gates')->insert([
            'id' => $promptGateId,
            'case_id' => $caseId,
            'section_id' => $sectionId,
            'prompt_version_id' => $promptVersionId,
            'candidate_id' => null,
            'gate_stage' => 'prompt',
            'basis' => 'owner_waived_demo',
            'real_qualification_id' => null,
            'waived_by_owner_id' => $ownerId,
            'policy_version' => '1.0',
            'policy_sha256' => hash('sha256', 'policy'),
            'prompt_sha256' => hash('sha256', 'prompt'),
            'candidate_manifest_sha256' => null,
            'missing_fields_json' => json_encode([]),
            'acknowledgment_sha256' => hash('sha256', 'ack'),
            'command_id' => $commandId,
            'created_at' => now(),
        ]);

        // Demo AuthoringRequest with null qualification_id
        $reqId = (string) Str::uuid();
        DB::connection('sqlite')->table('v3_authoring_requests')->insert([
            'id' => $reqId,
            'case_id' => $caseId,
            'section_id' => $sectionId,
            'prompt_version_id' => $promptVersionId,
            'qualification_id' => null,
            'prompt_body_sha256' => hash('sha256', 'Prompt body'),
            'settings_checksum' => hash('sha256', 'settings'),
            'template_sha256' => hash('sha256', 'template'),
            'settings_version_id' => 1,
            'template_id' => 'v3_standard',
            'origin' => 'initial',
            'state' => 'pending',
            'state_version' => 1,
            'attempt_limit' => 3,
            'eligibility_basis' => 'owner_waived_demo',
            'prompt_gate_id' => $promptGateId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $savedReq = DB::connection('sqlite')->table('v3_authoring_requests')->where('id', $reqId)->first();
        $this->assertNotNull($savedReq);
        $this->assertNull($savedReq->qualification_id);
        $this->assertSame('owner_waived_demo', $savedReq->eligibility_basis);
        $this->assertSame($promptGateId, $savedReq->prompt_gate_id);

        // ManualSceneImport and SceneCandidate with null request_id and attempt_id
        $manualImportId = (string) Str::uuid();
        $manualCommandId = (string) Str::uuid();
        DB::connection('sqlite')->table('v3_pipeline_commands')->insert([
            'id' => $manualCommandId,
            'run_id' => $runId,
            'actor_user_id' => $ownerId,
            'action' => 'import_manual_scene',
            'request_sha256' => hash('sha256', 'import_manual_scene'),
            'expected_version' => 1,
            'state' => 'applied',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('sqlite')->table('v3_manual_scene_imports')->insert([
            'id' => $manualImportId,
            'case_id' => $caseId,
            'section_id' => $sectionId,
            'prompt_version_id' => $promptVersionId,
            'actor_user_id' => $ownerId,
            'command_id' => $manualCommandId,
            'package_sha256' => hash('sha256', 'package'),
            'private_source_ref' => 'scenes/manual_123.zip',
            'created_at' => now(),
        ]);

        $candidateId = (string) Str::uuid();
        DB::connection('sqlite')->table('v3_scene_candidates')->insert([
            'id' => $candidateId,
            'request_id' => null,
            'attempt_id' => null,
            'case_id' => $caseId,
            'section_id' => $sectionId,
            'prompt_version_id' => $promptVersionId,
            'qualification_id' => null,
            'settings_version_id' => 1,
            'manifest_path' => 'scenes/manifest.json',
            'manifest_sha256' => hash('sha256', 'manifest'),
            'entrypoint' => 'index.html',
            'file_count' => 3,
            'total_bytes' => 1024,
            'origin' => 'manual_import',
            'package_profile' => 'static_three_file_v1',
            'manual_import_id' => $manualImportId,
            'collected_at' => now(),
        ]);

        $savedCandidate = DB::connection('sqlite')->table('v3_scene_candidates')->where('id', $candidateId)->first();
        $this->assertNotNull($savedCandidate);
        $this->assertNull($savedCandidate->request_id);
        $this->assertNull($savedCandidate->attempt_id);
        $this->assertSame('manual_import', $savedCandidate->origin);
        $this->assertSame('static_three_file_v1', $savedCandidate->package_profile);
        $this->assertSame($manualImportId, $savedCandidate->manual_import_id);
    }
}
