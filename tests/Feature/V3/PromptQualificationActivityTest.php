<?php

namespace Tests\Feature\V3;

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
use App\Services\V3\Authoring\ClaimAuthoringRequest;
use App\Services\V3\Prompts\PromptContextReader;
use App\Services\V3\Prompts\QualifiedPromptResolver;
use App\Services\V3\Prompts\RecordPromptQualification;
use App\Services\V3\Prompts\RecordPromptRevision;
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

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Activity Test');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Activity Test');

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

    $this->docContent = "PDF content for activity test";
    $this->docSha256 = hash('sha256', $this->docContent);
    $this->docPath = 'accepted/test-activity-doc.pdf';
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
        'idempotency_key' => 'idem-act-' . Str::uuid(),
        'action' => 'process',
    ]);

    $this->link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-act-test',
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
        'artifact_path' => 'extracted/act-ev.txt',
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
        'notebook_id' => 'nb-act-1',
        'source_id' => 'src-act-1',
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
        'title' => 'Introduction to Waves',
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
        'request_sha256' => hash('sha256', 'first-req-act'),
        'state' => 'recorded',
        'send_count' => 1,
        'conversation_in' => 'conv-in-first-act',
        'conversation_out' => 'conv-out-first-act',
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
        'section_identity' => 'Wave Motion',
        'core_understanding' => 'Waves transfer energy without matter.',
        'visual_first_explanation' => 'Visual particles oscillating.',
        'visual_scene_script' => 'Line of particles connected by springs.',
        'dynamic_view_blueprint' => 'Particles displaced in a wave pattern.',
        'visual_learning_outcome' => 'Learner understands particle vs wave motion.',
        'concept_check' => 'Do particles travel with wave?',
        'conceptual_summary' => 'Energy travels while particles vibrate.',
        'colloquial_egyptian_explanation' => 'الموجة بتنقل الطاقة مش المادة.',
        'laws_if_any' => 'v = f * lambda',
        'real_life_example' => 'Stadium wave.',
        'first_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
        'answer_sha256' => hash('sha256', 'first-ans-act'),
    ]);

    $this->section2 = TeacherSection::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'work_node_id' => $this->workNode1->id,
        'query_id' => $this->firstQuery->id,
        'ordinal_in_work' => 2,
        'section_identity' => 'Wave Frequency',
        'core_understanding' => 'Frequency is oscillations per second.',
        'visual_first_explanation' => 'Oscillating pendulum with counter.',
        'visual_scene_script' => 'Pendulum swinging past central mark.',
        'dynamic_view_blueprint' => 'Real-time frequency graph plotting cycles.',
        'visual_learning_outcome' => 'Learner relates cycle count to frequency.',
        'concept_check' => 'What is unit of frequency?',
        'conceptual_summary' => 'Hertz measures cycles per second.',
        'colloquial_egyptian_explanation' => 'التردد هو عدد الاهتزازات في الثانية.',
        'laws_if_any' => 'f = 1 / T',
        'real_life_example' => 'Heartbeat pulse.',
        'first_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
        'answer_sha256' => hash('sha256', 'first-ans-act-2'),
    ]);

    $this->cursor = LessonCursor::forceCreate([
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'current_work_ordinal' => 1,
        'phase' => 'awaiting_010',
        'conversation_id' => 'conv-out-act',
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
        'state' => 'originals_ready',
        'section_refs' => [$this->section1->id, $this->section2->id],
    ]);

    // Section 1 Prompt v1
    $body1 = 'Process: Wave propagation animation showing transverse displacement.';
    $bodySha1 = hash('sha256', $body1);
    $path1 = "prompt-bodies/{$this->case->id}/{$this->section1->id}/v1/{$bodySha1}.txt";
    Storage::disk('v3_private')->put($path1, $body1);

    $fields1 = [
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
    $fieldsSha1 = PromptContextReader::computeSectionFieldsSha256($fields1);

    $this->promptVersion1 = ScenePromptVersion::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'work_item_id' => $this->workItem->id,
        'section_id' => $this->section1->id,
        'version_no' => 1,
        'predecessor_id' => null,
        'origin' => 'third',
        'body_path' => $path1,
        'body_sha256' => $bodySha1,
        'body_bytes' => strlen($body1),
        'scene_type' => 'Process',
        'section_fields_sha256' => $fieldsSha1,
        'canonical_third_sha256' => PromptContextReader::CANONICAL_THIRD_SHA256,
        'created_at' => now(),
    ]);

    $this->sectionState1 = PromptSectionState::forceCreate([
        'section_id' => $this->section1->id,
        'case_id' => $this->case->id,
        'current_prompt_version_id' => $this->promptVersion1->id,
        'status' => 'awaiting_qualification',
        'revision_count' => 0,
    ]);

    // Section 2 Prompt v1
    $body2 = 'Comparison: Frequency oscillation comparing high vs low frequency.';
    $bodySha2 = hash('sha256', $body2);
    $path2 = "prompt-bodies/{$this->case->id}/{$this->section2->id}/v1/{$bodySha2}.txt";
    Storage::disk('v3_private')->put($path2, $body2);

    $fields2 = [
        'section_identity' => $this->section2->section_identity,
        'core_understanding' => $this->section2->core_understanding,
        'visual_first_explanation' => $this->section2->visual_first_explanation,
        'visual_scene_script' => $this->section2->visual_scene_script,
        'dynamic_view_blueprint' => $this->section2->dynamic_view_blueprint,
        'visual_learning_outcome' => $this->section2->visual_learning_outcome,
        'concept_check' => $this->section2->concept_check,
        'conceptual_summary' => $this->section2->conceptual_summary,
        'colloquial_egyptian_explanation' => $this->section2->colloquial_egyptian_explanation,
        'laws_if_any' => $this->section2->laws_if_any,
        'real_life_example' => $this->section2->real_life_example,
    ];
    $fieldsSha2 = PromptContextReader::computeSectionFieldsSha256($fields2);

    $this->promptVersion2 = ScenePromptVersion::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'work_item_id' => $this->workItem->id,
        'section_id' => $this->section2->id,
        'version_no' => 1,
        'predecessor_id' => null,
        'origin' => 'third',
        'body_path' => $path2,
        'body_sha256' => $bodySha2,
        'body_bytes' => strlen($body2),
        'scene_type' => 'Comparison',
        'section_fields_sha256' => $fieldsSha2,
        'canonical_third_sha256' => PromptContextReader::CANONICAL_THIRD_SHA256,
        'created_at' => now(),
    ]);

    $this->sectionState2 = PromptSectionState::forceCreate([
        'section_id' => $this->section2->id,
        'case_id' => $this->case->id,
        'current_prompt_version_id' => $this->promptVersion2->id,
        'status' => 'awaiting_qualification',
        'revision_count' => 0,
    ]);

    $this->questionAssetPath = resource_path('v3/jev/prompt-questions-v1.json');
    $this->questionSha256 = hash_file('sha256', $this->questionAssetPath);
    $this->jevModel = 'typesafe-systemone-v1';
});

