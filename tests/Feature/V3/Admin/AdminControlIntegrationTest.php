<?php

namespace Tests\Feature\V3\Admin;

use App\Models\V3\AdminCommand;
use App\Models\V3\AdminCommandDelivery;
use App\Models\V3\ApprovedScene;
use App\Models\V3\CaseEvent;
use App\Models\V3\CaseOperationControl;
use App\Models\V3\Document;
use App\Models\V3\PostapprovalCorrection;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ReviewOutbox;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\SettingsVersion;
use App\Models\V3\TeacherReviewDecision;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\TeacherSection;
use App\Models\V3\WorkflowLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\Support\V3\WorkflowFixtures;
use Tests\Support\V3\AuthoringFixtures;
use Tests\Support\V3\ReviewFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');

    $this->admin = AccessFixtures::createEnrolledAdmin('Active Admin');
    $this->teacher = AccessFixtures::createEnrolledTeacher('Active Teacher');

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
        'sha256' => hash('sha256', 'integration doc'),
        'storage_path' => 'private/v3/documents/integration.pdf',
        'byte_size' => 1024,
        'page_count' => 2,
        'state' => 'ready',
        'accepted_at' => now(),
    ]);
});

function createCorrectionForControlTest(array $env, string $outboxState): array
{
    $qualified = ReviewFixtures::createQualifiedCandidate($env, 1);
    $env['case']->state = 'running';
    $env['case']->state_version = 1;
    $env['case']->save();
    $reviewId = (string) Str::uuid();
    TeacherReviewItem::forceCreate([
        'id' => $reviewId, 'case_id' => $env['case']->id, 'section_id' => $qualified['section']->id,
        'candidate_id' => $qualified['candidate']->id, 'qualification_id' => $qualified['qualification']->id,
        'prompt_version_id' => $qualified['candidate']->prompt_version_id, 'candidate_version' => 1,
        'status' => 'decided', 'ready_at' => now(),
    ]);
    $decisionId = (string) Str::uuid();
    TeacherReviewDecision::forceCreate([
        'id' => $decisionId, 'review_item_id' => $reviewId, 'case_id' => $env['case']->id,
        'section_id' => $qualified['section']->id, 'candidate_id' => $qualified['candidate']->id,
        'qualification_id' => $qualified['qualification']->id, 'actor_user_id' => $env['teacher']->id,
        'candidate_version' => 1, 'expected_review_version' => 1, 'idempotency_key' => (string) Str::uuid(),
        'request_sha256' => str_repeat('a', 64), 'decision' => 'approve', 'created_at' => now(),
    ]);
    $approvalId = (string) Str::uuid();
    ApprovedScene::forceCreate([
        'id' => $approvalId, 'case_id' => $env['case']->id, 'section_id' => $qualified['section']->id,
        'candidate_id' => $qualified['candidate']->id, 'qualification_id' => $qualified['qualification']->id,
        'review_decision_id' => $decisionId, 'candidate_version' => 1, 'approved_at' => now(),
    ]);
    $correctionId = (string) Str::uuid();
    PostapprovalCorrection::forceCreate([
        'id' => $correctionId, 'case_id' => $env['case']->id, 'section_id' => $qualified['section']->id,
        'approval_id' => $approvalId, 'actor_user_id' => $env['teacher']->id,
        'idempotency_key' => (string) Str::uuid(), 'request_sha256' => str_repeat('b', 64),
        'feedback_kind' => 'visual_interaction', 'note_path' => "review-notes/{$correctionId}.txt",
        'note_sha256' => hash('sha256', 'private note'), 'note_bytes' => 12, 'state' => 'accepted',
        'created_at' => now(),
    ]);
    $workflowId = "v3-review-correction-{$correctionId}";
    $outbox = ReviewOutbox::forceCreate([
        'id' => (string) Str::uuid(), 'review_decision_id' => null,
        'postapproval_correction_id' => $correctionId, 'operation' => 'start_correction',
        'workflow_id' => $workflowId, 'state' => $outboxState,
        'attempt_count' => $outboxState === 'pending' ? 0 : 1,
    ]);

    return ['workflow_id' => $workflowId, 'outbox' => $outbox];
}

