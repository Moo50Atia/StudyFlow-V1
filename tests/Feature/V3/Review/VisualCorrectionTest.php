<?php

namespace Tests\Feature\V3\Review;

use App\Models\V3\ReviewRoute;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneRepair;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\WorkflowLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AuthoringFixtures;
use Tests\Support\V3\ReviewFixtures;
use Tests\Support\V3\WorkflowFixtures;
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
        'run_id' => 'run-visual-test',
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

    // Submit request_changes with visual_interaction
    $decUrl = "/v3/teacher/cases/{$this->case->id}/reviews/{$this->reviewRef}/decisions";
    $this->idempotencyKey = (string) Str::uuid();
    $decRes = $this->actingAs($this->teacher)->postJson($decUrl, [
        'schema_version' => 1,
        'idempotency_key' => $this->idempotencyKey,
        'expected_review_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'candidate_version' => 1,
        'decision' => 'request_changes',
        'feedback_kind' => 'visual_interaction',
        'notes' => 'Please increase button contrast and fix alignment.',
    ]);
    expect($decRes->status())->toBe(201);
    $this->routeRef = $decRes->json('data.route_ref');
    expect($this->routeRef)->not->toBeNull();
});

test('R17: Worker claims visual scene repair from teacher route with v2 criteria', function () {
    $repairUrl = "/api/internal/v1/cases/{$this->case->id}/teacher-scene-repairs";

    // 1. Claim scene repair via signed internal route
    $claimRes = ReviewFixtures::postSigned($this, $repairUrl, [
        'schema_version' => 1,
        'route_ref' => $this->routeRef,
    ]);

    expect($claimRes->status())->toBe(201)
        ->and($claimRes->json('data.repair_ref'))->not->toBeNull()
        ->and($claimRes->json('data.authoring_request_ref'))->not->toBeNull()
        ->and($claimRes->json('data.ordinal'))->toBe(1);

    $repairRef = $claimRes->json('data.repair_ref');

    // Verify SceneRepair persisted with v2 criteria
    $repair = SceneRepair::find($repairRef);
    expect($repair)->not->toBeNull()
        ->and($repair->from_candidate_id)->toBe($this->candidate->id)
        ->and($repair->ordinal)->toBe(1)
        ->and($repair->teacher_review_route_id)->toBe($this->routeRef);

    // Verify criteria on v3_private
    $criteriaJson = Storage::disk('v3_private')->get($repair->criteria_path);
    $criteria = json_decode($criteriaJson, true);
    expect($criteria['schema_version'])->toBe(2)
        ->and($criteria['reason'])->toBe('teacher_visual')
        ->and($criteria['teacher_note'])->toBe('Please increase button contrast and fix alignment.')
        ->and($criteria['source_route_ref'])->toBe($this->routeRef);

    // Verify route marked claimed
    $route = ReviewRoute::find($this->routeRef);
    expect($route->state)->toBe('claimed')
        ->and($route->target_ref)->toBe($repairRef);

    // The correction activity combines this teacher note with the captured
    // scene prompt returned by the authoring request context.
    $contextPath = "/api/internal/v1/cases/{$this->case->id}/scene-repairs/{$repairRef}/context";
    $contextHeaders = WorkflowFixtures::signCallbackRequest('GET', $contextPath, []);
    $contextRes = $this->withHeaders($contextHeaders)->get($contextPath);
    expect($contextRes->status())->toBe(200)
        ->and($contextRes->json('data.teacher_note'))->toBe('Please increase button contrast and fix alignment.');

    // 2. Exact replay returns 200 with same repair_ref
    $replayRes = ReviewFixtures::postSigned($this, $repairUrl, [
        'schema_version' => 1,
        'route_ref' => $this->routeRef,
    ]);
    expect($replayRes->status())->toBe(200)
        ->and($replayRes->json('data.repair_ref'))->toBe($repairRef);
});

test('R18: Repair budget 0 or exhausted blocks route and candidate without auto-approval', function () {
    // Create settings version with repair budget 0
    $payload = $this->env['settingsVersion']->payload;
    $payload['limits']['scene_repairs'] = 0;
    $newSettings = \App\Models\V3\SettingsVersion::forceCreate([
        'version' => 99,
        'schema_version' => 1,
        'checksum' => str_repeat('0', 64),
        'payload' => $payload,
        'published_by_user_id' => $this->env['admin']->id,
        'published_at' => now(),
    ]);
    DB::table('v3_processing_cases')->where('id', $this->case->id)->update(['settings_version_id' => $newSettings->id]);

    $repairUrl = "/api/internal/v1/cases/{$this->case->id}/teacher-scene-repairs";

    $res = ReviewFixtures::postSigned($this, $repairUrl, [
        'schema_version' => 1,
        'route_ref' => $this->routeRef,
    ]);

    expect($res->status())->toBe(409)
        ->and($res->json('error.code'))->toBe('repair_conflict');

    $route = ReviewRoute::find($this->routeRef);
    expect($route->state)->toBe('blocked')
        ->and($route->error_code)->toBe('validation');

    $candState = SceneCandidateState::where('candidate_id', $this->candidate->id)->first();
    expect($candState->status)->toBe('blocked');
});
