<?php

namespace Tests\Feature\V3\Admin;

use App\Models\User;
use App\Models\V3\AdminCommand;
use App\Models\V3\AdminOperationAudit;
use App\Models\V3\CaseOperationControl;
use App\Models\V3\DeletionAssessment;
use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SettingsVersion;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\TeacherSection;
use App\Models\V3\WorkflowLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
});

test('O22: terminal quiescent case records deferred_to_015 with fixed policy/version and zero deletion', function () {
    $env = AuthoringFixtures::createAuthoringEnvironment();
    $case = $env['case'];

    // Move case to terminal complete state
    $case->state = 'complete';
    $case->state_version = 3;
    $case->save();

    // Link in completed state
    WorkflowLink::forceCreate([
        'case_id' => $case->id,
        'workflow_id' => 'wf-' . $case->id,
        'run_id' => 'run-1',
        'settings_version_id' => $this->settingsVersion->id,
        'start_state' => 'closed',
        'stage_code' => 'approval',
        'status_code' => 'completed',
        'started_at' => now()->subHours(2),
        'last_event_at' => now()->subHour(),
    ]);

    $control = CaseOperationControl::create([
        'case_id' => $case->id,
        'mode' => 'normal',
        'control_version' => 0,
        'fenced' => false,
    ]);

    $idemKey = (string) Str::uuid();
    $payload = [
        'schema_version' => 1,
        'idempotency_key' => $idemKey,
        'expected_case_version' => 3,
        'expected_control_version' => 0,
        'reason_code' => 'pilot_cleanup_request',
    ];

    // 1. Initial assessment returns 201 deferred_to_015
    $resp = $this->actingAs($this->admin)->postJson(
        "/v3/admin/operations/{$case->id}/deletion-assessments",
        $payload
    );

    $resp->assertStatus(201);
    $resp->assertHeader('Cache-Control', 'no-store, private');
    $resp->assertJson([
        'data' => [
            'status' => 'deferred_to_015',
            'safe_blocker' => null,
            'policy_version' => 'pilot-retain-v1',
        ],
    ]);

    $assessmentRef = $resp->json('data.assessment_ref');
    expect($assessmentRef)->not->toBeNull();

    // Verify database row
    $assessment = DeletionAssessment::find($assessmentRef);
    expect($assessment)->not->toBeNull()
        ->and($assessment->case_id)->toBe($case->id)
        ->and($assessment->actor_user_id)->toBe($this->admin->id)
        ->and($assessment->idempotency_key)->toBe($idemKey)
        ->and($assessment->status)->toBe('deferred_to_015')
        ->and($assessment->safe_blocker)->toBeNull()
        ->and($assessment->reason_code)->toBe('pilot_cleanup_request')
        ->and($assessment->policy_version)->toBe('pilot-retain-v1')
        ->and($assessment->case_state_version)->toBe(3)
        ->and($assessment->control_version)->toBe(0);

    // Verify audit
    $audit = AdminOperationAudit::where('source_ref', $assessmentRef)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->event)->toBe('assessment_recorded')
        ->and($audit->action_code)->toBe('assess_deletion')
        ->and($audit->reason_code)->toBe('pilot_cleanup_request')
        ->and($audit->actor_user_id)->toBe($this->admin->id);

    // 2. Zero deletion of files or DB records
    expect(ProcessingCase::find($case->id))->not->toBeNull()
        ->and(Document::find($case->document_id))->not->toBeNull()
        ->and(WorkflowLink::find($case->id))->not->toBeNull();

    // 3. Exact same key replay returns 200
    $replayResp = $this->actingAs($this->admin)->postJson(
        "/v3/admin/operations/{$case->id}/deletion-assessments",
        $payload
    );
    $replayResp->assertStatus(200)
        ->assertJson([
            'data' => [
                'assessment_ref' => $assessmentRef,
                'status' => 'deferred_to_015',
                'safe_blocker' => null,
                'policy_version' => 'pilot-retain-v1',
            ],
        ]);

    // 4. Same key with changed payload returns 409
    $changedResp = $this->actingAs($this->admin)->postJson(
        "/v3/admin/operations/{$case->id}/deletion-assessments",
        array_merge($payload, ['expected_case_version' => 4])
    );
    $changedResp->assertStatus(409)
        ->assertJson(['error' => ['code' => 'operation_conflict']]);
});

