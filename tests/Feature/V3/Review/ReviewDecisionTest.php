<?php

namespace Tests\Feature\V3\Review;

use App\Models\V3\ApprovedScene;
use App\Models\V3\ReviewOutbox;
use App\Models\V3\ReviewPreviewSession;
use App\Models\V3\ReviewRoute;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\TeacherReviewDecision;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\WorkflowLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-decision-test',
        'settings_version_id' => $this->env['settingsVersion']->id,
        'stage_code' => 'scene_qualification',
        'status_code' => 'running',
        'start_state' => 'started',
        'started_at' => now(),
        'last_event_at' => now(),
    ]);

    // Create qualified candidate for Section 1
    $this->q1 = ReviewFixtures::createQualifiedCandidate($this->env, 1);
    $this->section = $this->q1['section'];
    $this->candidate = $this->q1['candidate'];

    // Ensure review ready
    $readyPath = "/api/internal/v1/cases/{$this->case->id}/reviews/ready";
    $readyRes = ReviewFixtures::postSigned($this, $readyPath, [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
    ]);
    expect($readyRes->status())->toBe(201);
    $this->reviewRef = $readyRes->json('data.review_ref');
    $this->reviewItem = TeacherReviewItem::find($this->reviewRef);
});

test('R11: Decision approve requires outward GET frame 0 proof; fails without it', function () {
    $url = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/decisions";
    $idempotencyKey = (string) Str::uuid();

    // 1. Submit approve WITHOUT frame 0 preview -> 409 review_conflict
    $resWithoutPreview = $this->actingAs($this->teacher)->postJson($url, [
        'schema_version' => 1,
        'idempotency_key' => $idempotencyKey,
        'expected_review_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'candidate_version' => 1,
        'decision' => 'approve',
        'feedback_kind' => null,
        'notes' => null,
    ]);

    expect($resWithoutPreview->status())->toBe(409)
        ->and($resWithoutPreview->json('error.code'))->toBe('review_conflict');

    // 2. Start session but DO NOT fetch frame 0 -> still fails
    $viewerId = (string) Str::uuid();
    $session = ReviewPreviewSession::forceCreate([
        'id' => $viewerId,
        'case_id' => $this->case->id,
        'section_id' => $this->section->id,
        'review_item_id' => $this->reviewItem->id,
        'candidate_id' => $this->candidate->id,
        'actor_user_id' => $this->teacher->id,
        'worker_session_ref' => $viewerId,
        'state' => 'ready',
        'first_frame_at' => null, // not fetched yet
        'last_frame_no' => 0,
        'expires_at' => now()->addMinutes(30),
        'idle_expires_at' => now()->addMinutes(15),
    ]);

    $resStillNoFrame = $this->actingAs($this->teacher)->postJson($url, [
        'schema_version' => 1,
        'idempotency_key' => $idempotencyKey,
        'expected_review_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'candidate_version' => 1,
        'decision' => 'approve',
        'feedback_kind' => null,
        'notes' => null,
    ]);
    expect($resStillNoFrame->status())->toBe(409)
        ->and($resStillNoFrame->json('error.code'))->toBe('review_conflict');

    // 3. Mark first_frame_at as verified
    $session->first_frame_at = now();
    $session->save();

    // Now approve succeeds!
    $resApproved = $this->actingAs($this->teacher)->postJson($url, [
        'schema_version' => 1,
        'idempotency_key' => $idempotencyKey,
        'expected_review_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'candidate_version' => 1,
        'decision' => 'approve',
        'feedback_kind' => null,
        'notes' => null,
    ]);

    expect($resApproved->status())->toBe(201)
        ->and($resApproved->headers->get('Cache-Control'))->toContain('no-store')
        ->and($resApproved->json('data.decision'))->toBe('approve')
        ->and($resApproved->json('data.review_ref'))->toBe($this->reviewRef)
        ->and($resApproved->json('data.approval_ref'))->not->toBeNull()
        ->and($resApproved->json('data.route_ref'))->toBeNull()
        ->and($resApproved->json('data.state'))->toBe('recorded')
        ->and($resApproved->json('data.notes'))->toBeNull()
        ->and($resApproved->json('data.feedback_kind'))->toBeNull();

    // Verify DB state
    $this->reviewItem->refresh();
    expect($this->reviewItem->status)->toBe('decided')
        ->and($this->reviewItem->decided_at)->not->toBeNull();

    $secState = ReviewSectionState::where('section_id', $this->section->id)->first();
    expect($secState->current_review_item_id)->toBeNull()
        ->and($secState->current_approval_id)->toBe($resApproved->json('data.approval_ref'))
        ->and($secState->state)->toBe('approved');

    // Verify outbox entry created
    $outbox = ReviewOutbox::where('review_decision_id', $resApproved->json('data.decision_ref'))->first();
    expect($outbox)->not->toBeNull()
        ->and($outbox->operation)->toBe('signal_decision')
        ->and($outbox->state)->toBe('pending');
});

