<?php

namespace Tests\Feature\V3\Prompts;

use App\Models\V3\Document;
use App\Models\V3\ExtractedSource;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\LessonCursor;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\LessonQuery;
use App\Models\V3\MapActivation;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptFeedbackEffect;
use App\Models\V3\PromptJevAttempt;
use App\Models\V3\PromptQualification;
use App\Models\V3\PromptSectionState;
use App\Models\V3\PromptWorkItem;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SourceActivation;
use App\Models\V3\TeacherSection;
use App\Models\V3\WorkflowLink;
use App\Services\V3\Prompts\PromptContextReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\Support\V3\WorkflowFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Revision');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Revision');

    SettingsFixtures::registerDefaultTemplates();
    $payload = SettingsFixtures::validPayload();
    $canonical = \App\Services\V3\Settings\CanonicalSettings::canonicalize($payload);
    $checksum = hash('sha256', \App\Services\V3\Settings\CanonicalSettings::encode($canonical));

    $this->settingsVersion = SettingsVersion::create([
        'version' => 1,
        'schema_version' => 1,
        'payload' => $canonical,
        'checksum' => $checksum,
        'published_by_user_id' => $this->admin->id,
        'published_at' => now(),
    ]);

    $this->docContent = "PDF content for revision";
    $this->docSha256 = hash('sha256', $this->docContent);
    $this->docPath = 'accepted/test-rev-doc.pdf';
    Storage::disk('v3_private')->put($this->docPath, $this->docContent);

    $this->document = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'sha256' => $this->docSha256,
        'storage_path' => $this->docPath,
        'byte_size' => strlen($this->docContent),
        'page_count' => 3,
        'state' => 'ready',
    ]);

    $this->case = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'state' => 'running',
        'idempotency_key' => 'idem-rev-' . Str::uuid(),
        'action' => 'process',
    ]);

    $this->link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-rev-test',
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'start_state' => 'started',
        'stage_code' => 'prompt_qualification',
        'status_code' => 'running',
        'started_at' => now(),
    ]);

    $this->sourceActivation = SourceActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'accepted',
        'signal_accepted_at' => now(),
    ]);

    $this->extractedSource = ExtractedSource::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'activation_id' => $this->sourceActivation->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'status' => 'ready',
        'artifact_path' => 'extracted/rev-ev.txt',
        'text_sha256' => hash('sha256', 'extracted text'),
        'text_bytes' => 100,
        'page_count' => 3,
        'page_report' => [],
        'extractor_version' => '008-v1',
    ]);

    $this->binding = KnowledgeSourceBinding::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'extracted_source_id' => $this->extractedSource->id,
        'adapter_kind' => 'notebooklm',
        'adapter_version' => '0.3.1',
        'notebook_title' => "v3-case-{$this->case->id}",
        'source_title' => "v3-text-{$this->case->id}",
        'notebook_id' => 'nb-rev-1',
        'source_id' => 'src-rev-1',
        'state' => 'bound',
        'text_sha256' => hash('sha256', 'extracted text'),
        'text_bytes' => 100,
        'transfer_status' => 'confirmed',
    ]);

    $this->mapActivation = MapActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'accepted',
        'signal_accepted_at' => now(),
    ]);

    $this->map = LessonMap::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'activation_id' => $this->mapActivation->id,
        'state' => 'ready',
        'page_count' => 3,
        'work_count' => 1,
    ]);

    $this->workNode1 = LessonNode::forceCreate([
        'id' => (string) Str::uuid(),
        'map_id' => $this->map->id,
        'parent_id' => null,
        'kind' => 'lesson',
        'position_path' => '001',
        'ordinal_in_parent' => 1,
        'title' => 'Wave Motion',
        'work_ordinal' => 1,
        'coverage_pages' => [1, 2],
    ]);

    $this->firstQuery = LessonQuery::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'kind' => 'first',
        'target_work_ordinal' => 1,
        'canonical_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
        'request_sha256' => hash('sha256', 'first-req-rev'),
        'state' => 'recorded',
        'send_count' => 1,
        'conversation_in' => 'conv-in-rev',
        'conversation_out' => 'conv-out-rev',
        'sent_at' => now(),
        'recorded_at' => now(),
    ]);

    $this->section1 = TeacherSection::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'work_node_id' => $this->workNode1->id,
        'query_id' => $this->firstQuery->id,
        'ordinal_in_work' => 1,
        'section_identity' => 'Wave Propagation',
        'core_understanding' => 'Waves transfer energy without transferring matter.',
        'visual_first_explanation' => 'Visual particles oscillating in place.',
        'visual_scene_script' => 'Show a line of particles connected by springs.',
        'dynamic_view_blueprint' => 'Particles displaced in a wave pattern.',
        'visual_learning_outcome' => 'Learner understands particle motion vs wave motion.',
        'concept_check' => 'Do particles travel with the wave?',
        'conceptual_summary' => 'Energy travels while particles vibrate.',
        'colloquial_egyptian_explanation' => 'الموجة بتنقل الطاقة مش المادة.',
        'laws_if_any' => 'v = f * lambda',
        'real_life_example' => 'Stadium wave where people stand up and sit down.',
        'first_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
        'answer_sha256' => hash('sha256', 'first-ans'),
    ]);

    $this->cursor = LessonCursor::forceCreate([
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'current_work_ordinal' => 1,
        'phase' => 'awaiting_010',
        'conversation_id' => 'conv-out-rev',
        'version' => 2,
    ]);

    $this->workItem = PromptWorkItem::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'work_node_id' => $this->workNode1->id,
        'work_ordinal' => 1,
        'expected_cursor_version' => 2,
        'section_query_id' => $this->firstQuery->id,
        'state' => 'awaiting_third',
        'section_refs' => [$this->section1->id],
    ]);

    // Create Third prompt version 1
    $this->promptBody = 'Process: Old prompt requiring revision.';
    $this->promptBodySha = hash('sha256', $this->promptBody);
    $this->promptPath = "prompt-bodies/{$this->case->id}/{$this->section1->id}/v1/{$this->promptBodySha}.txt";
    Storage::disk('v3_private')->put($this->promptPath, $this->promptBody);

    $fields = [
        'section_identity' => $this->section1->section_identity,
        'core_understanding' => $this->section1->core_understanding,
        'visual_first_explanation' => $this->section1->visual_first_explanation,
        'visual_scene_script' => $this->section1->visual_scene_script,
        'dynamic_view_blueprint' => $this->section1->dynamic_view_blueprint,
        'visual_learning_outcome' => $this->section1->visual_learning_outcome,
        'concept_check' => $this->section1->concept_check,
        'conceptual_summary' => $this->section1->conceptual_summary,
        'colloquial_egyptian_explanation' => $this->section1->colloquial_egyptian_explanation,
        'laws_if_any' => $this->section1->laws_if_any,
        'real_life_example' => $this->section1->real_life_example,
    ];
    $this->sectionFieldsSha = PromptContextReader::computeSectionFieldsSha256($fields);

    $this->promptVersion = ScenePromptVersion::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'work_item_id' => $this->workItem->id,
        'section_id' => $this->section1->id,
        'version_no' => 1,
        'predecessor_id' => null,
        'origin' => 'third',
        'third_turn_id' => null,
        'body_path' => $this->promptPath,
        'body_sha256' => $this->promptBodySha,
        'body_bytes' => strlen($this->promptBody),
        'scene_type' => 'Process',
        'section_fields_sha256' => $this->sectionFieldsSha,
        'canonical_third_sha256' => PromptContextReader::CANONICAL_THIRD_SHA256,
        'created_at' => now(),
    ]);

    $this->attempt = PromptJevAttempt::forceCreate([
        'id' => (string) Str::uuid(),
        'prompt_version_id' => $this->promptVersion->id,
        'attempt_no' => 1,
        'request_sha256' => hash('sha256', 'req-attempt-1'),
        'question_sha256' => hash('sha256', 'q-sha'),
        'requested_model' => 'typesafe-systemone-v1',
        'settings_version_id' => $this->settingsVersion->id,
        'state' => 'recorded',
        'sent_at' => now(),
        'finished_at' => now(),
    ]);

    $rawAns = json_encode(['choice' => 'revise', 'score' => 60.0]);
    $this->ansSha = hash('sha256', $rawAns);
    $this->ansPath = "prompt-jev/{$this->case->id}/{$this->promptVersion->id}/{$this->ansSha}.json";
    Storage::disk('v3_private')->put($this->ansPath, $rawAns);

    $this->qualification = PromptQualification::forceCreate([
        'id' => (string) Str::uuid(),
        'prompt_version_id' => $this->promptVersion->id,
        'winning_attempt_id' => $this->attempt->id,
        'case_id' => $this->case->id,
        'section_id' => $this->section1->id,
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'request_sha256' => $this->attempt->request_sha256,
        'answer_sha256' => $this->ansSha,
        'question_sha256' => $this->attempt->question_sha256,
        'rule_version' => 'prompt-rule-v1',
        'requested_model' => 'typesafe-systemone-v1',
        'returned_model' => 'typesafe-systemone-v1',
        'choice' => 'revise',
        'choice_confidence' => '0.800000000000000000',
        'score' => '60.000000000000000000',
        'score_confidence' => '0.800000000000000000',
        'noul_probability' => '0.800000000000000000',
        'noul_yes' => true,
        'min_score' => 80,
        'min_confidence' => 75,
        'decision' => 'revise',
        'failed_checks' => ['choice', 'score'],
        'answer_path' => $this->ansPath,
        'created_at' => now(),
    ]);

    $this->sectionState = PromptSectionState::forceCreate([
        'section_id' => $this->section1->id,
        'case_id' => $this->case->id,
        'current_prompt_version_id' => $this->promptVersion->id,
        'status' => 'revising',
        'revision_count' => 0,
        'last_qualification_id' => $this->qualification->id,
    ]);

    $this->templateId = SettingsFixtures::VALID_FEEDBACK_TEMPLATE_ID;
    $this->templateChecksum = hash('sha256', SettingsFixtures::VALID_FEEDBACK_BODY);
});