test('O12: cancellation with case workflow and open correction workflow requires both signed callbacks before terminal state', function () {
    $env = AuthoringFixtures::createAuthoringEnvironment();
    $case = $env['case'];
    $case->state = 'running';
    $case->state_version = 1;
    $case->save();
    $caseWorkflowId = "v3-case-{$case->id}";
    WorkflowLink::forceCreate([
        'case_id' => $case->id,
        'workflow_id' => $caseWorkflowId,
        'run_id' => 'run-1',
        'settings_version_id' => $env['settingsVersion']->id,
        'stage_code' => 'teacher_review',
        'status_code' => 'running',
        'error_code' => null,
        'attempt_count' => 1,
        'scene_count' => 1,
        'approved_scene_count' => 1,
        'start_state' => 'started',
        'started_at' => now(),
        'last_event_at' => now(),
    ]);

    $correction = createCorrectionForControlTest($env, 'sending');
    $correctionWorkflowId = $correction['workflow_id'];
    $case->state = 'running';
    $case->state_version = 1;
    $case->save();

    // Post cancel command
    $cancelPayload = [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_case_version' => 1,
        'expected_control_version' => 0,
        'action' => 'cancel',
        'reason_code' => 'operator_investigation',
        'target_kind' => null,
        'target_ref' => null,
    ];

    $response = $this->actingAs($env['admin'])->postJson("/v3/admin/operations/{$case->id}/commands", $cancelPayload);
    $response->assertStatus(201);
    $commandRef = $response->json('data.command_ref');
    expect($response->json('data.control_mode'))->toBe('cancel_requested');

    // Case is still running while deliveries are pending
    $case->refresh();
    expect($case->state)->toBe('running');

    // Deliveries must exist
    $deliveries = AdminCommandDelivery::where('command_id', $commandRef)->get();
    expect($deliveries->count())->toBe(2)
        ->and($deliveries->pluck('workflow_id')->all())->toContain($caseWorkflowId, $correctionWorkflowId);

    // Callback from case workflow
    $caseCallbackBody = [
        'schema_version' => 1,
        'case_id' => $case->id,
        'workflow_id' => $caseWorkflowId,
        'run_id' => 'run-1',
        'command_ref' => $commandRef,
        'control_version' => 1,
        'outcome' => 'cancelled',
    ];
    $headers = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/admin-control-results', $caseCallbackBody);

    $cbResp1 = $this->postJson('/api/internal/v1/admin-control-results', $caseCallbackBody, $headers);
    $cbResp1->assertStatus(200);

    $corrCallbackBody = [
        'schema_version' => 1,
        'case_id' => $case->id,
        'workflow_id' => $correctionWorkflowId,
        'run_id' => 'run-2',
        'command_ref' => $commandRef,
        'control_version' => 1,
        'outcome' => 'cancelled',
    ];
    $headers2 = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/admin-control-results', $corrCallbackBody);
    $cbResp2 = $this->postJson('/api/internal/v1/admin-control-results', $corrCallbackBody, $headers2);
    $cbResp2->assertStatus(200);

    // Now all deliveries acknowledged: Case must be cancelled!
    $case->refresh();
    expect($case->state)->toBe('cancelled')
        ->and($case->state_version)->toBe(2);

    $control = CaseOperationControl::find($case->id);
    expect($control->mode)->toBe('cancelled');

    // Verify proof-backed cancelled event
    $cancelledEvent = CaseEvent::where('case_id', $case->id)
        ->where('status_code', 'cancelled')
        ->first();
    expect($cancelledEvent)->not->toBeNull();
});