test('O23: blockers evaluated in order case_active -> workflow_open -> review_pending -> effect_uncertain', function () {
    // 1. case_active: active running case
    $env1 = AuthoringFixtures::createAuthoringEnvironment();
    $case1 = $env1['case'];
    $case1->state = 'running';
    $case1->state_version = 1;
    $case1->save();

    $resp1 = $this->actingAs($this->admin)->postJson(
        "/v3/admin/operations/{$case1->id}/deletion-assessments",
        [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_case_version' => 1,
            'expected_control_version' => 0,
            'reason_code' => 'pilot_cleanup_request',
        ]
    );
    $resp1->assertStatus(201)
        ->assertJson([
            'data' => [
                'status' => 'ineligible',
                'safe_blocker' => 'case_active',
                'policy_version' => 'pilot-retain-v1',
            ],
        ]);

    // 2. workflow_open: terminal case but workflow still running
    $env2 = AuthoringFixtures::createAuthoringEnvironment();
    $case2 = $env2['case'];
    $case2->state = 'cancelled';
    $case2->state_version = 2;
    $case2->save();

    WorkflowLink::forceCreate([
        'case_id' => $case2->id,
        'workflow_id' => 'wf-' . $case2->id,
        'run_id' => 'run-2',
        'settings_version_id' => $this->settingsVersion->id,
        'start_state' => 'started',
        'stage_code' => 'generation',
        'status_code' => 'running',
        'started_at' => now()->subMinutes(10),
    ]);

    $resp2 = $this->actingAs($this->admin)->postJson(
        "/v3/admin/operations/{$case2->id}/deletion-assessments",
        [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_case_version' => 2,
            'expected_control_version' => 0,
            'reason_code' => 'pilot_cleanup_request',
        ]
    );
    $resp2->assertStatus(201)
        ->assertJson([
            'data' => [
                'status' => 'ineligible',
                'safe_blocker' => 'workflow_open',
                'policy_version' => 'pilot-retain-v1',
            ],
        ]);

    // 3. review_pending: terminal case, closed workflow, but review item pending
    $env3 = AuthoringFixtures::createAuthoringEnvironment();
    $case3 = $env3['case'];
    $case3->state = 'complete';
    $case3->state_version = 2;
    $case3->save();

    WorkflowLink::forceCreate([
        'case_id' => $case3->id,
        'workflow_id' => 'wf-' . $case3->id,
        'run_id' => 'run-3',
        'settings_version_id' => $this->settingsVersion->id,
        'start_state' => 'closed',
        'stage_code' => 'approval',
        'status_code' => 'completed',
        'started_at' => now()->subHours(1),
    ]);

    $qualified = ReviewFixtures::createQualifiedCandidate($env3, 1);
    TeacherReviewItem::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $case3->id,
        'section_id' => $qualified['section']->id,
        'candidate_id' => $qualified['candidate']->id,
        'qualification_id' => $qualified['qualification']->id,
        'prompt_version_id' => $qualified['candidate']->prompt_version_id,
        'candidate_version' => 1,
        'status' => 'pending',
        'state_version' => 1,
        'ready_at' => now(),
    ]);

    $resp3 = $this->actingAs($this->admin)->postJson(
        "/v3/admin/operations/{$case3->id}/deletion-assessments",
        [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_case_version' => 2,
            'expected_control_version' => 0,
            'reason_code' => 'pilot_cleanup_request',
        ]
    );
    $resp3->assertStatus(201)
        ->assertJson([
            'data' => [
                'status' => 'ineligible',
                'safe_blocker' => 'review_pending',
                'policy_version' => 'pilot-retain-v1',
            ],
        ]);

    // 4. effect_uncertain: terminal case, closed workflow, no review items, but uncertain source effect
    $env4 = AuthoringFixtures::createAuthoringEnvironment();
    $case4 = $env4['case'];
    $case4->state = 'complete';
    $case4->state_version = 2;
    $case4->save();

    WorkflowLink::forceCreate([
        'case_id' => $case4->id,
        'workflow_id' => 'wf-' . $case4->id,
        'run_id' => 'run-4',
        'settings_version_id' => $this->settingsVersion->id,
        'start_state' => 'closed',
        'stage_code' => 'approval',
        'status_code' => 'completed',
        'started_at' => now()->subHours(1),
    ]);

    DB::table('v3_source_effects')->insert([
        'id' => (string) Str::uuid(),
        'case_id' => $case4->id,
        'operation' => 'notebook_create',
        'request_digest' => hash('sha256', 'uncertain test'),
        'state' => 'uncertain',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $resp4 = $this->actingAs($this->admin)->postJson(
        "/v3/admin/operations/{$case4->id}/deletion-assessments",
        [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_case_version' => 2,
            'expected_control_version' => 0,
            'reason_code' => 'pilot_cleanup_request',
        ]
    );
    $resp4->assertStatus(201)
        ->assertJson([
            'data' => [
                'status' => 'ineligible',
                'safe_blocker' => 'effect_uncertain',
                'policy_version' => 'pilot-retain-v1',
            ],
        ]);
});

