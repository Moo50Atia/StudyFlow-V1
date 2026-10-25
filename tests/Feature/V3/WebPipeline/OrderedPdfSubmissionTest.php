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

use App\Services\V3\Intake\ClamAvAdapter;
use App\Services\V3\Intake\PdfInspector;
use App\Services\V3\Intake\QpdfAdapter;
use App\Services\V3\WebPipeline\PrepareWebPdfs;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/** Real PHP intake -> Python/qpdf preparation -> immutable ordered run capture. */
class OrderedPdfSubmissionTest extends TestCase
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
        config()->set('services.v3_teacher_demo_waiver_enabled', true);

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

        app()->forgetInstance(PrepareWebPdfs::class);
        $clam = \Mockery::mock(ClamAvAdapter::class);
        $clam->shouldReceive('scan')->andReturn(['valid' => true]);
        app()->instance(PdfInspector::class, new PdfInspector(new QpdfAdapter(), $clam));
        $payload = SettingsFixtures::validPayload();
        $payload['limits']['max_pdf_pages'] = 200;
        app(PublishSettings::class)->publish($payload, 1, $this->admin->id);
        config()->set('services.v3.bridge_hmac_key', str_repeat('a', 64));
    }

    private function realPreparation(bool $tamper = false): void
    {
        Http::preventStrayRequests();
        Http::fake(['*/pdf-preparations' => function ($request) use ($tamper) {
            $process = new Process([base_path('.venv/bin/python'), '-c',
                'import json,sys; from pathlib import Path; from v3_engine.web_pipeline.pdf_preparation import prepare_web_pdfs; print(json.dumps(prepare_web_pdfs(Path(sys.argv[1]),json.loads(sys.argv[2]))))',
                Storage::disk('v3_private')->path(''), $request->body()], base_path());
            $process->setTimeout(60);
            $process->mustRun();
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            if ($tamper) {
                $path = dirname(Storage::disk('v3_private')->path($result['inventory_ref']))
                    .'/'.$result['prepared_pdfs'][0]['artifact_ref']['path'];
                file_put_contents($path, 'changed');
            }
            return Http::response(['data' => $result]);
        }]);
    }

    private function submit(string $key, array $files)
    {
        return $this->actingAs($this->teacherA)->postJson("/v3/teacher/subjects/{$this->subjectA->id}/runs", [
            'submission_key' => $key, 'files' => $files, 'titles' => array_map(fn ($f) => $f->getClientOriginalName(), $files),
            'mode' => 'export_manual', 'qualification_mode' => 'owner_waived_demo', 'waiver_acknowledged' => true,
        ]);
    }

    public function test_real_recursive_preparation_preserves_submission_order_and_replay(): void
    {
        $this->realPreparation();
        $key = (string) Str::uuid();
        $original = PdfFixtures::minimalValidPdf(114, 'Ordered content');
        $files = [UploadedFile::fake()->createWithContent('Zulu.pdf', $original),
            UploadedFile::fake()->createWithContent('Alpha.pdf', PdfFixtures::minimalValidPdf(2, 'Second source'))];
        $response = $this->submit($key, $files);
        $this->assertSame(202, $response->status(), $response->getContent());
        $run = SubjectRun::findOrFail($response->json('data.run_id'));
        $items = $run->items()->orderBy('pdf_ordinal')->get();
        $this->assertSame(5, $run->total_pdfs);
        $this->assertSame([[1, 28], [29, 57], [58, 85], [86, 114], [1, 2]],
            $items->map(fn ($i) => [$i->pdf_lineage['start_page'], $i->pdf_lineage['end_page']])->all());
        $this->assertSame([1, 1, 1, 1, 2], $items->map(fn ($i) => $i->pdf_lineage['source_ordinal'])->all());
        $this->assertSame([1, 2, 3, 4, 5], $items->pluck('pdf_ordinal')->all());
        $this->assertSame(hash('sha256', $original), $items[0]->pdf_lineage['original_sha256']);
        $this->assertSame($original, Storage::disk('v3_private')->get("web-preparations/{$this->teacherA->id}/{$key}/input/0001/Zulu.pdf"));
        $this->assertSame(2, $run->pdf_preparation['policy']['version']);
        $this->assertSame(5, $run->pdf_preparation['prepared_count']);
        $this->submit($key, $files)->assertAccepted()->assertJsonPath('data.run_id', $run->id);
        Http::assertSentCount(1);
        $this->submit($key, array_reverse($files))->assertConflict();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('v3_subject_runs', 1);
    }

    public function test_corrupt_original_never_reaches_preparation_or_creates_run(): void
    {
        $this->realPreparation();
        $this->submit((string) Str::uuid(), [UploadedFile::fake()->createWithContent('bad.pdf', 'broken')])->assertUnprocessable();
        Http::assertNothingSent();
        $this->assertDatabaseCount('v3_subject_runs', 0);
    }

    public function test_changed_prepared_bytes_are_rejected_before_run_capture(): void
    {
        $this->realPreparation(true);
        $this->submit((string) Str::uuid(), [UploadedFile::fake()->createWithContent('chapter.pdf', PdfFixtures::minimalValidPdf(51))])->assertStatus(503);
        $this->assertDatabaseCount('v3_subject_runs', 0);
    }
}
