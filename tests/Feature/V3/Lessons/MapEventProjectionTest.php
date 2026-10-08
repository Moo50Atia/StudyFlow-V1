<?php

namespace Tests\Feature\V3\Lessons;

use App\Models\V3\CaseEvent;
use App\Models\V3\Document;
use App\Models\V3\ExtractedSource;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\MapActivation;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SourceActivation;
use App\Models\V3\WorkflowLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\Support\V3\WorkflowFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Map Events');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Map Events');

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

    $this->document = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'sha256' => hash('sha256', 'map event doc'),
        'byte_size' => 2048,
        'page_count' => 2,
        'state' => 'ready',
        'storage_path' => 'accepted/doc-map-event.pdf',
        'accepted_at' => now(),
    ]);

    $this->case = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'queued',
        'state_version' => 6, // after workflow_started(2), ext:started(3), ext:completed(4), src:started(5), src:completed(6)
    ]);

    $this->link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-map-ev-1',
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'start_state' => 'started',
        'stage_code' => 'sourcing',
        'status_code' => 'queued',
        'started_at' => now(),
    ]);

    $this->sourceActivation = SourceActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'accepted',
        'signal_accepted_at' => now(),
    ]);

    $this->extractedSource = ExtractedSource::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'activation_id' => $this->sourceActivation->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'status' => 'ready',
        'artifact_path' => 'extracted/map-ev.txt',
        'text_sha256' => hash('sha256', 'extracted text'),
        'text_bytes' => 100,
        'page_count' => 2,
        'page_report' => [],
        'extractor_version' => '008-v1',
    ]);

    $this->binding = KnowledgeSourceBinding::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'extracted_source_id' => $this->extractedSource->id,
        'adapter_kind' => 'notebooklm',
        'adapter_version' => '0.3.1',
        'notebook_title' => "v3-case-{$this->case->id}",
        'source_title' => "v3-text-{$this->case->id}",
        'notebook_id' => 'nb-ev-1',
        'source_id' => 'src-ev-1',
        'state' => 'bound',
        'text_sha256' => hash('sha256', 'extracted text'),
        'text_bytes' => 100,
        'transfer_status' => 'confirmed',
    ]);

    $this->mapActivation = MapActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'accepted',
        'signal_accepted_at' => now(),
    ]);
});

test('L01: signed map:started callback transitions case to running and stage to mapping', function () {
    $payload = [
        'schema_version' => 1,
        'event_key' => 'map:started',
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-map-ev-1',
        'expected_state_version' => 6,
        'event_type' => 'stage_started',
        'stage_code' => 'mapping',
        'status_code' => 'running',
        'error_code' => null,
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/case-events', $payload);
    $response = $this->postJson('/api/internal/v1/case-events', $payload, $headers);

    $response->assertStatus(201)
        ->assertJson([
            'data' => [
                'case_id' => $this->case->id,
                'state_version' => 7,
            ],
        ]);

    $this->case->refresh();
    expect($this->case->state)->toBe('running');
    expect($this->case->state_version)->toBe(7);

    $this->link->refresh();
    expect($this->link->stage_code)->toBe('mapping');
    expect($this->link->status_code)->toBe('running');
});

test('L01: signed map:completed callback transitions case to queued and stage to mapping', function () {
    // First apply map:started
    $this->case->forceFill(['state' => 'running', 'state_version' => 7])->save();
    $this->link->forceFill(['stage_code' => 'mapping', 'status_code' => 'running'])->save();

    $payload = [
        'schema_version' => 1,
        'event_key' => 'map:completed',
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-map-ev-1',
        'expected_state_version' => 7,
        'event_type' => 'stage_completed',
        'stage_code' => 'mapping',
        'status_code' => 'queued',
        'error_code' => null,
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/case-events', $payload);
    $response = $this->postJson('/api/internal/v1/case-events', $payload, $headers);

    $response->assertStatus(201)
        ->assertJson([
            'data' => [
                'case_id' => $this->case->id,
                'state_version' => 8,
            ],
        ]);

    $this->case->refresh();
    expect($this->case->state)->toBe('queued');
    expect($this->case->state_version)->toBe(8);

    $this->link->refresh();
    expect($this->link->stage_code)->toBe('mapping');
    expect($this->link->status_code)->toBe('queued');
});

test('L01: provenance-checked map_blocked transitions case to blocked with safe error code', function () {
    $this->case->forceFill(['state' => 'running', 'state_version' => 7])->save();
    $this->link->forceFill(['stage_code' => 'mapping', 'status_code' => 'running'])->save();

    $payload = [
        'schema_version' => 1,
        'event_key' => 'map:blocked',
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-map-ev-1',
        'expected_state_version' => 7,
        'event_type' => 'map_blocked',
        'stage_code' => 'mapping',
        'status_code' => 'blocked',
        'error_code' => 'validation',
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/case-events', $payload);
    $response = $this->postJson('/api/internal/v1/case-events', $payload, $headers);

    $response->assertStatus(201)
        ->assertJson([
            'data' => [
                'case_id' => $this->case->id,
                'state_version' => 8,
            ],
        ]);

    $this->case->refresh();
    expect($this->case->state)->toBe('blocked');
    expect($this->case->state_version)->toBe(8);
});

test('L01: unproven map_blocked without current MapActivation is rejected', function () {
    // Delete map activation to simulate unproven event
    $this->mapActivation->delete();

    $this->case->forceFill(['state' => 'running', 'state_version' => 7])->save();
    $this->link->forceFill(['stage_code' => 'mapping', 'status_code' => 'running'])->save();

    $payload = [
        'schema_version' => 1,
        'event_key' => 'map:blocked',
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-map-ev-1',
        'expected_state_version' => 7,
        'event_type' => 'map_blocked',
        'stage_code' => 'mapping',
        'status_code' => 'blocked',
        'error_code' => 'validation',
    ];

    $headers = WorkflowFixtures::signCallbackRequest('POST', '/api/internal/v1/case-events', $payload);
    $response = $this->postJson('/api/internal/v1/case-events', $payload, $headers);

    $response->assertStatus(422);
});
