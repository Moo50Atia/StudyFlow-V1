<?php

namespace Tests\Feature\V3\Review;

use App\Models\V3\ApprovedScene;
use App\Models\V3\PostapprovalCorrection;
use App\Models\V3\ReviewOutbox;
use App\Models\V3\ReviewRoute;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\TeacherReviewDecision;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\WorkflowLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Services\V3\Review\DispatchReviewEffects;
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
        'run_id' => 'run-postapproval-test',
        'settings_version_id' => $this->env['settingsVersion']->id,
        'stage_code' => 'scene_qualification',
        'status_code' => 'running',
        'start_state' => 'started',
        'started_at' => now(),
        'last_event_at' => now(),
    ]);

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

    // Simulate preview frame 0 outward GET proof
    $viewerId = (string) Str::uuid();
    \App\Models\V3\ReviewPreviewSession::forceCreate([
        'id' => $viewerId,
        'case_id' => $this->case->id,
        'section_id' => $this->section->id,
        'review_item_id' => $this->reviewRef,
        'candidate_id' => $this->candidate->id,
        'actor_user_id' => $this->teacher->id,
        'worker_session_ref' => $viewerId,
        'state' => 'ready',
        'first_frame_at' => now(),
        'last_frame_no' => 0,
        'expires_at' => now()->addMinutes(30),
        'idle_expires_at' => now()->addMinutes(15),
    ]);

    // Approve the scene
    $decUrl = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/decisions";
    $decRes = $this->actingAs($this->teacher)->postJson($decUrl, [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_review_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'candidate_version' => 1,
        'decision' => 'approve',
    ]);
    expect($decRes->status())->toBe(201);
    $this->approvalRef = $decRes->json('data.approval_ref');
    expect($this->approvalRef)->not->toBeNull();
});

test('R25: Owner lists current approved scenes and submits postapproval correction', function () {
    // 1. List approved scenes
    $listUrl = "/v3/teacher/cases/{$this->case->id}/approved-scenes";
    $listRes = $this->actingAs($this->teacher)->getJson($listUrl);
    expect($listRes->status())->toBe(200);

    $items = $listRes->json('data');
    expect(count($items))->toBe(1)
        ->and($items[0]['approval_ref'])->toBe($this->approvalRef)
        ->and($items[0]['review_ref'])->toBe($this->reviewRef)
        ->and($items[0]['section_ref'])->toBe($this->section->id)
        ->and($items[0]['candidate_ref'])->toBe($this->candidate->id);

    // 2. Submit post-approval correction
    $correctUrl = "/v3/teacher/cases/{$this->case->id}/approved-scenes/{$this->approvalRef}/corrections";
    $idempotencyKey = (string) Str::uuid();
    $noteText = "Later review: please increase label size on the canvas.";

    // Current section state_version after approval is 2
    $secState = ReviewSectionState::where('section_id', $this->section->id)->first();
    $expectedSecVer = $secState->state_version;

    $correctRes = $this->actingAs($this->teacher)->postJson($correctUrl, [
        'schema_version' => 1,
        'idempotency_key' => $idempotencyKey,
        'expected_section_version' => $expectedSecVer,
        'feedback_kind' => 'visual_interaction',
        'notes' => $noteText,
    ]);

    expect($correctRes->status())->toBe(201)
        ->and($correctRes->json('data.correction_ref'))->not->toBeNull()
        ->and($correctRes->json('data.route_ref'))->not->toBeNull()
        ->and($correctRes->json('data.state'))->toBe('accepted');

    $correctionRef = $correctRes->json('data.correction_ref');
    $routeRef = $correctRes->json('data.route_ref');

    // 3. Verify section state: current_approval_id cleared!
    $secState->refresh();
    expect($secState->current_approval_id)->toBeNull()
        ->and($secState->state)->toBe('routing');

    // 4. Verify old approval row remains in DB (historical evidence)
    $approval = ApprovedScene::find($this->approvalRef);
    expect($approval)->not->toBeNull()
        ->and($approval->candidate_id)->toBe($this->candidate->id);

    // 5. Verify ReviewRoute created
    $route = ReviewRoute::find($routeRef);
    expect($route)->not->toBeNull()
        ->and($route->postapproval_correction_id)->toBe($correctionRef)
        ->and($route->review_decision_id)->toBeNull()
        ->and($route->kind)->toBe('scene_repair')
        ->and($route->state)->toBe('pending');

    // 6. Verify ReviewOutbox created for start_correction
    $outbox = ReviewOutbox::where('postapproval_correction_id', $correctionRef)->first();
    expect($outbox)->not->toBeNull()
        ->and($outbox->operation)->toBe('start_correction')
        ->and($outbox->workflow_id)->toBe("v3-review-correction-{$correctionRef}")
        ->and($outbox->state)->toBe('pending');

    // 7. Exact replay with same idempotency key returns 200
    $replayRes = $this->actingAs($this->teacher)->postJson($correctUrl, [
        'schema_version' => 1,
        'idempotency_key' => $idempotencyKey,
        'expected_section_version' => $expectedSecVer,
        'feedback_kind' => 'visual_interaction',
        'notes' => $noteText,
    ]);
    expect($replayRes->status())->toBe(200)
        ->and($replayRes->json('data.correction_ref'))->toBe($correctionRef);

    // 8. Conflicting replay with same idempotency key returns 409
    $conflictRes = $this->actingAs($this->teacher)->postJson($correctUrl, [
        'schema_version' => 1,
        'idempotency_key' => $idempotencyKey,
        'expected_section_version' => $expectedSecVer,
        'feedback_kind' => 'factual_source',
        'notes' => 'Completely different payload.',
    ]);
    expect($conflictRes->status())->toBe(409);
});

