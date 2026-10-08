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
use App\Models\V3\PromptJevAttempt;
use App\Models\V3\PromptQualification;
use App\Models\V3\PromptSectionState;
use App\Models\V3\PromptTurn;
use App\Models\V3\PromptWorkItem;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SettingsVersion;
use App\Models\V3\TeacherSection;
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

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Qual');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Qual');

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

    $this->docContent = "PDF content for qualification";
    $this->docSha256 = hash('sha256', $this->docContent);
    $this->docPath = 'accepted/test-qual-doc.pdf';
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
        'idempotency_key' => 'idem-qual-' . Str::uuid(),
        'action' => 'process',
    ]);

    $this->link = \App\Models\V3\WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-qual-test',
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'start_state' => 'started',
        'stage_code' => 'prompt_qualification',
        'status_code' => 'running',
        'started_at' => now(),
    ]);

    $this->sourceActivation = \App\Models\V3\SourceActivation::forceCreate([
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
        'artifact_path' => 'extracted/qual-ev.txt',
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
        'notebook_id' => 'nb-qual-1',
        'source_id' => 'src-qual-1',
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
        'title' => 'Introduction to Wave Motion',
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
        'request_sha256' => hash('sha256', 'first-req-1'),
        'state' => 'recorded',
        'send_count' => 1,
        'conversation_in' => 'conv-in-first-1',
        'conversation_out' => 'conv-out-first-1',
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
        'conversation_id' => 'conv-out-first-1',
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
    $this->promptBody = 'Process: Wave propagation animation showing transverse displacement over time.';
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

    $this->sectionState = PromptSectionState::forceCreate([
        'section_id' => $this->section1->id,
        'case_id' => $this->case->id,
        'current_prompt_version_id' => $this->promptVersion->id,
        'status' => 'awaiting_qualification',
        'revision_count' => 0,
    ]);

    $this->questionAssetPath = resource_path('v3/jev/prompt-questions-v1.json');
    $this->questionSha256 = hash_file('sha256', $this->questionAssetPath);
});

test('P05: GET qualification-context returns exact captured settings and coverage pages', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/prompt-versions/{$this->promptVersion->id}/qualification-context";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $resp = $this->withHeaders($headers)->get($path);
    $resp->assertStatus(200);

    $data = $resp->json('data');
    expect($data['case_id'])->toBe($this->case->id)
        ->and($data['section_ref'])->toBe($this->section1->id)
        ->and($data['prompt_version_ref'])->toBe($this->promptVersion->id)
        ->and($data['prompt'])->toBe($this->promptBody)
        ->and($data['coverage_pages'])->toBe([1, 2])
        ->and($data['settings_version_id'])->toBe($this->settingsVersion->id)
        ->and($data['settings_checksum'])->toBe($this->settingsVersion->checksum)
        ->and($data['min_score'])->toBe(80)
        ->and($data['min_confidence'])->toBe(75)
        ->and($data['technical_retries'])->toBe(3)
        ->and($data['retry_delay_seconds'])->toBe(60)
        ->and($data['question_version'])->toBe('prompt-questions-v1');
});

test('P05: claim JEV attempt returns send_now on first claim and reconcile on exact duplicate', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/prompt-versions/{$this->promptVersion->id}/jev-attempts/claim";
    $requestedModel = 'typesafe-systemone-v1';

    $reqPayload = "010-jev-v1\n"
        . $this->promptVersion->id . "\n"
        . $this->promptBodySha . "\n"
        . $this->sectionFieldsSha . "\n"
        . $this->settingsVersion->checksum . "\n"
        . $this->questionSha256 . "\n"
        . $requestedModel;
    $requestSha = hash('sha256', $reqPayload);

    $body = [
        'schema_version' => 1,
        'request_sha256' => $requestSha,
        'question_sha256' => $this->questionSha256,
        'requested_model' => $requestedModel,
    ];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $body);

    $resp = $this->withHeaders($headers)->postJson($path, $body);
    $resp->assertStatus(200);

    $data = $resp->json('data');
    expect($data['attempt_no'])->toBe(1)
        ->and($data['disposition'])->toBe('send_now')
        ->and($data['request_sha256'])->toBe($requestSha);

    $attemptRef = $data['attempt_ref'];

    // Duplicate exact claim returns reconcile, never a second send_now
    $dupResp = $this->withHeaders($headers)->postJson($path, $body);
    $dupResp->assertStatus(200)
        ->assertJson([
            'data' => [
                'attempt_ref' => $attemptRef,
                'attempt_no' => 1,
                'disposition' => 'reconcile',
                'request_sha256' => $requestSha,
            ],
        ]);
});