function buildJevRequestSha(ScenePromptVersion $pv, SettingsVersion $sv, string $qSha, string $model): string {
    return RecordPromptQualification::computeJevRequestDigest(
        $pv->id,
        $pv->body_sha256,
        $pv->section_fields_sha256,
        $sv->checksum,
        $qSha,
        $model
    );
}

function validPassAnswers(): array {
    return [
        'choice' => ['type' => 'choice', 'choice' => 'pass', 'confidence' => 0.85, 'probabilities' => ['pass' => 0.85]],
        'score' => ['type' => 'score', 'score' => 88.0, 'confidence' => 0.82, 'probabilities' => array_fill(0, 101, 0.0)],
        'noul' => ['type' => 'noul', 'noul' => 0.90],
    ];
}

test('R08: approved typed pass unlocks prompt and qualifies section', function () {
    $reqSha = buildJevRequestSha($this->promptVersion1, $this->settingsVersion, $this->questionSha256, $this->jevModel);

    // Claim attempt
    $claimPath = "/api/internal/v1/cases/{$this->case->id}/prompt-versions/{$this->promptVersion1->id}/jev-attempts/claim";
    $claimBody = [
        'schema_version' => 1,
        'request_sha256' => $reqSha,
        'question_sha256' => $this->questionSha256,
        'requested_model' => $this->jevModel,
    ];
    $resp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimBody))
        ->postJson($claimPath, $claimBody);
    $resp->assertStatus(200);
    $attemptRef = $resp->json('data.attempt_ref');

    // Post pass result
    $resPath = "/api/internal/v1/cases/{$this->case->id}/prompt-jev-attempts/{$attemptRef}/result";
    $resBody = [
        'schema_version' => 1,
        'request_sha256' => $reqSha,
        'response' => [
            'model' => $this->jevModel,
            'answers' => validPassAnswers(),
            'usage' => ['total_tokens' => 42],
        ],
    ];
    $resp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $resPath, $resBody))
        ->postJson($resPath, $resBody);
    $resp->assertStatus(201);
    expect($resp->json('data.decision'))->toBe('pass')
        ->and($resp->json('data.failed_checks'))->toBe([]);

    $this->sectionState1->refresh();
    expect($this->sectionState1->status)->toBe('qualified')
        ->and($this->sectionState1->error_code)->toBeNull();
});