test('P09: GET feedback-context returns captured template bytes, provider descriptor and failed checks', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/prompt-versions/{$this->promptVersion->id}/feedback-context";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $resp = $this->withHeaders($headers)->get($path);
    $resp->assertStatus(200);

    $data = $resp->json('data');
    expect($data['case_id'])->toBe($this->case->id)
        ->and($data['section_ref'])->toBe($this->section1->id)
        ->and($data['prompt_version_ref'])->toBe($this->promptVersion->id)
        ->and($data['previous_prompt'])->toBe($this->promptBody)
        ->and($data['qualification_ref'])->toBe($this->qualification->id)
        ->and($data['failed_checks'])->toBe(['choice', 'score'])
        ->and($data['template_id'])->toBe($this->templateId)
        ->and($data['template_sha256'])->toBe($this->templateChecksum)
        ->and($data['template_bytes'])->toBe(SettingsFixtures::VALID_FEEDBACK_BODY)
        ->and($data['provider']['id'])->toBe('gemini_api')
        ->and($data['provider']['model'])->toBe('gemini-2.5-flash')
        ->and($data['revision_count'])->toBe(0)
        ->and($data['revision_limit'])->toBe(2);
});

test('P09: POST feedback claim commits send and prevents duplicate send_now', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/prompt-versions/{$this->promptVersion->id}/feedback/claim";

    $reqPayload = "010-feedback-v1\n"
        . $this->promptVersion->id . "\n"
        . $this->qualification->answer_sha256 . "\n"
        . $this->templateChecksum . "\n"
        . 'gemini_api' . "\n"
        . 'gemini-2.5-flash' . "\n"
        . 'GEMINI_API_KEY';
    $requestSha = hash('sha256', $reqPayload);

    $claimBody = [
        'schema_version' => 1,
        'qualification_ref' => $this->qualification->id,
        'template_id' => $this->templateId,
        'template_sha256' => $this->templateChecksum,
        'provider_id' => 'gemini_api',
        'provider_model' => 'gemini-2.5-flash',
        'credential_ref' => 'GEMINI_API_KEY',
        'request_sha256' => $requestSha,
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $claimBody);
    $resp = $this->withHeaders($headers)->postJson($path, $claimBody);
    $resp->assertStatus(200);

    $data = $resp->json('data');
    expect($data['disposition'])->toBe('send_now');
    $effectRef = $data['effect_ref'];

    // Duplicate exact claim returns reconcile
    $dupResp = $this->withHeaders($headers)->postJson($path, $claimBody);
    $dupResp->assertStatus(200)
        ->assertJson([
            'data' => [
                'effect_ref' => $effectRef,
                'disposition' => 'reconcile',
            ],
        ]);
});

