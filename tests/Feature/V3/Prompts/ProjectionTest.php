<?php

namespace Tests\Feature\V3\Prompts;

use App\Models\V3\Document;
use App\Models\V3\ExtractedSource;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\LessonCursor;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\MapActivation;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptSectionState;
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

    $this->teacher = AccessFixtures::createEnrolledTeacher('Owner Teacher');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin User');

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

    $this->document = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'sha256' => hash('sha256', 'projection doc'),
        'byte_size' => 2048,
        'page_count' => 2,
        'state' => 'ready',
        'storage_path' => 'accepted/doc-proj.pdf',
        'accepted_at' => now(),
    ]);

    $this->case = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'queued',
        'state_version' => 7,
    ]);

    $this->link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-proj-1',
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'start_state' => 'started',
        'stage_code' => 'mapping',
        'status_code' => 'queued',
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
        'artifact_path' => 'extracted/proj-ev.txt',
        'text_sha256' => hash('sha256', 'extracted text'),
        'text_bytes' => 100,
        'page_count' => 2,
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
        'notebook_id' => 'nb-proj-1',
        'source_id' => 'src-proj-1',
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
        'page_count' => 2,
        'work_count' => 1,
    ]);

    $this->node = LessonNode::forceCreate([
        'id' => (string) Str::uuid(),
        'map_id' => $this->map->id,
        'parent_id' => null,
        'kind' => 'lesson',
        'position_path' => '001',
        'title' => 'Lesson 1',
        'ordinal_in_parent' => 1,
        'work_ordinal' => 1,
        'coverage_pages' => [1, 2],
    ]);

    $this->firstQuery = \App\Models\V3\LessonQuery::forceCreate([
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
        'work_node_id' => $this->node->id,
        'query_id' => $this->firstQuery->id,
        'ordinal_in_work' => 1,
        'section_identity' => 'Sec 1 Identity',
        'core_understanding' => 'Core 1',
        'visual_first_explanation' => 'Visual 1',
        'visual_scene_script' => 'Script 1',
        'dynamic_view_blueprint' => 'Blueprint 1',
        'visual_learning_outcome' => 'Outcome 1',
        'concept_check' => 'Check 1',
        'conceptual_summary' => 'Summary 1',
        'colloquial_egyptian_explanation' => 'Egyptian 1',
        'laws_if_any' => 'None',
        'real_life_example' => 'Example 1',
        'first_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
        'answer_sha256' => hash('sha256', 'first-ans'),
    ]);
});

test('P15: prompt:work:1:started requires claimed 010 work row and transitions to running prompting', function () {
    $payload = [
        'schema_version' => 1,
        'event_key' => 'prompt:work:1:started',
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-proj-1',
        'expected_state_version' => 7,
        'event_type' => 'stage_started',
        'stage_code' => 'prompting',
        'status_code' => 'running',
        'error_code' => null,
    ];

    // Without PromptWorkItem, projector rejects with 422 contract_invalid
    $headers = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/case-events', $payload);
    $resFail = $this->postJson('/api/internal/v1/case-events', $payload, $headers);
    $resFail->assertStatus(422)
        ->assertJson(['error' => ['code' => 'contract_invalid']]);

    // Now create claimed PromptWorkItem
    PromptWorkItem::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'work_node_id' => $this->node->id,
        'work_ordinal' => 1,
        'expected_cursor_version' => 1,
        'section_query_id' => $this->firstQuery->id,
        'state' => 'awaiting_execution',
        'section_refs' => [$this->section1->id],
    ]);

    // Now it projects successfully
    $resSuccess = $this->postJson('/api/internal/v1/case-events', $payload, $headers);
    $resSuccess->assertStatus(201);

    $this->case->refresh();
    expect($this->case->state)->toBe('running');
    expect($this->case->state_version)->toBe(8);

    $this->link->refresh();
    expect($this->link->stage_code)->toBe('prompting');
    expect($this->link->status_code)->toBe('running');
});