test('R08: choice block is terminal regardless of score and budget', function () {
    $reqSha = buildJevRequestSha($this->promptVersion1, $this->settingsVersion, $this->questionSha256, $this->jevModel);

    $claimPath = "/api/internal/v1/cases/{$this->case->id}/prompt-versions/{$this->promptVersion1->id}/jev-attempts/claim";
    $claimBody = [
        'schema_version' => 1,
        'request_sha256' => $reqSha,
        'question_sha256' => $this->questionSha256,
        'requested_model' => $this->jevModel,
    ];
    $resp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimBody))
        ->postJson($claimPath, $claimBody);
    $attemptRef = $resp->json('data.attempt_ref');

    $resPath = "/api/internal/v1/cases/{$this->case->id}/prompt-jev-attempts/{$attemptRef}/result";
    $answers = validPassAnswers();
    $answers['choice']['choice'] = 'block';

    $resBody = [
        'schema_version' => 1,
        'request_sha256' => $reqSha,
        'response' => [
            'model' => $this->jevModel,
            'answers' => $answers,
            'usage' => ['total_tokens' => 42],
        ],
    ];
    $resp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $resPath, $resBody))
        ->postJson($resPath, $resBody);
    $resp->assertStatus(201);
    expect($resp->json('data.decision'))->toBe('block')
        ->and($resp->json('data.failed_checks'))->toContain('choice')
        ->and($resp->json('data.failed_checks'))->not->toContain('revision_budget_exhausted');

    $this->sectionState1->refresh();
    expect($this->sectionState1->status)->toBe('blocked');
});

test('R08: noul boundary and low confidence produce revise decision', function () {
    $reqSha = buildJevRequestSha($this->promptVersion1, $this->settingsVersion, $this->questionSha256, $this->jevModel);

    $claimPath = "/api/internal/v1/cases/{$this->case->id}/prompt-versions/{$this->promptVersion1->id}/jev-attempts/claim";
    $claimBody = [
        'schema_version' => 1,
        'request_sha256' => $reqSha,
        'question_sha256' => $this->questionSha256,
        'requested_model' => $this->jevModel,
    ];
    $resp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimBody))
        ->postJson($claimPath, $claimBody);
    $attemptRef = $resp->json('data.attempt_ref');

    $resPath = "/api/internal/v1/cases/{$this->case->id}/prompt-jev-attempts/{$attemptRef}/result";
    $answers = validPassAnswers();
    $answers['noul']['noul'] = 0.60; // passes >= 0.5, but 100*0.60 = 60 < 75 min_confidence

    $resBody = [
        'schema_version' => 1,
        'request_sha256' => $reqSha,
        'response' => [
            'model' => $this->jevModel,
            'answers' => $answers,
            'usage' => ['total_tokens' => 42],
        ],
    ];
    $resp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $resPath, $resBody))
        ->postJson($resPath, $resBody);
    $resp->assertStatus(201);
    expect($resp->json('data.decision'))->toBe('revise')
        ->and($resp->json('data.failed_checks'))->toContain('noul_confidence');

    $this->sectionState1->refresh();
    expect($this->sectionState1->status)->toBe('revising');
});

