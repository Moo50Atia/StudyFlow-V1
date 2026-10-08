<?php

namespace Tests\Feature\V3\Review;

use App\Models\V3\ReviewSectionState;
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
        'run_id' => 'run-return-test',
        'settings_version_id' => $this->env['settingsVersion']->id,
        'stage_code' => 'scene_qualification',
        'status_code' => 'running',
        'start_state' => 'started',
        'started_at' => now(),
        'last_event_at' => now(),
    ]);

    // Candidate 1 qualified and ready
    $this->q1 = ReviewFixtures::createQualifiedCandidate($this->env, 1);
    $this->section = $this->q1['section'];
    $this->candidate1 = $this->q1['candidate'];

    $readyPath = "/api/internal/v1/cases/{$this->case->id}/reviews/ready";
    $readyRes1 = ReviewFixtures::postSigned($this, $readyPath, [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate1->id,
    ]);
    expect($readyRes1->status())->toBe(201);
    $this->reviewRef1 = $readyRes1->json('data.review_ref');

    // Submit request_changes
    $decUrl = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef1}/decisions";
    $this->actingAs($this->teacher)->postJson($decUrl, [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'expected_review_version' => 1,
        'candidate_ref' => $this->candidate1->id,
        'candidate_version' => 1,
        'decision' => 'request_changes',
        'feedback_kind' => 'visual_interaction',
        'notes' => 'Adjust contrast on button',
    ])->assertStatus(201);
});

