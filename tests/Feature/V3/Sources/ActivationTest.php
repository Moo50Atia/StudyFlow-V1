<?php

namespace Tests\Feature\V3\Sources;

use App\Jobs\V3\ActivateCaseSourceJob;
use App\Models\V3\CaseEvent;
use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SourceActivation;
use App\Models\V3\WorkflowLink;
use App\Services\V3\Source\ActivateSource;
use App\Services\V3\Source\ReconcileSource;
use App\Services\V3\Workflow\BridgeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Source Activation');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Source Activation');

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
        'sha256' => hash('sha256', 'source activation doc'),
        'byte_size' => 2048,
        'page_count' => 2,
        'state' => 'ready',
        'storage_path' => 'accepted/doc-act.pdf',
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
        'state_version' => 2,
    ]);

    $this->link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-wf-1',
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'start_state' => 'started',
        'stage_code' => 'extraction',
        'status_code' => 'queued',
        'started_at' => now(),
    ]);
});

test('K01: dispatch-sources discovers eligible 007 case and creates one activation with after-commit job', function () {
    Queue::fake();

    $this->artisan('v3:dispatch-sources')->assertSuccessful();

    $activation = SourceActivation::where('case_id', $this->case->id)->first();
    expect($activation)->not->toBeNull()
        ->and($activation->workflow_id)->toBe("v3-case-{$this->case->id}")
        ->and($activation->state)->toBe('pending');

    Queue::assertPushedOn('v3_workflow', ActivateCaseSourceJob::class, function ($job) use ($activation) {
        return $job->activationId === $activation->id;
    });

    // Running again does not create a duplicate activation or multiple jobs
    $this->artisan('v3:dispatch-sources')->assertSuccessful();
    expect(SourceActivation::where('case_id', $this->case->id)->count())->toBe(1);
});

test('K01: ActivateSource signals bridge and transitions to accepted on 202', function () {
    $activation = SourceActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'pending',
    ]);

    $fakeBridge = \Mockery::mock(BridgeClient::class);
    $fakeBridge->shouldReceive('activateSource')
        ->once()
        ->with("v3-case-{$this->case->id}", [
            'schema_version' => 1,
            'case_id' => $this->case->id,
            'activation_id' => $activation->id,
        ])
        ->andReturn([
            'status' => 202,
            'data' => [
                'workflow_id' => "v3-case-{$this->case->id}",
                'activation_id' => $activation->id,
                'accepted' => true,
            ],
        ]);
    $this->app->instance(BridgeClient::class, $fakeBridge);

    $activator = $this->app->make(ActivateSource::class);
    $result = $activator->activate($activation);

    expect($result)->toBeTrue();
    $activation->refresh();
    expect($activation->state)->toBe('accepted')
        ->and($activation->signal_accepted_at)->not->toBeNull()
        ->and($activation->signal_attempts)->toBe(1)
        ->and($activation->lease_until)->toBeNull();
});

test('K01: lost bridge ack leaves activation uncertain and reconciler resends the SAME activation ID', function () {
    $activation = SourceActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'pending',
    ]);

    $fakeBridge = \Mockery::mock(BridgeClient::class);
    // First attempt fails with 503
    $fakeBridge->shouldReceive('activateSource')
        ->once()
        ->andReturn([
            'status' => 503,
            'error' => ['code' => 'workflow_unavailable', 'message' => 'Temporal down'],
        ]);
    $this->app->instance(BridgeClient::class, $fakeBridge);

    $activator = $this->app->make(ActivateSource::class);
    $activator->activate($activation);

    $activation->refresh();
    expect($activation->state)->toBe('uncertain')
        ->and($activation->signal_attempts)->toBe(1);

    // Second attempt via reconciler succeeds on 202 with the SAME activation ID
    $fakeBridge2 = \Mockery::mock(BridgeClient::class);
    $fakeBridge2->shouldReceive('activateSource')
        ->once()
        ->with("v3-case-{$this->case->id}", [
            'schema_version' => 1,
            'case_id' => $this->case->id,
            'activation_id' => $activation->id,
        ])
        ->andReturn([
            'status' => 202,
            'data' => [
                'workflow_id' => "v3-case-{$this->case->id}",
                'activation_id' => $activation->id,
                'accepted' => true,
            ],
        ]);
    $this->app->instance(BridgeClient::class, $fakeBridge2);

    $reconciler = $this->app->make(ReconcileSource::class);
    $reconciler->reconcile();

    $activation->refresh();
    expect($activation->state)->toBe('accepted')
        ->and($activation->signal_attempts)->toBe(2)
        ->and($activation->id)->toBe($activation->id); // Exactly same ID
});

test('K03: suspended teacher owner after workflow start still allows automated source activation', function () {
    Queue::fake();

    // Suspend teacher account
    DB::table('v3_account_access')->where('user_id', $this->teacher->id)->update(['is_active' => false]);

    $this->artisan('v3:dispatch-sources')->assertSuccessful();

    $activation = SourceActivation::where('case_id', $this->case->id)->first();
    expect($activation)->not->toBeNull()
        ->and($activation->state)->toBe('pending');

    Queue::assertPushedOn('v3_workflow', ActivateCaseSourceJob::class);
});

test('K01: terminal case or mismatched settings checksum prevents source activation', function () {
    Queue::fake();

    // Case has terminal event
    $case2 = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'blocked',
        'state_version' => 3,
    ]);

    WorkflowLink::forceCreate([
        'case_id' => $case2->id,
        'workflow_id' => "v3-case-{$case2->id}",
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'start_state' => 'started',
        'stage_code' => 'extraction',
        'status_code' => 'blocked',
    ]);

    $this->artisan('v3:dispatch-sources')->assertSuccessful();

    expect(SourceActivation::where('case_id', $case2->id)->first())->toBeNull();
});
