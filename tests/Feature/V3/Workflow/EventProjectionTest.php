<?php

namespace Tests\Feature\V3\Workflow;

use App\Models\V3\CaseEvent;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\WorkflowLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\Support\V3\WorkflowFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Event Projection');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Event Projection');

    SettingsFixtures::registerDefaultTemplates();
    $payload = SettingsFixtures::validPayload();
    $canonical = \App\Services\V3\Settings\CanonicalSettings::canonicalize($payload);
    $checksum = hash('sha256', \App\Services\V3\Settings\CanonicalSettings::encode($canonical));

    $this->settingsVersion = SettingsVersion::create([
        'version' => 1,
        'schema_version' => 1,
        'payload' => $canonical,
        'checksum' => $checksum,
        'published_by_user_id' => $this->admin->id,
        'published_at' => now(),
    ]);

    $fixtures = WorkflowFixtures::createCaseWithLink($this->teacher, $this->settingsVersion);
    $this->case = $fixtures['case'];
    $this->link = $fixtures['link'];
});

test('W04: signed workflow_started callback transitions case to queued version 2 and link to started', function () {
    $payload = [
        'schema_version' => 1,
        'event_key' => 'workflow_started',
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-001',
        'expected_state_version' => 1,
        'event_type' => 'workflow_started',
        'stage_code' => 'extraction',
        'status_code' => 'queued',
        'error_code' => null,
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/case-events', $payload);
    $response = $this->postJson('/api/internal/v1/case-events', $payload, $headers);

    $response->assertStatus(201)
        ->assertJson([
            'data' => [
                'case_id' => $this->case->id,
                'state_version' => 2,
            ],
        ]);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    $this->case->refresh();
    expect($this->case->state)->toBe('queued')
        ->and($this->case->state_version)->toBe(2);

    $this->link->refresh();
    expect($this->link->start_state)->toBe('started')
        ->and($this->link->run_id)->toBe('run-001')
        ->and($this->link->stage_code)->toBe('extraction')
        ->and($this->link->status_code)->toBe('queued')
        ->and($this->link->started_at)->not->toBeNull()
        ->and($this->link->last_event_at)->not->toBeNull();

    $event = CaseEvent::where('case_id', $this->case->id)->where('event_key', 'workflow_started')->first();
    expect($event)->not->toBeNull()
        ->and($event->sequence)->toBe(2)
        ->and($event->workflow_id)->toBe("v3-case-{$this->case->id}")
        ->and($event->run_id)->toBe('run-001')
        ->and($event->event_type)->toBe('workflow_started')
        ->and($event->stage_code)->toBe('extraction')
        ->and($event->status_code)->toBe('queued')
        ->and($event->error_code)->toBeNull();
});

test('W08: exact replay of signed event returns 200 with identical data and no state mutation', function () {
    $payload = [
        'schema_version' => 1,
        'event_key' => 'workflow_started',
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-001',
        'expected_state_version' => 1,
        'event_type' => 'workflow_started',
        'stage_code' => 'extraction',
        'status_code' => 'queued',
        'error_code' => null,
    ];

    $headers1 = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/case-events', $payload);
    $response1 = $this->postJson('/api/internal/v1/case-events', $payload, $headers1);
    $response1->assertStatus(201);
    $eventId = $response1->json('data.event_id');

    // Second call with fresh timestamp/signature but identical payload
    $headers2 = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/case-events', $payload);
    $response2 = $this->postJson('/api/internal/v1/case-events', $payload, $headers2);

    $response2->assertStatus(200)
        ->assertJson([
            'data' => [
                'event_id' => $eventId,
                'case_id' => $this->case->id,
                'state_version' => 2,
            ],
        ]);

    expect(CaseEvent::where('case_id', $this->case->id)->count())->toBe(1);
});

test('W08: same event_key with changed payload returns 409 event_conflict and no state change', function () {
    $payload1 = [
        'schema_version' => 1,
        'event_key' => 'workflow_started',
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-001',
        'expected_state_version' => 1,
        'event_type' => 'workflow_started',
        'stage_code' => 'extraction',
        'status_code' => 'queued',
        'error_code' => null,
    ];

    $headers1 = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/case-events', $payload1);
    $this->postJson('/api/internal/v1/case-events', $payload1, $headers1)->assertStatus(201);

    $payloadStage1 = [
        'schema_version' => 1,
        'event_key' => 'stage_started_extraction',
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-001',
        'expected_state_version' => 2,
        'event_type' => 'stage_started',
        'stage_code' => 'extraction',
        'status_code' => 'running',
        'error_code' => null,
    ];

    $headersStage1 = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/case-events', $payloadStage1);
    $this->postJson('/api/internal/v1/case-events', $payloadStage1, $headersStage1)->assertStatus(201);

    // Reusing 'stage_started_extraction' key with different stage_code 'mapping'
    $payloadStage2 = $payloadStage1;
    $payloadStage2['stage_code'] = 'mapping';

    $headersStage2 = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/case-events', $payloadStage2);
    $response2 = $this->postJson('/api/internal/v1/case-events', $payloadStage2, $headersStage2);

    $response2->assertStatus(409)
        ->assertJson([
            'error' => [
                'code' => 'event_conflict',
            ],
        ]);

    $this->case->refresh();
    expect($this->case->state_version)->toBe(3)
        ->and($this->case->state)->toBe('running');
});

test('W08: stale or conflicting expected_state_version returns 409 version_conflict', function () {
    $payload = [
        'schema_version' => 1,
        'event_key' => 'stage_started_extraction',
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-001',
        'expected_state_version' => 99, // Current is 1
        'event_type' => 'stage_started',
        'stage_code' => 'extraction',
        'status_code' => 'running',
        'error_code' => null,
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/case-events', $payload);
    $response = $this->postJson('/api/internal/v1/case-events', $payload, $headers);

    $response->assertStatus(409)
        ->assertJson([
            'error' => [
                'code' => 'version_conflict',
            ],
        ]);
});

test('W08: unknown case_id returns 404 case_not_found', function () {
    $unknownCaseId = (string) \Illuminate\Support\Str::uuid();
    $payload = [
        'schema_version' => 1,
        'event_key' => 'workflow_started',
        'case_id' => $unknownCaseId,
        'workflow_id' => "v3-case-{$unknownCaseId}",
        'run_id' => 'run-001',
        'expected_state_version' => 1,
        'event_type' => 'workflow_started',
        'stage_code' => 'extraction',
        'status_code' => 'queued',
        'error_code' => null,
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/case-events', $payload);
    $response = $this->postJson('/api/internal/v1/case-events', $payload, $headers);

    $response->assertStatus(404)
        ->assertJson([
            'error' => [
                'code' => 'case_not_found',
            ],
        ]);
});

test('W04: callback arriving before start ack commits version 2 and subsequent start ack does not regress', function () {
    $payload = [
        'schema_version' => 1,
        'event_key' => 'workflow_started',
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-callback-first',
        'expected_state_version' => 1,
        'event_type' => 'workflow_started',
        'stage_code' => 'extraction',
        'status_code' => 'queued',
        'error_code' => null,
    ];

    // 1. Worker callback arrives first
    $headers = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/case-events', $payload);
    $response = $this->postJson('/api/internal/v1/case-events', $payload, $headers);
    $response->assertStatus(201);

    $this->case->refresh();
    $this->link->refresh();
    expect($this->case->state)->toBe('queued')
        ->and($this->case->state_version)->toBe(2)
        ->and($this->link->start_state)->toBe('started')
        ->and($this->link->run_id)->toBe('run-callback-first');

    // 2. Late start response arrives
    $fakeBridge = $this->createMock(\App\Services\V3\Workflow\BridgeClient::class);
    $fakeBridge->method('startWorkflow')->willReturn([
        'status' => 200,
        'data' => [
            'workflow_id' => "v3-case-{$this->case->id}",
            'run_id' => 'run-callback-first',
            'temporal_status' => 'open',
        ],
    ]);

    $starter = new \App\Services\V3\Workflow\StartWorkflow($fakeBridge);
    $result = $starter->start($this->case->id);
    expect($result['status'])->toBe('started');

    $this->case->refresh();
    $this->link->refresh();
    expect($this->case->state)->toBe('queued')
        ->and($this->case->state_version)->toBe(2)
        ->and($this->link->start_state)->toBe('started')
        ->and($this->link->run_id)->toBe('run-callback-first');
});

test('W08: schema violation or invalid combination returns 422 contract_invalid', function () {
    $payload = [
        'schema_version' => 1,
        'event_key' => 'invalid_stage_status',
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-001',
        'expected_state_version' => 1,
        'event_type' => 'stage_started',
        'stage_code' => 'extraction',
        'status_code' => 'approved', // Not allowed for worker callback!
        'error_code' => null,
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/case-events', $payload);
    $response = $this->postJson('/api/internal/v1/case-events', $payload, $headers);

    $response->assertStatus(422)
        ->assertJson([
            'error' => [
                'code' => 'contract_invalid',
            ],
        ]);
});