test('O12: cancellation fences a committed but unsent correction without requiring a remote correction acknowledgement', function () {
    $env = AuthoringFixtures::createAuthoringEnvironment();
    $case = $env['case'];
    $case->state = 'running';
    $case->state_version = 1;
    $case->save();
    $caseWorkflowId = "v3-case-{$case->id}";
    WorkflowLink::forceCreate([
        'case_id' => $case->id,
        'workflow_id' => $caseWorkflowId,
        'run_id' => 'run-unsent-correction',
        'settings_version_id' => $env['settingsVersion']->id,
        'stage_code' => 'teacher_review',
        'status_code' => 'running',
        'start_state' => 'started',
        'started_at' => now(),
        'last_event_at' => now(),
    ]);

    $correction = createCorrectionForControlTest($env, 'pending');
    $correctionWorkflowId = $correction['workflow_id'];
    $outbox = $correction['outbox'];

    $cancel = [
        'schema_version' => 1, 'idempotency_key' => (string) Str::uuid(),
        'expected_case_version' => 1, 'expected_control_version' => 0,
        'action' => 'cancel', 'reason_code' => 'operator_investigation',
        'target_kind' => null, 'target_ref' => null,
    ];
    $response = $this->actingAs($env['admin'])->postJson("/v3/admin/operations/{$case->id}/commands", $cancel);
    $response->assertStatus(201);

    $outbox->refresh();
    expect($response->json('data.control_mode'))->toBe('cancel_requested')
        ->and($outbox->state)->toBe('blocked')
        ->and($outbox->error_code)->toBe('operation_controlled')
        ->and($outbox->attempt_count)->toBe(0)
        ->and(AdminCommandDelivery::where('command_id', $response->json('data.command_ref'))
            ->where('workflow_id', $caseWorkflowId)->exists())->toBeTrue()
        ->and(AdminCommandDelivery::where('command_id', $response->json('data.command_ref'))
            ->where('workflow_id', $correctionWorkflowId)->exists())->toBeFalse();
});

test('O15: callback with invalid signature returns 401; duplicate callback is idempotent', function () {
    $caseId = (string) Str::uuid();
    $case = ProcessingCase::forceCreate([
        'id' => $caseId,
        'owner_user_id' => $this->teacher->id,
        'settings_version_id' => $this->settingsVersion->id,
        'document_id' => $this->document->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'running',
        'state_version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $workflowId = "v3-case-{$caseId}";
    WorkflowLink::forceCreate([
        'case_id' => $caseId,
        'workflow_id' => $workflowId,
        'settings_version_id' => $this->settingsVersion->id,
        'stage_code' => 'scene_generation',
        'status_code' => 'running',
        'error_code' => null,
        'attempt_count' => 1,
        'scene_count' => 1,
        'approved_scene_count' => 0,
        'start_state' => 'started',
        'started_at' => now(),
        'last_event_at' => now(),
    ]);

    $payload = [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_case_version' => 1,
        'expected_control_version' => 0,
        'action' => 'hold',
        'reason_code' => 'operator_investigation',
        'target_kind' => null,
        'target_ref' => null,
    ];

    $response = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$case->id}/commands", $payload);
    $response->assertStatus(201);
    $commandRef = $response->json('data.command_ref');

    $callbackBody = [
        'schema_version' => 1,
        'case_id' => $case->id,
        'workflow_id' => $workflowId,
        'run_id' => 'run-1',
        'command_ref' => $commandRef,
        'control_version' => 1,
        'outcome' => 'held',
    ];

    // Invalid signature -> 401
    $badHeaders = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/admin-control-results', $callbackBody, 'wrong-secret');
    $this->postJson('/api/internal/v1/admin-control-results', $callbackBody, $badHeaders)->assertStatus(401);

    // Valid signature -> 200
    $goodHeaders = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/admin-control-results', $callbackBody);
    $res1 = $this->postJson('/api/internal/v1/admin-control-results', $callbackBody, $goodHeaders);
    $res1->assertStatus(200);

    $control = CaseOperationControl::find($case->id);
    expect($control->mode)->toBe('held');

    // Duplicate exact callback -> 200 idempotent
    $res2 = $this->postJson('/api/internal/v1/admin-control-results', $callbackBody, $goodHeaders);
    $res2->assertStatus(200);
});
