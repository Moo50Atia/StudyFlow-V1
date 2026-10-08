<?php

namespace Tests\Feature\V3\Workflow;

use App\Models\V3\CaseEvent;
use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\WorkflowLink;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Workflow');
    SettingsFixtures::registerDefaultTemplates();
    $payload = SettingsFixtures::validPayload();
    $this->settingsVersion = SettingsVersion::create([
        'version' => 1,
        'schema_version' => 1,
        'payload' => $payload,
        'checksum' => hash('sha256', json_encode($payload)),
        'published_by_user_id' => $this->teacher->id,
        'published_at' => now(),
    ]);

    $this->document = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'sha256' => hash('sha256', 'workflow test file'),
        'byte_size' => 2048,
        'page_count' => 2,
        'state' => 'ready',
        'storage_path' => 'accepted/doc-wf.pdf',
        'accepted_at' => now(),
    ]);

    $this->case = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'awaiting_workflow',
        'state_version' => 1,
    ]);
});

test('WorkflowLink schema enforces PK, unique workflow_id, and default attributes', function () {
    $link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'start_state' => 'pending',
    ]);

    expect($link)->toBeInstanceOf(WorkflowLink::class);
    $fresh = WorkflowLink::find($this->case->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh->stage_code)->toBe('intake')
        ->and($fresh->status_code)->toBe('queued')
        ->and($fresh->attempt_count)->toBe(0)
        ->and($fresh->scene_count)->toBe(0)
        ->and($fresh->approved_scene_count)->toBe(0)
        ->and($fresh->start_attempts)->toBe(0);

    // Duplicate workflow_id on another case must fail unique constraint
    $case2 = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'awaiting_workflow',
        'state_version' => 1,
    ]);

    expect(function () use ($case2) {
        WorkflowLink::forceCreate([
            'case_id' => $case2->id,
            'workflow_id' => "v3-case-{$this->case->id}", // Reusing existing workflow_id
            'settings_version_id' => $this->settingsVersion->id,
            'start_state' => 'pending',
        ]);
    })->toThrow(QueryException::class);
});

test('WorkflowLink foreign keys restrict deletion of referenced case and settings version', function () {
    WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'start_state' => 'pending',
    ]);

    // Deleting processing case must be restricted
    expect(function () {
        $this->case->delete();
    })->toThrow(QueryException::class);

    // Deleting settings version at DB level must be restricted by FK
    expect(function () {
        DB::table('v3_settings_versions')->where('id', $this->settingsVersion->id)->delete();
    })->toThrow(QueryException::class);
});

test('CaseEvent schema enforces unique event_key and sequence per case', function () {
    $event1 = CaseEvent::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'event_key' => 'workflow_started',
        'payload_digest' => str_repeat('a', 64),
        'sequence' => 2,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-001',
        'event_type' => 'workflow_started',
        'stage_code' => 'extraction',
        'status_code' => 'queued',
        'error_code' => null,
        'recorded_at' => now(),
    ]);

    expect($event1)->toBeInstanceOf(CaseEvent::class);

    // Duplicate (case_id, event_key) must throw QueryException
    expect(function () {
        CaseEvent::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'event_key' => 'workflow_started', // Duplicate event_key
            'payload_digest' => str_repeat('b', 64),
            'sequence' => 3,
            'workflow_id' => "v3-case-{$this->case->id}",
            'run_id' => 'run-001',
            'event_type' => 'workflow_started',
            'stage_code' => 'extraction',
            'status_code' => 'queued',
            'recorded_at' => now(),
        ]);
    })->toThrow(QueryException::class);

    // Duplicate (case_id, sequence) must throw QueryException
    expect(function () {
        CaseEvent::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'event_key' => 'stage_started_extraction',
            'payload_digest' => str_repeat('c', 64),
            'sequence' => 2, // Duplicate sequence
            'workflow_id' => "v3-case-{$this->case->id}",
            'run_id' => 'run-001',
            'event_type' => 'stage_started',
            'stage_code' => 'extraction',
            'status_code' => 'running',
            'recorded_at' => now(),
        ]);
    })->toThrow(QueryException::class);
});

test('CaseEvent foreign key restricts deletion of referenced case', function () {
    CaseEvent::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'event_key' => 'workflow_started',
        'payload_digest' => str_repeat('a', 64),
        'sequence' => 2,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-001',
        'event_type' => 'workflow_started',
        'stage_code' => 'extraction',
        'status_code' => 'queued',
        'recorded_at' => now(),
    ]);

    expect(function () {
        $this->case->delete();
    })->toThrow(QueryException::class);
});

test('WorkflowLink model enforces immutability of immutable attributes', function () {
    $link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'run_id' => 'run-initial',
        'start_state' => 'pending',
    ]);

    expect(function () use ($link) {
        $link->workflow_id = 'v3-case-tampered';
        $link->save();
    })->toThrow(\InvalidArgumentException::class);

    expect(function () use ($link) {
        $link->settings_version_id = 999;
        $link->save();
    })->toThrow(\InvalidArgumentException::class);

    expect(function () use ($link) {
        $link->run_id = 'run-tampered';
        $link->save();
    })->toThrow(\InvalidArgumentException::class);

    expect(function () use ($link) {
        $link->settings_checksum = str_repeat('0', 64);
        $link->save();
    })->toThrow(\InvalidArgumentException::class);
});

test('CaseEvent model prevents updating committed events', function () {
    $event = CaseEvent::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'event_key' => 'workflow_started',
        'payload_digest' => str_repeat('a', 64),
        'sequence' => 2,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-001',
        'event_type' => 'workflow_started',
        'stage_code' => 'extraction',
        'status_code' => 'queued',
        'recorded_at' => now(),
    ]);

    expect(function () use ($event) {
        $event->status_code = 'failed';
        $event->save();
    })->toThrow(\LogicException::class);
});
