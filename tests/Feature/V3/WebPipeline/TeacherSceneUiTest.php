<?php

namespace Tests\Feature\V3\WebPipeline;

use App\Models\Subject;
use App\Models\User;
use App\Models\V3\AuthoringRequest;
use App\Models\V3\PipelineCommand;
use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptSectionState;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckAttempt;
use App\Models\V3\SceneCheckRun;
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

class TeacherSceneUiTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacherA;
    protected User $teacherB;
    protected User $admin;
    protected Subject $subject;
    protected SubjectRun $run;
    protected SubjectRunItem $item;
    protected ProcessingCase $case;
    protected TeacherSection $section;
    protected ScenePromptVersion $promptVersion;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('v3_private');

        $this->admin = WebPipelineFixtures::createAdmin();
        $this->teacherA = WebPipelineFixtures::createTeacherA();
        $this->teacherB = WebPipelineFixtures::createTeacherB();
        $this->subject = WebPipelineFixtures::createSubjectForTeacher($this->teacherA);

        $templateBody = 'tmpl';
        $templateChecksum = hash('sha256', $templateBody);
        DB::table('v3_approved_templates')->insert([
            'id' => 'tmpl-scene-v1',
            'kind' => 'scene',
            'body' => $templateBody,
            'checksum' => $templateChecksum,
            'registered_at' => now(),
        ]);

        $settingsId = DB::table('v3_settings_versions')->insertGetId([
            'version' => 1,
            'schema_version' => 1,
            'payload' => json_encode([
                'templates' => [
                    'scene' => [
                        'id' => 'tmpl-scene-v1',
                        'checksum' => $templateChecksum,
                    ],
                ],
                'limits' => ['coding_attempts' => 3],
                'providers' => ['coding_order' => ['gemini']],
            ]),
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
            'captured_settings_json' => json_encode(['mode' => 'export_manual']),
            'capture_sha256' => hash('sha256', json_encode(['mode' => 'export_manual'])),
            'state' => 'prompts_ready',
            'state_version' => 1,
            'total_pdfs' => 1,
            'total_lessons' => 1,
            'total_scenes' => 1,
            'last_event_sequence' => 1,
        ]);

        $doc = Document::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacherA->id,
            'sha256' => hash('sha256', 'doc1'),
            'byte_size' => 1024,
            'page_count' => 1,
            'state' => 'ready',
            'storage_path' => 'accepted/d1.pdf',
            'accepted_at' => now(),
        ]);

        $this->item = SubjectRunItem::create([
            'id' => (string) Str::uuid(),
            'run_id' => $this->run->id,
            'owner_user_id' => $this->teacherA->id,
            'pdf_ordinal' => 1,
            'display_title' => 'Orbital Mechanics',
            'original_filename' => 'orbital.pdf',
            'input_sha256' => hash('sha256', 'pdf1'),
            'input_bytes' => 1024,
            'state' => 'prompts_ready',
            'document_id' => $doc->id,
            'saved_lesson_count' => 1,
            'prompt_count' => 1,
            'state_version' => 1,
        ]);

        $this->case = ProcessingCase::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacherA->id,
            'document_id' => $doc->id,
            'settings_version_id' => $settingsId,
            'idempotency_key' => 'item:' . $this->item->id,
            'action' => 'web_subject',
            'state' => 'prompts_ready',
            'state_version' => 1,
            'pipeline_version' => 'notebooklm_per_pdf_web_v1',
            'notebook_framework_version_id' => $frameworkId,
            'subject_run_item_id' => $this->item->id,
        ]);
        $this->item->update(['case_id' => $this->case->id]);

        $srcAct = (string) Str::uuid();
        DB::table('v3_source_activations')->insert(['id' => $srcAct, 'case_id' => $this->case->id, 'workflow_id' => 'wf', 'state' => 'accepted']);
        $src = (string) Str::uuid();
        DB::table('v3_extracted_sources')->insert(['id' => $src, 'case_id' => $this->case->id, 'activation_id' => $srcAct, 'document_id' => $doc->id, 'settings_version_id' => $settingsId, 'status' => 'ready', 'page_report' => '{}', 'extractor_version' => 'v1']);
        $bind = (string) Str::uuid();
        DB::table('v3_knowledge_source_bindings')->insert(['id' => $bind, 'case_id' => $this->case->id, 'extracted_source_id' => $src, 'adapter_kind' => 'nb', 'adapter_version' => '1', 'notebook_title' => 't', 'source_title' => 's', 'state' => 'bound', 'text_sha256' => 't', 'text_bytes' => 1]);
        $mapAct = (string) Str::uuid();
        DB::table('v3_map_activations')->insert(['id' => $mapAct, 'case_id' => $this->case->id, 'binding_id' => $bind, 'workflow_id' => 'w', 'state' => 'ready']);
        $map = (string) Str::uuid();
        DB::table('v3_lesson_maps')->insert([
            'id' => $map,
            'case_id' => $this->case->id,
            'binding_id' => $bind,
            'activation_id' => $mapAct,
            'state' => 'ready',
            'page_count' => 1,
        ]);

        $node = (string) Str::uuid();
        DB::table('v3_lesson_nodes')->insert([
            'id' => $node,
            'map_id' => $map,
            'kind' => 'l',
            'position_path' => '001',
            'ordinal_in_parent' => 1,
            'title' => 'L1',
            'work_ordinal' => 1,
        ]);

        $q = (string) Str::uuid();
        DB::table('v3_lesson_queries')->insert([
            'id' => $q,
            'case_id' => $this->case->id,
            'map_id' => $map,
            'kind' => 'first',
            'target_work_ordinal' => 1,
            'state' => 'recorded',
            'send_count' => 1,
            'request_sha256' => 'r',
        ]);

        $this->section = TeacherSection::create([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'map_id' => $map,
            'work_node_id' => $node,
            'query_id' => $q,
            'ordinal_in_work' => 1,
            'section_identity' => 'Orbital Dynamics',
            'core_understanding' => 'Orbital dynamics',
            'first_prompt_sha256' => hash('sha256', 'f'),
            'answer_sha256' => hash('sha256', 'a'),
            'origin' => 'pipeline',
            'subject_run_item_id' => $this->item->id,
        ]);

        $promptText = 'Interactive 3D simulation of two-body Keplerian orbit with velocity vector.';
        $promptSha = hash('sha256', $promptText);
        $bodyPath = "prompt-versions/{$this->case->id}/{$this->section->id}/v1_{$promptSha}.txt";
        \Illuminate\Support\Facades\Storage::disk('v3_private')->put($bodyPath, $promptText);

        $wi = \App\Models\V3\PromptWorkItem::create([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'map_id' => $map,
            'work_node_id' => $node,
            'work_ordinal' => 1,
            'expected_cursor_version' => 1,
            'section_query_id' => $q,
            'state' => 'receipt_committed',
            'section_refs' => [$this->section->id],
        ]);

        $this->promptVersion = ScenePromptVersion::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'work_item_id' => $wi->id,
            'version_no' => 1,
            'origin' => 'third',
            'body_path' => $bodyPath,
            'body_sha256' => $promptSha,
            'body_bytes' => strlen($promptText),
            'scene_type' => 'simulation',
            'section_fields_sha256' => hash('sha256', 'fields'),
            'canonical_third_sha256' => hash('sha256', 'third'),
            'created_at' => now(),
        ]);

        PromptSectionState::create([
            'section_id' => $this->section->id,
            'case_id' => $this->case->id,
            'current_prompt_version_id' => $this->promptVersion->id,
            'status' => 'ready',
            'revision_count' => 1,
        ]);
    }

    public function test_teacher_can_view_scenes_page_html(): void
    {
        $response = $this->actingAs($this->teacherA)
            ->get(route('v3.teacher.subject-runs.scenes', $this->run));

        $response->assertStatus(200);
        $response->assertSee('Scene Management &amp; Authoring', false);
        $response->assertSee('Orbital Mechanics');
        $response->assertSee('Interactive 3D simulation of two-body Keplerian orbit');
        $response->assertSee('Export / Manual (Default)');
    }

    public function test_teacher_can_view_scenes_json(): void
    {
        $response = $this->actingAs($this->teacherA)
            ->getJson(route('v3.teacher.subject-runs.scenes', $this->run));

        $response->assertStatus(200);
        $response->assertJsonPath('data.run.id', $this->run->id);
        $response->assertJsonPath('data.current_selection.mode', 'export_manual');
        $this->assertCount(1, $response->json('data.scenes'));
        $this->assertEquals($this->promptVersion->id, $response->json('data.scenes.0.prompt.id'));
    }

    public function test_unauthorized_teacher_cannot_access_scenes(): void
    {
        $response = $this->actingAs($this->teacherB)
            ->get(route('v3.teacher.subject-runs.scenes', $this->run));

        $response->assertStatus(404);
    }

    public function test_teacher_can_update_generation_selection(): void
    {
        $response = $this->actingAs($this->teacherA)
            ->postJson(route('v3.teacher.subject-runs.generation-mode', $this->run), [
                'mode' => 'selected_api',
                'provider_id' => 'gemini_api',
                'model' => 'gemini-2.5-flash',
                'thinking_budget' => 12000,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.mode', 'selected_api');
        $response->assertJsonPath('data.selection.provider.id', 'gemini_api');
        $response->assertJsonPath('data.selection.provider.gemini_thinking_budget', 12000);

        $this->run->refresh();
        $this->assertEquals('selected_api', $this->run->mode);
        $this->assertEquals(2, $this->run->state_version);
    }

    public function test_teacher_can_request_scene_generation_with_demo_waiver(): void
    {
        $this->actingAs($this->teacherA)
            ->postJson(route('v3.teacher.subject-runs.generation-mode', $this->run), [
                'mode' => 'selected_api',
                'provider_id' => 'gemini_api',
                'model' => 'gemini-2.5-flash',
            ])
            ->assertStatus(200);

        $response = $this->actingAs($this->teacherA)
            ->postJson(route('v3.teacher.subject-runs.scenes.authoring-request', [
                'run' => $this->run,
                'scene' => $this->section,
            ]));

        $response->assertStatus(202);
        $response->assertJsonPath('data.eligibility_basis', 'owner_waived_demo');
        $this->assertNotNull($response->json('data.prompt_gate_id'));

        // Gate must exist in v3_qualification_gates
        $this->assertDatabaseHas('v3_qualification_gates', [
            'id' => $response->json('data.prompt_gate_id'),
            'basis' => 'owner_waived_demo',
            'section_id' => $this->section->id,
            'gate_stage' => 'prompt',
        ]);

        // Authoring request must exist with matching prompt_gate_id
        $this->assertDatabaseHas('v3_authoring_requests', [
            'id' => $response->json('data.request_id'),
            'section_id' => $this->section->id,
            'eligibility_basis' => 'owner_waived_demo',
            'prompt_gate_id' => $response->json('data.prompt_gate_id'),
        ]);

        $requestId = $response->json('data.request_id');
        $command = PipelineCommand::findOrFail($requestId);
        $this->assertSame('generate_scene', $command->action);
        $this->assertDatabaseHas('v3_pipeline_outbox', [
            'command_id' => $requestId,
            'destination' => 'application_bridge',
            'state' => 'pending',
        ]);
        $manifestPath = "manifests/{$command->private_payload_ref}.json";
        $this->assertTrue(Storage::disk('v3_private')->exists($manifestPath));
        $manifest = json_decode(Storage::disk('v3_private')->get($manifestPath), true);
        $this->assertSame('web_authoring_v1', $manifest['kind']);
        $this->assertSame($this->case->id, $manifest['case_id']);
        $this->assertSame($requestId, $manifest['request_ref']);

        $authoringRequest = AuthoringRequest::findOrFail($requestId);
        $this->assertSame('gemini_api', $authoringRequest->provider_override_json['id']);
        $this->assertSame('gemini-2.5-flash', $authoringRequest->provider_override_json['model']);
        $reservation = app(\App\Services\V3\Authoring\ClaimCodingAttempt::class)->claim(
            $this->case->id,
            $requestId,
            ['schema_version' => 1, 'ordinal' => 1]
        );
        $this->assertSame('gemini_api', $reservation['data']['provider']['id']);
        $this->assertSame('gemini-2.5-flash', $reservation['data']['provider']['model']);
        $this->assertSame('GEMINI_API_KEY', $reservation['data']['provider']['credential_ref']);
        $this->assertSame(24576, $reservation['data']['provider']['thinking_budget']);
    }

    public function test_teacher_can_request_scene_generation_with_qwen_provider(): void
    {
        $this->actingAs($this->teacherA)
            ->postJson(route('v3.teacher.subject-runs.generation-mode', $this->run), [
                'mode' => 'selected_api',
                'provider_id' => 'qwen_api',
                'model' => 'qwen-plus',
            ])
            ->assertStatus(200);

        $response = $this->actingAs($this->teacherA)
            ->postJson(route('v3.teacher.subject-runs.scenes.authoring-request', [
                'run' => $this->run,
                'scene' => $this->section,
            ]));

        $response->assertStatus(202);
        $requestId = $response->json('data.request_id');

        $authoringRequest = AuthoringRequest::findOrFail($requestId);
        $this->assertSame('qwen_api', $authoringRequest->provider_override_json['id']);
        $this->assertSame('qwen-plus', $authoringRequest->provider_override_json['model']);
        $reservation = app(\App\Services\V3\Authoring\ClaimCodingAttempt::class)->claim(
            $this->case->id,
            $requestId,
            ['schema_version' => 1, 'ordinal' => 1]
        );
        $this->assertSame('qwen_api', $reservation['data']['provider']['id']);
        $this->assertSame('qwen-plus', $reservation['data']['provider']['model']);
        $this->assertSame('QWEN_API_KEY', $reservation['data']['provider']['credential_ref']);
        $this->assertNull($reservation['data']['provider']['thinking_budget']);
    }

    public function test_generation_mode_aliases_support_api_generate_and_selected_api(): void
    {
        $resp1 = $this->actingAs($this->teacherA)
            ->postJson(route('v3.teacher.subject-runs.generation-mode', $this->run), [
                'mode' => 'api_generate',
                'provider_id' => 'gemini_api',
                'model' => 'gemini-2.5-flash',
            ]);
        $resp1->assertStatus(200);
        $resp1->assertJsonPath('data.mode', 'api_generate');

        $this->run->refresh();
        $this->assertSame('api_generate', $this->run->mode);

        $resp2 = $this->actingAs($this->teacherA)
            ->postJson(route('v3.teacher.subject-runs.generation-mode', $this->run), [
                'mode' => 'selected_api',
                'provider_id' => 'gemini_api',
                'model' => 'gemini-2.5-flash',
            ]);
        $resp2->assertStatus(200);
        $resp2->assertJsonPath('data.mode', 'selected_api');

        $this->run->refresh();
        $this->assertSame('selected_api', $this->run->mode);
    }

    public function test_generation_request_fails_when_mode_is_export_manual_or_settings_missing(): void
    {
        $this->assertSame('export_manual', $this->run->mode);

        $resp = $this->actingAs($this->teacherA)
            ->postJson(route('v3.teacher.subject-runs.scenes.authoring-request', [
                'run' => $this->run,
                'scene' => $this->section,
            ]));
        $resp->assertStatus(422);
        $this->assertStringContainsString('Select API scene generation', $resp->json('message') ?? $resp->getContent());

        $this->run->update([
            'mode' => 'selected_api',
            'captured_settings_json' => json_encode(['mode' => 'selected_api', 'provider' => null]),
        ]);

        $resp2 = $this->actingAs($this->teacherA)
            ->postJson(route('v3.teacher.subject-runs.scenes.authoring-request', [
                'run' => $this->run,
                'scene' => $this->section,
            ]));
        $resp2->assertStatus(422);
        $this->assertStringContainsString('Provider configuration is missing', $resp2->json('message') ?? $resp2->getContent());
    }

    public function test_generation_request_fails_when_consent_or_qualification_fails(): void
    {
        $this->actingAs($this->teacherA)
            ->postJson(route('v3.teacher.subject-runs.generation-mode', $this->run), [
                'mode' => 'selected_api',
                'provider_id' => 'gemini_api',
            ]);

        $respConsent = $this->actingAs($this->teacherA)
            ->postJson(route('v3.teacher.subject-runs.scenes.authoring-request', [
                'run' => $this->run,
                'scene' => $this->section,
            ]), [
                'waiver_acknowledged' => false,
            ]);
        $respConsent->assertStatus(422);
        $this->assertStringContainsString('Consent/waiver acknowledgment is required', $respConsent->json('message') ?? $respConsent->getContent());

        $this->run->update(['qualification_mode' => 'real_jev']);

        $respJev = $this->actingAs($this->teacherA)
            ->postJson(route('v3.teacher.subject-runs.scenes.authoring-request', [
                'run' => $this->run,
                'scene' => $this->section,
            ]));
        $respJev->assertStatus(422);
        $this->assertStringContainsString('not passed real JEV qualification', $respJev->json('message') ?? $respJev->getContent());
    }

    public function test_generation_request_fails_for_foreign_user_or_missing_prompt(): void
    {
        $this->actingAs($this->teacherA)
            ->postJson(route('v3.teacher.subject-runs.generation-mode', $this->run), [
                'mode' => 'selected_api',
                'provider_id' => 'gemini_api',
            ]);

        $respForeign = $this->actingAs($this->teacherB)
            ->postJson(route('v3.teacher.subject-runs.scenes.authoring-request', [
                'run' => $this->run,
                'scene' => $this->section,
            ]));
        $this->assertTrue(in_array($respForeign->status(), [403, 404]));

        PromptSectionState::where('section_id', $this->section->id)->update(['current_prompt_version_id' => null]);

        $respMissingPrompt = $this->actingAs($this->teacherA)
            ->postJson(route('v3.teacher.subject-runs.scenes.authoring-request', [
                'run' => $this->run,
                'scene' => $this->section,
            ]));
        $respMissingPrompt->assertStatus(422);
        $this->assertStringContainsString('does not have an active current prompt version', $respMissingPrompt->json('message') ?? $respMissingPrompt->getContent());
    }
}
