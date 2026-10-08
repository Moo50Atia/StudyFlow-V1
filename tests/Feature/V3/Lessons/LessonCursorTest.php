<?php

namespace Tests\Feature\V3\Lessons;

use App\Jobs\V3\NotifyLessonCompletionJob;
use App\Models\V3\Document;
use App\Models\V3\ExtractedSource;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\LessonCompletion;
use App\Models\V3\LessonCursor;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\LessonQuery;
use App\Models\V3\MapActivation;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SourceActivation;
use App\Models\V3\TeacherSection;
use App\Models\V3\WorkflowLink;
use App\Services\V3\Workflow\BridgeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\Support\V3\WorkflowFixtures;

uses(RefreshDatabase::class);

function computeCompletionDigest(
    string $caseId,
    string $mapRef,
    int $workOrdinal,
    int $expectedCursorVersion,
    array $sectionRefs,
    array $thirdOutputRefs,
    string $finalConvRef,
    string $completionRef
): string {
    $str = "009-completion-v1\n"
        . $caseId . "\n"
        . $mapRef . "\n"
        . $workOrdinal . "\n"
        . $expectedCursorVersion . "\n"
        . implode(',', $sectionRefs) . "\n"
        . implode(',', $thirdOutputRefs) . "\n"
        . strlen($finalConvRef) . ":" . $finalConvRef . "\n"
        . $completionRef;
    return strtolower(hash('sha256', $str));
}

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Cursor Test');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Cursor Test');

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
        'sha256' => hash('sha256', 'doc'),
        'byte_size' => 2048,
        'page_count' => 3,
        'state' => 'ready',
        'storage_path' => 'accepted/test.pdf',
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
        'run_id' => 'run-cursor-test',
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'start_state' => 'started',
        'stage_code' => 'mapping',
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
        'artifact_path' => 'extracted/cur.txt',
        'text_sha256' => hash('sha256', 'cur-text'),
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
        'notebook_id' => 'nb-cur-1',
        'source_id' => 'src-cur-1',
        'state' => 'bound',
        'text_sha256' => hash('sha256', 'cur-text'),
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
        'work_count' => 2, // 2 work items total
    ]);

    $this->chapter = LessonNode::forceCreate([
        'id' => (string) Str::uuid(),
        'map_id' => $this->map->id,
        'kind' => 'chapter',
        'position_path' => '001',
        'ordinal_in_parent' => 1,
        'title' => 'Kinematics',
    ]);

    $this->workNode1 = LessonNode::forceCreate([
        'id' => (string) Str::uuid(),
        'map_id' => $this->map->id,
        'parent_id' => $this->chapter->id,
        'kind' => 'lesson',
        'position_path' => '001.001',
        'ordinal_in_parent' => 1,
        'title' => 'Motion',
        'work_ordinal' => 1,
        'coverage_pages' => [1, 2],
    ]);

    $this->workNode2 = LessonNode::forceCreate([
        'id' => (string) Str::uuid(),
        'map_id' => $this->map->id,
        'parent_id' => $this->chapter->id,
        'kind' => 'lesson',
        'position_path' => '001.002',
        'ordinal_in_parent' => 2,
        'title' => 'Velocity',
        'work_ordinal' => 2,
        'coverage_pages' => [2, 3],
    ]);

    $this->query1 = LessonQuery::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'kind' => 'first',
        'target_work_ordinal' => 1,
        'state' => 'recorded',
        'send_count' => 1,
        'request_sha256' => hash('sha256', 'first-req-1'),
        'canonical_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
        'conversation_in' => 'conv-map-1',
        'conversation_out' => 'conv-first-1',
        'sent_at' => now(),
        'recorded_at' => now(),
    ]);

    $this->section1 = TeacherSection::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'work_node_id' => $this->workNode1->id,
        'query_id' => $this->query1->id,
        'ordinal_in_work' => 1,
        'section_identity' => 'Sec 1',
        'core_understanding' => 'Core 1',
        'visual_first_explanation' => 'Exp 1',
        'visual_scene_script' => 'Script 1',
        'dynamic_view_blueprint' => 'Blueprint 1',
        'visual_learning_outcome' => 'Outcome 1',
        'concept_check' => 'Check 1',
        'conceptual_summary' => 'Summary 1',
        'colloquial_egyptian_explanation' => 'عامي 1',
        'laws_if_any' => 'law 1',
        'real_life_example' => 'example 1',
        'first_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
        'answer_sha256' => hash('sha256', 'ans-1'),
    ]);

    $this->cursor = LessonCursor::forceCreate([
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'current_work_ordinal' => 1,
        'phase' => 'awaiting_010',
        'conversation_id' => 'conv-first-1',
        'version' => 2,
    ]);
});

