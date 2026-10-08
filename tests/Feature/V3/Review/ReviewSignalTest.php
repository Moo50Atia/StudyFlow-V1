<?php

namespace Tests\Feature\V3\Review;

use App\Models\V3\ReviewOutbox;
use App\Models\V3\CaseOperationControl;
use App\Models\V3\ReviewPreviewSession;
use App\Models\V3\ReviewRoute;
use App\Models\V3\SceneRepair;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\TeacherReviewDecision;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\WorkflowLink;
use App\Services\V3\Review\DispatchReviewEffects;
use App\Services\V3\Workflow\ReviewSignalGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AuthoringFixtures;
use Tests\Support\V3\ReviewFixtures;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');

    $this->env = AuthoringFixtures::createAuthoringEnvironment();
    $this->teacher = $this->env['teacher'];
    $this->case = $this->env['case'];
    $this->map = $this->env['map'];

    $this->workflowId = "v3-case-{$this->case->id}";
    WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => $this->workflowId,
        'run_id' => 'run-signal-test',
        'settings_version_id' => $this->env['settingsVersion']->id,
        'stage_code' => 'teacher_review',
        'status_code' => 'awaiting_review',
        'start_state' => 'started',
        'started_at' => now(),
        'last_event_at' => now(),
    ]);

    $this->q1 = ReviewFixtures::createQualifiedCandidate($this->env, 1);
    $this->section = $this->q1['section'];
    $this->candidate = $this->q1['candidate'];

    $readyPath = "/api/internal/v1/cases/{$this->case->id}/reviews/ready";
    $readyRes = ReviewFixtures::postSigned($this, $readyPath, [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
    ]);
    expect($readyRes->status())->toBe(201);
    $this->reviewRef = $readyRes->json('data.review_ref');
    $this->reviewItem = TeacherReviewItem::find($this->reviewRef);
});

test('R13: Decision creates pending outbox row without leaking notes or prompt to Temporal signal', function () {
    $url = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/decisions";
    $idempotencyKey = (string) Str::uuid();

    $res = $this->actingAs($this->teacher)->postJson($url, [
        'schema_version' => 1,
        'idempotency_key' => $idempotencyKey,
        'expected_review_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'candidate_version' => 1,
        'decision' => 'request_changes',
        'feedback_kind' => 'visual_interaction',
        'notes' => 'Secret teacher note content that must never enter Temporal',
    ]);
    expect($res->status())->toBe(201);

    $decisionRef = $res->json('data.decision_ref');

    // Outbox entry created in pending state
    $outbox = ReviewOutbox::where('review_decision_id', $decisionRef)->first();
    expect($outbox)->not->toBeNull()
        ->and($outbox->operation)->toBe('signal_decision')
        ->and($outbox->state)->toBe('pending')
        ->and($outbox->workflow_id)->toBe($this->workflowId);
});