test('R08: revision limit blocks section when budget exhausted', function () {
    // Set revision_count to 2 (limit is 2)
    $this->sectionState1->update(['revision_count' => 2]);

    $reqSha = buildJevRequestSha($this->promptVersion1, $this->settingsVersion, $this->questionSha256, $this->jevModel);

    $claimPath = "/api/internal/v1/cases/{$this->case->id}/prompt-versions/{$this->promptVersion1->id}/jev-attempts/claim";
    $claimBody = [
        'schema_version' => 1,
        'request_sha256' => $reqSha,
        'question_sha256' => $this->questionSha256,
        'requested_model' => $this->jevModel,
    ];
    $resp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimBody))
        ->postJson($claimPath, $claimBody);
    $attemptRef = $resp->json('data.attempt_ref');

    $resPath = "/api/internal/v1/cases/{$this->case->id}/prompt-jev-attempts/{$attemptRef}/result";
    $answers = validPassAnswers();
    $answers['choice']['choice'] = 'revise';

    $resBody = [
        'schema_version' => 1,
        'request_sha256' => $reqSha,
        'response' => [
            'model' => $this->jevModel,
            'answers' => $answers,
            'usage' => ['total_tokens' => 42],
        ],
    ];
    $resp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $resPath, $resBody))
        ->postJson($resPath, $resBody);
    $resp->assertStatus(201);
    expect($resp->json('data.decision'))->toBe('block')
        ->and($resp->json('data.failed_checks'))->toContain('revision_budget_exhausted');

    $this->sectionState1->refresh();
    expect($this->sectionState1->status)->toBe('blocked');
});

test('R08: technical failure endpoint records failure without producing verdict', function () {
    $reqSha = buildJevRequestSha($this->promptVersion1, $this->settingsVersion, $this->questionSha256, $this->jevModel);

    $claimPath = "/api/internal/v1/cases/{$this->case->id}/prompt-versions/{$this->promptVersion1->id}/jev-attempts/claim";
    $claimBody = [
        'schema_version' => 1,
        'request_sha256' => $reqSha,
        'question_sha256' => $this->questionSha256,
        'requested_model' => $this->jevModel,
    ];
    $resp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimBody))
        ->postJson($claimPath, $claimBody);
    $attemptRef = $resp->json('data.attempt_ref');

    $tfPath = "/api/internal/v1/cases/{$this->case->id}/prompt-jev-attempts/{$attemptRef}/technical-failure";
    $tfBody = [
        'schema_version' => 1,
        'error_code' => 'provider_unavailable',
    ];
    $resp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $tfPath, $tfBody))
        ->postJson($tfPath, $tfBody);
    $resp->assertStatus(200)
        ->assertJson([
            'data' => [
                'state' => 'technical_failure',
                'remaining_attempts' => 3,
            ],
        ]);

    expect(PromptQualification::where('prompt_version_id', $this->promptVersion1->id)->exists())->toBeFalse();
    $this->sectionState1->refresh();
    expect($this->sectionState1->status)->toBe('awaiting_qualification');
});

test('R08: malformed wire response rejects with 422', function () {
    $reqSha = buildJevRequestSha($this->promptVersion1, $this->settingsVersion, $this->questionSha256, $this->jevModel);

    $claimPath = "/api/internal/v1/cases/{$this->case->id}/prompt-versions/{$this->promptVersion1->id}/jev-attempts/claim";
    $claimBody = [
        'schema_version' => 1,
        'request_sha256' => $reqSha,
        'question_sha256' => $this->questionSha256,
        'requested_model' => $this->jevModel,
    ];
    $resp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimBody))
        ->postJson($claimPath, $claimBody);
    $attemptRef = $resp->json('data.attempt_ref');

    $resPath = "/api/internal/v1/cases/{$this->case->id}/prompt-jev-attempts/{$attemptRef}/result";
    $resBody = [
        'schema_version' => 1,
        'request_sha256' => $reqSha,
        'response' => [
            'model' => $this->jevModel,
            'answers' => ['choice' => ['type' => 'choice']], // missing score and noul
        ],
    ];
    $resp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $resPath, $resBody))
        ->postJson($resPath, $resBody);
    $resp->assertStatus(422);

    expect(PromptQualification::where('prompt_version_id', $this->promptVersion1->id)->exists())->toBeFalse();
});