test('L09: 010 completion receipt moves cursor to ready_to_continue for non-final work item', function () {
    Queue::fake([NotifyLessonCompletionJob::class]);

    $path = "/api/internal/v1/cases/{$this->case->id}/lesson-completions";
    $sectionRefs = [$this->section1->id];
    $thirdRefs = ['third-output-sec-1'];
    $finalConv = 'conv-final-w1';
    $completionRef = 'comp-010-w1';

    $digest = computeCompletionDigest(
        $this->case->id,
        $this->map->id,
        1,
        2,
        $sectionRefs,
        $thirdRefs,
        $finalConv,
        $completionRef
    );

    $payload = [
        'schema_version' => 1,
        'map_ref' => $this->map->id,
        'work_ordinal' => 1,
        'expected_cursor_version' => 2,
        'section_refs' => $sectionRefs,
        'third_output_refs' => $thirdRefs,
        'final_conversation_ref' => $finalConv,
        'completion_ref' => $completionRef,
        'completion_sha256' => $digest,
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $payload);
    $resp = $this->postJson($path, $payload, $headers, JSON_UNESCAPED_SLASHES);

    $resp->assertStatus(200);
    expect($resp->json('data.phase'))->toBe('ready_to_continue')
        ->and($resp->json('data.cursor_version'))->toBe(3)
        ->and($resp->json('data.next_work_ordinal'))->toBe(2);

    $this->cursor->refresh();
    expect($this->cursor->phase)->toBe('ready_to_continue')
        ->and($this->cursor->version)->toBe(3);

    // Verify completion record
    $comp = LessonCompletion::where('case_id', $this->case->id)->where('work_ordinal', 1)->first();
    expect($comp)->not->toBeNull()
        ->and($comp->completion_ref)->toBe($completionRef)
        ->and($comp->signal_state)->toBe('committed')
        ->and($comp->cursor_version)->toBe(3);

    $bridge = \Mockery::mock(BridgeClient::class);
    $bridge->shouldReceive('notifyLessonReady')->once()
        ->with("v3-case-{$this->case->id}", [
            'schema_version' => 1,
            'case_id' => $this->case->id,
            'map_ref' => $this->map->id,
            'work_ordinal' => 1,
            'cursor_version' => 3,
            'completion_ref' => $completionRef,
        ])
        ->andReturn(['status' => 202, 'data' => ['accepted' => true]]);
    (new NotifyLessonCompletionJob($this->case->id, $comp->id))->handle($bridge);
    $comp->refresh();
    expect($comp->signal_state)->toBe('accepted');

    Queue::assertPushed(NotifyLessonCompletionJob::class);
});

test('L09: 010 completion receipt moves cursor to complete on final work item', function () {
    Queue::fake([NotifyLessonCompletionJob::class]);

    // Setup cursor at work_ordinal 2 (the final work item out of 2)
    $this->query2 = LessonQuery::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'kind' => 'continue',
        'target_work_ordinal' => 2,
        'state' => 'recorded',
        'send_count' => 1,
        'request_sha256' => hash('sha256', 'continue-req-2'),
        'canonical_prompt_sha256' => null,
        'conversation_in' => 'conv-final-w1',
        'conversation_out' => 'conv-continue-2',
        'sent_at' => now(),
        'recorded_at' => now(),
    ]);

    $sec2 = TeacherSection::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'work_node_id' => $this->workNode2->id,
        'query_id' => $this->query2->id,
        'ordinal_in_work' => 1,
        'section_identity' => 'Sec 2',
        'core_understanding' => 'Core 2',
        'visual_first_explanation' => 'Exp 2',
        'visual_scene_script' => 'Script 2',
        'dynamic_view_blueprint' => 'Blueprint 2',
        'visual_learning_outcome' => 'Outcome 2',
        'concept_check' => 'Check 2',
        'conceptual_summary' => 'Summary 2',
        'colloquial_egyptian_explanation' => 'عامي 2',
        'laws_if_any' => 'law 2',
        'real_life_example' => 'example 2',
        'first_prompt_sha256' => hash('sha256', ''),
        'answer_sha256' => hash('sha256', 'ans-2'),
    ]);

    $this->cursor->update([
        'current_work_ordinal' => 2,
        'phase' => 'awaiting_010',
        'version' => 4,
    ]);

    $path = "/api/internal/v1/cases/{$this->case->id}/lesson-completions";
    $sectionRefs = [$sec2->id];
    $thirdRefs = ['third-output-sec-2'];
    $finalConv = 'conv-final-w2';
    $completionRef = 'comp-010-w2';

    $digest = computeCompletionDigest(
        $this->case->id,
        $this->map->id,
        2,
        4,
        $sectionRefs,
        $thirdRefs,
        $finalConv,
        $completionRef
    );

    $payload = [
        'schema_version' => 1,
        'map_ref' => $this->map->id,
        'work_ordinal' => 2,
        'expected_cursor_version' => 4,
        'section_refs' => $sectionRefs,
        'third_output_refs' => $thirdRefs,
        'final_conversation_ref' => $finalConv,
        'completion_ref' => $completionRef,
        'completion_sha256' => $digest,
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $payload);
    $resp = $this->postJson($path, $payload, $headers, JSON_UNESCAPED_SLASHES);

    $resp->assertStatus(200);
    expect($resp->json('data.phase'))->toBe('complete')
        ->and($resp->json('data.cursor_version'))->toBe(5)
        ->and($resp->json('data.next_work_ordinal'))->toBeNull();

    $this->cursor->refresh();
    expect($this->cursor->phase)->toBe('complete')
        ->and($this->cursor->version)->toBe(5);
});

