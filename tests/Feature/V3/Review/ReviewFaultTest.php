<?php

namespace Tests\Feature\V3\Review;

use App\Models\V3\ReviewOutbox;
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
        'run_id' => 'run-fault-test',
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
});

test('R13: Rollback on database or outbox failure leaves review pending and creates no decision row', function () {
    $url = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/decisions";
    $idempotencyKey = (string) Str::uuid();

    // Hook into ReviewOutbox::saving or DB table to force a failure
    ReviewOutbox::saving(function () {
        throw new \RuntimeException('Simulated outbox storage failure');
    });

    $res = $this->actingAs($this->teacher)->postJson($url, [
        'schema_version' => 1,
        'idempotency_key' => $idempotencyKey,
        'expected_review_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'candidate_version' => 1,
        'decision' => 'request_changes',
        'feedback_kind' => 'visual_interaction',
        'notes' => 'Testing transaction rollback on error',
    ]);

    // Expect 500 or 503 error
    expect(in_array($res->status(), [500, 503]))->toBeTrue();

    // Review item must remain pending
    $this->reviewItem->refresh();
    expect($this->reviewItem->status)->toBe('pending')
        ->and($this->reviewItem->decided_at)->toBeNull();

    // No decision row must exist
    expect(TeacherReviewDecision::count())->toBe(0);

    // Section state current_review_item_id still points to this item
    $secState = ReviewSectionState::where('section_id', $this->section->id)->first();
    expect($secState->current_review_item_id)->toBe($this->reviewItem->id)
        ->and($secState->current_approval_id)->toBeNull();
});
