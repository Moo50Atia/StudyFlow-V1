<?php

namespace Tests\Feature\V3\Admin;

use App\Models\V3\AdminCommand;
use App\Models\V3\AdminOperationAudit;
use App\Models\V3\CaseOperationControl;
use App\Models\V3\Document;
use App\Models\V3\ExtractedSource;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\PostapprovalCorrection;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ReviewOutbox;
use App\Models\V3\ReviewRoute;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckAttempt;
use App\Models\V3\SceneCheckRun;
use App\Models\V3\SceneJevAttempt;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SceneQualification;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SourceActivation;
use App\Models\V3\SourceEffect;
use App\Models\V3\TeacherReviewDecision;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\TeacherSection;
use App\Models\V3\WorkflowLink;
use App\Services\V3\Admin\DispatchAdminCommands;
use App\Services\V3\Admin\ReconcileAdminTarget;
use App\Services\V3\Workflow\BridgeClient;
use App\Services\V3\Workflow\ReviewSignalGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\AuthoringFixtures;
use Tests\Support\V3\ReviewFixtures;
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
        'sha256' => hash('sha256', 'retry doc'),
        'storage_path' => 'private/v3/documents/retry.pdf',
        'byte_size' => 1024,
        'page_count' => 2,
        'state' => 'ready',
        'accepted_at' => now(),
    ]);
});

function createRetryCase($teacher, $settingsVersion, $document, array $attrs = []): ProcessingCase
{
    $caseId = (string) Str::uuid();
    return ProcessingCase::forceCreate(array_merge([
        'id' => $caseId,
        'owner_user_id' => $teacher->id,
        'settings_version_id' => $settingsVersion->id,
        'document_id' => $document->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'running',
        'state_version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
}

test('O16: workflow_start retry reconciles via BridgeClient describe without new workflow ID', function () {
    $case = createRetryCase($this->teacher, $this->settingsVersion, $this->document, ['state' => 'queued']);
    $link = WorkflowLink::forceCreate([
        'case_id' => $case->id,
        'workflow_id' => "v3-case-{$case->id}",
        'settings_version_id' => $this->settingsVersion->id,
        'stage_code' => 'source_anchor',
        'status_code' => 'queued',
        'attempt_count' => 1,
        'scene_count' => 1,
        'approved_scene_count' => 0,
        'start_state' => 'uncertain',
        'started_at' => null,
    ]);

    // Check detail shows workflow_start target
    $detailResp = $this->actingAs($this->admin)->getJson("/v3/admin/operations/{$case->id}");
    $detailResp->assertStatus(200);
    $targets = $detailResp->json('data.action_targets');
    expect($targets)->toHaveCount(1)
        ->and($targets[0]['kind'])->toBe('workflow_start')
        ->and($targets[0]['ref'])->toBe($case->id);

    // Mock bridge describe returning 200 started
    $mockBridge = \Mockery::mock(BridgeClient::class);
    $mockBridge->shouldReceive('describeWorkflow')
        ->with("v3-case-{$case->id}")
        ->andReturn([
            'status' => 200,
            'data' => [
                'found' => true,
                'workflow_id' => "v3-case-{$case->id}",
                'run_id' => "run-abc-123",
                'temporal_status' => 'open',
            ],
        ]);
    app()->instance(BridgeClient::class, $mockBridge);

    // POST retry command
    $cmdResp = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$case->id}/commands", [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_case_version' => 1,
        'expected_control_version' => 0,
        'action' => 'retry',
        'reason_code' => 'technical_reconcile',
        'target_kind' => 'workflow_start',
        'target_ref' => $case->id,
    ]);

    $cmdResp->assertStatus(201);
    $cmdRef = $cmdResp->json('data.command_ref');
    expect($cmdResp->json('data.action'))->toBe('retry')
        ->and($cmdResp->json('data.status'))->toBe('accepted');

    // Run dispatcher / reconciler
    $dispatched = app(DispatchAdminCommands::class)->dispatch();
    expect($dispatched)->toBeGreaterThanOrEqual(1);

    $command = AdminCommand::find($cmdRef);
    expect($command->status)->toBe('applied')
        ->and($command->applied_at)->not->toBeNull();

    $link->refresh();
    expect($link->start_state)->toBe('started')
        ->and($link->run_id)->toBe('run-abc-123');

    // Verify command_applied audit was created
    $audit = AdminOperationAudit::where('source_ref', $cmdRef)
        ->where('event', 'command_applied')
        ->first();
    expect($audit)->not->toBeNull()
        ->and($audit->action_code)->toBe('retry')
        ->and($audit->reason_code)->toBe('technical_reconcile');
});