test('R23: DispatchReviewEffects delivers signal to bridge and reconciles via query', function () {
    $decisionId = (string) Str::uuid();
    $dec = TeacherReviewDecision::forceCreate([
        'id' => $decisionId,
        'review_item_id' => $this->reviewItem->id,
        'case_id' => $this->case->id,
        'section_id' => $this->section->id,
        'candidate_id' => $this->candidate->id,
        'qualification_id' => $this->q1['qualification']->id,
        'actor_user_id' => $this->teacher->id,
        'candidate_version' => 1,
        'expected_review_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'request_sha256' => str_repeat('e', 64),
        'decision' => 'approve',
        'feedback_kind' => null,
        'created_at' => now(),
    ]);

    $outbox = ReviewOutbox::forceCreate([
        'id' => (string) Str::uuid(),
        'review_decision_id' => $decisionId,
        'postapproval_correction_id' => null,
        'operation' => 'signal_decision',
        'workflow_id' => $this->workflowId,
        'run_id' => 'run-signal-test',
        'state' => 'pending',
        'attempt_count' => 0,
    ]);

    // Mock bridge endpoints:
    // 1. POST /internal/v1/workflows/{workflow_id}/review-decisions -> 202 Accepted
    // 2. GET /internal/v1/workflows/{workflow_id}/review-decisions/{decision_id} -> 200 {data: {consumed: true}}
    $signalReceivedBody = null;
    $queryCount = 0;
    Http::fake([
        '*/internal/v1/workflows/' . $this->workflowId . '/review-decisions/' . $decisionId => function () use (&$queryCount, $decisionId) {
            $queryCount++;
            return Http::response([
                'data' => [
                    'decision_ref' => $decisionId,
                    'consumed' => $queryCount > 1,
                ],
            ], 200);
        },
        '*/internal/v1/workflows/' . $this->workflowId . '/review-decisions' => function ($request) use (&$signalReceivedBody) {
            $signalReceivedBody = json_decode($request->body(), true);
            return Http::response([
                'data' => [
                    'workflow_id' => 'v3-case-test',
                    'decision_ref' => $signalReceivedBody['decision_ref'] ?? '',
                    'accepted' => true,
                ],
            ], 202);
        },
    ]);

    $dispatcher = app(DispatchReviewEffects::class);
    $processed = $dispatcher->dispatchPending();

    expect($processed)->toBe(1);

    // Verify outbox transitioned to delivered
    $outbox->refresh();
    expect($outbox->state)->toBe('delivered')
        ->and($outbox->delivered_at)->not->toBeNull();

    // Verify signal payload has exact fields and NO teacher content
    expect($signalReceivedBody)->not->toBeNull()
        ->and($signalReceivedBody['schema_version'])->toBe(1)
        ->and($signalReceivedBody['case_id'])->toBe($this->case->id)
        ->and($signalReceivedBody['section_ref'])->toBe($this->section->id)
        ->and($signalReceivedBody['review_ref'])->toBe($this->reviewRef)
        ->and($signalReceivedBody['decision_ref'])->toBe($decisionId)
        ->and($signalReceivedBody['candidate_ref'])->toBe($this->candidate->id)
        ->and($signalReceivedBody['candidate_version'])->toBe(1)
        ->and($signalReceivedBody['outcome'])->toBe('approve')
        ->and(isset($signalReceivedBody['notes']))->toBeFalse()
        ->and(isset($signalReceivedBody['prompt']))->toBeFalse();
});

test('R23: a consumed request_changes decision starts its durable correction route', function () {
    $url = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/decisions";
    $response = $this->actingAs($this->teacher)->postJson($url, [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_review_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'candidate_version' => 1,
        'decision' => 'request_changes',
        'feedback_kind' => 'visual_interaction',
        'notes' => 'Clarify the transition between the two states.',
    ]);
    $response->assertCreated();

    $decisionRef = $response->json('data.decision_ref');
    $route = ReviewRoute::where('review_decision_id', $decisionRef)->firstOrFail();
    $routeWorkflowId = "v3-review-correction-{$route->id}";
    $queryCount = 0;

    Http::fake([
        "*/internal/v1/workflows/{$this->workflowId}/review-decisions/{$decisionRef}" => function () use (&$queryCount) {
            $queryCount++;
            return Http::response(['data' => ['consumed' => $queryCount > 1]], 200);
        },
        "*/internal/v1/workflows/{$this->workflowId}/review-decisions" => Http::response([
            'data' => ['accepted' => true, 'decision_ref' => $decisionRef],
        ], 202),
        "*/internal/v1/workflows/{$routeWorkflowId}/start-correction" => function ($request) use ($route) {
            $payload = json_decode($request->body(), true);
            expect($payload['case_id'])->toBe($route->case_id)
                ->and($payload['route_ref'])->toBe($route->id)
                ->and($payload['correction_ref'])->toBe($route->id);
            return Http::response([
                'data' => ['accepted' => true, 'correction_ref' => $route->id],
            ], 202);
        },
    ]);

    expect(app(DispatchReviewEffects::class)->dispatchPending())->toBe(1);
    $outbox = ReviewOutbox::where('review_decision_id', $decisionRef)->firstOrFail();
    expect($outbox->state)->toBe('delivered')
        ->and($outbox->delivered_at)->not->toBeNull();
    Http::assertSent(fn ($request) => str_contains($request->url(), "/{$routeWorkflowId}/start-correction"));
});

