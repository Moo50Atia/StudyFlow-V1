<?php

namespace Tests\Feature\V3\WebPipeline;

use App\Models\Document;
use App\Models\Lecture;
use App\Models\Subject;
use App\Models\User;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use App\Models\V3\UploadAttempt;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\KnowledgeSourceFile;
use App\Models\V3\NotebookInstance;
use App\Services\V3\Settings\PublishSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\V3\Pdf\PdfFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

class SubjectRunIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacherA;
    protected User $teacherB;
    protected User $student;
    protected User $inactiveTeacher;
    protected User $admin;
    protected Subject $subjectA;
    protected Subject $subjectB;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('v3_private');

        $this->teacherA = WebPipelineFixtures::createTeacherA();
        $this->teacherB = WebPipelineFixtures::createTeacherB();
        $this->student = WebPipelineFixtures::createStudent();
        $this->inactiveTeacher = WebPipelineFixtures::createInactiveTeacher();
        $this->admin = WebPipelineFixtures::createAdmin();

        $this->subjectA = WebPipelineFixtures::createSubjectForTeacher($this->teacherA, 'Physics 101');
        $initialLecA = Lecture::create([
            'subject_id' => $this->subjectA->id,
            'title' => 'Seed Lecture A',
        ]);
        WebPipelineFixtures::grantTeacherPermission($this->teacherA, $this->subjectA, $initialLecA);

        $this->subjectB = WebPipelineFixtures::createSubjectForTeacher($this->teacherB, 'Chemistry 101');
        $initialLecB = Lecture::create([
            'subject_id' => $this->subjectB->id,
            'title' => 'Seed Lecture B',
        ]);
        WebPipelineFixtures::grantTeacherPermission($this->teacherB, $this->subjectB, $initialLecB);

        // Setup settings and framework fixtures
        SettingsFixtures::registerDefaultTemplates();
        $payload = SettingsFixtures::validPayload();
        $publisher = app(PublishSettings::class);
        $publisher->publish($payload, 0, $this->admin->id);

        // Ensure default framework version exists
        $frameworkId = (string) Str::uuid();
        DB::table('v3_notebook_framework_versions')->insertOrIgnore([
            'id' => $frameworkId,
            'profile' => 'expanded-root-v1',
            'body_path' => 'frameworks/' . $frameworkId . '.md',
            'body_sha256' => hash('sha256', 'Framework body content'),
            'body_bytes' => 22,
            'first_sha256' => hash('sha256', 'First step slice'),
            'second_sha256' => hash('sha256', 'Second step slice'),
            'third_sha256' => hash('sha256', 'Third step slice'),
            'response_format_sha256' => hash('sha256', 'Response format slice'),
            'approved_by_user_id' => $this->admin->id,
            'approved_at' => now(),
            'created_at' => now(),
        ]);
        Storage::disk('v3_private')->put('frameworks/' . $frameworkId . '.md', 'Framework body content');
        DB::table('v3_notebook_framework_pointer')->updateOrInsert(
            ['id' => 1],
            ['version_id' => $frameworkId, 'updated_at' => now()]
        );
    }

    public function test_guest_cannot_submit_subject_run(): void
    {
        $response = $this->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
            'submission_key' => (string) Str::uuid(),
        ]);

        $this->assertTrue(in_array($response->status(), [401, 302]));
    }

    public function test_non_teacher_and_inactive_user_cannot_submit(): void
    {
        // Student
        $respStudent = $this->actingAs($this->student)
            ->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
                'submission_key' => (string) Str::uuid(),
            ]);
        $respStudent->assertStatus(403);

        // Inactive Teacher
        $respInactive = $this->actingAs($this->inactiveTeacher)
            ->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
                'submission_key' => (string) Str::uuid(),
            ]);
        $respInactive->assertStatus(403);
    }

    public function test_teacher_without_subject_permission_cannot_submit(): void
    {
        // Teacher B has no permission on subject A
        $response = $this->actingAs($this->teacherB)
            ->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
                'submission_key' => (string) Str::uuid(),
            ]);

        $this->assertTrue(in_array($response->status(), [403, 404]));
    }

    public function test_validation_requires_submission_key_files_and_matching_titles(): void
    {
        // Missing all
        $resp = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", []);
        $resp->assertStatus(422);

        // Files count != titles count
        $file1 = UploadedFile::fake()->createWithContent('chap1.pdf', PdfFixtures::minimalValidPdf(1, 'Chap1'));
        $respMismatch = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
                'submission_key' => (string) Str::uuid(),
                'files' => [$file1],
                'titles' => ['Title 1', 'Title 2'],
            ]);
        $respMismatch->assertStatus(422);

        // Over limit (> 20 files)
        $manyFiles = [];
        $manyTitles = [];
        for ($i = 0; $i < 21; $i++) {
            $manyFiles[] = UploadedFile::fake()->createWithContent("c{$i}.pdf", PdfFixtures::minimalValidPdf(1, "Text {$i}"));
            $manyTitles[] = "Title {$i}";
        }
        $respOver = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
                'submission_key' => (string) Str::uuid(),
                'files' => $manyFiles,
                'titles' => $manyTitles,
            ]);
        $respOver->assertStatus(422);
    }

    public function test_validation_rejects_duplicate_pdf_in_same_batch(): void
    {
        $content = PdfFixtures::minimalValidPdf(1, 'Identical Content');
        $file1 = UploadedFile::fake()->createWithContent('file1.pdf', $content);
        $file2 = UploadedFile::fake()->createWithContent('file2.pdf', $content);

        $resp = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
                'submission_key' => (string) Str::uuid(),
                'files' => [$file1, $file2],
                'titles' => ['Part 1', 'Part 2'],
                'waiver_acknowledged' => true,
            ]);

        $resp->assertStatus(422);
        $this->assertSame('duplicate_batch_pdf', $resp->json('error.code'));
    }

    public function test_missing_approved_framework_rejects_before_staging(): void
    {
        DB::table('v3_notebook_framework_pointer')->where('id', 1)->delete();
        $file = UploadedFile::fake()->createWithContent('chapter.pdf', PdfFixtures::minimalValidPdf(1, 'Chapter'));

        $response = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
                'submission_key' => (string) Str::uuid(),
                'files' => [$file],
                'titles' => ['Chapter'],
                'waiver_acknowledged' => true,
            ]);

        $response->assertStatus(422);
        $this->assertSame(0, \App\Models\V3\SubjectRun::count());
    }

    public function test_demo_waiver_requires_explicit_acknowledgment(): void
    {
        $file1 = UploadedFile::fake()->createWithContent('c1.pdf', PdfFixtures::minimalValidPdf(1, 'C1'));

        // qualification_mode = owner_waived_demo without waiver_acknowledged
        $resp = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
                'submission_key' => (string) Str::uuid(),
                'files' => [$file1],
                'titles' => ['Chap 1'],
                'mode' => 'export_manual',
                'qualification_mode' => 'owner_waived_demo',
                'waiver_acknowledged' => false,
            ]);

        $resp->assertStatus(422);
    }

    public function test_valid_submission_creates_staging_run_items_command_and_notebook(): void
    {
        $submissionKey = (string) Str::uuid();
        $file1 = UploadedFile::fake()->createWithContent('c1.pdf', PdfFixtures::minimalValidPdf(1, 'Content 1'));
        $file2 = UploadedFile::fake()->createWithContent('c2.pdf', PdfFixtures::minimalValidPdf(1, 'Content 2'));

        $resp = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
                'submission_key' => $submissionKey,
                'files' => [$file1, $file2],
                'titles' => ['Mechanics Part 1', 'Mechanics Part 2'],
                'mode' => 'export_manual',
                'qualification_mode' => 'owner_waived_demo',
                'waiver_acknowledged' => true,
            ]);

        $resp->assertStatus(202);
        $data = $resp->json('data');
        $this->assertNotEmpty($data['run_id']);
        $this->assertSame('staging', $data['state']);
        $this->assertSame(1, $data['state_version']);
        $this->assertStringContainsString('no-store', $resp->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $resp->headers->get('Cache-Control'));

        // Check SubjectRun
        $run = SubjectRun::find($data['run_id']);
        $this->assertNotNull($run);
        $this->assertSame($this->teacherA->id, $run->owner_user_id);
        $this->assertSame($this->subjectA->id, $run->subject_id);
        $this->assertSame('web', $run->origin);
        $this->assertSame('notebooklm_per_pdf_web_v1', $run->protocol);
        $this->assertSame('export_manual', $run->mode);
        $this->assertSame('owner_waived_demo', $run->qualification_mode);
        $this->assertSame(2, $run->total_pdfs);

        // Check items
        $items = SubjectRunItem::where('run_id', $run->id)->orderBy('pdf_ordinal')->get();
        $this->assertCount(2, $items);
        $this->assertSame(1, $items[0]->pdf_ordinal);
        $this->assertSame('Mechanics Part 1', $items[0]->display_title);
        $this->assertSame(2, $items[1]->pdf_ordinal);
        $this->assertSame('Mechanics Part 2', $items[1]->display_title);

        // Check NotebookInstance
        $nb = DB::table('v3_notebook_instances')->where('run_id', $run->id)->first();
        $this->assertNotNull($nb);
        $this->assertSame('notebooklm_py', $nb->adapter_kind);
        $this->assertSame('mo50atiapro', $nb->account_alias);
        $this->assertSame('pending', $nb->state);

        // Check PipelineCommand
        $cmd = DB::table('v3_pipeline_commands')->where('run_id', $run->id)->where('action', 'submit')->first();
        $this->assertNotNull($cmd);
        $this->assertSame('accepted', $cmd->state);

        // Check PipelineOutbox
        $outbox = DB::table('v3_pipeline_outbox')->where('command_id', $cmd->id)->first();
        $this->assertNotNull($outbox);
        $this->assertSame('application_bridge', $outbox->destination);
        $this->assertSame('pending', $outbox->state);
    }

    public function test_exact_duplicate_submission_returns_prior_receipt(): void
    {
        $submissionKey = (string) Str::uuid();
        $file = UploadedFile::fake()->createWithContent('c1.pdf', PdfFixtures::minimalValidPdf(1, 'C1'));

        $resp1 = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
                'submission_key' => $submissionKey,
                'files' => [$file],
                'titles' => ['Lecture 1'],
                'mode' => 'export_manual',
                'qualification_mode' => 'owner_waived_demo',
                'waiver_acknowledged' => true,
            ]);
        $resp1->assertStatus(202);
        $runId1 = $resp1->json('data.run_id');

        // Re-submit exact same
        $resp2 = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
                'submission_key' => $submissionKey,
                'files' => [$file],
                'titles' => ['Lecture 1'],
                'mode' => 'export_manual',
                'qualification_mode' => 'owner_waived_demo',
                'waiver_acknowledged' => true,
            ]);
        $resp2->assertStatus(202);
        $this->assertSame($runId1, $resp2->json('data.run_id'));

        // Verify only 1 run row in DB
        $count = SubjectRun::where('submission_key', $submissionKey)->count();
        $this->assertSame(1, $count);
    }

    public function test_duplicate_submission_key_with_different_digest_throws_conflict(): void
    {
        $submissionKey = (string) Str::uuid();
        $file1 = UploadedFile::fake()->createWithContent('c1.pdf', PdfFixtures::minimalValidPdf(1, 'C1'));
        $file2 = UploadedFile::fake()->createWithContent('c2.pdf', PdfFixtures::minimalValidPdf(1, 'C2 Differing'));

        $resp1 = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
                'submission_key' => $submissionKey,
                'files' => [$file1],
                'titles' => ['Lecture 1'],
                'mode' => 'export_manual',
                'qualification_mode' => 'owner_waived_demo',
                'waiver_acknowledged' => true,
            ]);
        $resp1->assertStatus(202);

        // Submit same key with different title/file
        $resp2 = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
                'submission_key' => $submissionKey,
                'files' => [$file2],
                'titles' => ['Lecture Differing'],
                'mode' => 'export_manual',
                'qualification_mode' => 'owner_waived_demo',
                'waiver_acknowledged' => true,
            ]);
        $resp2->assertStatus(409);
        $this->assertSame('submission_conflict', $resp2->json('error.code'));
    }

    public function test_per_owner_hash_reuse_and_separate_owner_isolation(): void
    {
        $acceptRunItem = app(\App\Services\V3\WebPipeline\AcceptRunItem::class);

        $pdfBytes = PdfFixtures::minimalValidPdf(1, 'Shared Document Concept');
        $sha = hash('sha256', $pdfBytes);

        // 1. Teacher A submits run 1 with PDF
        $keyA1 = (string) Str::uuid();
        $fileA1 = UploadedFile::fake()->createWithContent('doc.pdf', $pdfBytes);
        $respA1 = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
                'submission_key' => $keyA1,
                'files' => [$fileA1],
                'titles' => ['Title A1'],
                'mode' => 'export_manual',
                'qualification_mode' => 'owner_waived_demo',
                'waiver_acknowledged' => true,
            ]);
        $respA1->assertStatus(202);
        $runIdA1 = $respA1->json('data.run_id');

        $itemA1 = SubjectRunItem::where('run_id', $runIdA1)->first();
        // Accept run item
        $acceptRunItem->accept($itemA1);
        $itemA1->refresh();

        $this->assertSame('accepted', $itemA1->state);
        $this->assertNotNull($itemA1->document_id);
        $this->assertNotNull($itemA1->lecture_id);
        $this->assertNotNull($itemA1->case_id);
        $docIdA1 = $itemA1->document_id;
        $caseIdA1 = $itemA1->case_id;

        // 2. Teacher A submits run 2 with SAME PDF -> Reuses Document A1, creates new Case and Lecture
        $keyA2 = (string) Str::uuid();
        $fileA2 = UploadedFile::fake()->createWithContent('doc.pdf', $pdfBytes);
        $respA2 = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
                'submission_key' => $keyA2,
                'files' => [$fileA2],
                'titles' => ['Title A2'],
                'mode' => 'export_manual',
                'qualification_mode' => 'owner_waived_demo',
                'waiver_acknowledged' => true,
            ]);
        $respA2->assertStatus(202);
        $runIdA2 = $respA2->json('data.run_id');

        $itemA2 = SubjectRunItem::where('run_id', $runIdA2)->first();
        $acceptRunItem->accept($itemA2);
        $itemA2->refresh();

        $this->assertSame($docIdA1, $itemA2->document_id, 'Should reuse Document for same owner');
        $this->assertNotSame($caseIdA1, $itemA2->case_id, 'Must create a distinct ProcessingCase');
        $this->assertNotSame($itemA1->lecture_id, $itemA2->lecture_id, 'Must create a distinct Lecture');

        // 3. Teacher B submits run with SAME PDF -> separate Document created for Teacher B
        $keyB = (string) Str::uuid();
        $fileB = UploadedFile::fake()->createWithContent('doc.pdf', $pdfBytes);
        $respB = $this->actingAs($this->teacherB)
            ->postJson("/v3/teacher/subjects/{$this->subjectB->id}/runs", [
                'submission_key' => $keyB,
                'files' => [$fileB],
                'titles' => ['Title B'],
                'mode' => 'export_manual',
                'qualification_mode' => 'owner_waived_demo',
                'waiver_acknowledged' => true,
            ]);
        $respB->assertStatus(202);
        $runIdB = $respB->json('data.run_id');

        $itemB = SubjectRunItem::where('run_id', $runIdB)->first();
        $acceptRunItem->accept($itemB);
        $itemB->refresh();

        $this->assertNotSame($docIdA1, $itemB->document_id, 'Teacher B must have separate Document record');
        $docB = \App\Models\V3\Document::find($itemB->document_id);
        $this->assertSame($this->teacherB->id, $docB->owner_user_id);
    }

    public function test_private_bytes_and_no_public_path_exposed(): void
    {
        $file = UploadedFile::fake()->createWithContent('private.pdf', PdfFixtures::minimalValidPdf(1, 'Private Material'));
        $resp = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
                'submission_key' => (string) Str::uuid(),
                'files' => [$file],
                'titles' => ['Private Lecture'],
                'mode' => 'export_manual',
                'qualification_mode' => 'owner_waived_demo',
                'waiver_acknowledged' => true,
            ]);
        $resp->assertStatus(202);
        $runId = $resp->json('data.run_id');
        $item = SubjectRunItem::where('run_id', $runId)->first();

        // Staged upload attempt should be in private quarantine
        $attempt = UploadAttempt::find($item->upload_attempt_id);
        $this->assertNotNull($attempt);
        $this->assertStringContainsString('quarantine', $attempt->staging_path);

        // Accept item
        app(\App\Services\V3\WebPipeline\AcceptRunItem::class)->accept($item);
        $item->refresh();

        $lecture = Lecture::find($item->lecture_id);
        $this->assertNull($lecture->pdf_path, 'Pipeline linked lecture must never populate public pdf_path');
    }

    public function test_signed_worker_prepares_input_idempotently_and_reads_only_verified_private_bytes(): void
    {
        $pdfBytes = PdfFixtures::minimalValidPdf(1, 'Worker intake handoff');
        $response = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
                'submission_key' => (string) Str::uuid(),
                'files' => [UploadedFile::fake()->createWithContent('worker.pdf', $pdfBytes)],
                'titles' => ['Worker handoff'],
                'mode' => 'export_manual',
                'qualification_mode' => 'owner_waived_demo',
                'waiver_acknowledged' => true,
            ]);
        $response->assertAccepted();

        $runId = $response->json('data.run_id');
        $item = SubjectRunItem::where('run_id', $runId)->firstOrFail();
        $path = "/api/v3/internal/web-pipeline/items/{$item->id}/prepare";
        $body = ['run_id' => $runId];
        $headers = \Tests\Support\V3\WorkflowFixtures::signCallbackRequest('POST', $path, $body);

        $signedHeaders = array_intersect_key($headers, array_flip(['X-V3-Timestamp', 'X-V3-Signature', 'X-V3-Contract-Version']));
        $prepared = $this->json('POST', $path, $body, $signedHeaders);
        $prepared->assertOk();
        $prepared->assertJsonPath('outcome', 'completed');
        $prepared->assertJsonPath('item_id', $item->id);
        $this->assertSame(hash('sha256', $pdfBytes), $prepared->json('input_sha256'));
        $this->assertSame(strlen($pdfBytes), $prepared->json('input_bytes'));
        $lectureId = $item->fresh()->lecture_id;

        // A lost response followed by the same request must not create another lecture.
        $repeated = $this->json('POST', $path, $body, $signedHeaders);
        $repeated->assertOk();
        $this->assertSame($lectureId, $item->fresh()->lecture_id);
        $this->assertSame(1, Lecture::where('subject_id', $this->subjectA->id)
            ->where('title', 'Worker handoff')->count());

        $inputPath = "/api/v3/internal/web-pipeline/items/{$item->id}/input";
        $inputHeaders = \Tests\Support\V3\WorkflowFixtures::signCallbackRequest('GET', $inputPath, []);
        $inputSignedHeaders = array_intersect_key($inputHeaders, array_flip(['X-V3-Timestamp', 'X-V3-Signature', 'X-V3-Contract-Version']));
        $input = $this->get($inputPath, $inputSignedHeaders);
        $input->assertOk();
        $this->assertSame($pdfBytes, $input->baseResponse->getFile()->getContent());
    }

    public function test_signed_worker_reads_exact_approved_framework_bytes(): void
    {
        $frameworkId = DB::table('v3_notebook_framework_pointer')->value('version_id');
        $path = "/api/v3/internal/web-pipeline/frameworks/{$frameworkId}/body";
        $headers = \Tests\Support\V3\WorkflowFixtures::signCallbackRequest('GET', $path, []);
        $signedHeaders = array_intersect_key($headers, array_flip(['X-V3-Timestamp', 'X-V3-Signature', 'X-V3-Contract-Version']));
        $response = $this->get($path, $signedHeaders);

        $response->assertOk();
        $this->assertSame('Framework body content', $response->baseResponse->getFile()->getContent());
    }

    public function test_signed_web_callbacks_keep_one_notebook_and_bind_each_pdf_to_real_direct_protocol_lineage(): void
    {
        $response = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
                'submission_key' => (string) Str::uuid(),
                'files' => [
                    UploadedFile::fake()->createWithContent('one.pdf', PdfFixtures::minimalValidPdf(1, 'One')),
                    UploadedFile::fake()->createWithContent('two.pdf', PdfFixtures::minimalValidPdf(1, 'Two')),
                ],
                'titles' => ['One', 'Two'],
                'mode' => 'export_manual',
                'qualification_mode' => 'owner_waived_demo',
                'waiver_acknowledged' => true,
            ]);
        $response->assertAccepted();
        $runId = $response->json('data.run_id');
        $notebookId = 'provider-notebook-web-1';
        $frameworkSourceId = 'provider-framework-web-1';

        $notebookPath = "/api/v3/internal/web-pipeline/runs/{$runId}/notebook";
        $body = ['notebook_id' => $notebookId, 'configured' => false];
        $this->json('POST', $notebookPath, $body, array_intersect_key(
            \Tests\Support\V3\WorkflowFixtures::signCallbackRequest('POST', $notebookPath, $body),
            array_flip(['X-V3-Timestamp', 'X-V3-Signature', 'X-V3-Contract-Version'])
        ))->assertOk()->assertJsonPath('outcome', 'completed');

        $body = [
            'notebook_id' => $notebookId,
            'framework_source_id' => $frameworkSourceId,
            'framework_sha256' => hash('sha256', 'Framework body content'),
            'configured' => false,
        ];
        $headers = \Tests\Support\V3\WorkflowFixtures::signCallbackRequest('POST', $notebookPath, $body);
        $this->json('POST', $notebookPath, $body, array_intersect_key($headers, array_flip(['X-V3-Timestamp', 'X-V3-Signature', 'X-V3-Contract-Version'])))
            ->assertOk()->assertJsonPath('outcome', 'completed');
        $body['configured'] = true;
        $headers = \Tests\Support\V3\WorkflowFixtures::signCallbackRequest('POST', $notebookPath, $body);
        $this->json('POST', $notebookPath, $body, array_intersect_key($headers, array_flip(['X-V3-Timestamp', 'X-V3-Signature', 'X-V3-Contract-Version'])))
            ->assertOk()->assertJsonPath('outcome', 'completed');

        $items = SubjectRunItem::where('run_id', $runId)->orderBy('pdf_ordinal')->get();
        foreach ($items as $index => $item) {
            $preparePath = "/api/v3/internal/web-pipeline/items/{$item->id}/prepare";
            $prepareBody = ['run_id' => $runId];
            $headers = \Tests\Support\V3\WorkflowFixtures::signCallbackRequest('POST', $preparePath, $prepareBody);
            $prepared = $this->json('POST', $preparePath, $prepareBody, array_intersect_key($headers, array_flip(['X-V3-Timestamp', 'X-V3-Signature', 'X-V3-Contract-Version'])));
            $prepared->assertOk();
            $caseId = $prepared->json('case_id');
            $bindingRef = $prepared->json('binding_ref');
            $this->assertNotEmpty($caseId);
            $this->assertNotEmpty($bindingRef);

            $sourcePath = "/api/v3/internal/web-pipeline/items/{$item->id}/source";
            $sourceBody = [
                'run_id' => $runId,
                'source_id' => "provider-pdf-web-" . ($index + 1),
                'input_sha256' => $item->input_sha256,
            ];
            $headers = \Tests\Support\V3\WorkflowFixtures::signCallbackRequest('POST', $sourcePath, $sourceBody);
            $this->json('POST', $sourcePath, $sourceBody, array_intersect_key($headers, array_flip(['X-V3-Timestamp', 'X-V3-Signature', 'X-V3-Contract-Version'])))->assertOk();

            $binding = KnowledgeSourceBinding::findOrFail($bindingRef);
            $this->assertSame('bound', $binding->state);
            $this->assertSame('ready', $binding->chat_configuration_state);
            $this->assertSame($notebookId, NotebookInstance::where('run_id', $runId)->value('provider_notebook_id'));
            $files = KnowledgeSourceFile::where('binding_id', $bindingRef)->get()->keyBy('role');
            $this->assertSame("provider-pdf-web-" . ($index + 1), $files['educational_pdf']->provider_source_id);
            $this->assertSame($frameworkSourceId, $files['framework_steps']->provider_source_id);

            $lookupPath = "/api/internal/v1/cases/{$caseId}/source-bindings/{$bindingRef}";
            $headers = \Tests\Support\V3\WorkflowFixtures::signCallbackRequest('GET', $lookupPath, []);
            $lookup = $this->get($lookupPath, array_intersect_key($headers, array_flip(['X-V3-Timestamp', 'X-V3-Signature', 'X-V3-Contract-Version'])));
            $lookup->assertOk();
            $this->assertSame($notebookId, $lookup->json('data.notebook_id'));
            $this->assertSame($frameworkSourceId, collect($lookup->json('data.manifest'))->firstWhere('role', 'framework_steps')['provider_source_id']);
        }
    }

    public function test_rejected_attempt_marks_item_failed_without_creating_lecture_or_case(): void
    {
        // Malformed non-pdf file
        $badFile = UploadedFile::fake()->createWithContent('corrupt.pdf', 'NOT_A_VALID_PDF_STREAM');

        $resp = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
                'submission_key' => (string) Str::uuid(),
                'files' => [$badFile],
                'titles' => ['Corrupt PDF Lecture'],
                'mode' => 'export_manual',
                'qualification_mode' => 'owner_waived_demo',
                'waiver_acknowledged' => true,
            ]);
        $resp->assertStatus(202);
        $runId = $resp->json('data.run_id');
        $item = SubjectRunItem::where('run_id', $runId)->first();

        // Process item intake with AcceptRunItem
        $result = app(\App\Services\V3\WebPipeline\AcceptRunItem::class)->accept($item);
        $item->refresh();

        $this->assertSame('rejected', $item->state);
        $this->assertSame('failed', $item->outcome);
        $this->assertNotNull($item->safe_code);
        $this->assertNull($item->lecture_id, 'Rejected item must NOT create a lecture');
        $this->assertNull($item->case_id, 'Rejected item must NOT create a processing case');
        $this->assertNull($item->document_id, 'Rejected item must NOT create or link a document');
    }

    public function test_teacher_can_render_subject_run_create_view(): void
    {
        $resp = $this->actingAs($this->teacherA)
            ->get("/v3/teacher/subjects/{$this->subjectA->id}/runs/create");

        $resp->assertStatus(200);
        $resp->assertSee('Upload Curriculum PDFs');
        $resp->assertSee('Export / Manual');
        $resp->assertSee('JEV is waived for this demo');
    }

    public function test_teacher_can_render_subject_run_show_view_with_export_buttons(): void
    {
        $file = UploadedFile::fake()->createWithContent('valid.pdf', PdfFixtures::minimalValidPdf(1, 'Valid PDF'));
        $createResp = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
                'submission_key' => (string) Str::uuid(),
                'files' => [$file],
                'titles' => ['Mechanics Intro'],
                'mode' => 'export_manual',
                'qualification_mode' => 'owner_waived_demo',
                'waiver_acknowledged' => true,
            ]);
        $runId = $createResp->json('data.run_id');

        $resp = $this->actingAs($this->teacherA)
            ->get("/v3/teacher/subject-runs/{$runId}");

        $resp->assertStatus(200);
        $resp->assertSee('Subject Run Details');
        $resp->assertSee('Export Prompts (JSON)');
        $resp->assertSee('Export Prompts (Text)');
        $resp->assertSee('Mechanics Intro');
    }

    public function test_non_owner_cannot_render_subject_run_show_view(): void
    {
        $file = UploadedFile::fake()->createWithContent('valid.pdf', PdfFixtures::minimalValidPdf(1, 'Valid PDF'));
        $createResp = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
                'submission_key' => (string) Str::uuid(),
                'files' => [$file],
                'titles' => ['Mechanics Intro'],
                'mode' => 'export_manual',
                'qualification_mode' => 'owner_waived_demo',
                'waiver_acknowledged' => true,
            ]);
        $runId = $createResp->json('data.run_id');

        $resp = $this->actingAs($this->teacherB)
            ->get("/v3/teacher/subject-runs/{$runId}");

        $resp->assertStatus(404);
    }
}