test('L09: 010 completion receipt rejects bad digest with 422', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/lesson-completions";
    $sectionRefs = [$this->section1->id];
    $thirdRefs = ['third-output-sec-1'];
    $payload = [
        'schema_version' => 1,
        'map_ref' => $this->map->id,
        'work_ordinal' => 1,
        'expected_cursor_version' => 2,
        'section_refs' => $sectionRefs,
        'third_output_refs' => $thirdRefs,
        'final_conversation_ref' => 'conv-final-w1',
        'completion_ref' => 'comp-1',
        'completion_sha256' => str_repeat('0', 64), // Mismatched digest
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $payload);
    $resp = $this->postJson($path, $payload, $headers, JSON_UNESCAPED_SLASHES);

    $resp->assertStatus(422)
        ->assertJson(['error' => ['code' => 'lesson_invalid']]);
});

test('L09: 010 completion receipt rejects stale cursor version with 409', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/lesson-completions";
    $sectionRefs = [$this->section1->id];
    $thirdRefs = ['third-output-sec-1'];
    $finalConv = 'conv-final-w1';
    $completionRef = 'comp-010-w1';

    $digest = computeCompletionDigest(
        $this->case->id,
        $this->map->id,
        1,
        99, // Stale version
        $sectionRefs,
        $thirdRefs,
        $finalConv,
        $completionRef
    );

    $payload = [
        'schema_version' => 1,
        'map_ref' => $this->map->id,
        'work_ordinal' => 1,
        'expected_cursor_version' => 99,
        'section_refs' => $sectionRefs,
        'third_output_refs' => $thirdRefs,
        'final_conversation_ref' => $finalConv,
        'completion_ref' => $completionRef,
        'completion_sha256' => $digest,
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $payload);
    $resp = $this->postJson($path, $payload, $headers, JSON_UNESCAPED_SLASHES);

    $resp->assertStatus(409)
        ->assertJson(['error' => ['code' => 'lesson_conflict']]);
});

test('L11: Continue claim allowed only from ready_to_continue and rejected from complete', function () {
    // 1. When cursor is awaiting_010, continue claim is rejected with 409
    $claimPath = "/api/internal/v1/lesson-queries/{$this->case->id}/continue/2/claim";
    $payload = [
        'schema_version' => 1,
        'activation_id' => $this->mapActivation->id,
        'binding_ref' => $this->binding->id,
        'query_text' => 'Continue',
        'conversation_in' => 'conv-first-1',
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', $claimPath, $payload);
    $resp = $this->postJson($claimPath, $payload, $headers, JSON_UNESCAPED_SLASHES);
    $resp->assertStatus(409)
        ->assertJson(['error' => ['code' => 'lesson_conflict']]);

    // 2. Set cursor to ready_to_continue
    $this->cursor->update([
        'phase' => 'ready_to_continue',
        'version' => 3,
    ]);

    $headers2 = WorkflowFixtures::signCallbackRequest('POST', $claimPath, $payload);
    $resp2 = $this->postJson($claimPath, $payload, $headers2, JSON_UNESCAPED_SLASHES);
    $resp2->assertStatus(200);
    expect($resp2->json('data.disposition'))->toBe('send_now');

    // 3. Set cursor to complete: continue claim is rejected with 409
    $this->cursor->update([
        'phase' => 'complete',
        'version' => 5,
    ]);

    $claimPath3 = "/api/internal/v1/lesson-queries/{$this->case->id}/continue/3/claim";
    $payload3 = $payload;
    $headers3 = WorkflowFixtures::signCallbackRequest('POST', $claimPath3, $payload3);
    $resp3 = $this->postJson($claimPath3, $payload3, $headers3, JSON_UNESCAPED_SLASHES);
    $resp3->assertStatus(409)
        ->assertJson(['error' => ['code' => 'lesson_conflict']]);
});
