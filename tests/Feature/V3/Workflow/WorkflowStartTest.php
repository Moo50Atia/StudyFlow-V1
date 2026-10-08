<?php

namespace Tests\Feature\V3\Workflow;

use App\Jobs\V3\StartCaseWorkflowJob;
use App\Models\V3\CaseEvent;
use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\WorkflowLink;
use App\Services\V3\Workflow\BridgeClient;
use App\Services\V3\Workflow\DiscoverCases;
use App\Services\V3\Workflow\ReconcileWorkflowStart;
use App\Services\V3\Workflow\StartWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\Support\V3\WorkflowFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Start Test');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Start Test');

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

    $this->doc = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'sha256' => hash('sha256', 'doc-start'),
        'byte_size' => 1024,
        'page_count' => 1,
        'state' => 'ready',
        'storage_path' => 'accepted/doc-start.pdf',
        'accepted_at' => now(),
    ]);

    $this->case = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'document_id' => $this->doc->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'awaiting_workflow',
        'state_version' => 1,
    ]);
});

test('W01: DiscoverCases locates awaiting_workflow case and creates exactly one WorkflowLink and dispatches job', function () {
    Queue::fake();

    $discoverer = app(DiscoverCases::class);
    $count = $discoverer->discover();

    expect($count)->toBe(1);

    $link = WorkflowLink::find($this->case->id);
    expect($link)->not->toBeNull()
        ->and($link->workflow_id)->toBe("v3-case-{$this->case->id}")
        ->and($link->start_state)->toBe('pending')
        ->and($link->settings_version_id)->toBe($this->settingsVersion->id);

    Queue::assertPushed(StartCaseWorkflowJob::class, function ($job) {
        return $job->caseId === $this->case->id;
    });

    // Running discovery again does not duplicate link or re-dispatch if lease is active
    $count2 = $discoverer->discover();
    expect($count2)->toBe(0);
    expect(WorkflowLink::where('case_id', $this->case->id)->count())->toBe(1);
});

test('W01: bridge 200 open response records started link but leaves case awaiting_workflow until worker callback', function () {
    $link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'settings_version_id' => $this->settingsVersion->id,
        'start_state' => 'pending',
    ]);

    $fakeBridge = $this->createMock(BridgeClient::class);
    $fakeBridge->expects($this->once())
        ->method('startWorkflow')
        ->willReturn([
            'status' => 200,
            'data' => [
                'workflow_id' => "v3-case-{$this->case->id}",
                'run_id' => 'run-real-001',
                'temporal_status' => 'open',
            ],
        ]);

    $starter = new StartWorkflow($fakeBridge);
    $result = $starter->start($this->case->id);

    expect($result['status'])->toBe('started');

    $link->refresh();
    expect($link->start_state)->toBe('started')
        ->and($link->run_id)->toBe('run-real-001')
        ->and($link->started_at)->not->toBeNull();

    $this->case->refresh();
    // Case MUST remain awaiting_workflow! The start response alone does not advance it.
    expect($this->case->state)->toBe('awaiting_workflow')
        ->and($this->case->state_version)->toBe(1);
});

test('W02: timeout or network error enters uncertain start_state and reconciliation adopts run_id on matching memo', function () {
    $link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'settings_version_id' => $this->settingsVersion->id,
        'start_state' => 'pending',
    ]);

    $fakeBridge = $this->createMock(BridgeClient::class);
    $fakeBridge->method('startWorkflow')
        ->willReturn([
            'status' => 503,
            'error' => [
                'code' => 'workflow_unavailable',
                'message' => 'Connection timed out',
            ],
        ]);

    $starter = new StartWorkflow($fakeBridge);
    $result = $starter->start($this->case->id);

    expect($result['status'])->toBe('uncertain');
    $link->refresh();
    expect($link->start_state)->toBe('uncertain');

    // Reconciliation describes workflow
    $fakeBridge2 = $this->createMock(BridgeClient::class);
    $fakeBridge2->method('describeWorkflow')
        ->willReturn([
            'status' => 200,
            'data' => [
                'found' => true,
                'workflow_id' => "v3-case-{$this->case->id}",
                'run_id' => 'run-reconciled-002',
                'temporal_status' => 'open',
                'case_id' => $this->case->id,
                'settings_version_id' => $this->settingsVersion->id,
                'settings_checksum' => $link->settings_checksum,
            ],
        ]);

    $reconciler = new ReconcileWorkflowStart($fakeBridge2);
    $reconciler->reconcileLink($link);

    $link->refresh();
    expect($link->start_state)->toBe('started')
        ->and($link->run_id)->toBe('run-reconciled-002');
});

