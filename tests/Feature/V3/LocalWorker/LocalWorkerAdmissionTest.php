<?php

namespace Tests\Feature\V3\LocalWorker;

use App\Models\Lecture;
use App\Models\Subject;
use App\Models\User;
use App\Models\V3\Document;
use App\Models\V3\LocalArtifact;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalTask;
use App\Models\V3\PipelineOutbox;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SubjectRun;
use App\Services\V3\Lessons\ActivateMap;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerJsonDecoder;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerV1Validator;
use App\Services\V3\Source\ActivateSource;
use App\Services\V3\WebPipeline\DispatchRunOutbox;
use App\Services\V3\WebPipeline\SubmitSubjectRun;
use App\Services\V3\Workflow\DiscoverCases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\V3\Pdf\PdfFixtures;
use App\Models\V3\NotebookFrameworkVersion;
use Tests\Support\V3\SettingsFixtures;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

class LocalWorkerAdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;
    protected Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.v3_teacher_demo_waiver_enabled', true);
        Storage::fake('v3_private');

        $this->teacher = WebPipelineFixtures::createTeacherA();
        $this->subject = WebPipelineFixtures::createSubjectForTeacher($this->teacher, 'Physics 101');

        $lecture = Lecture::create([
            'subject_id' => $this->subject->id,
            'title' => 'Existing Lecture',
        ]);
        WebPipelineFixtures::grantTeacherPermission($this->teacher, $this->subject, $lecture);

        $frameworkId = (string) Str::uuid();
        $frameworkBody = 'Framework body content';
        NotebookFrameworkVersion::create([
            'id' => $frameworkId,
            'profile' => 'expanded-root-v1',
            'body_path' => 'frameworks/' . $frameworkId . '.md',
            'body_sha256' => hash('sha256', $frameworkBody),
            'body_bytes' => strlen($frameworkBody),
            'first_sha256' => hash('sha256', 'first'),
            'second_sha256' => hash('sha256', 'second'),
            'third_sha256' => hash('sha256', 'third'),
            'response_format_sha256' => hash('sha256', 'format'),
            'approved_by_user_id' => $this->teacher->id,
            'approved_at' => now(),
        ]);
        Storage::disk('v3_private')->put('frameworks/' . $frameworkId . '.md', $frameworkBody);
        \Illuminate\Support\Facades\DB::table('v3_notebook_framework_pointer')->updateOrInsert(
            ['id' => 1],
            ['version_id' => $frameworkId, 'updated_at' => now()]
        );
        SettingsFixtures::registerDefaultTemplates();
        $admin = WebPipelineFixtures::createAdmin();
        $payload = SettingsFixtures::validPayload();
        $publisher = app(\App\Services\V3\Settings\PublishSettings::class);
        $publisher->publish($payload, 0, $admin->id);

        config()->set('services.v3.bridge_hmac_key', str_repeat('a', 64));
        \Illuminate\Support\Facades\Http::fake(['*/pdf-preparations' => function ($request) {
            $process = new \Symfony\Component\Process\Process([base_path('.venv/bin/python'), '-c',
                'import json,sys; from pathlib import Path; from v3_engine.web_pipeline.pdf_preparation import prepare_web_pdfs; print(json.dumps(prepare_web_pdfs(Path(sys.argv[1]),json.loads(sys.argv[2]))))',
                \Illuminate\Support\Facades\Storage::disk('v3_private')->path(''), $request->body()], base_path());
            $process->setTimeout(60);
            $process->mustRun();
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            return \Illuminate\Support\Facades\Http::response(['data' => $result]);
        }]);
    }

    protected function makePdf(string $name, int $pages = 1): UploadedFile
    {
        $content = PdfFixtures::minimalValidPdf($pages, "Content of {$name}");
        $tmp = tempnam(sys_get_temp_dir(), 'test_pdf_');
        file_put_contents($tmp, $content);

        return new UploadedFile($tmp, $name, 'application/pdf', null, true);
    }

    public function test_admission_creates_local_job_and_seals_manifest_with_provider_free_intake(): void
    {
        $submitService = app(SubmitSubjectRun::class);
        $submissionKey = (string) Str::uuid();

        $pdf1 = $this->makePdf('mechanics_part1.pdf', 2);
        $pdf2 = $this->makePdf('mechanics_part2.pdf', 3);

        $result = $submitService->submit($this->teacher, $this->subject->id, [
            'submission_key' => $submissionKey,
            'files' => [$pdf1, $pdf2],
            'titles' => ['Mechanics Part 1', 'Mechanics Part 2'],
            'mode' => 'export_manual',
            'execution_mode' => 'local_worker_v1',
            'output_scope' => 'prompts_only',
            'qualification_mode' => 'owner_waived_demo',
            'waiver_acknowledged' => true,
        ]);

        $this->assertArrayHasKey('run_id', $result);
        $this->assertArrayHasKey('job_id', $result);
        $runId = $result['run_id'];
        $jobId = $result['job_id'];

        $run = SubjectRun::findOrFail($runId);
        $this->assertSame('local_worker_v1', $run->execution_mode);
        $this->assertSame('queued', $run->state);

        // Verify LocalJob
        $job = LocalJob::findOrFail($jobId);
        $this->assertSame($runId, $job->run_id);
        $this->assertSame($this->teacher->id, $job->teacher_id);
        $this->assertSame('queued', $job->status);
        $this->assertSame('prompts_only', $job->output_scope);

        // Verify LocalTask (author_prompts)
        $task = LocalTask::where('job_id', $job->id)->where('task_kind', 'author_prompts')->firstOrFail();
        $this->assertSame('queued', $task->status);
        $this->assertNotEmpty($task->input_manifest_id);
        $this->assertNotEmpty($task->input_manifest_sha256);

        // Verify manifest artifact
        $manifestArtifact = LocalArtifact::findOrFail($task->input_manifest_id);
        $this->assertSame('input_manifest', $manifestArtifact->purpose);
        $manifestBytes = Storage::disk('v3_private')->get($manifestArtifact->storage_path);
        $this->assertSame(hash('sha256', $manifestBytes), $task->input_manifest_sha256);

        // Validate manifest adheres strictly to protocol schema
        $decodedManifest = LocalWorkerJsonDecoder::decode($manifestBytes, 2097152);
        LocalWorkerV1Validator::validateInputManifest($decodedManifest);

        $runtimeSettingsRef = collect($decodedManifest['artifacts'])->firstWhere('artifact_id', $decodedManifest['runtime_settings_artifact_id']);
        $this->assertNotNull($runtimeSettingsRef);
        $runtimeSettings = json_decode(
            Storage::disk('v3_private')->get(LocalArtifact::findOrFail($runtimeSettingsRef['artifact_id'])->storage_path),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame((string) $run->settings_version_id, $runtimeSettings['settings_version_id']);
        $this->assertSame($run->capture_sha256, $runtimeSettings['settings_sha256']);
        $wireSettings = json_decode(Storage::disk('v3_private')->get(
            LocalArtifact::findOrFail($runtimeSettingsRef['artifact_id'])->storage_path
        ), false, 512, JSON_THROW_ON_ERROR);
        foreach (['limits', 'qualification', 'providers', 'templates'] as $group) {
            $this->assertIsObject($wireSettings->captured_settings->{$group});
        }
        $capturedSettings = is_array($run->captured_settings_json)
            ? $run->captured_settings_json
            : json_decode((string) $run->captured_settings_json, true);
        $this->assertSame($capturedSettings['limits'] ?? [], $runtimeSettings['captured_settings']['limits']);
        $this->assertSame($capturedSettings['providers']['coding_order'], $runtimeSettings['captured_settings']['providers']['coding_order']);
        $this->assertArrayNotHasKey('api_key', $runtimeSettings['captured_settings']['providers']['prompt']);
        $frameworkRef = collect($decodedManifest['instruction_artifacts'])->firstWhere('name', 'framework');
        $this->assertNotNull($frameworkRef);
        $this->assertSame($frameworkRef['sha256'], LocalArtifact::findOrFail($frameworkRef['artifact_id'])->digest_sha256);
        $protocolRef = collect($decodedManifest['instruction_artifacts'])->firstWhere('name', 'notebooklm_protocol');
        $this->assertNotNull($protocolRef);
        $protocolArtifact = LocalArtifact::findOrFail($protocolRef['artifact_id']);
        $protocolBytes = Storage::disk('v3_private')->get($protocolArtifact->storage_path);
        $this->assertSame($protocolRef['sha256'], hash('sha256', $protocolBytes));
        $protocolBundle = json_decode($protocolBytes, true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame($decodedManifest['protocol_id'], $protocolBundle['protocol_id']);
        $this->assertSame($decodedManifest['protocol_version'], $protocolBundle['protocol_version']);
        $this->assertNotEmpty($protocolBundle['divide_prompt']);
        $this->assertNotEmpty($protocolBundle['execution_prompt']);
        $this->assertNotEmpty($protocolBundle['combined_response_format']);

        $this->assertSame(2, LocalArtifact::where('job_id', $jobId)->where('purpose', 'original_pdf')->count());
        foreach ($decodedManifest['items'] as $manifestItem) {
            $originalArtifact = LocalArtifact::findOrFail($manifestItem['original_artifact_id']);
            $preparedArtifact = LocalArtifact::findOrFail($manifestItem['prepared_artifact_id']);
            $this->assertSame('original_pdf', $originalArtifact->purpose);
            $this->assertSame('prepared_pdf', $preparedArtifact->purpose);
            $this->assertNotSame($originalArtifact->id, $preparedArtifact->id);
            $originalBytes = Storage::disk('v3_private')->get($originalArtifact->storage_path);
            $this->assertSame($manifestItem['original_sha256'], hash('sha256', $originalBytes));
            $this->assertSame($manifestItem['original_byte_count'], strlen($originalBytes));
        }

        // Verify items were processed through AcceptRunItem: Documents exist and are ready
        $this->assertCount(2, $run->items);
        foreach ($run->items as $item) {
            $this->assertSame('accepted', $item->state);
            $this->assertNotNull($item->document_id);
            $this->assertNotNull($item->lecture_id);
            $this->assertNotNull($item->case_id);

            $doc = Document::findOrFail($item->document_id);
            $this->assertSame('ready', $doc->state);
            $this->assertTrue(Storage::disk('v3_private')->exists($doc->storage_path));

            // Verify ProcessingCase has isLocalWorkerCase = true
            $case = ProcessingCase::findOrFail($item->case_id);
            $this->assertTrue($case->isLocalWorkerCase());
        }

        // Verify legacy dispatchers EXCLUDE this run and its cases (exclusivity guarantee)
        $outboxCount = PipelineOutbox::where('state', 'pending')->where('destination', 'application_bridge')->count();
        $this->assertSame(0, $outboxCount);

        $dispatchOutbox = app(DispatchRunOutbox::class);
        $dispatched = $dispatchOutbox->dispatchPending();
        $this->assertSame(0, $dispatched);

        $discoverCases = app(DiscoverCases::class);
        $discovered = $discoverCases->discover();
        $this->assertSame(0, $discovered);

        $activateMap = app(ActivateMap::class);
        $activatedMaps = $activateMap->discoverAndDispatch();
        $this->assertSame(0, $activatedMaps);

        $activateSource = app(ActivateSource::class);
        $activatedSources = $activateSource->discoverAndDispatch();
        $this->assertSame(0, $activatedSources);
    }

    public function test_idempotent_admission_returns_existing_job(): void
    {
        $submitService = app(SubmitSubjectRun::class);
        $submissionKey = (string) Str::uuid();

        $pdf = $this->makePdf('mechanics.pdf', 1);

        $result1 = $submitService->submit($this->teacher, $this->subject->id, [
            'submission_key' => $submissionKey,
            'files' => [$pdf],
            'titles' => ['Mechanics'],
            'mode' => 'export_manual',
            'execution_mode' => 'local_worker_v1',
            'output_scope' => 'prompts_only',
            'qualification_mode' => 'owner_waived_demo',
            'waiver_acknowledged' => true,
        ]);

        $pdfRepeat = $this->makePdf('mechanics.pdf', 1);

        $result2 = $submitService->submit($this->teacher, $this->subject->id, [
            'submission_key' => $submissionKey,
            'files' => [$pdfRepeat],
            'titles' => ['Mechanics'],
            'mode' => 'export_manual',
            'execution_mode' => 'local_worker_v1',
            'output_scope' => 'prompts_only',
            'qualification_mode' => 'owner_waived_demo',
            'waiver_acknowledged' => true,
        ]);

        $this->assertSame($result1['run_id'], $result2['run_id']);
        $this->assertSame($result1['state'], $result2['state']);
    }

    public function test_admission_seals_one_retained_original_across_split_prepared_parts(): void
    {
        $result = app(SubmitSubjectRun::class)->submit($this->teacher, $this->subject->id, [
            'submission_key' => (string) Str::uuid(),
            'files' => [$this->makePdf('large-mechanics.pdf', 51)],
            'titles' => ['Large Mechanics'],
            'mode' => 'export_manual',
            'execution_mode' => 'local_worker_v1',
            'output_scope' => 'prompts_only',
            'qualification_mode' => 'owner_waived_demo',
            'waiver_acknowledged' => true,
        ]);

        $manifestArtifact = LocalArtifact::where('job_id', $result['job_id'])
            ->where('purpose', 'input_manifest')->firstOrFail();
        $manifest = LocalWorkerJsonDecoder::decode(
            Storage::disk('v3_private')->get($manifestArtifact->storage_path),
            2097152
        );
        LocalWorkerV1Validator::validateInputManifest($manifest);

        $this->assertCount(2, $manifest['items']);
        $this->assertSame($manifest['items'][0]['original_artifact_id'], $manifest['items'][1]['original_artifact_id']);
        $original = LocalArtifact::findOrFail($manifest['items'][0]['original_artifact_id']);
        $this->assertSame('original_pdf', $original->purpose);
        $this->assertSame(51, $manifest['items'][0]['original_page_count']);
        $this->assertSame(51, $manifest['items'][1]['original_page_count']);
        $this->assertSame(1, $manifest['items'][0]['original_page_start']);
        $this->assertSame(25, $manifest['items'][0]['original_page_end']);
        $this->assertSame(26, $manifest['items'][1]['original_page_start']);
        $this->assertSame(51, $manifest['items'][1]['original_page_end']);
        $this->assertNotSame($original->digest_sha256, $manifest['items'][0]['prepared_sha256']);
        $this->assertSame(
            $manifest['items'][0]['original_sha256'],
            hash('sha256', Storage::disk('v3_private')->get($original->storage_path))
        );
    }
}
