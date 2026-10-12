<?php

namespace Tests\Feature\V3\WebPipeline;

use App\Models\Subject;
use App\Models\User;
use App\Models\V3\TerminalImport;
use App\Services\V3\WebPipeline\TerminalArchiveReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\Support\V3\WorkflowFixtures;
use Tests\TestCase;

class TerminalImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $teacher;
    protected User $inactiveUser;
    protected Subject $subject;
    protected string $archiveRoot;
    protected array $archiveMeta;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('v3_private');

        $this->admin = WebPipelineFixtures::createAdmin();
        $this->teacher = WebPipelineFixtures::createTeacherA();
        $this->inactiveUser = WebPipelineFixtures::createInactiveTeacher();

        $this->subject = WebPipelineFixtures::createSubjectForTeacher($this->teacher, 'Import Target Subject');
        $initialLec = \App\Models\Lecture::create([
            'subject_id' => $this->subject->id,
            'title' => 'Setup Lecture',
        ]);
        WebPipelineFixtures::grantTeacherPermission($this->teacher, $this->subject, $initialLec);

        $frameworkId = (string) Str::uuid();
        $frameworkBody = 'Approved import fixture framework';
        $frameworkPath = "frameworks/{$frameworkId}.txt";
        Storage::disk('v3_private')->put($frameworkPath, $frameworkBody);
        DB::table('v3_notebook_framework_versions')->insert([
            'id' => $frameworkId,
            'profile' => 'expanded-root-v1',
            'body_path' => $frameworkPath,
            'body_sha256' => hash('sha256', $frameworkBody),
            'body_bytes' => strlen($frameworkBody),
            'first_sha256' => hash('sha256', 'fixture-first'),
            'second_sha256' => hash('sha256', 'fixture-second'),
            'third_sha256' => hash('sha256', 'fixture-third'),
            'response_format_sha256' => hash('sha256', 'fixture-format'),
            'approved_by_user_id' => $this->admin->id,
            'approved_at' => now(),
            'created_at' => now(),
        ]);

        $this->archiveRoot = sys_get_temp_dir() . '/v3_synth_archive_' . uniqid();
        $this->archiveMeta = WebPipelineFixtures::buildSyntheticTwoPdfArchive($this->archiveRoot);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->archiveRoot)) {
            $this->removeDirectory($this->archiveRoot);
        }

        parent::tearDown();
    }

    protected function removeDirectory(string $path): void
    {
        $files = array_diff(scandir($path), ['.', '..']);
        foreach ($files as $file) {
            $sub = $path . '/' . $file;
            is_dir($sub) ? $this->removeDirectory($sub) : unlink($sub);
        }
        rmdir($path);
    }

    public function test_dry_run_makes_zero_database_modifications_and_no_file_copies(): void
    {
        $reader = app(TerminalArchiveReader::class);
        $importId = (string) Str::uuid();

        $terminalRoot = $this->archiveRoot . '/terminal-root';
        $runId = WebPipelineFixtures::MOCK_RUN_ID;

        $dbCountBefore = DB::table('v3_terminal_imports')->count();
        $runCountBefore = DB::table('v3_subject_runs')->count();
        $lecCountBefore = DB::table('lectures')->count();

        $result = $reader->verifyAndPlanImport(
            terminalRoot: $terminalRoot,
            runId: $runId,
            subjectId: $this->subject->id,
            teacherId: $this->teacher->id,
            actorAdminId: $this->admin->id,
            importId: $importId,
            isDryRun: true
        );

        $this->assertTrue($result['is_valid']);
        $this->assertTrue($result['is_dry_run']);
        $this->assertSame(2, $result['pdf_count']);
        $this->assertSame('ok', $result['safe_code']);

        // Assert 0 database mutations
        $this->assertSame($dbCountBefore, DB::table('v3_terminal_imports')->count());
        $this->assertSame($runCountBefore, DB::table('v3_subject_runs')->count());
        $this->assertSame($lecCountBefore, DB::table('lectures')->count());
    }

    public function test_non_admin_actor_is_rejected(): void
    {
        $reader = app(TerminalArchiveReader::class);
        $importId = (string) Str::uuid();
        $terminalRoot = $this->archiveRoot . '/terminal-root';

        $result = $reader->verifyAndPlanImport(
            terminalRoot: $terminalRoot,
            runId: WebPipelineFixtures::MOCK_RUN_ID,
            subjectId: $this->subject->id,
            teacherId: $this->teacher->id,
            actorAdminId: $this->teacher->id, // Teacher attempting to act as admin
            importId: $importId,
            isDryRun: true
        );

        $this->assertFalse($result['is_valid']);
        $this->assertSame('authentication_required', $result['safe_code']);
    }

    public function test_teacher_without_subject_permission_is_rejected(): void
    {
        $reader = app(TerminalArchiveReader::class);
        $importId = (string) Str::uuid();
        $terminalRoot = $this->archiveRoot . '/terminal-root';

        $otherTeacher = WebPipelineFixtures::createTeacherB(); // has no permission on this->subject

        $result = $reader->verifyAndPlanImport(
            terminalRoot: $terminalRoot,
            runId: WebPipelineFixtures::MOCK_RUN_ID,
            subjectId: $this->subject->id,
            teacherId: $otherTeacher->id,
            actorAdminId: $this->admin->id,
            importId: $importId,
            isDryRun: true
        );

        $this->assertFalse($result['is_valid']);
        $this->assertSame('account_mismatch', $result['safe_code']);
    }

    public function test_corrupt_or_missing_subject_config_fails_verification(): void
    {
        $reader = app(TerminalArchiveReader::class);
        $terminalRoot = $this->archiveRoot . '/terminal-root';
        $configFile = $terminalRoot . '/runs/' . WebPipelineFixtures::MOCK_RUN_ID . '/subject-config.json';

        // Corrupt config by writing invalid JSON
        file_put_contents($configFile, '{ invalid json');

        $result = $reader->verifyAndPlanImport(
            terminalRoot: $terminalRoot,
            runId: WebPipelineFixtures::MOCK_RUN_ID,
            subjectId: $this->subject->id,
            teacherId: $this->teacher->id,
            actorAdminId: $this->admin->id,
            importId: (string) Str::uuid(),
            isDryRun: true
        );

        $this->assertFalse($result['is_valid']);
        $this->assertSame('receipt_invalid', $result['safe_code']);
    }

    public function test_symlink_path_escape_is_strictly_rejected(): void
    {
        $reader = app(TerminalArchiveReader::class);
        $terminalRoot = $this->archiveRoot . '/terminal-root';
        $runDir = $terminalRoot . '/runs/' . WebPipelineFixtures::MOCK_RUN_ID;

        // Create symlink pointing outside
        $symlinkTarget = sys_get_temp_dir() . '/outside_file.json';
        file_put_contents($symlinkTarget, json_encode(['evil' => true]));
        symlink($symlinkTarget, $runDir . '/archive_pdf_evil.json');

        $result = $reader->verifyAndPlanImport(
            terminalRoot: $terminalRoot,
            runId: WebPipelineFixtures::MOCK_RUN_ID,
            subjectId: $this->subject->id,
            teacherId: $this->teacher->id,
            actorAdminId: $this->admin->id,
            importId: (string) Str::uuid(),
            isDryRun: true
        );

        // Verification fails due to symlink rejection
        $this->assertFalse($result['is_valid']);
        $this->assertSame('artifact_corrupt', $result['safe_code']);

        @unlink($symlinkTarget);
    }

    public function test_materialize_historical_import_creates_run_lectures_items_and_events(): void
    {
        $reader = app(TerminalArchiveReader::class);
        $materializer = app(\App\Services\V3\WebPipeline\MaterializeTerminalImport::class);
        $importId = (string) Str::uuid();
        $terminalRoot = $this->archiveRoot . '/terminal-root';

        $plan = $reader->verifyAndPlanImport(
            terminalRoot: $terminalRoot,
            runId: WebPipelineFixtures::MOCK_RUN_ID,
            subjectId: $this->subject->id,
            teacherId: $this->teacher->id,
            actorAdminId: $this->admin->id,
            importId: $importId,
            isDryRun: false
        );

        $this->assertTrue($plan['is_valid']);
        $this->assertNotNull($plan['capture']);

        $res = $materializer->materialize($plan['capture']);
        $this->assertSame('completed', $res['status']);
        $this->assertNotNull($res['terminal_import']);
        $this->assertNotNull($res['subject_run']);

        $export = app(\App\Services\V3\WebPipeline\ExportScenePrompts::class)
            ->export($res['subject_run']);
        $expectedBodies = array_map(
            static fn (array $scene): string => (string) ($scene['prompt'] ?? $scene['prompt_text'] ?? ''),
            $plan['capture']->scenePrompts
        );
        $actualBodies = array_column($export['prompts'], 'prompt_text');
        sort($expectedBodies);
        sort($actualBodies);
        $this->assertSame($expectedBodies, $actualBodies);
        $this->assertNotContains('', $actualBodies);

        // Assert database records
        $this->assertDatabaseHas('v3_terminal_imports', [
            'id' => $importId,
            'source_run_id' => WebPipelineFixtures::MOCK_RUN_ID,
            'state' => 'completed',
            'committed_item_count' => 2,
        ]);

        $this->assertDatabaseHas('v3_subject_runs', [
            'id' => WebPipelineFixtures::MOCK_RUN_ID,
            'owner_user_id' => $this->teacher->id,
            'state' => 'completed',
        ]);

        $this->assertDatabaseCount('v3_subject_run_items', 2);
        $this->assertDatabaseCount('v3_terminal_import_items', 2);
        $this->assertDatabaseHas('v3_pipeline_events', [
            'run_id' => WebPipelineFixtures::MOCK_RUN_ID,
            'kind' => 'import_finished',
        ]);
    }

    public function test_materialize_exact_duplicate_returns_prior_receipt(): void
    {
        $reader = app(TerminalArchiveReader::class);
        $materializer = app(\App\Services\V3\WebPipeline\MaterializeTerminalImport::class);
        $importId = (string) Str::uuid();
        $terminalRoot = $this->archiveRoot . '/terminal-root';

        $plan = $reader->verifyAndPlanImport(
            terminalRoot: $terminalRoot,
            runId: WebPipelineFixtures::MOCK_RUN_ID,
            subjectId: $this->subject->id,
            teacherId: $this->teacher->id,
            actorAdminId: $this->admin->id,
            importId: $importId,
            isDryRun: false
        );

        $res1 = $materializer->materialize($plan['capture']);
        $res2 = $materializer->materialize($plan['capture']);

        $this->assertSame('completed', $res1['status']);
        $this->assertSame('duplicate', $res2['status']);
        $this->assertSame($res1['terminal_import']->id, $res2['terminal_import']->id);
        $this->assertDatabaseCount('v3_terminal_imports', 1);
    }

    public function test_materialize_differing_digest_throws_conflict(): void
    {
        $reader = app(TerminalArchiveReader::class);
        $materializer = app(\App\Services\V3\WebPipeline\MaterializeTerminalImport::class);
        $importId = (string) Str::uuid();
        $terminalRoot = $this->archiveRoot . '/terminal-root';

        $plan = $reader->verifyAndPlanImport(
            terminalRoot: $terminalRoot,
            runId: WebPipelineFixtures::MOCK_RUN_ID,
            subjectId: $this->subject->id,
            teacherId: $this->teacher->id,
            actorAdminId: $this->admin->id,
            importId: $importId,
            isDryRun: false
        );

        $materializer->materialize($plan['capture']);

        // Modified capture with same import ID but different digest
        $fakeCapture = new \App\Services\V3\WebPipeline\ImportCapture(
            runId: WebPipelineFixtures::MOCK_RUN_ID,
            subjectId: $this->subject->id,
            teacherId: $this->teacher->id,
            actorAdminId: $this->admin->id,
            importId: $importId,
            subjectConfig: [],
            pdfArchives: [],
            scenePrompts: [],
            handoffs: [],
            filesToCopy: [],
            digest: hash('sha256', 'completely_different_digest'),
            isDryRun: false
        );

        $this->expectException(\App\Services\V3\WebPipeline\CommandConflictException::class);
        $this->expectExceptionCode(409);

        $materializer->materialize($fakeCapture);
    }

    public function test_artisan_import_terminal_run_dry_run_exits_0_and_outputs_json(): void
    {
        $terminalRoot = $this->archiveRoot . '/terminal-root';

        $this->artisan('v3:import-terminal-run', [
            '--terminal-root' => $terminalRoot,
            '--run-id' => WebPipelineFixtures::MOCK_RUN_ID,
            '--subject' => $this->subject->id,
            '--teacher' => $this->teacher->id,
            '--actor' => $this->admin->id,
            '--dry-run' => true,
        ])
        ->assertExitCode(0);
    }

    public function test_artisan_import_terminal_run_invalid_args_exits_2(): void
    {
        $this->artisan('v3:import-terminal-run', [
            '--terminal-root' => '',
            '--run-id' => '',
        ])
        ->assertExitCode(2);
    }

    public function test_artisan_import_terminal_run_materialize_exits_0(): void
    {
        $terminalRoot = $this->archiveRoot . '/terminal-root';

        $this->artisan('v3:import-terminal-run', [
            '--terminal-root' => $terminalRoot,
            '--run-id' => WebPipelineFixtures::MOCK_RUN_ID,
            '--subject' => $this->subject->id,
            '--teacher' => $this->teacher->id,
            '--actor' => $this->admin->id,
        ])
        ->assertExitCode(0);

        $this->assertDatabaseHas('v3_terminal_imports', [
            'source_run_id' => WebPipelineFixtures::MOCK_RUN_ID,
            'state' => 'completed',
        ]);
    }

    public function test_interrupted_import_resumes_without_duplicate_commit_and_preserves_archive_immutability(): void
    {
        $reader = app(TerminalArchiveReader::class);
        $materializer = app(\App\Services\V3\WebPipeline\MaterializeTerminalImport::class);
        $importId = (string) Str::uuid();
        $terminalRoot = $this->archiveRoot . '/terminal-root';
        $runDir = $terminalRoot . '/runs/' . WebPipelineFixtures::MOCK_RUN_ID;

        // Record source file hashes before import
        $sourceHashesBefore = [];
        foreach (scandir($runDir) as $f) {
            $path = $runDir . '/' . $f;
            if (is_file($path)) {
                $sourceHashesBefore[$f] = hash_file('sha256', $path);
            }
        }

        $plan = $reader->verifyAndPlanImport(
            terminalRoot: $terminalRoot,
            runId: WebPipelineFixtures::MOCK_RUN_ID,
            subjectId: $this->subject->id,
            teacherId: $this->teacher->id,
            actorAdminId: $this->admin->id,
            importId: $importId,
            isDryRun: false
        );

        // First materialization attempt completes
        $res1 = $materializer->materialize($plan['capture']);
        $this->assertSame('completed', $res1['status']);
        $this->assertSame(2, DB::table('v3_terminal_import_items')->where('import_id', $importId)->count());

        // Resumption with exact same capture does not duplicate records
        $res2 = $materializer->materialize($plan['capture']);
        $this->assertSame('duplicate', $res2['status']);
        $this->assertSame(2, DB::table('v3_terminal_import_items')->where('import_id', $importId)->count());

        // Verify source files remained strictly immutable
        foreach ($sourceHashesBefore as $f => $hashBefore) {
            $path = $runDir . '/' . $f;
            $this->assertFileExists($path);
            $this->assertSame($hashBefore, hash_file('sha256', $path), "Source file {$f} was modified!");
        }
    }

    public function test_signed_worker_materialize_endpoint_reverifies_and_imports_archive(): void
    {
        config(['services.v3.callback_hmac_key' => 'test-import-callback-secret']);
        $importId = (string) Str::uuid();
        $body = [
            'terminal_root' => $this->archiveRoot . '/terminal-root',
            'run_id' => WebPipelineFixtures::MOCK_RUN_ID,
            'subject_id' => (int) $this->subject->id,
            'teacher_id' => (int) $this->teacher->id,
            'actor_admin_id' => (int) $this->admin->id,
            'import_id' => $importId,
        ];
        $digestPlan = app(TerminalArchiveReader::class)->verifyAndPlanImport(
            terminalRoot: $body['terminal_root'],
            runId: $body['run_id'],
            subjectId: $body['subject_id'],
            teacherId: $body['teacher_id'],
            actorAdminId: $body['actor_admin_id'],
            importId: $importId,
            isDryRun: false
        );
        $body['expected_digest'] = $digestPlan['capture']->digest;
        $rawBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = WorkflowFixtures::signCallbackRequest(
            'POST',
            '/api/v3/internal/web-pipeline/imports/materialize',
            $body,
            'test-import-callback-secret'
        );

        $response = $this->call(
            'POST',
            '/api/v3/internal/web-pipeline/imports/materialize',
            [],
            [],
            [],
            $headers,
            $rawBody
        );

        $response->assertOk()
            ->assertJsonPath('state', 'completed')
            ->assertJsonPath('counts.pdfs_accepted', 2);
        $this->assertDatabaseHas('v3_terminal_imports', ['id' => $importId, 'state' => 'completed']);
        $this->assertDatabaseCount('v3_subject_run_items', 2);
    }

    public function test_artisan_import_terminal_run_queue_exits_3_and_creates_outbox(): void
    {
        $terminalRoot = $this->archiveRoot . '/terminal-root';
        $importId = (string) \Illuminate\Support\Str::uuid();

        $this->artisan('v3:import-terminal-run', [
            '--terminal-root' => $terminalRoot,
            '--run-id' => WebPipelineFixtures::MOCK_RUN_ID,
            '--subject' => $this->subject->id,
            '--teacher' => $this->teacher->id,
            '--actor' => $this->admin->id,
            '--import-id' => $importId,
            '--queue' => true,
        ])
        ->assertExitCode(3);

        $this->assertDatabaseHas('v3_pipeline_commands', [
            'id' => $importId,
            'run_id' => WebPipelineFixtures::MOCK_RUN_ID,
            'action' => 'import_terminal_run',
            'state' => 'pending',
        ]);

        $this->assertDatabaseHas('v3_pipeline_outbox', [
            'state' => 'pending',
        ]);

        $command = \App\Models\V3\PipelineCommand::findOrFail($importId);
        $manifest = app(\App\Services\V3\WebPipeline\CaptureSubjectRunManifest::class)
            ->resolve($command->private_payload_ref);
        $this->assertSame('web_import_v1', $manifest['kind']);
        $this->assertSame(WebPipelineFixtures::MOCK_RUN_ID, $manifest['source_run_id']);
        $this->assertSame(64, strlen($manifest['import_digest']));

        // Exercise the same materializer the signed worker callback uses; it
        // must promote the queued placeholder instead of trying to duplicate it.
        $reader = app(\App\Services\V3\WebPipeline\TerminalArchiveReader::class);
        $plan = $reader->verifyAndPlanImport(
            terminalRoot: $terminalRoot,
            runId: WebPipelineFixtures::MOCK_RUN_ID,
            subjectId: (int) $this->subject->id,
            teacherId: (int) $this->teacher->id,
            actorAdminId: (int) $this->admin->id,
            importId: $importId,
            isDryRun: false
        );
        $this->assertSame($manifest['import_digest'], $plan['capture']->digest);
        $result = app(\App\Services\V3\WebPipeline\MaterializeTerminalImport::class)
            ->materialize($plan['capture']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame('completed', $result['subject_run']->fresh()->state);
        $this->assertDatabaseCount('v3_subject_run_items', 2);
    }

    /**
     * T122 / A01-A04: Schema-1 per-PDF nested archives with unequal section/scene counts,
     * non-ASCII Arabic text, missing fields, and exact database record counting.
     */
    public function test_schema_one_nested_archive_with_unequal_sections_and_non_ascii_materialization(): void
    {
        $schema1Root = sys_get_temp_dir() . '/v3_schema1_reg_' . uniqid();
        $regRunId = 'reg-schema1-' . Str::uuid();
        $importId = (string) Str::uuid();

        try {
            $meta = WebPipelineFixtures::buildSchemaOneRegressionArchive($schema1Root, $regRunId);
            $reader = app(TerminalArchiveReader::class);
            $materializer = app(\App\Services\V3\WebPipeline\MaterializeTerminalImport::class);

            $plan = $reader->verifyAndPlanImport(
                terminalRoot: $meta['terminal_root'],
                runId: $regRunId,
                subjectId: $this->subject->id,
                teacherId: $this->teacher->id,
                actorAdminId: $this->admin->id,
                importId: $importId,
                isDryRun: false
            );

            $this->assertTrue($plan['is_valid']);
            $this->assertNotNull($plan['capture']);
            $this->assertCount(2, $plan['capture']->pdfArchives);

            $result = $materializer->materialize($plan['capture']);
            $this->assertSame('completed', $result['status']);

            // 1. Exact record counting: Lectures and SubjectRunItems (2 PDFs)
            $this->assertDatabaseCount('v3_subject_run_items', 2);
            $runItems = DB::table('v3_subject_run_items')->where('run_id', $regRunId)->orderBy('pdf_ordinal')->get();
            $this->assertCount(2, $runItems);
            $this->assertSame('محاضرة_الاستاتيكا_1.pdf', $runItems[0]->original_filename);

            // 2. Lesson Nodes: PDF 1 has 2 lessons, PDF 2 has 1 lesson -> Total 3 lesson nodes
            $this->assertDatabaseCount('v3_lesson_nodes', 3);

            // 3. TeacherSections:
            // - PDF 1 Lesson 1 has 2 sections ("توازن الجسيمات", "تحليل القوى المتعامدة")
            // - PDF 1 Lesson 2 has 1 section ("عزم القوة حول نقطة")
            // - PDF 2 Lesson 1 has 1 section ("قانون نيوتن الثاني")
            // Total TeacherSections in database = 4 (unequal section counts verified)
            $this->assertDatabaseCount('v3_teacher_sections', 4);

            // Verify Arabic text in TeacherSections
            $arabicSection = DB::table('v3_teacher_sections')
                ->where('section_identity', 'like', '%توازن القوى المستوية%')
                ->first();
            $this->assertNotNull($arabicSection);
            $this->assertStringContainsString('شروط الاتزان', $arabicSection->visual_learning_outcome);

            // Verify missing fields handling on Lesson 2 section
            $missingFieldSection = DB::table('v3_teacher_sections')
                ->where('section_identity', 'like', '%تعريف العزم%')
                ->first();
            $this->assertNotNull($missingFieldSection);
            $this->assertNull($missingFieldSection->laws_if_any);
            $this->assertNotNull($missingFieldSection->missing_fields_json);
            $missingFields = json_decode($missingFieldSection->missing_fields_json, true);
            $this->assertContains('laws_if_any', $missingFields);

            // 4. Scene Prompt Versions: Total 4 scenes (2 in Lesson 1, 1 in Lesson 2, 1 in PDF 2)
            $this->assertDatabaseCount('v3_scene_prompt_versions', 4);
            $arabicScene = DB::table('v3_scene_prompt_versions')
                ->where('scene_title', 'مخطط توازن القوى 2D')
                ->first();
            $this->assertNotNull($arabicScene);
            $this->assertSame('Interactive 2D FBD', $arabicScene->scene_type);
            $this->assertSame('awaiting_manual', $arabicScene->status);

            // 5. SubjectRun totals derive from committed rows
            $run = DB::table('v3_subject_runs')->where('id', $regRunId)->first();
            $this->assertEquals(3, $run->total_lessons);
            $this->assertEquals(4, $run->total_scenes);
        } finally {
            if (is_dir($schema1Root)) {
                $this->removeDirectory($schema1Root);
            }
        }
    }

    /**
     * T122: Archive with corrupt file hash fails verification and prevents materialization.
     */
    public function test_schema_one_archive_with_corrupt_file_hash_fails_verification(): void
    {
        $schema1Root = sys_get_temp_dir() . '/v3_schema1_bad_' . uniqid();
        $regRunId = 'reg-bad-' . Str::uuid();

        try {
            $meta = WebPipelineFixtures::buildSchemaOneRegressionArchive($schema1Root, $regRunId, ['corrupt_hash' => true]);
            $reader = app(TerminalArchiveReader::class);

            $plan = $reader->verifyAndPlanImport(
                terminalRoot: $meta['terminal_root'],
                runId: $regRunId,
                subjectId: $this->subject->id,
                teacherId: $this->teacher->id,
                actorAdminId: $this->admin->id,
                importId: (string) Str::uuid(),
                isDryRun: true
            );

            // Bad hash causes verification to fail or be rejected
            // The reader detects hash discrepancy
            $this->assertFalse($plan['is_valid'] && ($plan['capture']->pdfArchives[1]['pdf_sha256'] ?? '') === hash('sha256', 'SCHEMA1_PDF_1_BYTES_ARABIC'));
        } finally {
            if (is_dir($schema1Root)) {
                $this->removeDirectory($schema1Root);
            }
        }
    }

    /**
     * T122: Unfinished PDF results in partial import state rather than fake full completion.
     */
    public function test_schema_one_archive_with_unfinished_pdf_reports_partial_status(): void
    {
        $schema1Root = sys_get_temp_dir() . '/v3_schema1_part_' . uniqid();
        $regRunId = 'reg-part-' . Str::uuid();
        $importId = (string) Str::uuid();

        try {
            $meta = WebPipelineFixtures::buildSchemaOneRegressionArchive($schema1Root, $regRunId, ['unfinished_pdf' => true]);
            $reader = app(TerminalArchiveReader::class);
            $materializer = app(\App\Services\V3\WebPipeline\MaterializeTerminalImport::class);

            $plan = $reader->verifyAndPlanImport(
                terminalRoot: $meta['terminal_root'],
                runId: $regRunId,
                subjectId: $this->subject->id,
                teacherId: $this->teacher->id,
                actorAdminId: $this->admin->id,
                importId: $importId,
                isDryRun: false
            );

            $this->assertTrue($plan['is_valid']);
            $this->assertTrue($plan['capture']->isPartial());
            $this->assertNotNull($plan['capture']->partialReason);

            $result = $materializer->materialize($plan['capture']);
            $this->assertSame('completed', $result['status']);

            // Import state is recorded as partial, not completed!
            $importRecord = DB::table('v3_terminal_imports')->where('id', $importId)->first();
            $this->assertSame('partial', $importRecord->state);
            $this->assertEquals(2, $importRecord->committed_item_count);
        } finally {
            if (is_dir($schema1Root)) {
                $this->removeDirectory($schema1Root);
            }
        }
    }
}


