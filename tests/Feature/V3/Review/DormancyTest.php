<?php

namespace Tests\Feature\V3\Review;

use App\Models\V3\ReviewSectionState;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\WorkflowLink;
use App\Services\V3\Review\MarkDormantReviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        'run_id' => 'run-dormancy-test',
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
    $this->reviewItem = TeacherReviewItem::find($this->reviewRef);
});

test('R24: Captured day threshold marks pending review dormant once', function () {
    // 1. Move ready_at back 15 days (dormancy limit in validPayload is 14 days)
    $pastDate = now()->subDays(15);
    $this->reviewItem->update(['ready_at' => $pastDate]);

    // 2. Run MarkDormantReviews service
    $service = app(MarkDormantReviews::class);
    $count = $service->mark();
    expect($count)->toBe(1);

    // 3. Verify item status is dormant and dormant_at is recorded
    $this->reviewItem->refresh();
    expect($this->reviewItem->status)->toBe('dormant')
        ->and($this->reviewItem->dormant_at)->not->toBeNull()
        ->and($this->reviewItem->state_version)->toBe(2);

    // 4. Replay is idempotent: second run marks 0 items
    $recount = $service->mark();
    expect($recount)->toBe(0);

    // 5. Artisan command executes without error
    $this->artisan('v3:mark-dormant-reviews')->assertExitCode(0);
});

test('R24: Item within captured threshold remains pending', function () {
    // Move ready_at back only 10 days (limit is 14 days)
    $recentDate = now()->subDays(10);
    $this->reviewItem->update(['ready_at' => $recentDate]);

    $service = app(MarkDormantReviews::class);
    $count = $service->mark();
    expect($count)->toBe(0);

    $this->reviewItem->refresh();
    expect($this->reviewItem->status)->toBe('pending')
        ->and($this->reviewItem->dormant_at)->toBeNull();
});

test('R24: Opening dormant review records last_opened_at without resetting ready_at or auto-approving', function () {
    $pastDate = now()->subDays(15);
    $this->reviewItem->update([
        'ready_at' => $pastDate,
        'status' => 'dormant',
        'dormant_at' => now()->subDay(),
    ]);

    $showUrl = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}";
    $showRes = $this->actingAs($this->teacher)->getJson($showUrl);
    expect($showRes->status())->toBe(200);

    $this->reviewItem->refresh();
    // last_opened_at is recorded
    expect($this->reviewItem->last_opened_at)->not->toBeNull()
        ->and($this->reviewItem->status)->toBe('dormant')
        ->and($this->reviewItem->ready_at->timestamp)->toBe($pastDate->timestamp);

    // Section state remains awaiting_review, no auto-approval
    $secState = ReviewSectionState::where('section_id', $this->section->id)->first();
    expect($secState->current_approval_id)->toBeNull()
        ->and($secState->state)->toBe('awaiting_review');
});

test('R24: Inactive original owner remains assigned; no admin takeover or auto-approval', function () {
    $pastDate = now()->subDays(15);
    $this->reviewItem->update(['ready_at' => $pastDate]);

    // Deactivate teacher
    DB::table('v3_account_access')->where('user_id', $this->teacher->id)->update([
        'is_active' => false,
    ]);

    $service = app(MarkDormantReviews::class);
    $count = $service->mark();
    expect($count)->toBe(1);

    // Review item still points to the same case and owner
    $this->reviewItem->refresh();
    expect($this->reviewItem->status)->toBe('dormant')
        ->and($this->case->refresh()->owner_user_id)->toBe($this->teacher->id);
});
