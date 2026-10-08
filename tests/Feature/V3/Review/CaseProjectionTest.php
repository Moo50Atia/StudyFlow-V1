<?php

namespace Tests\Feature\V3\Review;

use App\Models\V3\ApprovedScene;
use App\Models\V3\LessonCursor;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\TeacherReviewDecision;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\WorkflowLink;
use App\Services\V3\Review\ProjectReviewEvent;
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
    $this->case = $this->env['case'];
    $this->map = $this->env['map'];

    WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-test-1',
        'settings_version_id' => $this->env['settingsVersion']->id,
        'stage_code' => 'scene_qualification',
        'status_code' => 'running',
        'start_state' => 'started',
        'started_at' => now(),
        'last_event_at' => now(),
    ]);
});

test('R15: one section approval leaves sibling pending and case awaiting review; all current sections approved yields complete/approved', function () {
    $q1 = ReviewFixtures::createQualifiedCandidate($this->env, 1);
    $q2 = ReviewFixtures::createQualifiedCandidate($this->env, 2);

    // Create complete cursor
    LessonCursor::forceCreate([
        'case_id' => $this->case->id,
        'map_id' => $this->map->id,
        'current_work_ordinal' => 2,
        'phase' => 'complete',
        'version' => 1,
    ]);

    $readyPath = "/api/internal/v1/cases/{$this->case->id}/reviews/ready";
    $res1 = ReviewFixtures::postSigned($this, $readyPath, ['schema_version' => 1, 'candidate_ref' => $q1['candidate']->id]);
    $review1Ref = $res1->json('data.review_ref');

    $res2 = ReviewFixtures::postSigned($this, $readyPath, ['schema_version' => 1, 'candidate_ref' => $q2['candidate']->id]);
    $review2Ref = $res2->json('data.review_ref');

    $projector = app(ProjectReviewEvent::class);

    // Initial ready projection transitions case to teacher_review / awaiting_review
    $projector->projectReady($this->case->id, $review1Ref);
    $link = WorkflowLink::where('case_id', $this->case->id)->first();
    expect($link->stage_code)->toBe('teacher_review')
        ->and($link->status_code)->toBe('awaiting_review');

    // Section 1 is approved
    $dec1 = TeacherReviewDecision::forceCreate([
        'id' => (string) Str::uuid(),
        'review_item_id' => $review1Ref,
        'case_id' => $this->case->id,
        'section_id' => $q1['section']->id,
        'candidate_id' => $q1['candidate']->id,
        'qualification_id' => $q1['qualification']->id,
        'actor_user_id' => $this->env['teacher']->id,
        'candidate_version' => 1,
        'expected_review_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'request_sha256' => str_repeat('a', 64),
        'decision' => 'approve',
        'created_at' => now(),
    ]);

    $appr1 = ApprovedScene::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'section_id' => $q1['section']->id,
        'candidate_id' => $q1['candidate']->id,
        'qualification_id' => $q1['qualification']->id,
        'review_decision_id' => $dec1->id,
        'candidate_version' => 1,
        'approved_at' => now(),
    ]);

    ReviewSectionState::where('section_id', $q1['section']->id)->update([
        'current_review_item_id' => null,
        'current_approval_id' => $appr1->id,
        'state' => 'approved',
    ]);

    $projector->projectDecision($this->case->id, $dec1->id);
    $link->refresh();

    // Section 2 is still pending, so case remains teacher_review / awaiting_review
    expect($link->stage_code)->toBe('teacher_review')
        ->and($link->status_code)->toBe('awaiting_review')
        ->and($link->approved_scene_count)->toBe(1);

    // Section 2 is now approved
    $dec2 = TeacherReviewDecision::forceCreate([
        'id' => (string) Str::uuid(),
        'review_item_id' => $review2Ref,
        'case_id' => $this->case->id,
        'section_id' => $q2['section']->id,
        'candidate_id' => $q2['candidate']->id,
        'qualification_id' => $q2['qualification']->id,
        'actor_user_id' => $this->env['teacher']->id,
        'candidate_version' => 1,
        'expected_review_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'request_sha256' => str_repeat('b', 64),
        'decision' => 'approve',
        'created_at' => now(),
    ]);

    $appr2 = ApprovedScene::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'section_id' => $q2['section']->id,
        'candidate_id' => $q2['candidate']->id,
        'qualification_id' => $q2['qualification']->id,
        'review_decision_id' => $dec2->id,
        'candidate_version' => 1,
        'approved_at' => now(),
    ]);

    ReviewSectionState::where('section_id', $q2['section']->id)->update([
        'current_review_item_id' => null,
        'current_approval_id' => $appr2->id,
        'state' => 'approved',
    ]);

    $projector->projectDecision($this->case->id, $dec2->id);
    $link->refresh();

    // Both sections approved: case reaches complete / approved!
    expect($link->stage_code)->toBe('complete')
        ->and($link->status_code)->toBe('approved')
        ->and($link->approved_scene_count)->toBe(2);
});

test('R13: Request changes decision projects teacher_review/running state', function () {
    $q1 = ReviewFixtures::createQualifiedCandidate($this->env, 1);
    $readyPath = "/api/internal/v1/cases/{$this->case->id}/reviews/ready";
    $res = ReviewFixtures::postSigned($this, $readyPath, ['schema_version' => 1, 'candidate_ref' => $q1['candidate']->id]);
    $reviewRef = $res->json('data.review_ref');

    $dec = TeacherReviewDecision::forceCreate([
        'id' => (string) Str::uuid(),
        'review_item_id' => $reviewRef,
        'case_id' => $this->case->id,
        'section_id' => $q1['section']->id,
        'candidate_id' => $q1['candidate']->id,
        'qualification_id' => $q1['qualification']->id,
        'actor_user_id' => $this->env['teacher']->id,
        'candidate_version' => 1,
        'expected_review_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'request_sha256' => str_repeat('c', 64),
        'decision' => 'request_changes',
        'feedback_kind' => 'visual_interaction',
        'created_at' => now(),
    ]);

    $projector = app(ProjectReviewEvent::class);
    $projector->projectDecision($this->case->id, $dec->id);

    $link = WorkflowLink::where('case_id', $this->case->id)->first();
    expect($link->stage_code)->toBe('teacher_review')
        ->and($link->status_code)->toBe('running');
});

test('R15: projectAllBlocked sets teacher_review/blocked/validation', function () {
    $projector = app(ProjectReviewEvent::class);
    $projector->projectAllBlocked($this->case->id);

    $link = WorkflowLink::where('case_id', $this->case->id)->first();
    expect($link->stage_code)->toBe('teacher_review')
        ->and($link->status_code)->toBe('blocked')
        ->and($link->error_code)->toBe('validation');
});

