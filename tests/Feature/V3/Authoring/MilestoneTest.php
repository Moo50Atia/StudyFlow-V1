<?php

namespace Tests\Feature\V3\Authoring;

use App\Models\V3\AuthoringRequest;
use App\Models\V3\CaseEvent;
use App\Models\V3\SceneCandidate;
use App\Services\V3\Authoring\ClaimAuthoringRequest;
use App\Services\V3\Authoring\ClaimCodingAttempt;
use App\Services\V3\Authoring\RecordAuthoringResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AuthoringFixtures;
use Tests\Support\V3\WorkflowFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');

    $env = AuthoringFixtures::createAuthoringEnvironment();
    $this->teacher = $env['teacher'];
    $this->admin = $env['admin'];
    $this->settingsVersion = $env['settingsVersion'];
    $this->case = $env['case'];
    $this->map = $env['map'];

    $this->sec1 = AuthoringFixtures::createQualifiedSection($this->case, $this->map, 1, 'Prompt 1', true);

    $claimService = app(ClaimAuthoringRequest::class);
    $res = $claimService->claimInitial($this->case->id, $this->sec1['version']->id);
    $this->request = $res['request'];

    $attemptService = app(ClaimCodingAttempt::class);
    $attRes = $attemptService->claim($this->case->id, $this->request->id, [
        'schema_version' => 1,
        'ordinal' => 1,
    ]);
    $this->attemptRef = $attRes['data']['attempt_ref'];

    \App\Models\V3\WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-test-1',
        'settings_version_id' => $this->settingsVersion->id,
        'stage_code' => 'prompt_qualification',
        'status_code' => 'running',
        'start_state' => 'started',
        'started_at' => now(),
        'last_event_at' => now(),
    ]);
});

test('A17: forged scene request started without persisted request is rejected with 422', function () {
    $eventsPath = "/api/internal/v1/case-events";
    $fakeRequestRef = (string) Str::uuid();

    $body = [
        'schema_version' => 1,
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-test-1',
        'event_key' => "scene:request:{$fakeRequestRef}:started",
        'expected_state_version' => $this->case->state_version,
        'event_type' => 'stage_started',
        'stage_code' => 'scene_generation',
        'status_code' => 'running',
        'error_code' => null,
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', $eventsPath, $body);
    $response = $this->postJson($eventsPath, $body, $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('contract_invalid');
});

test('A17: valid scene request started milestone records event and updates workflow projection', function () {
    $eventsPath = "/api/internal/v1/case-events";

    $body = [
        'schema_version' => 1,
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-test-1',
        'event_key' => "scene:request:{$this->request->id}:started",
        'expected_state_version' => $this->case->state_version,
        'event_type' => 'stage_started',
        'stage_code' => 'scene_generation',
        'status_code' => 'running',
        'error_code' => null,
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', $eventsPath, $body);
    $response = $this->postJson($eventsPath, $body, $headers);

    $response->assertStatus(201);
    expect($response->json('data.event_key'))->toBe("scene:request:{$this->request->id}:started");

    // Idempotent duplicate returns 200 with prior event
    $replayResponse = $this->postJson($eventsPath, $body, $headers);
    $replayResponse->assertStatus(200);
    expect($replayResponse->json('data.event_key'))->toBe("scene:request:{$this->request->id}:started");
});

test('A17: forged scene candidate milestone without persisted candidate is rejected with 422', function () {
    $eventsPath = "/api/internal/v1/case-events";

    // Request exists, but candidate does NOT yet exist
    $body = [
        'schema_version' => 1,
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-test-1',
        'event_key' => "scene:request:{$this->request->id}:candidate",
        'expected_state_version' => $this->case->state_version,
        'event_type' => 'stage_completed',
        'stage_code' => 'scene_generation',
        'status_code' => 'queued',
        'error_code' => null,
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', $eventsPath, $body);
    $response = $this->postJson($eventsPath, $body, $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('contract_invalid');
});

test('A17: valid scene candidate milestone succeeds once candidate exists', function () {
    // Record candidate first
    $recordService = app(RecordAuthoringResult::class);
    $resultPayload = [
        'schema_version' => 1,
        'outcome' => 'candidate',
        'returned_model' => 'gemini-2.5-pro',
        'provider_response_id' => 'resp-milestone',
        'provider_response_json' => [],
        'package' => [
            'schema_version' => 1,
            'files' => [
                ['path' => 'index.html', 'content' => '<!DOCTYPE html><html><body>Milestone</body></html>'],
            ],
        ],
        'failure_code' => null,
        'usage' => null,
        'duration_ms' => 400,
    ];
    $rawBytes = json_encode($resultPayload, JSON_UNESCAPED_SLASHES);
    $recordService->record($this->case->id, $this->attemptRef, $resultPayload, $rawBytes);

    $eventsPath = "/api/internal/v1/case-events";
    $body = [
        'schema_version' => 1,
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-test-1',
        'event_key' => "scene:request:{$this->request->id}:candidate",
        'expected_state_version' => $this->case->state_version,
        'event_type' => 'stage_completed',
        'stage_code' => 'scene_generation',
        'status_code' => 'queued',
        'error_code' => null,
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', $eventsPath, $body);
    $response = $this->postJson($eventsPath, $body, $headers);

    $response->assertStatus(201);
    expect($response->json('data.event_key'))->toBe("scene:request:{$this->request->id}:candidate");
});
