<?php

namespace Tests\Feature\V3\Admin;

use App\Models\V3\AdminCommand;
use App\Models\V3\AdminOperationAudit;
use App\Models\V3\ApprovedScene;
use App\Models\V3\CaseEvent;
use App\Models\V3\CaseOperationControl;
use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\SettingsVersion;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\WorkflowLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

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
        'sha256' => hash('sha256', 'command doc'),
        'storage_path' => 'private/v3/documents/command.pdf',
        'byte_size' => 1024,
        'page_count' => 2,
        'state' => 'ready',
        'accepted_at' => now(),
    ]);
});

function createRunningCaseWithWorkflow($teacher, $settingsVersion, $document, array $attrs = []): array
{
    $caseId = (string) Str::uuid();
    $case = ProcessingCase::forceCreate([
        'id' => $caseId,
        'owner_user_id' => $teacher->id,
        'settings_version_id' => $settingsVersion->id,
        'document_id' => $document->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => $attrs['state'] ?? 'running',
        'state_version' => $attrs['state_version'] ?? 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $link = null;
    if (! ($attrs['no_workflow'] ?? false)) {
        $link = WorkflowLink::forceCreate([
            'case_id' => $caseId,
            'workflow_id' => "v3-case-{$caseId}",
            'settings_version_id' => $settingsVersion->id,
            'stage_code' => $attrs['stage_code'] ?? 'scene_generation',
            'status_code' => $attrs['state'] ?? 'running',
            'error_code' => null,
            'attempt_count' => 1,
            'scene_count' => 1,
            'approved_scene_count' => $attrs['approved_scene_count'] ?? 0,
            'start_state' => 'started',
            'started_at' => now(),
            'last_event_at' => now(),
        ]);
    }

    $control = null;
    if (! empty($attrs['control'])) {
        $control = CaseOperationControl::create(array_merge([
            'case_id' => $caseId,
            'mode' => 'normal',
            'control_version' => 0,
        ], $attrs['control']));
    }

    return ['case' => $case, 'link' => $link, 'control' => $control];
}

test('O07: hold from running state commits fence and safe audit before bridge delivery', function () {
    $setup = createRunningCaseWithWorkflow($this->teacher, $this->settingsVersion, $this->document);
    $case = $setup['case'];

    $idempotencyKey = (string) Str::uuid();
    $payload = [
        'schema_version' => 1,
        'idempotency_key' => $idempotencyKey,
        'expected_case_version' => 1,
        'expected_control_version' => 0,
        'action' => 'hold',
        'reason_code' => 'operator_investigation',
        'target_kind' => null,
        'target_ref' => null,
    ];

    $response = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$case->id}/commands", $payload);
    $response->assertStatus(201);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $data = $response->json('data');
    expect($data['action'])->toBe('hold')
        ->and($data['control_mode'])->toBe('hold_requested')
        ->and($data['control_version'])->toBe(1)
        ->and($data['status'])->toBe('accepted');

    // Verify CaseOperationControl updated
    $control = CaseOperationControl::find($case->id);
    expect($control)->not->toBeNull()
        ->and($control->mode)->toBe('hold_requested')
        ->and($control->control_version)->toBe(1);

    // Verify AdminCommand committed
    $command = AdminCommand::where('id', $data['command_ref'])->first();
    expect($command)->not->toBeNull()
        ->and($command->action)->toBe('hold')
        ->and($command->reason_code)->toBe('operator_investigation');

    // Verify AdminOperationAudit committed
    $audit = AdminOperationAudit::where('source_ref', $command->id)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->event)->toBe('command_requested')
        ->and($audit->action_code)->toBe('hold')
        ->and($audit->old_control_mode)->toBe('normal')
        ->and($audit->new_control_mode)->toBe('hold_requested');
});

test('O10: resume only from held state; out-of-order resume on normal returns 409', function () {
    $setup = createRunningCaseWithWorkflow($this->teacher, $this->settingsVersion, $this->document);
    $case = $setup['case'];

    // Try resume when mode is normal -> 409
    $payload = [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_case_version' => 1,
        'expected_control_version' => 0,
        'action' => 'resume',
        'reason_code' => 'service_recovered',
        'target_kind' => null,
        'target_ref' => null,
    ];

    $response = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$case->id}/commands", $payload);
    $response->assertStatus(409)
        ->assertJson([
            'error' => [
                'code' => 'operation_conflict',
                'message' => 'Operation changed. Reload and retry.',
            ],
        ]);

    // Now set control to held with control_version = 1
    CaseOperationControl::create([
        'case_id' => $case->id,
        'mode' => 'held',
        'control_version' => 1,
    ]);

    // Resume from held -> 201 resume_requested
    $resumePayload = [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_case_version' => 1,
        'expected_control_version' => 1,
        'action' => 'resume',
        'reason_code' => 'service_recovered',
        'target_kind' => null,
        'target_ref' => null,
    ];

    $resResponse = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$case->id}/commands", $resumePayload);
    $resResponse->assertStatus(201);
    expect($resResponse->json('data.control_mode'))->toBe('resume_requested')
        ->and($resResponse->json('data.control_version'))->toBe(2);
});