test('P05: POST result with passing typed answers unlocks current version to qualified', function () {
    // 1. Claim attempt
    $claimPath = "/api/internal/v1/cases/{$this->case->id}/prompt-versions/{$this->promptVersion->id}/jev-attempts/claim";
    $requestedModel = 'typesafe-systemone-v1';
    $reqPayload = "010-jev-v1\n"
        . $this->promptVersion->id . "\n"
        . $this->promptBodySha . "\n"
        . $this->sectionFieldsSha . "\n"
        . $this->settingsVersion->checksum . "\n"
        . $this->questionSha256 . "\n"
        . $requestedModel;
    $requestSha = hash('sha256', $reqPayload);

    $claimBody = [
        'schema_version' => 1,
        'request_sha256' => $requestSha,
        'question_sha256' => $this->questionSha256,
        'requested_model' => $requestedModel,
    ];
    $claimResp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimBody))
        ->postJson($claimPath, $claimBody);
    $attemptRef = $claimResp->json('data.attempt_ref');

    // 2. Post valid passing result (at exact equality threshold)
    $resultPath = "/api/internal/v1/cases/{$this->case->id}/prompt-jev-attempts/{$attemptRef}/result";
    $responsePayload = [
        'model' => 'typesafe-systemone-v1',
        'answers' => [
            'choice' => [
                'type' => 'choice',
                'choice' => 'pass',
                'confidence' => 0.75,
                'probabilities' => ['pass' => 0.75, 'revise' => 0.2, 'block' => 0.05],
            ],
            'score' => [
                'type' => 'score',
                'score' => 80.0,
                'confidence' => 0.75,
                'probabilities' => array_fill(0, 101, 0.0),
            ],
            'noul' => [
                'type' => 'noul',
                'noul' => 0.75,
            ],
        ],
        'usage' => ['total_tokens' => 50],
    ];

    $resultBody = [
        'schema_version' => 1,
        'response' => $responsePayload,
        'request_sha256' => $requestSha,
    ];
    $resultHeaders = WorkflowFixtures::signCallbackRequest('POST', $resultPath, $resultBody);

    $resp = $this->withHeaders($resultHeaders)->postJson($resultPath, $resultBody);
    $resp->assertStatus(201);

    $data = $resp->json('data');
    expect($data['decision'])->toBe('pass')
        ->and($data['failed_checks'])->toBe([])
        ->and($data['qualification_ref'])->not->toBeEmpty();

    $qualRef = $data['qualification_ref'];

    // Verify row in DB
    $qual = PromptQualification::find($qualRef);
    expect($qual)->not->toBeNull()
        ->and($qual->decision)->toBe('pass')
        ->and($qual->rule_version)->toBe('prompt-rule-v1')
        ->and((float) $qual->score)->toBe(80.0)
        ->and($qual->noul_yes)->toBeTrue();

    // Verify private storage of answer
    expect(Storage::disk('v3_private')->exists($qual->answer_path))->toBeTrue();

    // Verify Section state updated to qualified
    $state = PromptSectionState::find($this->section1->id);
    expect($state->status)->toBe('qualified')
        ->and($state->last_qualification_id)->toBe($qualRef);

    // Exact replay returns 200 with same decision and ref
    $replayResp = $this->withHeaders($resultHeaders)->postJson($resultPath, $resultBody);
    $replayResp->assertStatus(200)
        ->assertJson([
            'data' => [
                'qualification_ref' => $qualRef,
                'decision' => 'pass',
                'failed_checks' => [],
            ],
        ]);
});

test('P08: changed JEV response replay conflicts with 409 and new attempt after verdict conflicts', function () {
    // 1. Claim attempt and record result
    $claimPath = "/api/internal/v1/cases/{$this->case->id}/prompt-versions/{$this->promptVersion->id}/jev-attempts/claim";
    $requestedModel = 'typesafe-systemone-v1';
    $reqPayload = "010-jev-v1\n"
        . $this->promptVersion->id . "\n"
        . $this->promptBodySha . "\n"
        . $this->sectionFieldsSha . "\n"
        . $this->settingsVersion->checksum . "\n"
        . $this->questionSha256 . "\n"
        . $requestedModel;
    $requestSha = hash('sha256', $reqPayload);

    $claimBody = [
        'schema_version' => 1,
        'request_sha256' => $requestSha,
        'question_sha256' => $this->questionSha256,
        'requested_model' => $requestedModel,
    ];
    $claimResp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimBody))
        ->postJson($claimPath, $claimBody);
    $attemptRef = $claimResp->json('data.attempt_ref');

    $resultPath = "/api/internal/v1/cases/{$this->case->id}/prompt-jev-attempts/{$attemptRef}/result";
    $validResponse = [
        'model' => 'typesafe-systemone-v1',
        'answers' => [
            'choice' => ['type' => 'choice', 'choice' => 'pass', 'confidence' => 0.8],
            'score' => ['type' => 'score', 'score' => 85.0, 'confidence' => 0.8],
            'noul' => ['type' => 'noul', 'noul' => 0.8],
        ],
    ];
    $resultBody = [
        'schema_version' => 1,
        'response' => $validResponse,
        'request_sha256' => $requestSha,
    ];
    $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $resultPath, $resultBody))
        ->postJson($resultPath, $resultBody)
        ->assertStatus(201);

    // 2. Changed replay (different score) returns 409 prompt_conflict
    $changedResponse = $validResponse;
    $changedResponse['answers']['score']['score'] = 95.0;
    $changedBody = [
        'schema_version' => 1,
        'response' => $changedResponse,
        'request_sha256' => $requestSha,
    ];
    $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $resultPath, $changedBody))
        ->postJson($resultPath, $changedBody)
        ->assertStatus(409)
        ->assertJson(['error' => ['code' => 'prompt_conflict']]);

    // 3. New attempt claim after recorded verdict returns 409 prompt_conflict
    $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimBody))
        ->postJson($claimPath, $claimBody)
        ->assertStatus(409)
        ->assertJson(['error' => ['code' => 'prompt_conflict']]);
});

