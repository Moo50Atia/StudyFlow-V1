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
use App\Models\V3\PromptTurn;
use App\Models\V3\PromptWorkItem;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SourceActivation;
use App\Models\V3\TeacherSection;
use App\Models\V3\WorkflowLink;
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

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Prompt Turn Test');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Prompt Turn Test');

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

    $this->docContent = "PDF content";
    $this->docSha256 = hash('sha256', $this->docContent);
    $this->docPath = 'accepted/test-prompt-turn.pdf';
    Storage::disk('v3_private')->put($this->docPath, $this->docContent);

    $this->document = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'sha256' => $this->docSha256,
        'byte_size' => strlen($this->docContent),
        'page_count' => 3,
        'state' => 'ready',
        'storage_path' => $this->docPath,
        'accepted_at' => now(),
    ]);

    $this->case = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'running',
        'state_version' => 8,
    ]);

    $this->link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-turn-test',
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'start_state' => 'started',
        'stage_code' => 'sections',
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
        'artifact_path' => 'extracted/prompt-turn-ev.txt',
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
        'notebook_id' => 'nb-turn-1',
        'source_id' => 'src-turn-1',
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
        'work_count' => 2,
    ]);

    $this->workNode1 = LessonNode::forceCreate([
        'id' => (string) Str::uuid(),
        'map_id' => $this->map->id,
        'kind' => 'lesson',
        'position_path' => '001',
        'ordinal_in_parent' => 1,
        'title' => 'Kinematics Lesson',
        'work_ordinal' => 1,
        'coverage_pages' => [1, 2],
    ]);

    $this->firstQuery1 = LessonQuery::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'kind' => 'first',
        'target_work_ordinal' => 1,
        'state' => 'recorded',
        'send_count' => 1,
        'request_sha256' => hash('sha256', 'first-req-1'),
        'canonical_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
        'conversation_in' => 'conv-in-1',
        'conversation_out' => 'conv-first-out-1',
        'sent_at' => now(),
        'recorded_at' => now(),
    ]);

    $this->section1 = TeacherSection::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'work_node_id' => $this->workNode1->id,
        'query_id' => $this->firstQuery1->id,
        'ordinal_in_work' => 1,
        'section_identity' => 'Displacement vs Distance',
        'core_understanding' => 'Displacement is a vector; distance is scalar.',
        'visual_first_explanation' => 'Visual path of curved walking vs straight arrow.',
        'visual_scene_script' => 'A runner completes a lap and ends at the start line.',
        'dynamic_view_blueprint' => 'Overlay distance counter and displacement vector.',
        'visual_learning_outcome' => 'Learner sees net displacement is zero after round trip.',
        'concept_check' => 'What is the displacement for a full circle?',
        'conceptual_summary' => 'Distance measures path traveled; displacement measures net change.',
        'colloquial_egyptian_explanation' => 'المسافة كل اللي مشيته، والإزاحة أقصر خط من البداية للنهاية.',
        'laws_if_any' => 'delta_x = x_f - x_i',
        'real_life_example' => 'Walking to the supermarket and returning home.',
        'first_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
        'answer_sha256' => hash('sha256', 'ans-first-1'),
    ]);

    $this->cursor = LessonCursor::forceCreate([
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'current_work_ordinal' => 1,
        'phase' => 'awaiting_010',
        'conversation_id' => 'conv-first-out-1',
        'version' => 2,
    ]);
});

test('P04: GET prompt-context returns 11 Section fields and canonical digests for awaiting_010 work', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/prompt-context/1";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $resp = $this->withHeaders($headers)->get($path);
    $resp->assertStatus(200);

    $data = $resp->json('data');
    expect($data['case_id'])->toBe($this->case->id)
        ->and($data['work_ordinal'])->toBe(1)
        ->and($data['cursor_version'])->toBe(2)
        ->and($data['conversation_ref'])->toBe('conv-first-out-1')
        ->and($data['sections'])->toHaveCount(1)
        ->and($data['sections'][0]['fields'])->toHaveKeys([
            'section_identity',
            'core_understanding',
            'visual_first_explanation',
            'visual_scene_script',
            'dynamic_view_blueprint',
            'visual_learning_outcome',
            'concept_check',
            'conceptual_summary',
            'colloquial_egyptian_explanation',
            'laws_if_any',
            'real_life_example',
        ])
        ->and($data['continue_origin'])->toBeNull();
});