test('W03: 24-hour overdue start emits warning log deduplicated', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with('workflow_start_overdue', \Mockery::on(function ($context) {
            return $context['code'] === 'workflow_start_overdue' && $context['case_id'] === $this->case->id;
        }));

    $link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'settings_version_id' => $this->settingsVersion->id,
        'start_state' => 'pending',
        'created_at' => now()->subHours(25),
        'updated_at' => now()->subHours(25),
    ]);

    $fakeBridge = $this->createMock(BridgeClient::class);
    $reconciler = new ReconcileWorkflowStart($fakeBridge);
    $reconciler->reconcileLink($link);

    $link->refresh();
    expect($link->last_alert_code)->toBe('workflow_start_overdue')
        ->and($link->last_alerted_at)->not->toBeNull();

    // Second check within 24 hours does not re-emit log
    $reconciler->reconcileLink($link);
});

test('W05: inactive or suspended owner defers workflow start without external call', function () {
    // Suspend teacher account
    DB::table('v3_account_access')->where('user_id', $this->teacher->id)->update([
        'is_active' => false,
    ]);

    $link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'settings_version_id' => $this->settingsVersion->id,
        'start_state' => 'pending',
    ]);

    $fakeBridge = $this->createMock(BridgeClient::class);
    $fakeBridge->expects($this->never())->method('startWorkflow');

    $starter = new StartWorkflow($fakeBridge);
    $result = $starter->start($this->case->id);

    expect($result['status'])->toBe('deferred')
        ->and($result['reason'])->toBe('inactive_owner');

    $link->refresh();
    expect($link->start_state)->toBe('pending');
});

test('W19: missing or corrupt captured settings version prevents start and emits settings_unavailable', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with('settings_unavailable', \Mockery::on(function ($context) {
            return $context['code'] === 'settings_unavailable' && $context['case_id'] === $this->case->id;
        }));

    // Corrupt checksum of captured settings version
    DB::table('v3_settings_versions')->where('id', $this->settingsVersion->id)->update([
        'checksum' => str_repeat('f', 64),
    ]);

    $link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'settings_version_id' => $this->settingsVersion->id,
        'start_state' => 'pending',
    ]);

    $fakeBridge = $this->createMock(BridgeClient::class);
    $fakeBridge->expects($this->never())->method('startWorkflow');

    $starter = new StartWorkflow($fakeBridge);
    $result = $starter->start($this->case->id);

    expect($result['status'])->toBe('deferred')
        ->and($result['reason'])->toBe('settings_unavailable');

    $link->refresh();
    expect($link->start_state)->toBe('pending')
        ->and($link->last_alert_code)->toBe('settings_unavailable');
});

test('W20: verified not-found after 30 days marks history_missing and alerts operator', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with('workflow_history_missing', \Mockery::on(function ($context) {
            return $context['code'] === 'workflow_history_missing' && $context['case_id'] === $this->case->id;
        }));

    $link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'settings_version_id' => $this->settingsVersion->id,
        'start_state' => 'uncertain',
        'last_alert_code' => 'workflow_start_overdue',
        'last_alerted_at' => now(),
        'start_attempted_at' => now()->subDays(31),
        'created_at' => now()->subDays(31),
        'updated_at' => now()->subDays(31),
    ]);

    $fakeBridge = $this->createMock(BridgeClient::class);
    $fakeBridge->method('describeWorkflow')
        ->willReturn([
            'status' => 200,
            'data' => [
                'found' => false,
                'workflow_id' => "v3-case-{$this->case->id}",
            ],
        ]);

    $reconciler = new ReconcileWorkflowStart($fakeBridge);
    $reconciler->reconcileLink($link);

    $link->refresh();
    expect($link->start_state)->toBe('history_missing')
        ->and($link->last_alert_code)->toBe('workflow_history_missing');
});