test('R23: successor approval is confirmed by its correction workflow and the parent case workflow', function () {
    $successor = ReviewFixtures::createQualifiedCandidate($this->env, 2);
    $routeId = (string) Str::uuid();
    ReviewRoute::forceCreate([
        'id' => $routeId,
        'case_id' => $this->case->id,
        'section_id' => $successor['section']->id,
        'review_decision_id' => null,
        'postapproval_correction_id' => null,
        'kind' => 'scene_repair',
        'state' => 'completed',
        'target_ref' => null,
    ]);
    SceneRepair::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'section_id' => $successor['section']->id,
        'prompt_version_id' => $successor['candidate']->prompt_version_id,
        'root_candidate_id' => $this->candidate->id,
        'from_candidate_id' => $this->candidate->id,
        'to_candidate_id' => $successor['candidate']->id,
        'authoring_request_id' => null,
        'teacher_review_route_id' => $routeId,
        'ordinal' => 1,
        'criteria_path' => 'repairs/criteria.json',
        'criteria_sha256' => str_repeat('a', 64),
        'state' => 'completed',
    ]);
    $readyPath = "/api/internal/v1/cases/{$this->case->id}/reviews/ready";
    $ready = ReviewFixtures::postSigned($this, $readyPath, [
        'schema_version' => 1,
        'candidate_ref' => $successor['candidate']->id,
    ]);
    $ready->assertCreated();
    $reviewRef = $ready->json('data.review_ref');

    $decisionId = (string) Str::uuid();
    TeacherReviewDecision::forceCreate([
        'id' => $decisionId,
        'review_item_id' => $reviewRef,
        'case_id' => $this->case->id,
        'section_id' => $successor['section']->id,
        'candidate_id' => $successor['candidate']->id,
        'qualification_id' => $successor['qualification']->id,
        'actor_user_id' => $this->teacher->id,
        'candidate_version' => 1,
        'expected_review_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'request_sha256' => str_repeat('b', 64),
        'decision' => 'approve',
        'feedback_kind' => null,
        'created_at' => now(),
    ]);
    $correctionWorkflowId = "v3-review-correction-{$routeId}";
    ReviewOutbox::forceCreate([
        'id' => (string) Str::uuid(),
        'review_decision_id' => $decisionId,
        'postapproval_correction_id' => null,
        'operation' => 'signal_decision',
        'workflow_id' => $correctionWorkflowId,
        'state' => 'pending',
        'attempt_count' => 0,
    ]);

    $correctionQueries = 0;
    $parentQueries = 0;
    Http::fake([
        "*/internal/v1/workflows/{$correctionWorkflowId}/review-decisions/{$decisionId}" => function () use (&$correctionQueries) {
            $correctionQueries++;
            return Http::response(['data' => ['consumed' => $correctionQueries > 1]], 200);
        },
        "*/internal/v1/workflows/{$correctionWorkflowId}/review-decisions" => Http::response(['data' => ['accepted' => true]], 202),
        "*/internal/v1/workflows/{$this->workflowId}/review-decisions/{$decisionId}" => function () use (&$parentQueries) {
            $parentQueries++;
            return Http::response(['data' => ['consumed' => $parentQueries > 1]], 200);
        },
        "*/internal/v1/workflows/{$this->workflowId}/review-decisions" => Http::response(['data' => ['accepted' => true]], 202),
    ]);

    expect(app(DispatchReviewEffects::class)->dispatchPending())->toBe(1);
    $outbox = ReviewOutbox::where('review_decision_id', $decisionId)->firstOrFail();
    expect($outbox->state)->toBe('delivered');
    Http::assertSent(fn ($request) => str_contains($request->url(), "/{$correctionWorkflowId}/review-decisions"));
    Http::assertSent(fn ($request) => str_contains($request->url(), "/{$this->workflowId}/review-decisions"));
});

