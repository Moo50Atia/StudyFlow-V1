<?php

namespace Tests\Feature\V3\WebPipeline;

use App\Models\Subject;
use App\Models\User;
use App\Models\V3\AuthoringRequest;
use App\Models\V3\PipelineCommand;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptQualification;
use App\Models\V3\PromptSectionState;
use App\Models\V3\PromptWorkItem;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use App\Models\V3\TeacherSection;
use App\Services\V3\Authoring\AuthoringUnavailableException;
use App\Services\V3\Authoring\ClaimAuthoringRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

class EligibleAuthoringRequestTest extends TestCase
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
    protected PromptSectionState $sectionState;
    protected string $promptText;
    protected string $promptSha;
    protected int $settingsId;
    protected ClaimAuthoringRequest $claimService;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->settingsId = DB::table('v3_settings_versions')->insertGetId([
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
            'settings_version_id' => $this->settingsId,
            'framework_version_id' => $frameworkId,
            'mode' => 'selected_api',
            'qualification_mode' => 'owner_waived_demo',
            'submission_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', 'run1'),
            'captured_settings_json' => '{}',
            'capture_sha256' => hash('sha256', '{}'),
            'state' => 'prompts_ready',
            'state_version' => 1,
            'total_pdfs' => 1,
            'total_lessons' => 1,
            'total_scenes' => 1,
            'last_event_sequence' => 1,
        ]);

        $doc = \App\Models\V3\Document::forceCreate([
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
            'display_title' => 'Mechanics',
            'original_filename' => 'mechanics.pdf',
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
            'settings_version_id' => $this->settingsId,
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
        DB::table('v3_extracted_sources')->insert(['id' => $src, 'case_id' => $this->case->id, 'activation_id' => $srcAct, 'document_id' => $doc->id, 'settings_version_id' => $this->settingsId, 'status' => 'ready', 'page_report' => '{}', 'extractor_version' => 'v1']);
        $bind = (string) Str::uuid();
        DB::table('v3_knowledge_source_bindings')->insert(['id' => $bind, 'case_id' => $this->case->id, 'extracted_source_id' => $src, 'adapter_kind' => 'nb', 'adapter_version' => '1', 'notebook_title' => 't', 'source_title' => 's', 'state' => 'bound', 'text_sha256' => 't', 'text_bytes' => 1]);
        $mapAct = (string) Str::uuid();
        DB::table('v3_map_activations')->insert(['id' => $mapAct, 'case_id' => $this->case->id, 'binding_id' => $bind, 'workflow_id' => 'w', 'state' => 'ready']);
        $map = (string) Str::uuid();
        DB::table('v3_lesson_maps')->insert(['id' => $map, 'case_id' => $this->case->id, 'binding_id' => $bind, 'activation_id' => $mapAct, 'state' => 'ready', 'page_count' => 1]);
        $node = (string) Str::uuid();
        DB::table('v3_lesson_nodes')->insert(['id' => $node, 'map_id' => $map, 'kind' => 'l', 'position_path' => '001', 'ordinal_in_parent' => 1, 'title' => 'L1', 'work_ordinal' => 1]);
        $q = (string) Str::uuid();
        DB::table('v3_lesson_queries')->insert(['id' => $q, 'case_id' => $this->case->id, 'map_id' => $map, 'kind' => 'first', 'target_work_ordinal' => 1, 'state' => 'recorded', 'send_count' => 1, 'request_sha256' => 'r']);

        $secId = (string) Str::uuid();
        DB::table('v3_teacher_sections')->insert([
            'id' => $secId,
            'case_id' => $this->case->id,
            'map_id' => $map,
            'work_node_id' => $node,
            'query_id' => $q,
            'ordinal_in_work' => 1,
            'section_identity' => 'Harmonic Oscillator',
            'core_understanding' => 'Oscillator dynamics.',
            'first_prompt_sha256' => hash('sha256', 'f'),
            'answer_sha256' => hash('sha256', 'a'),
            'origin' => 'pipeline',
            'subject_run_item_id' => $this->item->id,
        ]);
        $this->section = TeacherSection::find($secId);

        $this->promptText = "Create an interactive harmonic oscillator simulation with spring stiffness slider.";
        $this->promptSha = hash('sha256', $this->promptText);
        $bodyPath = "prompt-versions/{$this->case->id}/{$secId}/v1_{$this->promptSha}.txt";
        Storage::disk('v3_private')->put($bodyPath, $this->promptText);

        $wi = PromptWorkItem::create([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'map_id' => $map,
            'work_node_id' => $node,
            'work_ordinal' => 1,
            'expected_cursor_version' => 1,
            'section_query_id' => $q,
            'state' => 'receipt_committed',
            'section_refs' => [$secId],
        ]);

        $this->promptVersion = ScenePromptVersion::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'work_item_id' => $wi->id,
            'section_id' => $secId,
            'version_no' => 1,
            'origin' => 'third',
            'body_path' => $bodyPath,
            'body_sha256' => $this->promptSha,
            'body_bytes' => strlen($this->promptText),
            'scene_type' => 'Interactive 2D Canvas',
            'section_fields_sha256' => hash('sha256', 'fields'),
            'canonical_third_sha256' => hash('sha256', 'third'),
            'created_at' => now(),
        ]);

        $this->sectionState = PromptSectionState::create([
            'section_id' => $secId,
            'case_id' => $this->case->id,
            'current_prompt_version_id' => $this->promptVersion->id,
            'status' => 'awaiting_qualification',
            'revision_count' => 0,
        ]);

        $this->claimService = app(ClaimAuthoringRequest::class);
    }

    protected function createPipelineCommand(): string
    {
        $commandId = (string) Str::uuid();
        PipelineCommand::create([
            'id' => $commandId,
            'run_id' => $this->run->id,
            'actor_user_id' => $this->teacherA->id,
            'action' => 'waive_prompt_gate',
            'request_sha256' => hash('sha256', $commandId),
            'expected_version' => 1,
            'state' => 'applied',
            'created_at' => now(),
        ]);
        return $commandId;
    }

    protected function createPassingPromptQualification(ScenePromptVersion $pv): PromptQualification
    {
        $attemptId = (string) Str::uuid();
        DB::table('v3_prompt_jev_attempts')->insert([
            'id' => $attemptId,
            'prompt_version_id' => $pv->id,
            'attempt_no' => 1,
            'request_sha256' => hash('sha256', 'req'),
            'question_sha256' => hash('sha256', 'q'),
            'requested_model' => 'gemini-1.5-pro',
            'settings_version_id' => $this->settingsId,
            'state' => 'recorded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PromptQualification::forceCreate([
            'id' => (string) Str::uuid(),
            'prompt_version_id' => $pv->id,
            'winning_attempt_id' => $attemptId,
            'case_id' => $pv->case_id,
            'section_id' => $pv->section_id,
            'settings_version_id' => $this->settingsId,
            'settings_checksum' => hash('sha256', '{}'),
            'request_sha256' => hash('sha256', 'req'),
            'answer_sha256' => hash('sha256', 'ans'),
            'question_sha256' => hash('sha256', 'q'),
            'rule_version' => 'prompt-rule-v1',
            'requested_model' => 'gemini-1.5-pro',
            'returned_model' => 'gemini-1.5-pro',
            'choice' => 'pass',
            'choice_confidence' => 0.99,
            'score' => 95.0,
            'score_confidence' => 0.99,
            'noul_probability' => 0.01,
            'noul_yes' => true,
            'min_score' => 80,
            'min_confidence' => 80,
            'decision' => 'pass',
            'failed_checks' => '[]',
            'answer_path' => 'qual/' . $pv->id . '.json',
            'created_at' => now(),
        ]);
    }

    public function test_real_pass_creates_genuine_authoring_request_with_real_jev_basis(): void
    {
        $this->sectionState->update(['status' => 'qualified']);

        $qual = $this->createPassingPromptQualification($this->promptVersion);

        $result = $this->claimService->claimInitial($this->case->id, $this->promptVersion->id);

        $this->assertFalse($result['is_replay']);
        $req = $result['request'];
        $this->assertInstanceOf(AuthoringRequest::class, $req);
        $this->assertSame('real_jev', $req->eligibility_basis);
        $this->assertSame($qual->id, $req->qualification_id);
        $this->assertNull($req->prompt_gate_id);
        $this->assertSame($this->promptVersion->id, $req->prompt_version_id);
        $this->assertSame($this->case->id, $req->case_id);
        $this->assertSame('initial', $req->origin);
        $this->assertSame('pending', $req->state);

        // Invariant: exactly 1 real qualification row, zero fake ones
        $this->assertSame(1, PromptQualification::count());
    }

    public function test_demo_waived_gate_creates_genuine_authoring_request_with_null_qualification_id(): void
    {
        $commandId = $this->createPipelineCommand();

        $gateId = (string) Str::uuid();
        DB::table('v3_qualification_gates')->insert([
            'id' => $gateId,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'candidate_id' => null,
            'gate_stage' => 'prompt',
            'basis' => 'owner_waived_demo',
            'real_qualification_id' => null,
            'waived_by_owner_id' => $this->teacherA->id,
            'policy_version' => 'demo_v1',
            'policy_sha256' => hash('sha256', 'policy_demo'),
            'prompt_sha256' => $this->promptVersion->body_sha256,
            'candidate_manifest_sha256' => null,
            'missing_fields_json' => json_encode(['laws_if_any']),
            'acknowledgment_sha256' => hash('sha256', 'ack'),
            'command_id' => $commandId,
            'created_at' => now(),
        ]);

        $result = $this->claimService->claimInitial($this->case->id, $this->promptVersion->id);

        $this->assertFalse($result['is_replay']);
        $req = $result['request'];
        $this->assertInstanceOf(AuthoringRequest::class, $req);
        $this->assertSame('owner_waived_demo', $req->eligibility_basis);
        $this->assertNull($req->qualification_id, 'Demo branch MUST have NULL qualification_id');
        $this->assertSame($gateId, $req->prompt_gate_id, 'Demo branch MUST reference prompt_gate_id');
        $this->assertSame($this->promptVersion->id, $req->prompt_version_id);
        $this->assertSame('initial', $req->origin);
        $this->assertSame('pending', $req->state);

        // Strict invariant: zero fabricated JEV rows
        $this->assertSame(0, PromptQualification::count());
    }

    public function test_authoring_request_rejected_when_no_pass_and_no_demo_gate_exists(): void
    {
        $this->expectException(AuthoringUnavailableException::class);

        $this->claimService->claimInitial($this->case->id, $this->promptVersion->id);

        $this->assertSame(0, AuthoringRequest::count());
    }

    public function test_authoring_request_rejected_when_demo_gate_belongs_to_foreign_owner(): void
    {
        $commandId = $this->createPipelineCommand();

        DB::table('v3_qualification_gates')->insert([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'candidate_id' => null,
            'gate_stage' => 'prompt',
            'basis' => 'owner_waived_demo',
            'real_qualification_id' => null,
            'waived_by_owner_id' => $this->teacherB->id, // foreign teacher!
            'policy_version' => 'demo_v1',
            'policy_sha256' => hash('sha256', 'policy_demo'),
            'prompt_sha256' => $this->promptVersion->body_sha256,
            'missing_fields_json' => '[]',
            'command_id' => $commandId,
            'created_at' => now(),
        ]);

        $this->expectException(AuthoringUnavailableException::class);

        $this->claimService->claimInitial($this->case->id, $this->promptVersion->id);
    }

    public function test_authoring_request_rejected_when_demo_gate_has_stale_or_mismatched_prompt_hash(): void
    {
        $commandId = $this->createPipelineCommand();

        DB::table('v3_qualification_gates')->insert([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'candidate_id' => null,
            'gate_stage' => 'prompt',
            'basis' => 'owner_waived_demo',
            'real_qualification_id' => null,
            'waived_by_owner_id' => $this->teacherA->id,
            'policy_version' => 'demo_v1',
            'policy_sha256' => hash('sha256', 'policy_demo'),
            'prompt_sha256' => hash('sha256', 'mismatched_stale_prompt_body'),
            'missing_fields_json' => '[]',
            'command_id' => $commandId,
            'created_at' => now(),
        ]);

        $this->expectException(AuthoringUnavailableException::class);

        $this->claimService->claimInitial($this->case->id, $this->promptVersion->id);
    }

    public function test_authoring_request_rejected_when_prompt_version_is_superseded(): void
    {
        $commandId = $this->createPipelineCommand();

        DB::table('v3_qualification_gates')->insert([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'candidate_id' => null,
            'gate_stage' => 'prompt',
            'basis' => 'owner_waived_demo',
            'real_qualification_id' => null,
            'waived_by_owner_id' => $this->teacherA->id,
            'policy_version' => 'demo_v1',
            'policy_sha256' => hash('sha256', 'policy_demo'),
            'prompt_sha256' => $this->promptVersion->body_sha256,
            'missing_fields_json' => '[]',
            'command_id' => $commandId,
            'created_at' => now(),
        ]);

        // Create a new prompt version v2 and point current_prompt_version_id to it
        $newPrompt = ScenePromptVersion::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'work_item_id' => $this->promptVersion->work_item_id,
            'section_id' => $this->section->id,
            'version_no' => 2,
            'origin' => 'third',
            'body_path' => $this->promptVersion->body_path,
            'body_sha256' => $this->promptVersion->body_sha256,
            'body_bytes' => $this->promptVersion->body_bytes,
            'scene_type' => 'Interactive 2D Canvas',
            'section_fields_sha256' => hash('sha256', 'fields2'),
            'canonical_third_sha256' => hash('sha256', 'third2'),
            'created_at' => now(),
        ]);
        $this->sectionState->update(['current_prompt_version_id' => $newPrompt->id]);

        $this->expectException(AuthoringUnavailableException::class);

        $this->claimService->claimInitial($this->case->id, $this->promptVersion->id);
    }

    public function test_legacy_protocol_case_cannot_use_demo_gate_branch(): void
    {
        $legacyCase = ProcessingCase::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacherA->id,
            'document_id' => $this->case->document_id,
            'settings_version_id' => $this->case->settings_version_id,
            'idempotency_key' => 'legacy:case:1',
            'action' => 'legacy_subject',
            'state' => 'prompts_ready',
            'state_version' => 1,
            'pipeline_version' => 'legacy_v1_case',
        ]);

        $commandId = $this->createPipelineCommand();

        DB::table('v3_qualification_gates')->insert([
            'id' => (string) Str::uuid(),
            'case_id' => $legacyCase->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'candidate_id' => null,
            'gate_stage' => 'prompt',
            'basis' => 'owner_waived_demo',
            'real_qualification_id' => null,
            'waived_by_owner_id' => $this->teacherA->id,
            'policy_version' => 'demo_v1',
            'policy_sha256' => hash('sha256', 'policy_demo'),
            'prompt_sha256' => $this->promptVersion->body_sha256,
            'missing_fields_json' => '[]',
            'command_id' => $commandId,
            'created_at' => now(),
        ]);

        $this->expectException(AuthoringUnavailableException::class);

        $this->claimService->claimInitial($legacyCase->id, $this->promptVersion->id);
    }

    public function test_idempotent_reclaim_returns_replay(): void
    {
        $commandId = $this->createPipelineCommand();

        $gateId = (string) Str::uuid();
        DB::table('v3_qualification_gates')->insert([
            'id' => $gateId,
            'case_id' => $this->case->id,
            'section_id' => $this->section->id,
            'prompt_version_id' => $this->promptVersion->id,
            'candidate_id' => null,
            'gate_stage' => 'prompt',
            'basis' => 'owner_waived_demo',
            'real_qualification_id' => null,
            'waived_by_owner_id' => $this->teacherA->id,
            'policy_version' => 'demo_v1',
            'policy_sha256' => hash('sha256', 'policy_demo'),
            'prompt_sha256' => $this->promptVersion->body_sha256,
            'missing_fields_json' => '[]',
            'command_id' => $commandId,
            'created_at' => now(),
        ]);

        $res1 = $this->claimService->claimInitial($this->case->id, $this->promptVersion->id);
        $this->assertFalse($res1['is_replay']);

        $res2 = $this->claimService->claimInitial($this->case->id, $this->promptVersion->id);
        $this->assertTrue($res2['is_replay']);
        $this->assertSame($res1['request']->id, $res2['request']->id);
        $this->assertSame(1, AuthoringRequest::count());
    }
}