test('P09: POST feedback result inserts successor version and advances section current pointer', function () {
    // 1. Claim effect
    $claimPath = "/api/internal/v1/cases/{$this->case->id}/prompt-versions/{$this->promptVersion->id}/feedback/claim";
    $reqPayload = "010-feedback-v1\n"
        . $this->promptVersion->id . "\n"
        . $this->qualification->answer_sha256 . "\n"
        . $this->templateChecksum . "\n"
        . 'gemini_api' . "\n"
        . 'gemini-2.5-flash' . "\n"
        . 'GEMINI_API_KEY';
    $requestSha = hash('sha256', $reqPayload);

    $claimBody = [
        'schema_version' => 1,
        'qualification_ref' => $this->qualification->id,
        'template_id' => $this->templateId,
        'template_sha256' => $this->templateChecksum,
        'provider_id' => 'gemini_api',
        'provider_model' => 'gemini-2.5-flash',
        'credential_ref' => 'GEMINI_API_KEY',
        'request_sha256' => $requestSha,
    ];
    $claimResp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimBody))
        ->postJson($claimPath, $claimBody);
    $effectRef = $claimResp->json('data.effect_ref');

    // 2. Submit revised prompt result
    $resultPath = "/api/internal/v1/cases/{$this->case->id}/prompt-feedback-effects/{$effectRef}/result";
    $revisedPrompt = "Process: Revised wave step with particle oscillators.";
    $outputSha = hash('sha256', $revisedPrompt);
    $rawResponse = json_encode(['modelVersion' => 'gemini-2.5-flash-v1', 'candidates' => [['content' => ['parts' => [['text' => $revisedPrompt]]]]]]);
    $respSha = hash('sha256', $rawResponse);

    $resultBody = [
        'schema_version' => 1,
        'revised_prompt' => $revisedPrompt,
        'output_sha256' => $outputSha,
        'provider_response_json' => $rawResponse,
        'provider_response_sha256' => $respSha,
        'returned_model' => 'gemini-2.5-flash-v1',
        'provider_response_id' => 'resp-123',
    ];

    $resultHeaders = WorkflowFixtures::signCallbackRequest('POST', $resultPath, $resultBody);
    $resp = $this->withHeaders($resultHeaders)->postJson($resultPath, $resultBody);
    $resp->assertStatus(201);

    $data = $resp->json('data');
    expect($data['version_no'])->toBe(2)
        ->and($data['state'])->toBe('awaiting_qualification');

    $v2Ref = $data['prompt_version_ref'];

    // Verify ScenePromptVersion row
    $v2 = ScenePromptVersion::find($v2Ref);
    expect($v2)->not->toBeNull()
        ->and($v2->version_no)->toBe(2)
        ->and($v2->predecessor_id)->toBe($this->promptVersion->id)
        ->and($v2->origin)->toBe('feedback')
        ->and($v2->feedback_effect_id)->toBe($effectRef)
        ->and($v2->scene_type)->toBe('Process');

    // Verify PromptSectionState updated
    $state = PromptSectionState::find($this->section1->id);
    expect($state->current_prompt_version_id)->toBe($v2Ref)
        ->and($state->status)->toBe('awaiting_qualification')
        ->and($state->revision_count)->toBe(1);

    // Exact replay returns 200 with same version
    $replayResp = $this->withHeaders($resultHeaders)->postJson($resultPath, $resultBody);
    $replayResp->assertStatus(200)
        ->assertJson([
            'data' => [
                'prompt_version_ref' => $v2Ref,
                'version_no' => 2,
                'state' => 'awaiting_qualification',
            ],
        ]);
});