test('O16: source_proof retry verifies read-only binding without incrementing provider send_count', function () {
    $case = createRetryCase($this->teacher, $this->settingsVersion, $this->document, ['state' => 'queued']);
    $activation = SourceActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $case->id,
        'workflow_id' => "v3-case-{$case->id}",
        'state' => 'accepted',
    ]);

    $extracted = ExtractedSource::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $case->id,
        'activation_id' => $activation->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'status' => 'ready',
        'text_sha256' => hash('sha256', 'test text'),
        'page_report' => [],
        'extractor_version' => '0.1.0',
    ]);

    $effect = SourceEffect::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $case->id,
        'operation' => 'notebook_create',
        'request_digest' => hash('sha256', "notebook_create\n{$case->id}\nv3-case-{$case->id}"),
        'state' => 'uncertain',
        'send_count' => 1,
    ]);

    $binding = KnowledgeSourceBinding::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $case->id,
        'extracted_source_id' => $extracted->id,
        'adapter_kind' => 'notebooklm',
        'adapter_version' => '0.1.0',
        'notebook_title' => "v3-case-{$case->id}",
        'source_title' => "v3-text-{$case->id}",
        'notebook_id' => 'nb-provider-verified-999',
        'state' => 'notebook_ready',
        'text_sha256' => $extracted->text_sha256,
        'text_bytes' => 100,
    ]);

    // Detail targets include source_proof
    $detailResp = $this->actingAs($this->admin)->getJson("/v3/admin/operations/{$case->id}");
    $targets = $detailResp->json('data.action_targets');
    expect($targets)->toHaveCount(1)
        ->and($targets[0]['kind'])->toBe('source_proof')
        ->and($targets[0]['ref'])->toBe($effect->id);

    // POST retry
    $cmdResp = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$case->id}/commands", [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_case_version' => 1,
        'expected_control_version' => 0,
        'action' => 'retry',
        'reason_code' => 'technical_reconcile',
        'target_kind' => 'source_proof',
        'target_ref' => $effect->id,
    ]);
    $cmdResp->assertStatus(201);
    $cmdRef = $cmdResp->json('data.command_ref');

    app(DispatchAdminCommands::class)->dispatch();

    $command = AdminCommand::find($cmdRef);
    expect($command->status)->toBe('applied');

    $effect->refresh();
    expect($effect->state)->toBe('confirmed')
        ->and($effect->send_count)->toBe(1); // Provider send count must remain unchanged!
});

test('O16: source_proof retry with unresolved binding remains uncertain without provider send', function () {
    $case = createRetryCase($this->teacher, $this->settingsVersion, $this->document, ['state' => 'queued']);
    $activation = SourceActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $case->id,
        'workflow_id' => "v3-case-{$case->id}",
        'state' => 'accepted',
    ]);

    $extracted = ExtractedSource::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $case->id,
        'activation_id' => $activation->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'status' => 'ready',
        'text_sha256' => hash('sha256', 'test text 2'),
        'page_report' => [],
        'extractor_version' => '0.1.0',
    ]);

    $effect = SourceEffect::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $case->id,
        'operation' => 'notebook_add_text',
        'request_digest' => hash('sha256', "notebook_add_text\n{$case->id}"),
        'state' => 'uncertain',
        'send_count' => 1,
    ]);

    $binding = KnowledgeSourceBinding::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $case->id,
        'extracted_source_id' => $extracted->id,
        'adapter_kind' => 'notebooklm',
        'adapter_version' => '0.1.0',
        'notebook_title' => "v3-case-{$case->id}",
        'source_title' => "v3-text-{$case->id}",
        'notebook_id' => null,
        'state' => 'add_uncertain',
        'text_sha256' => $extracted->text_sha256,
        'text_bytes' => 100,
    ]);

    $cmdResp = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$case->id}/commands", [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_case_version' => 1,
        'expected_control_version' => 0,
        'action' => 'retry',
        'reason_code' => 'technical_reconcile',
        'target_kind' => 'source_proof',
        'target_ref' => $effect->id,
    ]);
    $cmdResp->assertStatus(201);
    $cmdRef = $cmdResp->json('data.command_ref');

    app(DispatchAdminCommands::class)->dispatch();

    $command = AdminCommand::find($cmdRef);
    expect($command->status)->toBe('uncertain');

    $effect->refresh();
    expect($effect->state)->toBe('uncertain')
        ->and($effect->send_count)->toBe(1);
});