test('P15: prompt:work:1:originals requires committed 009 receipt and transitions to queued prompting', function () {
    $workItem = PromptWorkItem::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'work_node_id' => $this->node->id,
        'work_ordinal' => 1,
        'expected_cursor_version' => 1,
        'section_query_id' => $this->firstQuery->id,
        'state' => 'awaiting_execution',
        'section_refs' => [$this->section1->id],
    ]);

    $this->case->forceFill(['state' => 'running', 'state_version' => 8])->save();
    $this->link->forceFill(['stage_code' => 'prompting', 'status_code' => 'running'])->save();

    $payload = [
        'schema_version' => 1,
        'event_key' => 'prompt:work:1:originals',
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-proj-1',
        'expected_state_version' => 8,
        'event_type' => 'stage_completed',
        'stage_code' => 'prompting',
        'status_code' => 'queued',
        'error_code' => null,
    ];

    // Receipt not committed yet -> 422
    $headers = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/case-events', $payload);
    $resFail = $this->postJson('/api/internal/v1/case-events', $payload, $headers);
    $resFail->assertStatus(422)
        ->assertJson(['error' => ['code' => 'contract_invalid']]);

    // The production receipt sender persists this state before the
    // completion notification is projected into the workflow milestone.
    $workItem->forceFill([
        'completion_ref' => 'prompt-work-' . $workItem->id,
        'completion_sha256' => hash('sha256', 'completion'),
        'state' => 'receipt_committed',
    ])->save();

    $resSuccess = $this->postJson('/api/internal/v1/case-events', $payload, $headers);
    $resSuccess->assertStatus(201);

    $this->case->refresh();
    expect($this->case->state)->toBe('queued');
    expect($this->case->state_version)->toBe(9);
});