test('R08: duplicate claim returns reconcile and replay result returns 200', function () {
    $reqSha = buildJevRequestSha($this->promptVersion1, $this->settingsVersion, $this->questionSha256, $this->jevModel);

    $claimPath = "/api/internal/v1/cases/{$this->case->id}/prompt-versions/{$this->promptVersion1->id}/jev-attempts/claim";
    $claimBody = [
        'schema_version' => 1,
        'request_sha256' => $reqSha,
        'question_sha256' => $this->questionSha256,
        'requested_model' => $this->jevModel,
    ];
    $resp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimBody))
        ->postJson($claimPath, $claimBody);
    $attemptRef = $resp->json('data.attempt_ref');

    // Duplicate claim returns reconcile
    $dupClaim = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimBody))
        ->postJson($claimPath, $claimBody);
    $dupClaim->assertStatus(200)
        ->assertJson([
            'data' => [
                'attempt_ref' => $attemptRef,
                'disposition' => 'reconcile',
            ],
        ]);

    // Record result
    $resPath = "/api/internal/v1/cases/{$this->case->id}/prompt-jev-attempts/{$attemptRef}/result";
    $resBody = [
        'schema_version' => 1,
        'request_sha256' => $reqSha,
        'response' => [
            'model' => $this->jevModel,
            'answers' => validPassAnswers(),
            'usage' => ['total_tokens' => 42],
        ],
    ];
    $resp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $resPath, $resBody))
        ->postJson($resPath, $resBody);
    $resp->assertStatus(201);
    $qualRef = $resp->json('data.qualification_ref');

    // Replay exact result returns 200 with same qualification_ref
    $replayResp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $resPath, $resBody))
        ->postJson($resPath, $resBody);
    $replayResp->assertStatus(200)
        ->assertJson([
            'data' => [
                'qualification_ref' => $qualRef,
                'decision' => 'pass',
            ],
        ]);
});