test('O16: scene_check retry reconciles recorded check run on current candidate', function () {
    $env = AuthoringFixtures::createAuthoringEnvironment();
    $case = $env['case'];
    $qualified = ReviewFixtures::createQualifiedCandidate($env, 1);
    $candidate = $qualified['candidate'];

    $attempt = SceneCheckAttempt::where('candidate_id', $candidate->id)->first();
    $attempt->update(['state' => 'uncertain']);

    $cmdResp = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$case->id}/commands", [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_case_version' => (int) $case->state_version,
        'expected_control_version' => 0,
        'action' => 'retry',
        'reason_code' => 'technical_reconcile',
        'target_kind' => 'scene_check',
        'target_ref' => $attempt->id,
    ]);
    $cmdResp->assertStatus(201);
    $cmdRef = $cmdResp->json('data.command_ref');

    app(DispatchAdminCommands::class)->dispatch();

    $command = AdminCommand::find($cmdRef);
    expect($command->status)->toBe('applied');
});

test('O16: scene_jev retry reconciles recorded qualification on current candidate', function () {
    $env = AuthoringFixtures::createAuthoringEnvironment();
    $case = $env['case'];
    $qualified = ReviewFixtures::createQualifiedCandidate($env, 1);
    $candidate = $qualified['candidate'];

    $attempt = SceneJevAttempt::where('candidate_id', $candidate->id)->first();
    $attempt->update(['state' => 'uncertain']);

    $cmdResp = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$case->id}/commands", [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_case_version' => (int) $case->state_version,
        'expected_control_version' => 0,
        'action' => 'retry',
        'reason_code' => 'technical_reconcile',
        'target_kind' => 'scene_jev',
        'target_ref' => $attempt->id,
    ]);
    $cmdResp->assertStatus(201);
    $cmdRef = $cmdResp->json('data.command_ref');

    app(DispatchAdminCommands::class)->dispatch();

    $command = AdminCommand::find($cmdRef);
    expect($command->status)->toBe('applied');
});

test('O16: review_signal retry queries and delivers decision signal to workflow', function () {
    $env = AuthoringFixtures::createAuthoringEnvironment();
    $case = $env['case'];
    $qualified = ReviewFixtures::createQualifiedCandidate($env, 1);
    $candidate = $qualified['candidate'];
    $section = $qualified['section'];

    $reviewItem = TeacherReviewItem::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $case->id,
        'section_id' => $section->id,
        'candidate_id' => $candidate->id,
        'qualification_id' => $qualified['qualification']->id,
        'prompt_version_id' => $candidate->prompt_version_id,
        'candidate_version' => 1,
        'status' => 'pending',
        'ready_at' => now(),
    ]);

    $decision = TeacherReviewDecision::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $case->id,
        'section_id' => $section->id,
        'review_item_id' => $reviewItem->id,
        'candidate_id' => $candidate->id,
        'qualification_id' => $qualified['qualification']->id,
        'actor_user_id' => $this->teacher->id,
        'candidate_version' => 1,
        'expected_review_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'request_sha256' => hash('sha256', 'dec_req'),
        'decision' => 'request_changes',
        'feedback_kind' => 'visual_interaction',
        'note_bytes' => 10,
    ]);

    $outbox = ReviewOutbox::forceCreate([
        'id' => (string) Str::uuid(),
        'review_decision_id' => $decision->id,
        'operation' => 'signal_decision',
        'workflow_id' => "v3-case-{$case->id}",
        'state' => 'uncertain',
        'attempt_count' => 1,
    ]);

    $mockGateway = \Mockery::mock(ReviewSignalGateway::class);
    $mockGateway->shouldReceive('queryDecision')
        ->with("v3-case-{$case->id}", $decision->id)
        ->andReturn(['consumed' => true]);
    app()->instance(ReviewSignalGateway::class, $mockGateway);

    $cmdResp = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$case->id}/commands", [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_case_version' => (int) $case->state_version,
        'expected_control_version' => 0,
        'action' => 'retry',
        'reason_code' => 'technical_reconcile',
        'target_kind' => 'review_signal',
        'target_ref' => $outbox->id,
    ]);
    $cmdResp->assertStatus(201);
    $cmdRef = $cmdResp->json('data.command_ref');

    app(DispatchAdminCommands::class)->dispatch();

    $command = AdminCommand::find($cmdRef);
    expect($command->status)->toBe('applied');

    $outbox->refresh();
    expect($outbox->state)->toBe('delivered');
});