test('P04: POST prompt-work-items claim commits work item and prevents stale cursor claims', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/prompt-work-items/1/claim";
    $payload = [
        'schema_version' => 1,
        'map_ref' => $this->map->id,
        'work_node_ref' => $this->workNode1->id,
        'section_refs' => [$this->section1->id],
        'expected_cursor_version' => 2,
    ];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $payload);

    $resp = $this->withHeaders($headers)->postJson($path, $payload);
    $resp->assertStatus(201);
    $workItemRef = $resp->json('data.work_item_ref');
    expect($workItemRef)->not->toBeEmpty();

    // Exact replay returns 200
    $replayResp = $this->withHeaders($headers)->postJson($path, $payload);
    $replayResp->assertStatus(200)
        ->assertJson(['data' => ['work_item_ref' => $workItemRef]]);

    // Stale or conflicting cursor version returns 409 prompt_conflict
    $conflictPayload = $payload;
    $conflictPayload['expected_cursor_version'] = 999;
    $conflictHeaders = WorkflowFixtures::signCallbackRequest('POST', $path, $conflictPayload);
    $this->withHeaders($conflictHeaders)->postJson($path, $conflictPayload)
        ->assertStatus(409)
        ->assertJson(['error' => ['code' => 'prompt_conflict']]);
});

test('P04 & P12: turn claim and result lifecycle commits turns, artifacts and original prompt versions', function () {
    // 1. Claim work item
    $claimWorkPath = "/api/internal/v1/cases/{$this->case->id}/prompt-work-items/1/claim";
    $claimWorkPayload = [
        'schema_version' => 1,
        'map_ref' => $this->map->id,
        'work_node_ref' => $this->workNode1->id,
        'section_refs' => [$this->section1->id],
        'expected_cursor_version' => 2,
    ];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $claimWorkPath, $claimWorkPayload);
    $resp = $this->withHeaders($headers)->postJson($claimWorkPath, $claimWorkPayload);
    $resp->assertStatus(201);
    $workItemRef = $resp->json('data.work_item_ref');

    // 2. Claim Execution turn (work 1 sent once)
    $claimExecPath = "/api/internal/v1/cases/{$this->case->id}/prompt-work-items/{$workItemRef}/turns/execution/claim";
    $claimExecPayload = [
        'schema_version' => 1,
        'origin' => 'sent',
        'conversation_in' => 'conv-first-out-1',
        'canonical_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_EXECUTION_SHA256,
        'origin_query_ref' => null,
        'origin_turn_ref' => null,
    ];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $claimExecPath, $claimExecPayload);
    $resp = $this->withHeaders($headers)->postJson($claimExecPath, $claimExecPayload);
    $resp->assertStatus(200);
    expect($resp->json('data.disposition'))->toBe('send_now');
    $execTurnRef = $resp->json('data.turn_ref');

    // 3. Post Execution result (ready shape)
    $execAns = "System Ready";
    $execAnsSha = hash('sha256', $execAns);
    $resExecPath = "/api/internal/v1/cases/{$this->case->id}/prompt-turns/{$execTurnRef}/result";
    $resExecPayload = [
        'schema_version' => 1,
        'answer' => $execAns,
        'conversation_out' => 'conv-exec-out',
        'execution_shape' => 'ready',
        'blocks' => [],
        'answer_sha256' => $execAnsSha,
    ];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $resExecPath, $resExecPayload);
    $this->withHeaders($headers)->postJson($resExecPath, $resExecPayload)->assertStatus(201);

    // 4. Claim and post Second turn
    $claimSecPath = "/api/internal/v1/cases/{$this->case->id}/prompt-work-items/{$workItemRef}/turns/second/claim";
    $claimSecPayload = [
        'schema_version' => 1,
        'origin' => 'sent',
        'conversation_in' => 'conv-exec-out',
        'canonical_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_SECOND_SHA256,
        'origin_query_ref' => null,
        'origin_turn_ref' => null,
    ];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $claimSecPath, $claimSecPayload);
    $resp = $this->withHeaders($headers)->postJson($claimSecPath, $claimSecPayload);
    $resp->assertStatus(200);
    $secTurnRef = $resp->json('data.turn_ref');

    $secAns = "Lecture: W1 | Kinematics Lesson\n\nLec1 (Sec1):\n\"Scene Blueprint Placeholder\"";
    $secAnsSha = hash('sha256', $secAns);
    $resSecPath = "/api/internal/v1/cases/{$this->case->id}/prompt-turns/{$secTurnRef}/result";
    $resSecPayload = [
        'schema_version' => 1,
        'answer' => $secAns,
        'conversation_out' => 'conv-sec-out',
        'execution_shape' => null,
        'blocks' => [
            ['section_ref' => $this->section1->id, 'ordinal' => 1, 'text' => '"Scene Blueprint Placeholder"'],
        ],
        'answer_sha256' => $secAnsSha,
    ];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $resSecPath, $resSecPayload);
    $this->withHeaders($headers)->postJson($resSecPath, $resSecPayload)->assertStatus(201);

    // 5. Claim and post Third turn
    $claimThirdPath = "/api/internal/v1/cases/{$this->case->id}/prompt-work-items/{$workItemRef}/turns/third/claim";
    $claimThirdPayload = [
        'schema_version' => 1,
        'origin' => 'sent',
        'conversation_in' => 'conv-sec-out',
        'canonical_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_THIRD_SHA256,
        'origin_query_ref' => null,
        'origin_turn_ref' => null,
    ];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $claimThirdPath, $claimThirdPayload);
    $resp = $this->withHeaders($headers)->postJson($claimThirdPath, $claimThirdPayload);
    $resp->assertStatus(200);
    $thirdTurnRef = $resp->json('data.turn_ref');

    $promptBody = "Process: A displacement arrow extends from origin to destination as the particle moves.";
    $thirdAns = "Lecture: W1 | Kinematics Lesson\n\nLec1 (Sec1):\n\"{$promptBody}\"";
    $thirdAnsSha = hash('sha256', $thirdAns);
    $resThirdPath = "/api/internal/v1/cases/{$this->case->id}/prompt-turns/{$thirdTurnRef}/result";
    $resThirdPayload = [
        'schema_version' => 1,
        'answer' => $thirdAns,
        'conversation_out' => 'conv-third-out',
        'execution_shape' => null,
        'blocks' => [
            ['section_ref' => $this->section1->id, 'ordinal' => 1, 'text' => $promptBody],
        ],
        'answer_sha256' => $thirdAnsSha,
    ];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $resThirdPath, $resThirdPayload);
    $thirdResultResp = $this->withHeaders($headers)->postJson($resThirdPath, $resThirdPayload);
    $thirdResultResp->assertStatus(201);

    $originalPromptRefs = $thirdResultResp->json('data.original_prompt_refs');
    expect($originalPromptRefs)->toHaveCount(1);
    $promptRef = $originalPromptRefs[0];

    // Verify DB state: version-1 row exists and section status is awaiting_qualification
    $promptRow = DB::table('v3_scene_prompt_versions')->where('id', $promptRef)->first();
    expect($promptRow)->not->toBeNull()
        ->and($promptRow->version_no)->toBe(1)
        ->and($promptRow->scene_type)->toBe('Process')
        ->and($promptRow->origin)->toBe('third');

    $sectionState = DB::table('v3_prompt_section_states')->where('section_id', $this->section1->id)->first();
    expect($sectionState)->not->toBeNull()
        ->and($sectionState->status)->toBe('awaiting_qualification')
        ->and($sectionState->current_prompt_version_id)->toBe($promptRef);
});