test('R08: mixed set of qualified and blocked sections only promotes passing refs to authoring', function () {
    // 1. Qualify Section 1 with pass
    $reqSha1 = buildJevRequestSha($this->promptVersion1, $this->settingsVersion, $this->questionSha256, $this->jevModel);
    $claimPath1 = "/api/internal/v1/cases/{$this->case->id}/prompt-versions/{$this->promptVersion1->id}/jev-attempts/claim";
    $claimBody1 = [
        'schema_version' => 1,
        'request_sha256' => $reqSha1,
        'question_sha256' => $this->questionSha256,
        'requested_model' => $this->jevModel,
    ];
    $resp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $claimPath1, $claimBody1))->postJson($claimPath1, $claimBody1);
    $attempt1 = $resp->json('data.attempt_ref');

    $resPath1 = "/api/internal/v1/cases/{$this->case->id}/prompt-jev-attempts/{$attempt1}/result";
    $resBody1 = [
        'schema_version' => 1,
        'request_sha256' => $reqSha1,
        'response' => ['model' => $this->jevModel, 'answers' => validPassAnswers(), 'usage' => []],
    ];
    $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $resPath1, $resBody1))->postJson($resPath1, $resBody1)->assertStatus(201);

    // 2. Block Section 2 with choice block
    $reqSha2 = buildJevRequestSha($this->promptVersion2, $this->settingsVersion, $this->questionSha256, $this->jevModel);
    $claimPath2 = "/api/internal/v1/cases/{$this->case->id}/prompt-versions/{$this->promptVersion2->id}/jev-attempts/claim";
    $claimBody2 = [
        'schema_version' => 1,
        'request_sha256' => $reqSha2,
        'question_sha256' => $this->questionSha256,
        'requested_model' => $this->jevModel,
    ];
    $resp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $claimPath2, $claimBody2))->postJson($claimPath2, $claimBody2);
    $attempt2 = $resp->json('data.attempt_ref');

    $resPath2 = "/api/internal/v1/cases/{$this->case->id}/prompt-jev-attempts/{$attempt2}/result";
    $blockAnswers = validPassAnswers();
    $blockAnswers['choice']['choice'] = 'block';
    $resBody2 = [
        'schema_version' => 1,
        'request_sha256' => $reqSha2,
        'response' => ['model' => $this->jevModel, 'answers' => $blockAnswers, 'usage' => []],
    ];
    $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $resPath2, $resBody2))->postJson($resPath2, $resBody2)->assertStatus(201);

    // 3. Verify QualifiedPromptResolver resolves section 1 and rejects section 2
    $resolver = app(QualifiedPromptResolver::class);
    $resolved1 = $resolver->resolve($this->case->id, $this->promptVersion1->id);
    expect($resolved1['prompt_version_ref'])->toBe($this->promptVersion1->id);

    expect(fn() => $resolver->resolve($this->case->id, $this->promptVersion2->id))
        ->toThrow(\App\Services\V3\Prompts\PromptUnavailableException::class);

    // 4. Verify Authoring lists ONLY section 1
    $authoringService = app(ClaimAuthoringRequest::class);
    $eligible = $authoringService->listEligiblePrompts($this->case->id);
    expect($eligible)->toHaveCount(1)
        ->and($eligible[0]['prompt_version_ref'])->toBe($this->promptVersion1->id)
        ->and($eligible[0]['section_ref'])->toBe($this->section1->id);
});

test('R08: feedback-context projects qualification_answer_sha for caller digest derivation', function () {
    // Set up a qualification on promptVersion1
    $reqSha = buildJevRequestSha($this->promptVersion1, $this->settingsVersion, $this->questionSha256, $this->jevModel);
    $claimPath = "/api/internal/v1/cases/{$this->case->id}/prompt-versions/{$this->promptVersion1->id}/jev-attempts/claim";
    $claimBody = [
        'schema_version' => 1,
        'request_sha256' => $reqSha,
        'question_sha256' => $this->questionSha256,
        'requested_model' => $this->jevModel,
    ];
    $resp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimBody))->postJson($claimPath, $claimBody);
    $attemptRef = $resp->json('data.attempt_ref');

    $resPath = "/api/internal/v1/cases/{$this->case->id}/prompt-jev-attempts/{$attemptRef}/result";
    $answers = validPassAnswers();
    $answers['choice']['choice'] = 'revise';
    $resBody = [
        'schema_version' => 1,
        'request_sha256' => $reqSha,
        'response' => ['model' => $this->jevModel, 'answers' => $answers, 'usage' => []],
    ];
    $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $resPath, $resBody))->postJson($resPath, $resBody)->assertStatus(201);

    // Call feedback-context
    $fcPath = "/api/internal/v1/cases/{$this->case->id}/prompt-versions/{$this->promptVersion1->id}/feedback-context";
    $fcResp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('GET', $fcPath, []))->get($fcPath);
    $fcResp->assertStatus(200);

    $fcData = $fcResp->json('data');
    expect($fcData)->toHaveKey('qualification_answer_sha256')
        ->and($fcData['qualification_answer_sha256'])->not->toBeEmpty();
});

test('R08: prompt-work-items prompt-versions endpoint returns version 1 prompt refs', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/prompt-work-items/1/prompt-versions";
    $resp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('GET', $path, []))->get($path);
    $resp->assertStatus(200);

    $data = $resp->json('data');
    expect($data['prompt_version_refs'])->toHaveCount(2)
        ->and($data['prompt_version_refs'])->toContain($this->promptVersion1->id)
        ->and($data['prompt_version_refs'])->toContain($this->promptVersion2->id);
});

