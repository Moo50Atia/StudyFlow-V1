<?php

namespace Tests\Feature\V3\Workflow;

use App\Models\V3\CaseEvent;
use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\WorkflowLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Privacy Test');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Privacy Test');

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
        'sha256' => hash('sha256', 'doc-privacy-secret-content'),
        'byte_size' => 2048,
        'page_count' => 5,
        'state' => 'ready',
        'storage_path' => 'accepted/secret_exam_file.pdf',
        'accepted_at' => now(),
    ]);

    $this->case = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'document_id' => $this->doc->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'running',
        'state_version' => 3,
    ]);

    $this->link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-priv-001',
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'start_state' => 'started',
        'stage_code' => 'extraction',
        'status_code' => 'running',
        'started_at' => now(),
    ]);

    $this->event = CaseEvent::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'event_key' => 'workflow_started',
        'payload_digest' => str_repeat('c', 64),
        'sequence' => 2,
        'workflow_id' => $this->link->workflow_id,
        'run_id' => $this->link->run_id,
        'event_type' => 'workflow_started',
        'stage_code' => 'extraction',
        'status_code' => 'queued',
        'error_code' => null,
        'recorded_at' => now(),
    ]);
});

test('W07 & W18: admin case responses NEVER contain owner, document, file, or prompt content', function () {
    $indexResponse = $this->actingAs($this->admin)->getJson('/v3/admin/cases');
    $indexResponse->assertStatus(200);
    $indexJson = $indexResponse->getContent();

    $summaryResponse = $this->actingAs($this->admin)->getJson("/v3/admin/cases/{$this->case->id}/summary");
    $summaryResponse->assertStatus(200);
    $summaryJson = $summaryResponse->getContent();

    foreach ([$indexJson, $summaryJson] as $json) {
        // Teacher identity must never leak
        expect($json)->not->toContain($this->teacher->email)
            ->and($json)->not->toContain($this->teacher->name)
            ->and($json)->not->toContain('owner_user_id')
            // Document/file data must never leak
            ->and($json)->not->toContain($this->doc->id)
            ->and($json)->not->toContain('secret_exam_file.pdf')
            ->and($json)->not->toContain('doc-privacy-secret-content')
            ->and($json)->not->toContain('storage_path')
            ->and($json)->not->toContain('document_id')
            // Educational / prompt sentinels must never exist
            ->and($json)->not->toContain('prompt_text')
            ->and($json)->not->toContain('scene_description')
            ->and($json)->not->toContain('scene_payload')
            ->and($json)->not->toContain('source_text')
            ->and($json)->not->toContain('teacher_notes');
    }
});

test('W18: no HTTP DELETE route exists for cases or workflow links', function () {
    $this->actingAs($this->teacher)
        ->deleteJson("/v3/teacher/cases/{$this->case->id}")
        ->assertStatus(405);

    $this->actingAs($this->admin)
        ->deleteJson("/v3/admin/cases")
        ->assertStatus(405);

    $this->actingAs($this->admin)
        ->deleteJson("/v3/admin/cases/{$this->case->id}/summary")
        ->assertStatus(405);
});