test('R19: false or failed decision-consumption proof keeps the outbox recoverable', function () {
    $decisionId = (string) Str::uuid();
    $decision = TeacherReviewDecision::forceCreate([
        'id' => $decisionId, 'review_item_id' => $this->reviewItem->id,
        'case_id' => $this->case->id, 'section_id' => $this->section->id,
        'candidate_id' => $this->candidate->id, 'qualification_id' => $this->q1['qualification']->id,
        'actor_user_id' => $this->teacher->id, 'candidate_version' => 1,
        'expected_review_version' => 1, 'idempotency_key' => (string) Str::uuid(),
        'request_sha256' => str_repeat('b', 64), 'decision' => 'approve',
        'feedback_kind' => null, 'created_at' => now(),
    ]);
    $outbox = ReviewOutbox::forceCreate([
        'id' => (string) Str::uuid(), 'review_decision_id' => $decision->id,
        'postapproval_correction_id' => null, 'operation' => 'signal_decision',
        'workflow_id' => $this->workflowId, 'state' => 'pending', 'attempt_count' => 0,
    ]);
    Http::fake([
        '*/internal/v1/workflows/'.$this->workflowId.'/review-decisions/'.$decisionId => Http::response(['data' => ['consumed' => false]], 503),
        '*/internal/v1/workflows/'.$this->workflowId.'/review-decisions' => Http::response(['data' => ['accepted' => true]], 202),
    ]);

    expect(app(DispatchReviewEffects::class)->dispatchPending())->toBe(0);
    $outbox->refresh();
    expect($outbox->state)->toBe('uncertain')->and($outbox->delivered_at)->toBeNull();
});

test('O08: a committed hold fence defers a not-yet-claimed review signal', function () {
    $decision = TeacherReviewDecision::forceCreate([
        'id' => (string) Str::uuid(), 'review_item_id' => $this->reviewItem->id,
        'case_id' => $this->case->id, 'section_id' => $this->section->id,
        'candidate_id' => $this->candidate->id, 'qualification_id' => $this->q1['qualification']->id,
        'actor_user_id' => $this->teacher->id, 'candidate_version' => 1,
        'expected_review_version' => 1, 'idempotency_key' => (string) Str::uuid(),
        'request_sha256' => str_repeat('c', 64), 'decision' => 'approve',
        'feedback_kind' => null, 'created_at' => now(),
    ]);
    $outbox = ReviewOutbox::forceCreate([
        'id' => (string) Str::uuid(),
        'review_decision_id' => $decision->id, 'postapproval_correction_id' => null,
        'operation' => 'signal_decision', 'workflow_id' => $this->workflowId,
        'state' => 'pending', 'attempt_count' => 0,
    ]);
    CaseOperationControl::create(['case_id' => $this->case->id, 'mode' => 'held', 'control_version' => 1]);
    Http::fake();

    expect(app(DispatchReviewEffects::class)->dispatchPending())->toBe(0);
    $outbox->refresh();
    expect($outbox->state)->toBe('pending')->and($outbox->attempt_count)->toBe(0);
    Http::assertNothingSent();
});

test('O11: cancellation permanently fences an unsent review outbox effect', function () {
    $decision = TeacherReviewDecision::forceCreate([
        'id' => (string) Str::uuid(), 'review_item_id' => $this->reviewItem->id,
        'case_id' => $this->case->id, 'section_id' => $this->section->id,
        'candidate_id' => $this->candidate->id, 'qualification_id' => $this->q1['qualification']->id,
        'actor_user_id' => $this->teacher->id, 'candidate_version' => 1,
        'expected_review_version' => 1, 'idempotency_key' => (string) Str::uuid(),
        'request_sha256' => str_repeat('d', 64), 'decision' => 'approve',
        'feedback_kind' => null, 'created_at' => now(),
    ]);
    $outbox = ReviewOutbox::forceCreate([
        'id' => (string) Str::uuid(),
        'review_decision_id' => $decision->id, 'postapproval_correction_id' => null,
        'operation' => 'signal_decision', 'workflow_id' => $this->workflowId,
        'state' => 'pending', 'attempt_count' => 0,
    ]);
    $this->case->update(['state' => 'cancelled']);
    CaseOperationControl::create(['case_id' => $this->case->id, 'mode' => 'cancelled', 'control_version' => 2]);
    Http::fake();

    expect(app(DispatchReviewEffects::class)->dispatchPending())->toBe(0);
    $outbox->refresh();
    expect($outbox->state)->toBe('blocked')
        ->and($outbox->error_code)->toBe('operation_controlled')
        ->and($outbox->attempt_count)->toBe(0);
    Http::assertNothingSent();
});
