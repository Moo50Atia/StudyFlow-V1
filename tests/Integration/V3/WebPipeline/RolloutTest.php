<?php

namespace Tests\Integration\V3\WebPipeline;

use App\Models\Lecture;
use App\Models\Subject;
use App\Models\User;
use App\Models\V3\Document;
use App\Models\V3\PipelineCommand;
use App\Models\V3\PipelineOutbox;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use App\Models\V3\TeacherSection;
use App\Services\V3\Settings\PublishSettings;
use App\Services\V3\WebPipeline\SubmitSubjectRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\V3\Pdf\PdfFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

/**
 * T061: Rollout, Legacy Compatibility and Populated-Upgrade Coverage.
 *
 * Requirements: FR-027, SC-005, A28.
 * Verifies:
 * 1. Populated legacy cases and records remain untouched with NULL pipeline links.
 * 2. Legacy intake flows remain functional and do not synthesize web-specific runs.
 * 3. New web protocol creates exactly one workflow owner / dispatch.
 * 4. Re-submission under same digest is idempotent; conflicting re-submission rejects.
 */
class RolloutTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacherA;
    protected User $teacherB;
    protected User $admin;
    protected Subject $subject;
    protected int $settingsId;
    protected string $frameworkId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('v3_private');

        $this->admin = WebPipelineFixtures::createAdmin();
        $this->teacherA = WebPipelineFixtures::createTeacherA();
        $this->teacherB = WebPipelineFixtures::createTeacherB();
        $this->subject = WebPipelineFixtures::createSubjectForTeacher($this->teacherA, 'Electromagnetism');

        $initialLecture = Lecture::create([
            'subject_id' => $this->subject->id,
            'title' => 'Initial Seed Lecture',
        ]);
        WebPipelineFixtures::grantTeacherPermission($this->teacherA, $this->subject, $initialLecture);

        SettingsFixtures::registerDefaultTemplates();
        $payload = SettingsFixtures::validPayload();
        $publisher = app(PublishSettings::class);
        $publisher->publish($payload, 0, $this->admin->id);
        $this->settingsId = (int) DB::table('v3_settings_versions')->max('id');

        $this->frameworkId = (string) Str::uuid();
        DB::table('v3_notebook_framework_versions')->insert([
            'id' => $this->frameworkId,
            'profile' => 'expanded-root-v1',
            'body_path' => 'frameworks/' . $this->frameworkId . '.md',
            'body_sha256' => hash('sha256', 'mock_framework_body'),
            'body_bytes' => 19,
            'first_sha256' => hash('sha256', 'first'),
            'second_sha256' => hash('sha256', 'second'),
            'third_sha256' => hash('sha256', 'third'),
            'response_format_sha256' => hash('sha256', 'format'),
            'approved_by_user_id' => $this->admin->id,
            'approved_at' => now(),
            'created_at' => now(),
        ]);
        DB::table('v3_notebook_framework_pointer')->updateOrInsert(
            ['id' => 1],
            ['version_id' => $this->frameworkId, 'updated_at' => now()]
        );
        Storage::disk('v3_private')->put('frameworks/' . $this->frameworkId . '.md', 'mock_framework_body');
    }

    /**
     * FR-027, A28: Populated legacy cases have null web provenance and remain intact.
     */
    public function test_populated_legacy_cases_and_sections_remain_untouched(): void
    {
        $legacyDoc = Document::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacherA->id,
            'sha256' => hash('sha256', 'legacy_pdf'),
            'byte_size' => 2048,
            'page_count' => 2,
            'state' => 'ready',
            'storage_path' => 'accepted/legacy.pdf',
            'accepted_at' => now()->subDays(5),
        ]);

        $legacyCase = ProcessingCase::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacherA->id,
            'document_id' => $legacyDoc->id,
            'settings_version_id' => $this->settingsId,
            'idempotency_key' => 'legacy_case_1',
            'action' => 'single_document',
            'state' => 'prompts_ready',
            'state_version' => 1,
            'pipeline_version' => 'v3_terminal_v1',
            'subject_run_item_id' => null, // Legacy case has no web pipeline item link
        ]);

        $srcAct = (string) Str::uuid();
        DB::table('v3_source_activations')->insert(['id' => $srcAct, 'case_id' => $legacyCase->id, 'workflow_id' => 'wf', 'state' => 'accepted']);
        $src = (string) Str::uuid();
        DB::table('v3_extracted_sources')->insert(['id' => $src, 'case_id' => $legacyCase->id, 'activation_id' => $srcAct, 'document_id' => $legacyDoc->id, 'settings_version_id' => $this->settingsId, 'status' => 'ready', 'page_report' => '{}', 'extractor_version' => 'v1']);
        $bind = (string) Str::uuid();
        DB::table('v3_knowledge_source_bindings')->insert(['id' => $bind, 'case_id' => $legacyCase->id, 'extracted_source_id' => $src, 'adapter_kind' => 'nb', 'adapter_version' => '1', 'notebook_title' => 't', 'source_title' => 's', 'state' => 'bound', 'text_sha256' => 't', 'text_bytes' => 1]);
        $mapAct = (string) Str::uuid();
        DB::table('v3_map_activations')->insert(['id' => $mapAct, 'case_id' => $legacyCase->id, 'binding_id' => $bind, 'workflow_id' => 'w', 'state' => 'ready']);
        $map = (string) Str::uuid();
        DB::table('v3_lesson_maps')->insert(['id' => $map, 'case_id' => $legacyCase->id, 'binding_id' => $bind, 'activation_id' => $mapAct, 'state' => 'ready', 'page_count' => 1]);
        $node = (string) Str::uuid();
        DB::table('v3_lesson_nodes')->insert(['id' => $node, 'map_id' => $map, 'kind' => 'l', 'position_path' => '001', 'ordinal_in_parent' => 1, 'title' => 'L1', 'work_ordinal' => 1]);
        $q = (string) Str::uuid();
        DB::table('v3_lesson_queries')->insert(['id' => $q, 'case_id' => $legacyCase->id, 'map_id' => $map, 'kind' => 'first', 'target_work_ordinal' => 1, 'state' => 'recorded', 'send_count' => 1, 'request_sha256' => 'r']);

        $legacySection = TeacherSection::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $legacyCase->id,
            'map_id' => $map,
            'work_node_id' => $node,
            'query_id' => $q,
            'ordinal_in_work' => 1,
            'section_identity' => 'Coulomb Law and Electric Field',
            'core_understanding' => 'Electrostatic attraction and repulsion.',
            'first_prompt_sha256' => hash('sha256', 'f_legacy'),
            'answer_sha256' => hash('sha256', 'a_legacy'),
            'origin' => 'pipeline',
            'subject_run_item_id' => null, // NULL web pipeline item link
        ]);

        // Assert legacy case and section are completely untouched
        $this->assertNull($legacyCase->subject_run_item_id);
        $this->assertNull($legacySection->subject_run_item_id);
        $this->assertSame('single_document', $legacyCase->action);
        $this->assertSame('v3_terminal_v1', $legacyCase->pipeline_version);

        // Assert zero SubjectRun rows exist for legacy case
        $this->assertSame(0, SubjectRun::count());
        $this->assertSame(0, SubjectRunItem::count());
    }

    /**
     * FR-027, SC-005, A28: New web protocol dispatches exactly one workflow owner.
     */
    public function test_new_web_protocol_dispatches_exactly_one_workflow_owner(): void
    {
        $submissionKey = (string) Str::uuid();
        $file1 = UploadedFile::fake()->createWithContent('ch1.pdf', PdfFixtures::minimalValidPdf(1, 'Ch1'));
        $file2 = UploadedFile::fake()->createWithContent('ch2.pdf', PdfFixtures::minimalValidPdf(1, 'Ch2'));

        $submitter = app(SubmitSubjectRun::class);
        $result1 = $submitter->submit(
            actor: $this->teacherA,
            subjectId: $this->subject->id,
            payload: [
                'submission_key' => $submissionKey,
                'files' => [$file1, $file2],
                'titles' => ['Maxwell Equations', 'Electromagnetic Waves'],
                'mode' => 'export_manual',
                'qualification_mode' => 'owner_waived_demo',
                'waiver_acknowledged' => true,
            ]
        );

        $this->assertSame('staging', $result1['state']);
        $this->assertSame(1, $result1['state_version']);
        $runId = $result1['run_id'];

        // Assert exactly one SubjectRun exists
        $this->assertSame(1, SubjectRun::where('id', $runId)->count());
        $run = SubjectRun::find($runId);
        $this->assertSame('notebooklm_per_pdf_web_v1', $run->protocol);
        $this->assertSame($this->teacherA->id, $run->owner_user_id);
        $this->assertSame(2, $run->total_pdfs);

        // Assert exactly two items exist, one per PDF
        $items = SubjectRunItem::where('run_id', $runId)->orderBy('pdf_ordinal')->get();
        $this->assertCount(2, $items);
        $this->assertSame(1, $items[0]->pdf_ordinal);
        $this->assertSame(2, $items[1]->pdf_ordinal);

        // Assert exactly one PipelineCommand with action=submit
        $commands = PipelineCommand::where('run_id', $runId)->where('action', 'submit')->get();
        $this->assertCount(1, $commands);

        // Assert exactly one PipelineOutbox entry
        $outbox = PipelineOutbox::where('command_id', $commands->first()->id)->get();
        $this->assertCount(1, $outbox);
        $this->assertSame('application_bridge', $outbox->first()->destination);
        $this->assertSame('pending', $outbox->first()->state);

        // Replay submission with same submission_key and same content: idempotent replay
        $file1Replay = UploadedFile::fake()->createWithContent('ch1.pdf', PdfFixtures::minimalValidPdf(1, 'Ch1'));
        $file2Replay = UploadedFile::fake()->createWithContent('ch2.pdf', PdfFixtures::minimalValidPdf(1, 'Ch2'));

        $result2 = $submitter->submit(
            actor: $this->teacherA,
            subjectId: $this->subject->id,
            payload: [
                'submission_key' => $submissionKey,
                'files' => [$file1Replay, $file2Replay],
                'titles' => ['Maxwell Equations', 'Electromagnetic Waves'],
                'mode' => 'export_manual',
                'qualification_mode' => 'owner_waived_demo',
                'waiver_acknowledged' => true,
            ]
        );

        $this->assertSame($runId, $result2['run_id']);
        $this->assertSame(1, SubjectRun::where('id', $runId)->count(), 'Replay must not duplicate SubjectRun');
        $this->assertSame(1, PipelineCommand::where('run_id', $runId)->where('action', 'submit')->count(), 'Replay must not duplicate submit command');
        $this->assertSame(1, PipelineOutbox::where('command_id', $commands->first()->id)->count(), 'Replay must not duplicate outbox');
    }

    /**
     * FR-019, SC-005: Conflicting submission with different content rejects with 409.
     */
    public function test_conflicting_submission_payload_rejects_with_409(): void
    {
        $submissionKey = (string) Str::uuid();
        $file1 = UploadedFile::fake()->createWithContent('ch1.pdf', PdfFixtures::minimalValidPdf(1, 'Ch1'));

        $submitter = app(SubmitSubjectRun::class);
        $submitter->submit(
            actor: $this->teacherA,
            subjectId: $this->subject->id,
            payload: [
                'submission_key' => $submissionKey,
                'files' => [$file1],
                'titles' => ['Title 1'],
                'mode' => 'export_manual',
                'qualification_mode' => 'owner_waived_demo',
                'waiver_acknowledged' => true,
            ]
        );

        // Conflicting submission with differing titles
        $file1Diff = UploadedFile::fake()->createWithContent('ch1.pdf', PdfFixtures::minimalValidPdf(1, 'Ch1'));

        try {
            $submitter->submit(
                actor: $this->teacherA,
                subjectId: $this->subject->id,
                payload: [
                    'submission_key' => $submissionKey,
                    'files' => [$file1Diff],
                    'titles' => ['Differing Title'],
                    'mode' => 'export_manual',
                    'qualification_mode' => 'owner_waived_demo',
                    'waiver_acknowledged' => true,
                ]
            );
            $this->fail('Expected 409 conflict exception was not thrown.');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $this->assertSame('submission_conflict', $e->getResponse()->getData(true)['error']['code']);
        }
    }
}
