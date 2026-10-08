<?php

namespace Tests\Feature\V3\Lessons;

use App\Jobs\V3\ActivateMapJob;
use App\Models\V3\Document;
use App\Models\V3\ExtractedSource;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\MapActivation;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SourceActivation;
use App\Models\V3\WorkflowLink;
use App\Services\V3\Lessons\ActivateMap;
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

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Map Activation');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Map Activation');

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
        'sha256' => hash('sha256', 'map activation doc'),
        'byte_size' => 2048,
        'page_count' => 2,
        'state' => 'ready',
        'storage_path' => 'accepted/doc-map-act.pdf',
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
        'run_id' => 'run-wf-map-1',
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
        'artifact_path' => 'extracted/map-test.txt',
        'text_sha256' => hash('sha256', 'extracted text'),
        'text_bytes' => 200,
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
        'notebook_id' => 'nb-map-1',
        'source_id' => 'src-map-1',
        'state' => 'bound',
        'text_sha256' => hash('sha256', 'extracted text'),
        'text_bytes' => 200,
        'transfer_status' => 'confirmed',
    ]);
});

test('L01: dispatch-maps discovers eligible 008 bound case and creates one activation with after-commit job', function () {
    Queue::fake();

    $activator = app(ActivateMap::class);
    $dispatched = $activator->discoverAndDispatch();

    expect($dispatched)->toBe(1);

    $activation = MapActivation::where('case_id', $this->case->id)->first();
    expect($activation)->not->toBeNull();
    expect($activation->state)->toBe('pending');
    expect($activation->binding_id)->toBe($this->binding->id);
    expect($activation->workflow_id)->toBe("v3-case-{$this->case->id}");

    Queue::assertPushed(ActivateMapJob::class, function ($job) use ($activation) {
        return $job->activationId === $activation->id;
    });

    // Running again does not create a duplicate
    $dispatchedAgain = $activator->discoverAndDispatch();
    expect($dispatchedAgain)->toBe(0);
    expect(MapActivation::where('case_id', $this->case->id)->count())->toBe(1);
});

test('L01: ActivateMap signals bridge and transitions to accepted on 202', function () {
    $activation = MapActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'pending',
    ]);

    $mockBridge = $this->createMock(BridgeClient::class);
    $mockBridge->expects($this->once())
        ->method('activateMap')
        ->with("v3-case-{$this->case->id}", [
            'schema_version' => 1,
            'case_id' => $this->case->id,
            'binding_ref' => $this->binding->id,
            'activation_id' => $activation->id,
        ])
        ->willReturn([
            'status' => 202,
            'data' => [
                'workflow_id' => "v3-case-{$this->case->id}",
                'activation_id' => $activation->id,
                'accepted' => true,
            ],
        ]);

    $activator = new ActivateMap($mockBridge);
    $success = $activator->activate($activation);

    expect($success)->toBeTrue();
    $activation->refresh();
    expect($activation->state)->toBe('accepted');
    expect($activation->signal_accepted_at)->not->toBeNull();
    expect($activation->lease_until)->toBeNull();
});

test('L01: lost bridge ack leaves activation uncertain and reconciler resends same activation ID', function () {
    $activation = MapActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'binding_id' => $this->binding->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'pending',
    ]);

    $mockBridge = $this->createMock(BridgeClient::class);
    $mockBridge->expects($this->exactly(2))
        ->method('activateMap')
        ->willReturnOnConsecutiveCalls(
            [
                'status' => 503,
                'error' => ['code' => 'workflow_unavailable', 'message' => 'Connection timed out'],
            ],
            [
                'status' => 202,
                'data' => ['accepted' => true],
            ]
        );

    $activator = new ActivateMap($mockBridge);
    $firstAttempt = $activator->activate($activation);
    expect($firstAttempt)->toBeFalse();

    $activation->refresh();
    expect($activation->state)->toBe('uncertain');
    expect($activation->error_code)->toBe('workflow_unavailable');
    expect($activation->signal_attempts)->toBe(1);

    // Reconciler retry resends exact same ID
    $secondAttempt = $activator->activate($activation);
    expect($secondAttempt)->toBeTrue();

    $activation->refresh();
    expect($activation->state)->toBe('accepted');
    expect($activation->signal_attempts)->toBe(2);
});

test('L01: suspended teacher owner after workflow start still allows automated map activation', function () {
    DB::table('v3_account_access')
        ->where('user_id', $this->teacher->id)
        ->update(['is_active' => false]);

    $activator = app(ActivateMap::class);
    $dispatched = $activator->discoverAndDispatch();

    expect($dispatched)->toBe(1);
    expect(MapActivation::where('case_id', $this->case->id)->exists())->toBeTrue();
});

test('L01: non-bound source binding prevents map activation', function () {
    $this->binding->update(['state' => 'binding']);

    $activator = app(ActivateMap::class);
    $dispatched = $activator->discoverAndDispatch();

    expect($dispatched)->toBe(0);
    expect(MapActivation::where('case_id', $this->case->id)->exists())->toBeFalse();
});