test('P12: post-send timeout marks turn uncertain without automatic resend', function () {
    // Claim work item and execution turn
    $claimWorkPath = "/api/internal/v1/cases/{$this->case->id}/prompt-work-items/1/claim";
    $claimWorkPayload = [
        'schema_version' => 1,
        'map_ref' => $this->map->id,
        'work_node_ref' => $this->workNode1->id,
        'section_refs' => [$this->section1->id],
        'expected_cursor_version' => 2,
    ];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $claimWorkPath, $claimWorkPayload);
    $resp = $this->withHeaders($headers)->postJson($claimWorkPath, $claimWorkPayload);
    $workItemRef = $resp->json('data.work_item_ref');

    $claimExecPath = "/api/internal/v1/cases/{$this->case->id}/prompt-work-items/{$workItemRef}/turns/execution/claim";
    $claimExecPayload = [
        'schema_version' => 1,
        'origin' => 'sent',
        'conversation_in' => 'conv-first-out-1',
        'canonical_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_EXECUTION_SHA256,
        'origin_query_ref' => null,
        'origin_turn_ref' => null,
    ];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $claimExecPath, $claimExecPayload);
    $resp = $this->withHeaders($headers)->postJson($claimExecPath, $claimExecPayload);
    $turnRef = $resp->json('data.turn_ref');

    // Post uncertain
    $uncertainPath = "/api/internal/v1/cases/{$this->case->id}/prompt-turns/{$turnRef}/uncertain";
    $uncertainPayload = [
        'schema_version' => 1,
        'error_code' => 'timeout',
    ];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $uncertainPath, $uncertainPayload);
    $this->withHeaders($headers)->postJson($uncertainPath, $uncertainPayload)->assertStatus(200);

    // Verify turn state is uncertain
    $turn = DB::table('v3_prompt_turns')->where('id', $turnRef)->first();
    expect($turn->state)->toBe('uncertain')
        ->and($turn->error_code)->toBe('timeout');

    // Replay claim does not return send_now
    $claimReplayHeaders = WorkflowFixtures::signCallbackRequest('POST', $claimExecPath, $claimExecPayload);
    $replayResp = $this->withHeaders($claimReplayHeaders)->postJson($claimExecPath, $claimExecPayload);
    $replayResp->assertStatus(200);
    expect($replayResp->json('data.disposition'))->not->toBe('send_now');
});
