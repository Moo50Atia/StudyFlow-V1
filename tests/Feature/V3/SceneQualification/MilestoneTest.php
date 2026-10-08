<?php

namespace Tests\Feature\V3\SceneQualification;

use App\Models\V3\CaseEvent;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckAttempt;
use App\Models\V3\SceneCheckRun;
use App\Models\V3\SceneQualification;
use App\Models\V3\WorkflowLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\SceneQualificationFixtures;
use Tests\Support\V3\WorkflowFixtures;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');
    $this->env = SceneQualificationFixtures::createCandidateEnvironment();
    $this->case = $this->env['case'];
    $this->candidate = $this->env['candidate'];

    WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-qual-test',
        'settings_version_id' => $this->case->settings_version_id,
        'stage_code' => 'scene_qualification',
        'status_code' => 'running',
        'start_state' => 'started',
        'started_at' => now(),
        'last_event_at' => now(),
    ]);
});

test('S22: forged scene check started event without persisted attempt returns 422', function () {
    $eventsPath = "/api/internal/v1/case-events";
    $fakeCandidateRef = (string) Str::uuid();

    $body = [
        'schema_version' => 1,
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-qual-test',
        'event_key' => "scene:check:{$fakeCandidateRef}:started",
        'expected_state_version' => $this->case->state_version,
        'event_type' => 'stage_started',
        'stage_code' => 'scene_qualification',
        'status_code' => 'running',
        'error_code' => null,
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', $eventsPath, $body);
    $response = $this->postJson($eventsPath, $body, $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('contract_invalid');
});

test('S22: valid scene check started milestone records event', function () {
    // Create attempt
    SceneCheckAttempt::forceCreate([
        'id' => (string) Str::uuid(),
        'candidate_id' => $this->candidate->id,
        'case_id' => $this->case->id,
        'attempt_no' => 1,
        'manifest_sha256' => $this->candidate->manifest_sha256,
        'request_sha256' => str_repeat('a', 64),
        'checker_version' => 'chromium-pw-1.63.0-v1',
        'state' => 'claimed',
    ]);

    $eventsPath = "/api/internal/v1/case-events";
    $body = [
        'schema_version' => 1,
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-qual-test',
        'event_key' => "scene:check:{$this->candidate->id}:started",
        'expected_state_version' => $this->case->state_version,
        'event_type' => 'stage_started',
        'stage_code' => 'scene_qualification',
        'status_code' => 'running',
        'error_code' => null,
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', $eventsPath, $body);
    $response = $this->postJson($eventsPath, $body, $headers);

    $response->assertStatus(201);
});