test('O23: mismatched expected version, malformed input, and unauthorized actors are rejected', function () {
    $env = AuthoringFixtures::createAuthoringEnvironment();
    $case = $env['case'];
    $case->state = 'complete';
    $case->state_version = 2;
    $case->save();

    // 1. Mismatched case version -> 409 operation_conflict
    $resp1 = $this->actingAs($this->admin)->postJson(
        "/v3/admin/operations/{$case->id}/deletion-assessments",
        [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_case_version' => 99,
            'expected_control_version' => 0,
            'reason_code' => 'pilot_cleanup_request',
        ]
    );
    $resp1->assertStatus(409)
        ->assertJson(['error' => ['code' => 'operation_conflict']]);

    // 2. Mismatched control version -> 409 operation_conflict
    $resp2 = $this->actingAs($this->admin)->postJson(
        "/v3/admin/operations/{$case->id}/deletion-assessments",
        [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_case_version' => 2,
            'expected_control_version' => 99,
            'reason_code' => 'pilot_cleanup_request',
        ]
    );
    $resp2->assertStatus(409)
        ->assertJson(['error' => ['code' => 'operation_conflict']]);

    // 3. Invalid reason code -> 422 contract_invalid
    $resp3 = $this->actingAs($this->admin)->postJson(
        "/v3/admin/operations/{$case->id}/deletion-assessments",
        [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_case_version' => 2,
            'expected_control_version' => 0,
            'reason_code' => 'immediate_purge',
        ]
    );
    $resp3->assertStatus(422)
        ->assertJson(['error' => ['code' => 'contract_invalid']]);

    // 4. Missing case -> 404 resource_unavailable
    $resp4 = $this->actingAs($this->admin)->postJson(
        "/v3/admin/operations/" . Str::uuid() . "/deletion-assessments",
        [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_case_version' => 1,
            'expected_control_version' => 0,
            'reason_code' => 'pilot_cleanup_request',
        ]
    );
    $resp4->assertStatus(404)
        ->assertJson(['error' => ['code' => 'resource_unavailable']]);

    // 5. Unauthorized teacher -> 403 access_denied
    $resp5 = $this->actingAs($this->teacher)->postJson(
        "/v3/admin/operations/{$case->id}/deletion-assessments",
        [
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'expected_case_version' => 2,
            'expected_control_version' => 0,
            'reason_code' => 'pilot_cleanup_request',
        ]
    );
    $resp5->assertStatus(403)
        ->assertJson(['error' => ['code' => 'access_denied']]);
});