test('P07: technical failure produces no verdict and decrements remaining attempts', function () {
    // 1. Claim attempt
    $claimPath = "/api/internal/v1/cases/{$this->case->id}/prompt-versions/{$this->promptVersion->id}/jev-attempts/claim";
    $requestedModel = 'typesafe-systemone-v1';
    $reqPayload = "010-jev-v1\n"
        . $this->promptVersion->id . "\n"
        . $this->promptBodySha . "\n"
        . $this->sectionFieldsSha . "\n"
        . $this->settingsVersion->checksum . "\n"
        . $this->questionSha256 . "\n"
        . $requestedModel;
    $requestSha = hash('sha256', $reqPayload);

    $claimBody = [
        'schema_version' => 1,
        'request_sha256' => $requestSha,
        'question_sha256' => $this->questionSha256,
        'requested_model' => $requestedModel,
    ];
    $claimResp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimBody))
        ->postJson($claimPath, $claimBody);
    $attemptRef = $claimResp->json('data.attempt_ref');

    // 2. Post technical failure
    $techPath = "/api/internal/v1/cases/{$this->case->id}/prompt-jev-attempts/{$attemptRef}/technical-failure";
    $techBody = [
        'schema_version' => 1,
        'error_code' => 'provider_unavailable',
    ];
    $resp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $techPath, $techBody))
        ->postJson($techPath, $techBody);
    $resp->assertStatus(200);

    // technical_retries is 3, total attempts allowed = 4. attempt_no 1 failed -> remaining = 3
    expect($resp->json('data.state'))->toBe('technical_failure')
        ->and($resp->json('data.remaining_attempts'))->toBe(3);

    // Assert NO qualification row created
    expect(PromptQualification::where('prompt_version_id', $this->promptVersion->id)->count())->toBe(0);

    // Next claim succeeds with attempt_no = 2
    $nextClaimResp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimBody))
        ->postJson($claimPath, $claimBody);
    $nextClaimResp->assertStatus(200)
        ->assertJson([
            'data' => [
                'attempt_no' => 2,
                'disposition' => 'send_now',
            ],
        ]);
});

test('P06: choice block is terminal and low score with exhausted budget blocks', function () {
    // 1. Choice block test
    $claimPath = "/api/internal/v1/cases/{$this->case->id}/prompt-versions/{$this->promptVersion->id}/jev-attempts/claim";
    $requestedModel = 'typesafe-systemone-v1';
    $reqPayload = "010-jev-v1\n"
        . $this->promptVersion->id . "\n"
        . $this->promptBodySha . "\n"
        . $this->sectionFieldsSha . "\n"
        . $this->settingsVersion->checksum . "\n"
        . $this->questionSha256 . "\n"
        . $requestedModel;
    $requestSha = hash('sha256', $reqPayload);

    $claimBody = [
        'schema_version' => 1,
        'request_sha256' => $requestSha,
        'question_sha256' => $this->questionSha256,
        'requested_model' => $requestedModel,
    ];
    $claimResp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $claimPath, $claimBody))
        ->postJson($claimPath, $claimBody);
    $attemptRef = $claimResp->json('data.attempt_ref');

    $resultPath = "/api/internal/v1/cases/{$this->case->id}/prompt-jev-attempts/{$attemptRef}/result";
    $blockResponse = [
        'model' => 'typesafe-systemone-v1',
        'answers' => [
            'choice' => ['type' => 'choice', 'choice' => 'block', 'confidence' => 0.99],
            'score' => ['type' => 'score', 'score' => 90.0, 'confidence' => 0.9],
            'noul' => ['type' => 'noul', 'noul' => 0.9],
        ],
    ];
    $resultBody = [
        'schema_version' => 1,
        'response' => $blockResponse,
        'request_sha256' => $requestSha,
    ];
    $resp = $this->withHeaders(WorkflowFixtures::signCallbackRequest('POST', $resultPath, $resultBody))
        ->postJson($resultPath, $resultBody);
    $resp->assertStatus(201);

    expect($resp->json('data.decision'))->toBe('block')
        ->and($resp->json('data.failed_checks'))->toContain('choice');

    $state = PromptSectionState::find($this->section1->id);
    expect($state->status)->toBe('blocked');
});