test('R17: Successor qualified candidate creates new review item; old review remains decided', function () {
    // 1. Claim repair on route
    $repairUrl = "/api/internal/v1/cases/{$this->case->id}/teacher-scene-repairs";
    $route = \App\Models\V3\ReviewRoute::where('case_id', $this->case->id)->first();
    $repairRes = ReviewFixtures::postSigned($this, $repairUrl, [
        'schema_version' => 1,
        'route_ref' => $route->id,
    ]);
    expect($repairRes->status())->toBe(201);
    $authoringReqRef = $repairRes->json('data.authoring_request_ref');
    $repairRef = $repairRes->json('data.repair_ref');

    // 2. Claim attempt and record candidate 2
    $attemptService = app(\App\Services\V3\Authoring\ClaimCodingAttempt::class);
    $attRes = $attemptService->claim($this->case->id, $authoringReqRef, [
        'schema_version' => 1,
        'ordinal' => 1,
    ]);
    $attemptRef = $attRes['data']['attempt_ref'];

    $resultService = app(\App\Services\V3\Authoring\RecordAuthoringResult::class);
    $files = [
        [
            'path' => 'index.html',
            'content' => "<!DOCTYPE html><html lang=\"en\"><head><title>Repaired Scene</title></head><body><h1>Repaired</h1></body></html>",
        ],
    ];
    $payload = [
        'schema_version' => 1,
        'outcome' => 'candidate',
        'returned_model' => 'gemini-2.5-pro',
        'provider_response_id' => 'resp-' . Str::random(8),
        'provider_response_json' => ['id' => 'test'],
        'package' => [
            'schema_version' => 1,
            'files' => $files,
        ],
        'failure_code' => null,
        'usage' => ['input_tokens' => 500, 'output_tokens' => 200, 'cost_minor_units' => 5, 'currency' => 'USD'],
        'duration_ms' => 1000,
    ];
    $recRes = $resultService->record($this->case->id, $attemptRef, $payload, json_encode($payload, JSON_UNESCAPED_SLASHES));
    $candidate2Ref = $recRes['data']['candidate_ref'];
    $candidate2 = \App\Models\V3\SceneCandidate::find($candidate2Ref);

    // 3. Link candidate 2 to repair
    $linkService = app(\App\Services\V3\SceneQualification\LinkRepairCandidate::class);
    $linkService->link($this->case->id, $repairRef, ['candidate_ref' => $candidate2Ref]);

    // 4. Qualify candidate 2
    $checkAttempt2 = \App\Models\V3\SceneCheckAttempt::forceCreate([
        'id' => (string) Str::uuid(),
        'candidate_id' => $candidate2->id,
        'case_id' => $this->case->id,
        'attempt_no' => 1,
        'manifest_sha256' => $candidate2->manifest_sha256,
        'request_sha256' => str_repeat('a', 64),
        'checker_version' => 'chromium-pw-1.63.0-v1',
        'state' => 'recorded',
    ]);

    $checkRun2 = \App\Models\V3\SceneCheckRun::forceCreate([
        'id' => (string) Str::uuid(),
        'candidate_id' => $candidate2->id,
        'winning_attempt_id' => $checkAttempt2->id,
        'case_id' => $this->case->id,
        'section_id' => $this->section->id,
        'prompt_version_id' => $candidate2->prompt_version_id,
        'settings_version_id' => $this->case->settings_version_id,
        'manifest_sha256' => $candidate2->manifest_sha256,
        'report_sha256' => str_repeat('1', 64),
        'screenshot_sha256' => str_repeat('2', 64),
        'report_path' => "reports/{$candidate2->id}.json",
        'screenshot_path' => "screens/{$candidate2->id}.png",
        'verdict' => 'pass',
        'failed_checks' => [],
        'duration_ms' => 100,
    ]);

    $jevAttempt2 = \App\Models\V3\SceneJevAttempt::forceCreate([
        'id' => (string) Str::uuid(),
        'candidate_id' => $candidate2->id,
        'check_run_id' => $checkRun2->id,
        'case_id' => $this->case->id,
        'attempt_no' => 1,
        'request_sha256' => str_repeat('a', 64),
        'question_sha256' => str_repeat('a', 64),
        'evidence_sha256' => str_repeat('a', 64),
        'evidence_path' => "ev/{$candidate2->id}.json",
        'requested_model' => 'gemini-2.5-pro',
        'state' => 'recorded',
    ]);

    $qual2 = \App\Models\V3\SceneQualification::forceCreate([
        'id' => (string) Str::uuid(),
        'candidate_id' => $candidate2->id,
        'check_run_id' => $checkRun2->id,
        'winning_jev_attempt_id' => $jevAttempt2->id,
        'case_id' => $this->case->id,
        'section_id' => $this->section->id,
        'prompt_version_id' => $candidate2->prompt_version_id,
        'settings_version_id' => $this->case->settings_version_id,
        'settings_checksum' => str_repeat('3', 64),
        'manifest_sha256' => $candidate2->manifest_sha256,
        'evidence_sha256' => str_repeat('4', 64),
        'request_sha256' => str_repeat('5', 64),
        'question_sha256' => str_repeat('6', 64),
        'answer_sha256' => str_repeat('7', 64),
        'evidence_path' => "ev/{$candidate2->id}.json",
        'answer_path' => "ans/{$candidate2->id}.json",
        'requested_model' => 'gemini-2.5-pro',
        'returned_model' => 'gemini-2.5-pro',
        'choice' => 'pass',
        'choice_confidence' => 0.95,
        'score' => 95.0,
        'score_confidence' => 0.95,
        'noul_probability' => 0.95,
        'noul_yes' => true,
        'min_score' => 75,
        'min_confidence' => 70,
        'decision' => 'pass',
        'failed_checks' => [],
    ]);

    \App\Models\V3\SceneCandidateState::where('candidate_id', $candidate2->id)->update([
        'status' => 'qualified',
        'check_run_id' => $checkRun2->id,
        'qualification_id' => $qual2->id,
    ]);

    // 5. Call EnsureReviewReady for candidate 2
    $readyPath = "/api/internal/v1/cases/{$this->case->id}/reviews/ready";
    $readyRes2 = ReviewFixtures::postSigned($this, $readyPath, [
        'schema_version' => 1,
        'candidate_ref' => $candidate2->id,
    ]);

    expect($readyRes2->status())->toBe(201);
    $reviewRef2 = $readyRes2->json('data.review_ref');
    expect($reviewRef2)->not->toBe($this->reviewRef1);

    // Old review remains decided
    $item1 = TeacherReviewItem::find($this->reviewRef1);
    expect($item1->status)->toBe('decided');

    // New review item is pending
    $item2 = TeacherReviewItem::find($reviewRef2);
    expect($item2->status)->toBe('pending')
        ->and($item2->candidate_id)->toBe($candidate2->id);

    // Section state current pointer points to new review item
    $secState = ReviewSectionState::where('section_id', $this->section->id)->first();
    expect($secState->current_review_item_id)->toBe($reviewRef2)
        ->and($secState->state)->toBe('awaiting_review');
});
