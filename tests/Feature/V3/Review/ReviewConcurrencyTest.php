<?php

namespace Tests\Feature\V3\Review;

use App\Models\V3\ReviewPreviewSession;
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
        'run_id' => 'run-concurrency-test',
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

    $readyPath = "/api/internal/v1/cases/{$this->case->id}/reviews/ready";
    $readyRes = ReviewFixtures::postSigned($this, $readyPath, [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
    ]);
    expect($readyRes->status())->toBe(201);
    $this->reviewRef = $readyRes->json('data.review_ref');
    $this->reviewItem = TeacherReviewItem::find($this->reviewRef);

    // Create session with verified frame 0
    $viewerId = (string) Str::uuid();
    ReviewPreviewSession::forceCreate([
        'id' => $viewerId,
        'case_id' => $this->case->id,
        'section_id' => $this->section->id,
        'review_item_id' => $this->reviewItem->id,
        'candidate_id' => $this->candidate->id,
        'actor_user_id' => $this->teacher->id,
        'worker_session_ref' => $viewerId,
        'state' => 'ready',
        'first_frame_at' => now(),
        'last_frame_no' => 0,
        'expires_at' => now()->addMinutes(30),
        'idle_expires_at' => now()->addMinutes(15),
    ]);
});

test('R14: Competing concurrent decisions on same review: exactly one wins, second conflicts', function () {
    $url = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/decisions";

    $key1 = (string) Str::uuid();
    $key2 = (string) Str::uuid();

    $body1 = [
        'schema_version' => 1,
        'idempotency_key' => $key1,
        'expected_review_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'candidate_version' => 1,
        'decision' => 'approve',
        'feedback_kind' => null,
        'notes' => null,
    ];

    $body2 = [
        'schema_version' => 1,
        'idempotency_key' => $key2,
        'expected_review_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'candidate_version' => 1,
        'decision' => 'request_changes',
        'feedback_kind' => 'visual_interaction',
        'notes' => 'Competing request',
    ];

    // First request succeeds
    $res1 = $this->actingAs($this->teacher)->postJson($url, $body1);
    expect($res1->status())->toBe(201);

    // Second request with different idempotency key on now-decided item fails with 409
    $res2 = $this->actingAs($this->teacher)->postJson($url, $body2);
    expect($res2->status())->toBe(409)
        ->and($res2->json('error.code'))->toBe('review_conflict');

    // Exactly one decision row exists
    expect(TeacherReviewDecision::where('review_item_id', $this->reviewRef)->count())->toBe(1);
});
