<?php

namespace Tests\Feature\V3\Workflow;

use App\Models\V3\SettingsVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\Support\V3\WorkflowFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Worker Boundary');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Worker Boundary');

    SettingsFixtures::registerDefaultTemplates();
    $payload = SettingsFixtures::validPayload();
    $this->settingsVersion = SettingsVersion::create([
        'version' => 1,
        'schema_version' => 1,
        'payload' => $payload,
        'checksum' => hash('sha256', json_encode($payload)),
        'published_by_user_id' => $this->admin->id,
        'published_at' => now(),
    ]);

    $fixtures = WorkflowFixtures::createCaseWithLink($this->teacher, $this->settingsVersion);
    $this->case = $fixtures['case'];
    $this->link = $fixtures['link'];
});

test('W10: missing or empty signature headers returns 401 worker_unauthenticated', function () {
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

    $response = $this->postJson('/api/internal/v1/case-events', $payload);

    $response->assertStatus(401)
        ->assertJson([
            'error' => [
                'code' => 'worker_unauthenticated',
            ],
        ]);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

test('W10: invalid signature or wrong secret returns 401 worker_unauthenticated', function () {
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

    $headers = WorkflowFixtures::signCallbackRequest(
        'POST',
        '/api/internal/v1/case-events',
        $payload,
        'wrong-secret-key'
    );

    $response = $this->postJson('/api/internal/v1/case-events', $payload, $headers);

    $response->assertStatus(401)
        ->assertJson([
            'error' => [
                'code' => 'worker_unauthenticated',
            ],
        ]);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

test('W10: stale timestamp beyond 300 seconds returns 401 worker_unauthenticated', function () {
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

    $headers = WorkflowFixtures::signCallbackRequest(
        'POST',
        '/api/internal/v1/case-events',
        $payload,
        null,
        time() - 301
    );

    $response = $this->postJson('/api/internal/v1/case-events', $payload, $headers);

    $response->assertStatus(401)
        ->assertJson([
            'error' => [
                'code' => 'worker_unauthenticated',
            ],
        ]);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

test('W10: web session or admin cookie cannot bypass worker HMAC signature', function () {
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

    // Authenticated admin without HMAC headers must fail 401
    $response = $this->actingAs($this->admin)->postJson('/api/internal/v1/case-events', $payload);

    $response->assertStatus(401)
        ->assertJson([
            'error' => [
                'code' => 'worker_unauthenticated',
            ],
        ]);
});