test('R25: Postapproval correction rejected if approval is no longer section-current', function () {
    // Clear current approval pointer to simulate stale approval
    ReviewSectionState::where('section_id', $this->section->id)->update([
        'current_approval_id' => null,
    ]);

    $correctUrl = "/v3/teacher/cases/{$this->case->id}/approved-scenes/{$this->approvalRef}/corrections";
    $correctRes = $this->actingAs($this->teacher)->postJson($correctUrl, [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_section_version' => (int) ReviewSectionState::where('section_id', $this->section->id)->value('state_version'),
        'feedback_kind' => 'visual_interaction',
        'notes' => 'Note on already cleared approval.',
    ]);

    expect($correctRes->status())->toBe(409);
});

test('R25: Non-owner access to approved-scenes and corrections is denied with no-store', function () {
    $otherTeacher = \Tests\Support\V3\AccessFixtures::createEnrolledTeacher('Other Teacher');

    // 1. Foreign teacher GET approved-scenes -> 404
    $listUrl = "/v3/teacher/cases/{$this->case->id}/approved-scenes";
    $listRes = $this->actingAs($otherTeacher)->getJson($listUrl);
    expect($listRes->status())->toBe(404)
        ->and($listRes->headers->get('Cache-Control'))->toContain('no-store');

    // 2. Foreign teacher POST corrections -> 404
    $correctUrl = "/v3/teacher/cases/{$this->case->id}/approved-scenes/{$this->approvalRef}/corrections";
    $correctRes = $this->actingAs($otherTeacher)->postJson($correctUrl, [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_section_version' => 2,
        'feedback_kind' => 'visual_interaction',
        'notes' => 'Unauthorized correction.',
    ]);
    expect($correctRes->status())->toBe(404)
        ->and($correctRes->headers->get('Cache-Control'))->toContain('no-store');

    // 3. Unauthenticated -> 401
    auth()->logout();
    $guestRes = $this->postJson($correctUrl, [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_section_version' => 2,
        'feedback_kind' => 'visual_interaction',
        'notes' => 'Guest attempt.',
    ]);
    expect($guestRes->status())->toBe(401);
});

test('R25: Postapproval correction succeeds even when original workflow is completed', function () {
    // Close original workflow run
    WorkflowLink::where('case_id', $this->case->id)->update([
        'status_code' => 'completed',
        'last_event_at' => now(),
    ]);

    $secState = ReviewSectionState::where('section_id', $this->section->id)->first();
    $correctUrl = "/v3/teacher/cases/{$this->case->id}/approved-scenes/{$this->approvalRef}/corrections";
    $correctRes = $this->actingAs($this->teacher)->postJson($correctUrl, [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_section_version' => $secState->state_version,
        'feedback_kind' => 'factual_source',
        'notes' => 'Factual correction after workflow closed.',
    ]);

    expect($correctRes->status())->toBe(201);
    $correctionRef = $correctRes->json('data.correction_ref');

    // Outbox starts standalone correction workflow, does NOT restart closed case workflow
    $outbox = ReviewOutbox::where('postapproval_correction_id', $correctionRef)->first();
    expect($outbox->workflow_id)->toBe("v3-review-correction-{$correctionRef}")
        ->and($outbox->operation)->toBe('start_correction');
});

test('R19: correction outbox starts and reconciles the deterministic correction workflow', function () {
    $url = "/v3/teacher/cases/{$this->case->id}/approved-scenes/{$this->approvalRef}/corrections";
    $response = $this->actingAs($this->teacher)->postJson($url, [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_section_version' => (int) ReviewSectionState::where('section_id', $this->section->id)->value('state_version'),
        'feedback_kind' => 'visual_interaction',
        'notes' => 'Clarify the interaction timing.',
    ]);
    expect($response->status())->toBe(201);
    $correctionRef = $response->json('data.correction_ref');
    $workflowId = "v3-review-correction-{$correctionRef}";
    $seen = null;
    Http::fake(['*/internal/v1/workflows/'.$workflowId.'/start-correction' => function ($request) use (&$seen, $correctionRef) {
        $seen = json_decode($request->body(), true);
        return Http::response(['data' => ['workflow_id' => 'x', 'correction_ref' => $correctionRef, 'accepted' => true]], 202);
    }]);

    expect(app(DispatchReviewEffects::class)->dispatchPending())->toBe(1);
    $outbox = ReviewOutbox::where('postapproval_correction_id', $correctionRef)->firstOrFail();
    expect($outbox->state)->toBe('delivered')
        ->and($seen['case_id'])->toBe($this->case->id)
        ->and($seen['section_ref'])->toBe($this->section->id)
        ->and($seen['correction_ref'])->toBe($correctionRef)
        ->and($seen['feedback_kind'])->toBe('visual_interaction')
        ->and(isset($seen['notes']))->toBeFalse();
});