test('O16: review_correction retry describes and adopts existing correction workflow ID', function () {
    $env = AuthoringFixtures::createAuthoringEnvironment();
    $case = $env['case'];
    $qualified = ReviewFixtures::createQualifiedCandidate($env, 1);
    $candidate = $qualified['candidate'];
    $section = $qualified['section'];

    $correctionId = (string) Str::uuid();
    $workflowId = "v3-review-correction-{$correctionId}";

    $reviewItem = TeacherReviewItem::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $case->id,
        'section_id' => $section->id,
        'candidate_id' => $candidate->id,
        'qualification_id' => $qualified['qualification']->id,
        'prompt_version_id' => $candidate->prompt_version_id,
        'candidate_version' => 1,
        'status' => 'decided',
        'ready_at' => now(),
    ]);

    $decision = TeacherReviewDecision::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $case->id,
        'section_id' => $section->id,
        'review_item_id' => $reviewItem->id,
        'candidate_id' => $candidate->id,
        'qualification_id' => $qualified['qualification']->id,
        'actor_user_id' => $this->teacher->id,
        'candidate_version' => 1,
        'expected_review_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'request_sha256' => hash('sha256', 'dec_req_corr'),
        'decision' => 'approve',
    ]);

    $approval = \App\Models\V3\ApprovedScene::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $case->id,
        'section_id' => $section->id,
        'candidate_id' => $candidate->id,
        'qualification_id' => $qualified['qualification']->id,
        'review_decision_id' => $decision->id,
        'candidate_version' => 1,
    ]);

    $correction = PostapprovalCorrection::forceCreate([
        'id' => $correctionId,
        'case_id' => $case->id,
        'section_id' => $section->id,
        'approval_id' => $approval->id,
        'actor_user_id' => $this->teacher->id,
        'idempotency_key' => (string) Str::uuid(),
        'request_sha256' => hash('sha256', 'corr_req'),
        'feedback_kind' => 'visual_interaction',
        'note_path' => 'notes/corr.txt',
        'note_sha256' => hash('sha256', 'corr note'),
        'note_bytes' => 10,
        'state' => 'accepted',
    ]);

    $outbox = ReviewOutbox::forceCreate([
        'id' => (string) Str::uuid(),
        'postapproval_correction_id' => $correction->id,
        'operation' => 'start_correction',
        'workflow_id' => $workflowId,
        'state' => 'uncertain',
        'attempt_count' => 1,
    ]);

    $mockBridge = \Mockery::mock(BridgeClient::class);
    $mockBridge->shouldReceive('describeWorkflow')
        ->with($workflowId)
        ->andReturn([
            'status' => 200,
            'data' => [
                'workflow_id' => $workflowId,
                'run_id' => 'correction-run-456',
                'status' => 'RUNNING',
            ],
        ]);
    app()->instance(BridgeClient::class, $mockBridge);

    $cmdResp = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$case->id}/commands", [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_case_version' => (int) $case->state_version,
        'expected_control_version' => 0,
        'action' => 'retry',
        'reason_code' => 'technical_reconcile',
        'target_kind' => 'review_correction',
        'target_ref' => $outbox->id,
    ]);
    $cmdResp->assertStatus(201);
    $cmdRef = $cmdResp->json('data.command_ref');

    app(DispatchAdminCommands::class)->dispatch();

    $command = AdminCommand::find($cmdRef);
    expect($command->status)->toBe('applied');

    $outbox->refresh();
    expect($outbox->run_id)->toBe('correction-run-456');
});