test('R12: Request changes requires kind and normalized notes; does not require preview', function () {
    $url = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/decisions";
    $idempotencyKey = (string) Str::uuid();

    // 1. Missing notes or kind fails validation (422)
    $resMissingNotes = $this->actingAs($this->teacher)->postJson($url, [
        'schema_version' => 1,
        'idempotency_key' => $idempotencyKey,
        'expected_review_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'candidate_version' => 1,
        'decision' => 'request_changes',
        'feedback_kind' => 'visual_interaction',
        'notes' => null,
    ]);
    expect($resMissingNotes->status())->toBe(422);

    $resMissingKind = $this->actingAs($this->teacher)->postJson($url, [
        'schema_version' => 1,
        'idempotency_key' => $idempotencyKey,
        'expected_review_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'candidate_version' => 1,
        'decision' => 'request_changes',
        'feedback_kind' => null,
        'notes' => 'Some changes needed',
    ]);
    expect($resMissingKind->status())->toBe(422);

    // 2. Valid request_changes with CRLF note succeeds (NO preview session required!)
    $noteWithCrlf = "First line\r\nSecond line\r\nThird line";
    $res = $this->actingAs($this->teacher)->postJson($url, [
        'schema_version' => 1,
        'idempotency_key' => $idempotencyKey,
        'expected_review_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'candidate_version' => 1,
        'decision' => 'request_changes',
        'feedback_kind' => 'visual_interaction',
        'notes' => $noteWithCrlf,
    ]);

    expect($res->status())->toBe(201)
        ->and($res->headers->get('Cache-Control'))->toContain('no-store')
        ->and($res->json('data.decision'))->toBe('request_changes')
        ->and($res->json('data.approval_ref'))->toBeNull()
        ->and($res->json('data.route_ref'))->not->toBeNull()
        ->and($res->json('data.state'))->toBe('recorded')
        ->and($res->json('data.notes'))->toBeNull()
        ->and($res->json('data.feedback_kind'))->toBeNull();

    // Verify Route created
    $route = ReviewRoute::find($res->json('data.route_ref'));
    expect($route)->not->toBeNull()
        ->and($route->kind)->toBe('scene_repair')
        ->and($route->state)->toBe('pending');

    // Verify stored note has LF normalization
    $decision = TeacherReviewDecision::find($res->json('data.decision_ref'));
    expect($decision)->not->toBeNull()
        ->and($decision->note_path)->not->toBeNull();

    $savedNote = Storage::disk('v3_private')->get($decision->note_path);
    expect($savedNote)->toBe("First line\nSecond line\nThird line")
        ->and($decision->note_sha256)->toBe(hash('sha256', "First line\nSecond line\nThird line"))
        ->and($decision->note_bytes)->toBe(strlen("First line\nSecond line\nThird line"));
});

test('R14: Idempotency replay with same key and body returns 200; changed body returns 409', function () {
    $url = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/decisions";
    $idempotencyKey = (string) Str::uuid();

    $body = [
        'schema_version' => 1,
        'idempotency_key' => $idempotencyKey,
        'expected_review_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'candidate_version' => 1,
        'decision' => 'request_changes',
        'feedback_kind' => 'factual_source',
        'notes' => 'The formula on the board has an error in exponent.',
    ];

    // First call -> 201
    $res1 = $this->actingAs($this->teacher)->postJson($url, $body);
    expect($res1->status())->toBe(201);
    $decRef = $res1->json('data.decision_ref');

    // Replay exact same body -> 200 OK with identical decision_ref
    $res2 = $this->actingAs($this->teacher)->postJson($url, $body);
    expect($res2->status())->toBe(200)
        ->and($res2->json('data.decision_ref'))->toBe($decRef)
        ->and($res2->json('data.route_ref'))->toBe($res1->json('data.route_ref'));

    // Replay with SAME idempotency key but CHANGED notes -> 409 Conflict
    $changedBody = $body;
    $changedBody['notes'] = 'Completely different note content.';
    $res3 = $this->actingAs($this->teacher)->postJson($url, $changedBody);
    expect($res3->status())->toBe(409)
        ->and($res3->json('error.code'))->toBe('review_conflict');

    // Call with DIFFERENT idempotency key on now-decided item -> 409 Conflict
    $differentKeyBody = $body;
    $differentKeyBody['idempotency_key'] = (string) Str::uuid();
    $res4 = $this->actingAs($this->teacher)->postJson($url, $differentKeyBody);
    expect($res4->status())->toBe(409)
        ->and($res4->json('error.code'))->toBe('review_conflict');
});

test('R16: Browser form POST returns 303 redirect back to review list', function () {
    $url = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/decisions";
    $idempotencyKey = (string) Str::uuid();

    $body = [
        'schema_version' => 1,
        'idempotency_key' => $idempotencyKey,
        'expected_review_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'candidate_version' => 1,
        'decision' => 'request_changes',
        'feedback_kind' => 'mixed',
        'notes' => 'Mixed feedback text',
    ];

    $res = $this->actingAs($this->teacher)->post($url, $body, [
        'Accept' => 'text/html',
    ]);

    expect($res->status())->toBe(303)
        ->and($res->headers->get('Location'))->toContain("/v3/teacher/cases/{$this->case->id}/reviews");
});
