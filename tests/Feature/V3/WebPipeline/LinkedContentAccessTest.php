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
        $response->assertRedirect(route('login'));
        $response->assertDontSee($this->linkedLecture->title);
        $response->assertDontSee($this->linkedSection->title);
    }

    public function test_publication_opens_legacy_routes_and_correction_revokes_them(): void
    {
        $source = $this->linkedSection->pipelineLink->teacherSection;
        foreach (\App\Services\V3\WebPipeline\LessonProjection::REQUIRED as $field) {
            $source->{$field} = $field;
        }
        $source->save();
        DB::table('v3_lesson_publications')->insert([
            'work_node_id' => $source->work_node_id,
            'lecture_id' => $this->linkedLecture->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->student)->get(route('sections.show', $this->linkedSection))->assertForbidden();
        $this->actingAs($this->teacherAlpha)->post(route('lessons.publication.update', $source->work_node_id), ['published' => 1])->assertRedirect();
        $this->actingAs($this->student)->get(route('sections.show', $this->linkedSection))->assertOk();

        $this->actingAs($this->teacherAlpha)->put(route('sections.update', $this->linkedSection), [
            'lecture_id' => $this->linkedLecture->id,
            'title' => 'Corrected title',
            'core_concept' => 'Corrected concept',
        ])->assertRedirect();
        $this->actingAs($this->student)->get(route('sections.show', $this->linkedSection))->assertForbidden();
        $this->assertDatabaseHas('v3_lesson_publication_events', ['work_node_id' => $source->work_node_id, 'actor_user_id' => $this->teacherAlpha->id, 'action' => 'correction']);
    }

    public function test_teacher_creates_owned_subject_and_other_teacher_cannot_open_it(): void
    {
        $response = $this->actingAs($this->teacherAlpha)->post(route('subjects.store'), ['name' => 'New private course']);
        $response->assertRedirect();
        $subject = Subject::where('name', 'New private course')->firstOrFail();
        $this->assertSame($this->teacherAlpha->id, $subject->owner_user_id);
        $this->assertDatabaseHas('teacher_permissions', ['teacher_id' => $this->teacherAlpha->id, 'subject_id' => $subject->id, 'lecture_id' => null]);
        $this->actingAs($this->teacherBeta)->get(route('subjects.show', $subject))->assertForbidden();
        $this->actingAs($this->student)->get(route('subjects.show', $subject))->assertForbidden();
    }

    public function test_projection_fills_empty_legacy_fields_without_overwriting_edits_or_duplicating(): void
    {
        $source = $this->linkedSection->pipelineLink->teacherSection;
        $source->update([
            'section_identity' => 'Original title',
            'conceptual_summary' => 'Notebook summary',
            'core_understanding' => 'Notebook core',
            'colloquial_egyptian_explanation' => 'شرح مصري',
            'laws_if_any' => 'F = ma',
            'real_life_example' => 'A car accelerating',
        ]);
        $service = app(\App\Services\V3\WebPipeline\LessonProjection::class);
        $itemId = $this->linkedSection->pipelineLink->item_id;
        $service->project($source, $this->linkedLecture->id, $this->teacherAlpha->id, $itemId);
        $service->project($source, $this->linkedLecture->id, $this->teacherAlpha->id, $itemId);
        $this->linkedSection->refresh();
        $this->assertSame('Curvilinear Kinematics Section', $this->linkedSection->title);
        $this->assertSame('Tangential and normal acceleration', $this->linkedSection->core_concept);
        $this->assertSame('Notebook summary', $this->linkedSection->quick_summary);
        $this->assertSame('شرح مصري', $this->linkedSection->egyptian_explain);
        $this->assertSame('F = ma', $this->linkedSection->formulas);
        $this->assertSame('A car accelerating', $this->linkedSection->real_life);
        $this->assertSame(1, SectionPipelineLink::where('teacher_section_id', $source->id)->count());
    }

    public function test_owner_only_features_and_private_mindmap_asset(): void
    {
        $owner = User::factory()->create(['email' => 'admin@test.com', 'role' => 'admin']);
        $this->linkedLecture->update(['mindmap_path' => 'lectures/mindmaps/private.png']);
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('lectures/mindmaps/private.png', 'image bytes');
        $asset = route('owner-assets.show', ['kind' => 'lecture', 'id' => $this->linkedLecture->id, 'field' => 'mindmap_path']);
        $this->actingAs($this->student)->get($asset)->assertForbidden();
        $this->actingAs($this->teacherAlpha)->get($asset)->assertForbidden();
        $this->actingAs($owner)->get($asset)->assertOk();
        $this->actingAs($this->teacherAlpha)->get(route('questions.index'))->assertForbidden();
        $this->actingAs($this->student)->get(route('exam_questions.index'))->assertForbidden();
        $this->actingAs($this->teacherAlpha)->post(route('questions.store'), ['lecture_id' => $this->linkedLecture->id, 'question_text' => 'Forged'])->assertForbidden();
        $this->actingAs($this->student)->post(route('exam_questions.store'), ['lecture_id' => $this->linkedLecture->id])->assertForbidden();
        $this->actingAs($this->teacherAlpha)->post(route('love.toggle'), ['type' => 'question', 'id' => 1])->assertForbidden();
        $this->actingAs($this->admin)->get($asset)->assertForbidden();
    }

    public function test_role_dashboards_render_without_restricted_features(): void
    {
        $this->actingAs($this->teacherAlpha)->get(route('dashboard'))
            ->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->component('Dashboard')->where('role','teacher')->has('subjects'));
        $this->actingAs($this->student)->get(route('dashboard'))
            ->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->component('Dashboard')->where('role','student')->has('lectures'));
        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->component('Dashboard')->where('role','admin')->where('isOwner',false));
        $owner = User::factory()->create(['email' => 'admin@test.com', 'role' => 'admin']);
        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->component('Dashboard')->where('role','admin')->where('isOwner',true));
    }

    public function test_limited_admin_cannot_claim_or_change_owner_account(): void
    {
        $this->actingAs($this->admin)->patch(route('profile.update'), [
            'name' => $this->admin->name, 'email' => 'admin@test.com',
        ])->assertForbidden();
        $owner = User::factory()->create(['email' => 'admin@test.com', 'role' => 'admin']);
        $this->actingAs($this->admin)->delete(route('users.destroy', $owner))->assertForbidden();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Services\V3\Access\ChangeAccountAccess::class)->updateAccess(
            $this->admin, $owner->id, 'student', false, 0, 'admin', 'change_role'
        );
    }

    public function test_teacher_cannot_delete_lecture_containing_owner_question(): void
    {
        \App\Models\Question::create(['lecture_id' => $this->unlinkedLecture->id, 'question_text' => 'Owner question']);
        $this->actingAs($this->teacherAlpha)->delete(route('lectures.destroy', $this->unlinkedLecture))->assertForbidden();
        $this->assertDatabaseHas('questions', ['lecture_id' => $this->unlinkedLecture->id]);
        $this->actingAs($this->teacherAlpha)->delete(route('subjects.destroy', $this->subject))->assertForbidden();
        $this->assertDatabaseHas('subjects', ['id' => $this->subject->id]);
    }

    public function test_teacher_cannot_upload_or_replace_mindmap(): void
    {
        $upload = \Illuminate\Http\UploadedFile::fake()->image('map.png');
        $this->actingAs($this->teacherAlpha)->post(route('lectures.store'), [
            'subject_id' => $this->subject->id, 'title' => 'Mind map attempt', 'mindmap_path' => $upload,
        ])->assertForbidden();
        $this->actingAs($this->teacherAlpha)->put(route('lectures.update', $this->unlinkedLecture), [
            'subject_id' => $this->subject->id, 'title' => $this->unlinkedLecture->title,
            'mindmap_path' => \Illuminate\Http\UploadedFile::fake()->image('replacement.png'),
        ])->assertForbidden();
    }

    public function test_final_reset_callback_marks_run_completed(): void
    {
        $item = SubjectRunItem::findOrFail($this->linkedLecture->pipelineLink->item_id);
        $item->update(['state' => 'archived', 'archive_ref' => 'private-archive-ref']);
        $path = "/api/v3/internal/web-pipeline/items/{$item->id}/reset-verified";
        $body = [
            'run_id' => $item->run_id,
            'archive_ref' => 'private-archive-ref',
            'reset_history_sha256' => hash('sha256', ''),
        ];
        $headers = \Tests\Support\V3\WorkflowFixtures::signCallbackRequest('POST', $path, $body);
        $this->json('POST', $path, $body, array_intersect_key($headers, array_flip([
            'X-V3-Timestamp', 'X-V3-Signature', 'X-V3-Contract-Version',
        ])))->assertOk();
        $this->assertSame('completed', SubjectRun::findOrFail($item->run_id)->state);
    }
}