test('O16: retry rejects non-eligible target, terminal case, or invalid target_kind', function () {
    $case = createRetryCase($this->teacher, $this->settingsVersion, $this->document);

    // 1. Non-eligible random UUID target_ref returns 409 operation_conflict
    $resp = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$case->id}/commands", [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_case_version' => 1,
        'expected_control_version' => 0,
        'action' => 'retry',
        'reason_code' => 'technical_reconcile',
        'target_kind' => 'workflow_start',
        'target_ref' => (string) Str::uuid(),
    ]);
    $resp->assertStatus(409)
        ->assertJson(['error' => ['code' => 'operation_conflict']]);

    // 2. Terminal case (complete) returns 409 operation_conflict
    $terminalCase = createRetryCase($this->teacher, $this->settingsVersion, $this->document, ['state' => 'complete']);
    $resp2 = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$terminalCase->id}/commands", [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_case_version' => 1,
        'expected_control_version' => 0,
        'action' => 'retry',
        'reason_code' => 'technical_reconcile',
        'target_kind' => 'workflow_start',
        'target_ref' => $terminalCase->id,
    ]);
    $resp2->assertStatus(409)
        ->assertJson(['error' => ['code' => 'operation_conflict']]);

    // 3. Ambiguous provider send targets (e.g. notebook_create, prompt_generation) return 422 contract_invalid
    $resp3 = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$case->id}/commands", [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_case_version' => 1,
        'expected_control_version' => 0,
        'action' => 'retry',
        'reason_code' => 'technical_reconcile',
        'target_kind' => 'notebook_create',
        'target_ref' => (string) Str::uuid(),
    ]);
    $resp3->assertStatus(422)
        ->assertJson(['error' => ['code' => 'contract_invalid']]);

    // 4. Case under hold or cancel control returns 409 operation_conflict
    CaseOperationControl::create([
        'case_id' => $case->id,
        'mode' => 'held',
        'control_version' => 1,
    ]);
    $resp4 = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$case->id}/commands", [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_case_version' => 1,
        'expected_control_version' => 1,
        'action' => 'retry',
        'reason_code' => 'technical_reconcile',
        'target_kind' => 'workflow_start',
        'target_ref' => $case->id,
    ]);
    $resp4->assertStatus(409)
        ->assertJson(['error' => ['code' => 'operation_conflict']]);
});

test('O16: exact replay of retry returns prior command without re-invoking reconciler', function () {
    $case = createRetryCase($this->teacher, $this->settingsVersion, $this->document, ['state' => 'queued']);
    WorkflowLink::forceCreate([
        'case_id' => $case->id,
        'workflow_id' => "v3-case-{$case->id}",
        'settings_version_id' => $this->settingsVersion->id,
        'stage_code' => 'source_anchor',
        'status_code' => 'queued',
        'attempt_count' => 1,
        'scene_count' => 1,
        'approved_scene_count' => 0,
        'start_state' => 'uncertain',
        'started_at' => null,
    ]);

    $idemKey = (string) Str::uuid();
    $payload = [
        'schema_version' => 1,
        'idempotency_key' => $idemKey,
        'expected_case_version' => 1,
        'expected_control_version' => 0,
        'action' => 'retry',
        'reason_code' => 'technical_reconcile',
        'target_kind' => 'workflow_start',
        'target_ref' => $case->id,
    ];

    $resp1 = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$case->id}/commands", $payload);
    $resp1->assertStatus(201);
    $cmdRef = $resp1->json('data.command_ref');

    // Manually mark command as applied
    $cmd = AdminCommand::find($cmdRef);
    $cmd->update(['status' => 'applied', 'applied_at' => now()]);

    // Exact replay returns 200 with status applied
    $resp2 = $this->actingAs($this->admin)->postJson("/v3/admin/operations/{$case->id}/commands", $payload);
    $resp2->assertStatus(200)
        ->assertJson([
            'data' => [
                'command_ref' => $cmdRef,
                'action' => 'retry',
                'status' => 'applied',
            ],
        ]);
});
