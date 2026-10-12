<?php

namespace Tests\Feature\V3\WebPipeline;

use App\Models\Subject;
use App\Models\User;
use App\Services\V3\WebPipeline\MaterializeTerminalImport;
use App\Services\V3\WebPipeline\TerminalArchiveReader;
use App\Services\V3\Settings\PublishSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\SettingsFixtures;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

class ImportedLectureViewTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $teacher;
    protected User $otherTeacher;
    protected User $student;
    protected Subject $subject;
    protected string $archiveRoot;
    protected array $materialized;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('v3_private');

        $this->admin = WebPipelineFixtures::createAdmin();
        $this->teacher = WebPipelineFixtures::createTeacherA();
        $this->otherTeacher = WebPipelineFixtures::createTeacherB();
        $this->student = WebPipelineFixtures::createStudent();

        SettingsFixtures::registerDefaultTemplates();
        app(PublishSettings::class)->publish(SettingsFixtures::validPayload(), 0, $this->admin->id);
        $frameworkId = (string) Str::uuid();
        $frameworkBody = 'Historical destination framework';
        DB::table('v3_notebook_framework_versions')->insert([
            'id' => $frameworkId,
            'profile' => 'expanded-root-v1',
            'body_path' => 'frameworks/' . $frameworkId . '.md',
            'body_sha256' => hash('sha256', $frameworkBody),
            'body_bytes' => strlen($frameworkBody),
            'first_sha256' => hash('sha256', 'first'),
            'second_sha256' => hash('sha256', 'second'),
            'third_sha256' => hash('sha256', 'third'),
            'response_format_sha256' => hash('sha256', 'format'),
            'approved_by_user_id' => $this->admin->id,
            'approved_at' => now(),
            'created_at' => now(),
        ]);
        Storage::disk('v3_private')->put('frameworks/' . $frameworkId . '.md', $frameworkBody);

        $this->subject = WebPipelineFixtures::createSubjectForTeacher($this->teacher, 'Kinematics & Dynamics');
        $initLec = \App\Models\Lecture::create([
            'subject_id' => $this->subject->id,
            'title' => 'Initial Syllabus',
        ]);
        WebPipelineFixtures::grantTeacherPermission($this->teacher, $this->subject, $initLec);

        $this->archiveRoot = sys_get_temp_dir() . '/v3_synth_view_' . uniqid();
        WebPipelineFixtures::buildSyntheticTwoPdfArchive($this->archiveRoot);

        $reader = app(TerminalArchiveReader::class);
        $materializer = app(MaterializeTerminalImport::class);

        $plan = $reader->verifyAndPlanImport(
            terminalRoot: $this->archiveRoot . '/terminal-root',
            runId: WebPipelineFixtures::MOCK_RUN_ID,
            subjectId: $this->subject->id,
            teacherId: $this->teacher->id,
            actorAdminId: $this->admin->id,
            importId: (string) Str::uuid(),
            isDryRun: false
        );

        $this->materialized = $materializer->materialize($plan['capture']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->archiveRoot)) {
            $files = array_diff(scandir($this->archiveRoot), ['.', '..']);
            foreach ($files as $file) {
                $sub = $this->archiveRoot . '/' . $file;
                is_dir($sub) ? $this->removeDirectory($sub) : unlink($sub);
            }
            rmdir($this->archiveRoot);
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

    public function test_owner_teacher_can_view_imported_lectures_in_subject_show(): void
    {
        $response = $this->actingAs($this->teacher)
            ->get(route('subjects.show', $this->subject));

        $response->assertOk();
        $response->assertSee('Kinematics');
        $response->assertSee('Historical');
    }

    public function test_owner_teacher_can_view_imported_lecture_detail_with_historical_badge(): void
    {
        $lecture = $this->subject->lectures()->whereHas('pipelineLink')->firstOrFail();

        $response = $this->actingAs($this->teacher)
            ->get(route('lectures.show', $lecture));

        $response->assertOk();
        $response->assertSee($lecture->title);
        $response->assertSee('Imported Historical Content');
    }

    public function test_non_owner_teacher_is_forbidden_from_viewing_imported_lecture(): void
    {
        $lecture = $this->subject->lectures()->whereHas('pipelineLink')->firstOrFail();

        $response = $this->actingAs($this->otherTeacher)
            ->get(route('lectures.show', $lecture));

        $response->assertForbidden();
    }

    public function test_student_cannot_view_unapproved_imported_lecture(): void
    {
        $lecture = $this->subject->lectures()->whereHas('pipelineLink')->firstOrFail();

        $response = $this->actingAs($this->student)
            ->get(route('lectures.show', $lecture));

        $response->assertForbidden();
    }

    public function test_no_dynamic_view_link_is_fabricated_for_imported_lecture(): void
    {
        $lecture = $this->subject->lectures()->whereHas('pipelineLink')->firstOrFail();

        $projection = app(\App\Services\V3\WebPipeline\LectureProjection::class)
            ->projectLecture($lecture, $this->teacher);

        $this->assertTrue($projection['is_pipeline_linked']);
        $this->assertTrue($projection['is_historical']);
        $this->assertFalse($projection['has_dynamic_view']);
        $this->assertNull($projection['dynamic_view_link']);
    }
}