test('P12: POST feedback uncertain marks effect and section uncertain without resend', function () {
    // 1. Claim effect
    $claimPath = "/api/internal/v1/cases/{$this->case->id}/prompt-versions/{$this->promptVersion->id}/feedback/claim";
    $reqPayload = "010-feedback-v1\n"
        . $this->promptVersion->id . "\n"
        . $this->qualification->answer_sha256 . "\n"
        . $this->templateChecksum . "\n"
        . 'gemini_api' . "\n"
        . 'gemini-2.5-flash' . "\n"
        . 'GEMINI_API_KEY';
    $requestSha = hash('sha256', $reqPayload);

    $claimBody = [
        'schema_version' => 1,
        'qualification_ref' => $this->qualification->id,
        'template_id' => $this->templateId,
        'template_sha256' => $this->templateChecksum,
        'provider_id' => 'gemini_api',
        'provider_model' => 'gemini-2.5-flash',
        'credential_ref' => 'GEMINI_API_KEY',
        'request_sha256' => $requestSha,
    ];
    $claimResp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimBody))
        ->postJson($claimPath, $claimBody);
    $effectRef = $claimResp->json('data.effect_ref');

    // 2. Post uncertain
    $uncPath = "/api/internal/v1/cases/{$this->case->id}/prompt-feedback-effects/{$effectRef}/uncertain";
    $uncBody = [
        'schema_version' => 1,
        'error_code' => 'timeout',
    ];
    $resp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $uncPath, $uncBody))
        ->postJson($uncPath, $uncBody);
    $resp->assertStatus(200)
        ->assertJson(['data' => ['state' => 'uncertain']]);

    // Verify DB states
    $effect = PromptFeedbackEffect::find($effectRef);
    expect($effect->state)->toBe('uncertain');

    $sectionState = PromptSectionState::find($this->section1->id);
    expect($sectionState->status)->toBe('uncertain');
});
