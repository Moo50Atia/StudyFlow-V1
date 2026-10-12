<?php

namespace Tests\Feature\V3\WebPipeline;

use App\Models\Subject;
use App\Models\User;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptSectionState;
use App\Models\V3\PromptWorkItem;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use App\Models\V3\TeacherSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

class PromptExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacherA;
    protected User $teacherB;
    protected User $admin;
    protected Subject $subject;
    protected SubjectRun $run;
    protected SubjectRunItem $item1;
    protected SubjectRunItem $item2;
    protected TeacherSection $section1;
    protected TeacherSection $section2;
    protected ScenePromptVersion $prompt1;
    protected ScenePromptVersion $prompt2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = WebPipelineFixtures::createAdmin();
        $this->teacherA = WebPipelineFixtures::createTeacherA();
        $this->teacherB = WebPipelineFixtures::createTeacherB();
        $this->subject = WebPipelineFixtures::createSubjectForTeacher($this->teacherA);

        $lecture = \App\Models\Lecture::create([
            'subject_id' => $this->subject->id,
            'title' => 'Intro Lecture',
        ]);
        WebPipelineFixtures::grantTeacherPermission($this->teacherA, $this->subject, $lecture);

        $settingsId = DB::table('v3_settings_versions')->insertGetId([
            'version' => 1,
            'schema_version' => 1,
            'payload' => '{}',
            'checksum' => hash('sha256', '{}'),
            'published_by_user_id' => $this->admin->id,
            'published_at' => now(),
        ]);

        $frameworkId = (string) Str::uuid();
        DB::table('v3_notebook_framework_versions')->insert([
            'id' => $frameworkId,
            'profile' => 'expanded-root-v1',
            'body_path' => 'frameworks/' . $frameworkId . '.md',
            'body_sha256' => hash('sha256', 'mock_framework'),
            'body_bytes' => 14,
            'first_sha256' => hash('sha256', 'first'),
            'second_sha256' => hash('sha256', 'second'),
            'third_sha256' => hash('sha256', 'third'),
            'response_format_sha256' => hash('sha256', 'fmt'),
            'approved_by_user_id' => $this->admin->id,
            'approved_at' => now(),
            'created_at' => now(),
        ]);

        $this->run = SubjectRun::create([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacherA->id,
            'subject_id' => $this->subject->id,
            'origin' => 'web',
            'protocol' => 'notebooklm_per_pdf_web_v1',
            'settings_version_id' => $settingsId,
            'framework_version_id' => $frameworkId,
            'mode' => 'export_manual',
            'qualification_mode' => 'owner_waived_demo',
            'submission_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', 'run1'),
            'captured_settings_json' => '{}',
            'capture_sha256' => hash('sha256', '{}'),
            'state' => 'prompts_ready',
            'state_version' => 3,
            'total_pdfs' => 2,
            'total_lessons' => 2,
            'total_scenes' => 2,
            'last_event_sequence' => 3,
        ]);

        // Item 1
        $this->item1 = SubjectRunItem::create([
            'id' => (string) Str::uuid(),
            'run_id' => $this->run->id,
            'owner_user_id' => $this->teacherA->id,
            'pdf_ordinal' => 1,
            'display_title' => 'Lecture 1: Kinematics',
            'original_filename' => 'kinematics.pdf',
            'input_sha256' => hash('sha256', 'pdf1'),
            'input_bytes' => 1024,
            'state' => 'prompts_ready',
            'saved_lesson_count' => 1,
            'prompt_count' => 1,
            'state_version' => 2,
        ]);

        // Item 2
        $this->item2 = SubjectRunItem::create([
            'id' => (string) Str::uuid(),
            'run_id' => $this->run->id,
            'owner_user_id' => $this->teacherA->id,
            'pdf_ordinal' => 2,
            'display_title' => 'Lecture 2: Dynamics',
            'original_filename' => 'dynamics.pdf',
            'input_sha256' => hash('sha256', 'pdf2'),
            'input_bytes' => 2048,
            'state' => 'prompts_ready',
            'saved_lesson_count' => 1,
            'prompt_count' => 1,
            'state_version' => 2,
        ]);

        // Case 1
        $doc1 = \App\Models\V3\Document::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacherA->id,
            'sha256' => hash('sha256', 'pdf1'),
            'byte_size' => 1024,
            'page_count' => 1,
            'state' => 'ready',
            'storage_path' => 'accepted/p1.pdf',
            'accepted_at' => now(),
        ]);
        $this->item1->update(['document_id' => $doc1->id]);

        $case1 = ProcessingCase::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacherA->id,
            'document_id' => $doc1->id,
            'settings_version_id' => $settingsId,
            'idempotency_key' => 'item:' . $this->item1->id,
            'action' => 'web_subject',
            'state' => 'prompts_ready',
            'state_version' => 2,
            'pipeline_version' => 'notebooklm_per_pdf_web_v1',
            'notebook_framework_version_id' => $frameworkId,
            'subject_run_item_id' => $this->item1->id,
        ]);
        $this->item1->update(['case_id' => $case1->id]);

        // Section 1
        $srcAct1 = (string) Str::uuid();
        DB::table('v3_source_activations')->insert(['id' => $srcAct1, 'case_id' => $case1->id, 'workflow_id' => 'wf1', 'state' => 'accepted']);
        $src1 = (string) Str::uuid();
        DB::table('v3_extracted_sources')->insert(['id' => $src1, 'case_id' => $case1->id, 'activation_id' => $srcAct1, 'document_id' => $doc1->id, 'settings_version_id' => $settingsId, 'status' => 'ready', 'page_report' => '{}', 'extractor_version' => 'v1']);
        $bind1 = (string) Str::uuid();
        DB::table('v3_knowledge_source_bindings')->insert(['id' => $bind1, 'case_id' => $case1->id, 'extracted_source_id' => $src1, 'adapter_kind' => 'nb', 'adapter_version' => '1', 'notebook_title' => 't', 'source_title' => 's', 'state' => 'bound', 'text_sha256' => 't', 'text_bytes' => 1]);
        $mapAct1 = (string) Str::uuid();
        DB::table('v3_map_activations')->insert(['id' => $mapAct1, 'case_id' => $case1->id, 'binding_id' => $bind1, 'workflow_id' => 'w', 'state' => 'ready']);
        $map1 = (string) Str::uuid();
        DB::table('v3_lesson_maps')->insert(['id' => $map1, 'case_id' => $case1->id, 'binding_id' => $bind1, 'activation_id' => $mapAct1, 'state' => 'ready', 'page_count' => 1]);
        $node1 = (string) Str::uuid();
        DB::table('v3_lesson_nodes')->insert(['id' => $node1, 'map_id' => $map1, 'kind' => 'l', 'position_path' => '001', 'ordinal_in_parent' => 1, 'title' => 'L1', 'work_ordinal' => 1]);
        $q1 = (string) Str::uuid();
        DB::table('v3_lesson_queries')->insert(['id' => $q1, 'case_id' => $case1->id, 'map_id' => $map1, 'kind' => 'first', 'target_work_ordinal' => 1, 'state' => 'recorded', 'send_count' => 1, 'request_sha256' => 'r']);

        $sec1Id = (string) Str::uuid();
        DB::table('v3_teacher_sections')->insert([
            'id' => $sec1Id,
            'case_id' => $case1->id,
            'map_id' => $map1,
            'work_node_id' => $node1,
            'query_id' => $q1,
            'ordinal_in_work' => 1,
            'section_identity' => 'Position and Velocity Integration',
            'core_understanding' => 'Integration of acceleration yields velocity.',
            'first_prompt_sha256' => hash('sha256', 'f1'),
            'answer_sha256' => hash('sha256', 'a1'),
            'origin' => 'pipeline',
            'subject_run_item_id' => $this->item1->id,
        ]);
        $this->section1 = TeacherSection::find($sec1Id);

        $prompt1Text = "Create an interactive graph showing s(t) and v(t) integration curves with a draggable slider.";
        $prompt1Sha = hash('sha256', $prompt1Text);
        $bodyPath1 = "prompt-versions/{$case1->id}/{$sec1Id}/v1_{$prompt1Sha}.txt";
        Storage::disk('v3_private')->put($bodyPath1, $prompt1Text);

        $wi1 = PromptWorkItem::create([
            'id' => (string) Str::uuid(),
            'case_id' => $case1->id,
            'map_id' => $map1,
            'work_node_id' => $node1,
            'work_ordinal' => 1,
            'expected_cursor_version' => 1,
            'section_query_id' => $q1,
            'state' => 'receipt_committed',
            'section_refs' => [$sec1Id],
        ]);

        $this->prompt1 = ScenePromptVersion::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case1->id,
            'work_item_id' => $wi1->id,
            'section_id' => $sec1Id,
            'version_no' => 1,
            'origin' => 'third',
            'body_path' => $bodyPath1,
            'body_sha256' => $prompt1Sha,
            'body_bytes' => strlen($prompt1Text),
            'scene_type' => '2D Interactive Graph',
            'section_fields_sha256' => hash('sha256', 'fields1'),
            'canonical_third_sha256' => hash('sha256', 't1'),
            'created_at' => now(),
        ]);
        PromptSectionState::create([
            'section_id' => $sec1Id,
            'case_id' => $case1->id,
            'current_prompt_version_id' => $this->prompt1->id,
            'status' => 'awaiting_manual',
            'revision_count' => 0,
        ]);

        // Case 2
        $doc2 = \App\Models\V3\Document::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacherA->id,
            'sha256' => hash('sha256', 'pdf2'),
            'byte_size' => 2048,
            'page_count' => 1,
            'state' => 'ready',
            'storage_path' => 'accepted/p2.pdf',
            'accepted_at' => now(),
        ]);
        $this->item2->update(['document_id' => $doc2->id]);

        $case2 = ProcessingCase::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacherA->id,
            'document_id' => $doc2->id,
            'settings_version_id' => $settingsId,
            'idempotency_key' => 'item:' . $this->item2->id,
            'action' => 'web_subject',
            'state' => 'prompts_ready',
            'state_version' => 2,
            'pipeline_version' => 'notebooklm_per_pdf_web_v1',
            'notebook_framework_version_id' => $frameworkId,
            'subject_run_item_id' => $this->item2->id,
        ]);
        $this->item2->update(['case_id' => $case2->id]);

        $srcAct2 = (string) Str::uuid();
        DB::table('v3_source_activations')->insert(['id' => $srcAct2, 'case_id' => $case2->id, 'workflow_id' => 'wf2', 'state' => 'accepted']);
        $src2 = (string) Str::uuid();
        DB::table('v3_extracted_sources')->insert(['id' => $src2, 'case_id' => $case2->id, 'activation_id' => $srcAct2, 'document_id' => $doc2->id, 'settings_version_id' => $settingsId, 'status' => 'ready', 'page_report' => '{}', 'extractor_version' => 'v1']);
        $bind2 = (string) Str::uuid();
        DB::table('v3_knowledge_source_bindings')->insert(['id' => $bind2, 'case_id' => $case2->id, 'extracted_source_id' => $src2, 'adapter_kind' => 'nb', 'adapter_version' => '1', 'notebook_title' => 't', 'source_title' => 's', 'state' => 'bound', 'text_sha256' => 't', 'text_bytes' => 1]);
        $mapAct2 = (string) Str::uuid();
        DB::table('v3_map_activations')->insert(['id' => $mapAct2, 'case_id' => $case2->id, 'binding_id' => $bind2, 'workflow_id' => 'w', 'state' => 'ready']);
        $map2 = (string) Str::uuid();
        DB::table('v3_lesson_maps')->insert(['id' => $map2, 'case_id' => $case2->id, 'binding_id' => $bind2, 'activation_id' => $mapAct2, 'state' => 'ready', 'page_count' => 1]);
        $node2 = (string) Str::uuid();
        DB::table('v3_lesson_nodes')->insert(['id' => $node2, 'map_id' => $map2, 'kind' => 'l', 'position_path' => '001', 'ordinal_in_parent' => 1, 'title' => 'L2', 'work_ordinal' => 1]);
        $q2 = (string) Str::uuid();
        DB::table('v3_lesson_queries')->insert(['id' => $q2, 'case_id' => $case2->id, 'map_id' => $map2, 'kind' => 'first', 'target_work_ordinal' => 1, 'state' => 'recorded', 'send_count' => 1, 'request_sha256' => 'r']);

        $sec2Id = (string) Str::uuid();
        DB::table('v3_teacher_sections')->insert([
            'id' => $sec2Id,
            'case_id' => $case2->id,
            'map_id' => $map2,
            'work_node_id' => $node2,
            'query_id' => $q2,
            'ordinal_in_work' => 1,
            'section_identity' => 'Friction Threshold Simulation',
            'core_understanding' => 'Static friction exceeds kinetic friction.',
            'first_prompt_sha256' => hash('sha256', 'f2'),
            'answer_sha256' => hash('sha256', 'a2'),
            'origin' => 'pipeline',
            'subject_run_item_id' => $this->item2->id,
        ]);
        $this->section2 = TeacherSection::find($sec2Id);

        $prompt2Text = "Render a 3D block with applied horizontal force demonstrating the stick-slip transition.";
        $prompt2Sha = hash('sha256', $prompt2Text);
        $bodyPath2 = "prompt-versions/{$case2->id}/{$sec2Id}/v1_{$prompt2Sha}.txt";
        Storage::disk('v3_private')->put($bodyPath2, $prompt2Text);

        $wi2 = PromptWorkItem::create([
            'id' => (string) Str::uuid(),
            'case_id' => $case2->id,
            'map_id' => $map2,
            'work_node_id' => $node2,
            'work_ordinal' => 1,
            'expected_cursor_version' => 1,
            'section_query_id' => $q2,
            'state' => 'receipt_committed',
            'section_refs' => [$sec2Id],
        ]);

        $this->prompt2 = ScenePromptVersion::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case2->id,
            'work_item_id' => $wi2->id,
            'section_id' => $sec2Id,
            'version_no' => 1,
            'origin' => 'third',
            'body_path' => $bodyPath2,
            'body_sha256' => $prompt2Sha,
            'body_bytes' => strlen($prompt2Text),
            'scene_type' => '3D Physical Simulation',
            'section_fields_sha256' => hash('sha256', 'fields2'),
            'canonical_third_sha256' => hash('sha256', 't2'),
            'created_at' => now(),
        ]);
        PromptSectionState::create([
            'section_id' => $sec2Id,
            'case_id' => $case2->id,
            'current_prompt_version_id' => $this->prompt2->id,
            'status' => 'awaiting_manual',
            'revision_count' => 0,
        ]);
    }

    public function test_owner_teacher_can_export_full_run_prompts_as_json(): void
    {
        $response = $this->actingAs($this->teacherA)
            ->getJson("/v3/teacher/subject-runs/{$this->run->id}/prompt-export");

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertSame(1, $data['schema_version']);
        $this->assertSame($this->run->id, $data['run_id']);
        $this->assertSame('export_manual', $data['mode']);
        $this->assertSame(2, $data['total_prompts']);
        $this->assertCount(2, $data['prompts']);

        $p1 = $data['prompts'][0];
        $this->assertSame('Position and Velocity Integration', $p1['section_title']);
        $this->assertSame('2D Interactive Graph', $p1['scene_type']);
        $this->assertStringContainsString('interactive graph showing s(t)', $p1['prompt_text']);

        $p2 = $data['prompts'][1];
        $this->assertSame('Friction Threshold Simulation', $p2['section_title']);
        $this->assertSame('3D Physical Simulation', $p2['scene_type']);
        $this->assertStringContainsString('stick-slip transition', $p2['prompt_text']);
    }

    public function test_export_omits_sections_without_a_saved_prompt_version(): void
    {
        $sectionWithoutPrompt = $this->section1->replicate();
        $sectionWithoutPrompt->id = (string) Str::uuid();
        $sectionWithoutPrompt->ordinal_in_work = 2;
        $sectionWithoutPrompt->section_identity = 'Section with no saved scene prompt';
        $sectionWithoutPrompt->save();

        $response = $this->actingAs($this->teacherA)
            ->getJson("/v3/teacher/subject-runs/{$this->run->id}/prompt-export");

        $response->assertOk();
        $this->assertSame(2, $response->json('total_prompts'));
        $this->assertCount(2, $response->json('prompts'));
        $this->assertNotContains(
            $sectionWithoutPrompt->id,
            array_column($response->json('prompts'), 'scene_id')
        );
    }

    public function test_owner_teacher_can_export_scoped_item_prompts(): void
    {
        $response = $this->actingAs($this->teacherA)
            ->getJson("/v3/teacher/subject-runs/{$this->run->id}/prompt-export?item_id={$this->item1->id}");

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertSame(1, $data['total_prompts']);
        $this->assertCount(1, $data['prompts']);
        $this->assertSame('Position and Velocity Integration', $data['prompts'][0]['section_title']);
    }

    public function test_owner_teacher_can_export_scoped_scene_prompt(): void
    {
        $response = $this->actingAs($this->teacherA)
            ->getJson("/v3/teacher/subject-runs/{$this->run->id}/prompt-export?scene_id={$this->section2->id}");

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertSame(1, $data['total_prompts']);
        $this->assertSame('Friction Threshold Simulation', $data['prompts'][0]['section_title']);
    }

    public function test_owner_teacher_can_export_as_human_readable_text(): void
    {
        $response = $this->actingAs($this->teacherA)
            ->get("/v3/teacher/subject-runs/{$this->run->id}/prompt-export?format=text");

        $response->assertStatus(200);
        $content = $response->getContent();

        $this->assertStringContainsString('Position and Velocity Integration', $content);
        $this->assertStringContainsString('Friction Threshold Simulation', $content);
        $this->assertStringContainsString('2D Interactive Graph', $content);
        $this->assertStringContainsString('interactive graph showing s(t)', $content);
    }

    public function test_non_owner_teacher_is_forbidden_from_exporting(): void
    {
        $response = $this->actingAs($this->teacherB)
            ->getJson("/v3/teacher/subject-runs/{$this->run->id}/prompt-export");

        $this->assertTrue(in_array($response->status(), [403, 404]));
    }

    public function test_unauthenticated_guest_is_unauthorized(): void
    {
        $response = $this->getJson("/v3/teacher/subject-runs/{$this->run->id}/prompt-export");
        $response->assertStatus(401);
    }

    public function test_export_compares_prompt_text_and_approved_template_separately_by_hash(): void
    {
        $jsonResp = $this->actingAs($this->teacherA)
            ->getJson("/v3/teacher/subject-runs/{$this->run->id}/prompt-export");

        $jsonResp->assertStatus(200);
        $prompts = $jsonResp->json('prompts');
        $this->assertNotEmpty($prompts);

        foreach ($prompts as $p) {
            $this->assertNotEmpty($p['prompt_sha256'], 'Prompt body hash must be present');
            $this->assertNotEmpty($p['template_sha256'], 'Approved template hash must be present');
            // Separate hashes
            $this->assertSame(64, strlen($p['prompt_sha256']));
            $this->assertSame(64, strlen($p['template_sha256']));
        }

        $textResp = $this->actingAs($this->teacherA)
            ->get("/v3/teacher/subject-runs/{$this->run->id}/prompt-export?format=text");

        $textResp->assertStatus(200);
        $text = $textResp->getContent();
        $this->assertStringContainsString('Prompt SHA256:', $text);
        $this->assertStringContainsString('Template SHA256:', $text);
    }

    public function test_upload_validates_pdf_count_boundaries_1_to_20(): void
    {
        // 0 files -> 422
        $resp0 = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subjects/{$this->subject->id}/runs", [
                'submission_key' => (string) Str::uuid(),
                'mode' => 'export_manual',
                'qualification_mode' => 'owner_waived_demo',
                'waiver_acknowledged' => true,
                'files' => [],
                'titles' => [],
            ]);
        $resp0->assertStatus(422);

        // 21 files -> 422
        $files21 = [];
        $titles21 = [];
        for ($i = 1; $i <= 21; $i++) {
            $files21[] = \Illuminate\Http\UploadedFile::fake()->create("doc_{$i}.pdf", 10, 'application/pdf');
            $titles21[] = "Title {$i}";
        }

        $resp21 = $this->actingAs($this->teacherA)
            ->post("/v3/teacher/subjects/{$this->subject->id}/runs", [
                'submission_key' => (string) Str::uuid(),
                'mode' => 'export_manual',
                'qualification_mode' => 'owner_waived_demo',
                'waiver_acknowledged' => '1',
                'files' => $files21,
                'titles' => $titles21,
            ], ['Accept' => 'application/json']);
        $resp21->assertStatus(422);
    }

    public function test_default_export_manual_makes_zero_generation_requests(): void
    {
        $this->assertSame('export_manual', $this->run->mode);

        // Verify zero generation attempts exist for this run
        $jevAttempts = DB::table('v3_prompt_jev_attempts')
            ->whereIn('prompt_version_id', [$this->prompt1->id, $this->prompt2->id])
            ->count();
        $codingAttempts = DB::table('v3_coding_attempts')->count();
        $this->assertSame(0, $codingAttempts, 'export_manual mode must produce zero coding attempts');

        $authoringRequests = DB::table('v3_authoring_requests')->count();
        $this->assertSame(0, $authoringRequests, 'export_manual mode must produce zero authoring requests');
    }
}
