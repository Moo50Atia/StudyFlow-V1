<?php

namespace Tests\Feature\V3\WebPipeline;

use App\Models\Lecture;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeacherPermission;
use App\Models\User;
use App\Models\V3\LecturePipelineLink;
use App\Models\V3\NotebookFrameworkVersion;
use App\Models\V3\SectionPipelineLink;
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\V3\AuthoringFixtures;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

class LinkedContentAccessTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacherAlpha;
    protected User $teacherBeta;
    protected User $admin;
    protected User $student;
    protected Subject $subject;
    protected Lecture $linkedLecture;
    protected Section $linkedSection;
    protected Lecture $unlinkedLecture;
    protected Section $unlinkedSection;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Actors from fixtures
        $this->teacherAlpha = WebPipelineFixtures::createTeacherA();
        $this->teacherBeta = WebPipelineFixtures::createTeacherB();
        $this->admin = WebPipelineFixtures::createAdmin();
        $this->student = WebPipelineFixtures::createStudent();

        // 2. Shared Subject
        $this->subject = WebPipelineFixtures::createSubjectForTeacher($this->teacherAlpha, 'Physics 101');

        // 3. Setup V3 Web Pipeline run and items for Teacher Alpha
        $env = AuthoringFixtures::createAuthoringEnvironment();
        $case = $env['case'];

        $frameworkId = (string) Str::uuid();
        DB::table('v3_notebook_framework_versions')->insert([
            'id' => $frameworkId,
            'profile' => 'expanded-root-v1',
            'body_path' => 'frameworks/test.txt',
            'body_sha256' => hash('sha256', 'framework body'),
            'body_bytes' => 100,
            'first_sha256' => hash('sha256', 'first'),
            'second_sha256' => hash('sha256', 'second'),
            'third_sha256' => hash('sha256', 'third'),
            'response_format_sha256' => hash('sha256', 'format'),
            'approved_by_user_id' => $this->teacherAlpha->id,
            'approved_at' => now(),
            'created_at' => now(),
        ]);

        $run = SubjectRun::create([
            'id' => (string) Str::uuid(),
            'origin' => 'web_intake',
            'owner_user_id' => $this->teacherAlpha->id,
            'subject_id' => $this->subject->id,
            'settings_version_id' => $case->settings_version_id,
            'framework_version_id' => $frameworkId,
            'captured_settings_json' => ['max_pdf_bytes' => 50000000],
            'capture_sha256' => hash('sha256', 'cap'),
            'submission_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', 'req'),
        ]);

        $runItem = SubjectRunItem::create([
            'id' => (string) Str::uuid(),
            'run_id' => $run->id,
            'owner_user_id' => $this->teacherAlpha->id,
            'pdf_ordinal' => 1,
            'original_filename' => '01_kinematics.pdf',
            'display_title' => 'Kinematics PDF 1',
        ]);

        // 4. Linked Lecture and Section
        $this->linkedLecture = Lecture::create([
            'subject_id' => $this->subject->id,
            'title' => 'Pipeline Linked Kinematics',
            'summary' => 'Pipeline generated lecture',
        ]);

        LecturePipelineLink::create([
            'lecture_id' => $this->linkedLecture->id,
            'item_id' => $runItem->id,
            'owner_user_id' => $this->teacherAlpha->id,
            'protocol' => 'notebooklm_per_pdf_web_v1',
        ]);

        $secFixture = AuthoringFixtures::createQualifiedSection($case, $env['map']);
        $teacherSection = $secFixture['section'];

        $this->linkedSection = Section::create([
            'lecture_id' => $this->linkedLecture->id,
            'title' => 'Curvilinear Kinematics Section',
            'core_concept' => 'Tangential and normal acceleration',
        ]);

        SectionPipelineLink::create([
            'section_id' => $this->linkedSection->id,
            'teacher_section_id' => $teacherSection->id,
            'item_id' => $runItem->id,
            'owner_user_id' => $this->teacherAlpha->id,
        ]);

        // 5. Normal unlinked Lecture and Section
        $this->unlinkedLecture = Lecture::create([
            'subject_id' => $this->subject->id,
            'title' => 'Standard Unlinked Lecture',
            'summary' => 'Manually created lecture',
        ]);

        $this->unlinkedSection = Section::create([
            'lecture_id' => $this->unlinkedLecture->id,
            'title' => 'Standard Unlinked Section',
            'core_concept' => 'Basic concept',
        ]);

        WebPipelineFixtures::grantTeacherPermission($this->teacherAlpha, $this->subject, $this->linkedLecture);
        WebPipelineFixtures::grantTeacherPermission($this->teacherAlpha, $this->subject, $this->unlinkedLecture);
        WebPipelineFixtures::grantTeacherPermission($this->teacherBeta, $this->subject, $this->unlinkedLecture);
    }

    /**
     * FR-002, SC-006, A26: Owner teacher (Alpha) can access their own pipeline-linked lecture and section.
     */
    public function test_owner_teacher_can_access_own_pipeline_linked_content(): void
    {
        $response = $this->actingAs($this->teacherAlpha)->get(route('lectures.show', $this->linkedLecture));
        $response->assertOk();

        $secResponse = $this->actingAs($this->teacherAlpha)->get(route('sections.show', $this->linkedSection));
        $secResponse->assertOk();
    }

    /**
     * FR-002, SC-006, A26: Non-owner teacher (Beta) is forbidden from viewing or modifying Alpha's pipeline-linked lecture.
     */
    public function test_non_owner_teacher_is_forbidden_from_linked_lecture(): void
    {
        // 1. Show
        $response = $this->actingAs($this->teacherBeta)->get(route('lectures.show', $this->linkedLecture));
        $response->assertForbidden();

        // 2. Edit
        $editResponse = $this->actingAs($this->teacherBeta)->get(route('lectures.edit', $this->linkedLecture));
        $editResponse->assertForbidden();

        // 3. Update
        $updateResponse = $this->actingAs($this->teacherBeta)->put(route('lectures.update', $this->linkedLecture), [
            'subject_id' => $this->subject->id,
            'title' => 'Hacked Title',
        ]);
        $updateResponse->assertForbidden();

        // 4. Delete
        $deleteResponse = $this->actingAs($this->teacherBeta)->delete(route('lectures.destroy', $this->linkedLecture));
        $deleteResponse->assertForbidden();
    }

    /**
     * FR-002, SC-006, A26: Non-owner teacher (Beta) is forbidden from Alpha's pipeline-linked section.
     */
    public function test_non_owner_teacher_is_forbidden_from_linked_section(): void
    {
        $response = $this->actingAs($this->teacherBeta)->get(route('sections.show', $this->linkedSection));
        $response->assertForbidden();

        $editResponse = $this->actingAs($this->teacherBeta)->get(route('sections.edit', $this->linkedSection));
        $editResponse->assertForbidden();

        $updateResponse = $this->actingAs($this->teacherBeta)->put(route('sections.update', $this->linkedSection), [
            'lecture_id' => $this->linkedLecture->id,
            'title' => 'Hacked Section Title',
        ]);
        $updateResponse->assertForbidden();
    }

    /**
     * FR-002, SC-006, A26: Non-owner teacher does not see Alpha's linked lecture in index listing.
     */
    public function test_non_owner_teacher_does_not_see_linked_lecture_in_listings(): void
    {
        $response = $this->actingAs($this->teacherBeta)->get(route('lectures.index'));
        $response->assertOk();
        $response->assertDontSee($this->linkedLecture->title);
        $response->assertSee($this->unlinkedLecture->title);
    }

    /**
     * FR-002, SC-006, A26: API filter does not return linked lecture to non-owner.
     */
    public function test_api_filter_does_not_leak_linked_lecture_to_non_owner(): void
    {
        // 1. Beta queries subject lectures
        $response = $this->actingAs($this->teacherBeta)->getJson("/api/subjects/{$this->subject->id}/lectures");
        $response->assertOk();
        $titles = collect($response->json())->pluck('title')->all();
        $this->assertNotContains($this->linkedLecture->title, $titles);
        $this->assertContains($this->unlinkedLecture->title, $titles);

        // 2. Beta queries linked lecture's sections directly
        $secResponse = $this->actingAs($this->teacherBeta)->getJson("/api/lectures/{$this->linkedLecture->id}/sections");
        $secResponse->assertForbidden();
    }

    /**
     * FR-002, SC-006, A26: Student cannot view unapproved pipeline-linked lecture.
     */
    public function test_student_cannot_view_unapproved_pipeline_linked_lecture(): void
    {
        $response = $this->actingAs($this->student)->get(route('lectures.show', $this->linkedLecture));
        $response->assertForbidden();
    }

    /**
     * FR-002, FR-027, SC-006, A28: Unlinked lecture regression passes for both teachers.
     */
    public function test_unlinked_lecture_regression_remains_accessible(): void
    {
        $responseA = $this->actingAs($this->teacherAlpha)->get(route('lectures.show', $this->unlinkedLecture));
        $responseA->assertOk();

        $responseB = $this->actingAs($this->teacherBeta)->get(route('lectures.show', $this->unlinkedLecture));
        $responseB->assertOk();
    }

    /**
     * FR-027, SC-006: MohammedTestController does not leak linked educational content.
     */
    public function test_public_test_endpoint_does_not_leak_linked_lecture_content(): void
    {
        $response = $this->get('/mohammed-test');
        $response->assertOk();
        $response->assertDontSee($this->linkedLecture->title);
        $response->assertDontSee($this->linkedSection->title);
    }
}