test('P15: prompt:qualification:started requires awaiting_qualification section', function () {
    $this->case->forceFill(['state' => 'queued', 'state_version' => 9])->save();
    $this->link->forceFill(['stage_code' => 'prompting', 'status_code' => 'queued'])->save();

    $payload = [
        'schema_version' => 1,
        'event_key' => 'prompt:qualification:started',
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-proj-1',
        'expected_state_version' => 9,
        'event_type' => 'stage_started',
        'stage_code' => 'prompt_qualification',
        'status_code' => 'running',
        'error_code' => null,
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/case-events', $payload);
    $resFail = $this->postJson('/api/internal/v1/case-events', $payload, $headers);
    $resFail->assertStatus(422)
        ->assertJson(['error' => ['code' => 'contract_invalid']]);

    // Create prompt section state awaiting_qualification
    PromptSectionState::forceCreate([
        'case_id' => $this->case->id,
        'section_id' => $this->section1->id,
        'status' => 'awaiting_qualification',
        'revision_count' => 0,
    ]);

    $resSuccess = $this->postJson('/api/internal/v1/case-events', $payload, $headers);
    $resSuccess->assertStatus(201);

    $this->case->refresh();
    expect($this->case->state)->toBe('running');
    expect($this->case->state_version)->toBe(10);
});

test('P15: prompt:qualification:completed requires all sections terminal and at least one qualified', function () {
    $this->case->forceFill(['state' => 'running', 'state_version' => 10])->save();
    $this->link->forceFill(['stage_code' => 'prompt_qualification', 'status_code' => 'running'])->save();

    $state = PromptSectionState::forceCreate([
        'case_id' => $this->case->id,
        'section_id' => $this->section1->id,
        'status' => 'awaiting_qualification',
        'revision_count' => 0,
    ]);

    $payload = [
        'schema_version' => 1,
        'event_key' => 'prompt:qualification:completed',
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-proj-1',
        'expected_state_version' => 10,
        'event_type' => 'stage_completed',
        'stage_code' => 'prompt_qualification',
        'status_code' => 'queued',
        'error_code' => null,
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/case-events', $payload);
    // Not terminal yet -> 422
    $resFail = $this->postJson('/api/internal/v1/case-events', $payload, $headers);
    $resFail->assertStatus(422);

    // Make qualified
    $state->forceFill(['status' => 'qualified'])->save();

    $resSuccess = $this->postJson('/api/internal/v1/case-events', $payload, $headers);
    $resSuccess->assertStatus(201);
});

test('P15: prompt:qualification:all_blocked requires cursor complete and all sections blocked', function () {
    $this->case->forceFill(['state' => 'running', 'state_version' => 10])->save();
    $this->link->forceFill(['stage_code' => 'prompt_qualification', 'status_code' => 'running'])->save();

    $state = PromptSectionState::forceCreate([
        'case_id' => $this->case->id,
        'section_id' => $this->section1->id,
        'status' => 'blocked',
        'error_code' => 'policy_denied',
        'revision_count' => 1,
    ]);

    $payload = [
        'schema_version' => 1,
        'event_key' => 'prompt:qualification:all_blocked',
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-proj-1',
        'expected_state_version' => 10,
        'event_type' => 'workflow_failed',
        'stage_code' => 'prompt_qualification',
        'status_code' => 'failed',
        'error_code' => 'policy_denied',
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/case-events', $payload);
    // Cursor not complete -> 422
    $resFail = $this->postJson('/api/internal/v1/case-events', $payload, $headers);
    $resFail->assertStatus(422);

    LessonCursor::forceCreate([
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'version' => 2,
        'current_work_ordinal' => 1,
        'phase' => 'complete',
    ]);

    $resSuccess = $this->postJson('/api/internal/v1/case-events', $payload, $headers);
    $resSuccess->assertStatus(201);

    $this->case->refresh();
    expect($this->case->state)->toBe('failed');
});

test('P15: teacher prompt-status endpoint returns safe scalar fields only, sorted by work and ordinal', function () {
    // Add second section to work 1
    $section2 = TeacherSection::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'work_node_id' => $this->node->id,
        'query_id' => $this->firstQuery->id,
        'ordinal_in_work' => 2,
        'section_identity' => 'Sec 2 Identity',
        'core_understanding' => 'Core 2',
        'visual_first_explanation' => 'Visual 2',
        'visual_scene_script' => 'Script 2',
        'dynamic_view_blueprint' => 'Blueprint 2',
        'visual_learning_outcome' => 'Outcome 2',
        'concept_check' => 'Check 2',
        'conceptual_summary' => 'Summary 2',
        'colloquial_egyptian_explanation' => 'Egyptian 2',
        'laws_if_any' => 'None',
        'real_life_example' => 'Example 2',
        'first_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
        'answer_sha256' => hash('sha256', 'first-ans-2'),
    ]);

    PromptSectionState::forceCreate([
        'case_id' => $this->case->id,
        'section_id' => $this->section1->id,
        'status' => 'qualified',
        'revision_count' => 0,
    ]);

    PromptSectionState::forceCreate([
        'case_id' => $this->case->id,
        'section_id' => $section2->id,
        'status' => 'blocked',
        'error_code' => 'revision_budget_exhausted',
        'revision_count' => 2,
    ]);

    $path = "/v3/teacher/cases/{$this->case->id}/prompt-status";
    $resp = $this->actingAs($this->teacher)->getJson($path);

    $resp->assertStatus(200);
    $data = $resp->json('data');

    expect($data['case_id'])->toBe($this->case->id);
    expect($data['sections'])->toHaveCount(2);

    expect($data['sections'][0]['section_ref'])->toBe($this->section1->id);
    expect($data['sections'][0]['work_ordinal'])->toBe(1);
    expect($data['sections'][0]['ordinal_in_work'])->toBe(1);
    expect($data['sections'][0]['status'])->toBe('qualified');
    expect($data['sections'][0]['revision_count'])->toBe(0);

    expect($data['sections'][1]['section_ref'])->toBe($section2->id);
    expect($data['sections'][1]['work_ordinal'])->toBe(1);
    expect($data['sections'][1]['ordinal_in_work'])->toBe(2);
    expect($data['sections'][1]['status'])->toBe('blocked');
    expect($data['sections'][1]['error_code'])->toBe('revision_budget_exhausted');
    expect($data['sections'][1]['revision_count'])->toBe(2);

    // Verify no prompt text, JEV answers, scores, raw feedback or provider info exist in response
    $rawJson = $resp->getContent();
    expect($rawJson)->not->toContain('Process:');
    expect($rawJson)->not->toContain('choice');
    expect($rawJson)->not->toContain('score');
    expect($rawJson)->not->toContain('noul');
    expect($rawJson)->not->toContain('gemini');
    expect($rawJson)->not->toContain('glm');
    expect($rawJson)->not->toContain('deepseek');
});