test('O11: cancel before workflow start performs direct local cancel; terminal states cannot be cancelled', function () {
    // 1. Case awaiting_workflow with no workflow link
    $setup = createRunningCaseWithWorkflow($this->teacher, $this->settingsVersion, $this->document, [
        'state' => 'awaiting_workflow',
        'no_workflow' => true,
    ]);
    $case = $setup['case'];

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

    $response = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$case->id}/commands", $cancelPayload);
    $response->assertStatus(201);
    expect($response->json('data.control_mode'))->toBe('cancelled')
        ->and($response->json('data.status'))->toBe('applied');

    $case->refresh();
    expect($case->state)->toBe('cancelled')
        ->and($case->state_version)->toBe(2);

    // Verify proof-backed cancelled case event
    $cancelledEvent = CaseEvent::where('case_id', $case->id)->where('status_code', 'cancelled')->first();
    expect($cancelledEvent)->not->toBeNull();

    // 2. Terminal complete / approved case cannot be cancelled -> 409
    $approvedSetup = createRunningCaseWithWorkflow($this->teacher, $this->settingsVersion, $this->document, [
        'state' => 'complete',
    ]);
    $approvedCase = $approvedSetup['case'];

    $approvedPayload = [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_case_version' => 1,
        'expected_control_version' => 0,
        'action' => 'cancel',
        'reason_code' => 'operator_investigation',
        'target_kind' => null,
        'target_ref' => null,
    ];

    $apprResp = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$approvedCase->id}/commands", $approvedPayload);
    $apprResp->assertStatus(409)
        ->assertJson([
            'error' => [
                'code' => 'operation_conflict',
            ],
        ]);
});

test('O13: exact same-key replay returns 200 with original command; changed body or stale version returns 409', function () {
    $setup = createRunningCaseWithWorkflow($this->teacher, $this->settingsVersion, $this->document);
    $case = $setup['case'];

    $idempotencyKey = (string) Str::uuid();
    $payload = [
        'schema_version' => 1,
        'idempotency_key' => $idempotencyKey,
        'expected_case_version' => 1,
        'expected_control_version' => 0,
        'action' => 'hold',
        'reason_code' => 'operator_investigation',
        'target_kind' => null,
        'target_ref' => null,
    ];

    // Initial POST -> 201
    $firstResp = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$case->id}/commands", $payload);
    $firstResp->assertStatus(201);
    $originalRef = $firstResp->json('data.command_ref');

    // Exact replay with same key and body -> 200
    $replayResp = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$case->id}/commands", $payload);
    $replayResp->assertStatus(200);
    expect($replayResp->json('data.command_ref'))->toBe($originalRef)
        ->and($replayResp->json('data.action'))->toBe('hold');

    // Same key with changed body -> 409
    $changedPayload = $payload;
    $changedPayload['expected_control_version'] = 999;
    $changedResp = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$case->id}/commands", $changedPayload);
    $changedResp->assertStatus(409)
        ->assertJson(['error' => ['code' => 'operation_conflict']]);

    // New key with stale expected_control_version (control_version is now 1) -> 409
    $stalePayload = [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_case_version' => 1,
        'expected_control_version' => 0, // stale!
        'action' => 'cancel',
        'reason_code' => 'operator_investigation',
        'target_kind' => null,
        'target_ref' => null,
    ];
    $staleResp = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$case->id}/commands", $stalePayload);
    $staleResp->assertStatus(409)
        ->assertJson(['error' => ['code' => 'operation_conflict']]);
});

test('O14: forbidden transition records safe denial audit; audit failure preserves 409', function () {
    $setup = createRunningCaseWithWorkflow($this->teacher, $this->settingsVersion, $this->document);
    $case = $setup['case'];

    // Stale version command
    $badPayload = [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_case_version' => 999, // Stale!
        'expected_control_version' => 0,
        'action' => 'hold',
        'reason_code' => 'operator_investigation',
        'target_kind' => null,
        'target_ref' => null,
    ];

    $response = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$case->id}/commands", $badPayload);
    $response->assertStatus(409);

    // Verify safe denial audit recorded
    $denialAudit = AdminOperationAudit::where('case_id', $case->id)
        ->where('event', 'operation_denied')
        ->first();
    expect($denialAudit)->not->toBeNull()
        ->and($denialAudit->safe_denial_code)->toBe('operation_conflict')
        ->and($denialAudit->action_code)->toBe('hold');
});
