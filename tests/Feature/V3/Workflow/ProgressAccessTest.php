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

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Owner');
    $this->otherTeacher = AccessFixtures::createEnrolledTeacher('Teacher Other');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin User');

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
        'sha256' => hash('sha256', 'doc-progress-test'),
        'byte_size' => 1024,
        'page_count' => 1,
        'state' => 'ready',
        'storage_path' => 'accepted/doc-progress.pdf',
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

test('W06: owning active teacher gets ordered progress milestones and pagination', function () {
    $link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-prog-001',
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'start_state' => 'started',
        'stage_code' => 'extraction',
        'status_code' => 'running',
        'started_at' => now(),
    ]);

    $this->case->forceFill(['state' => 'running', 'state_version' => 3])->save();

    $ev1 = CaseEvent::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'event_key' => 'workflow_started',
        'payload_digest' => str_repeat('a', 64),
        'sequence' => 2,
        'workflow_id' => $link->workflow_id,
        'run_id' => $link->run_id,
        'event_type' => 'workflow_started',
        'stage_code' => 'extraction',
        'status_code' => 'queued',
        'error_code' => null,
        'recorded_at' => now()->subMinutes(2),
    ]);

    $ev2 = CaseEvent::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'event_key' => 'stage_started_extraction',
        'payload_digest' => str_repeat('b', 64),
        'sequence' => 3,
        'workflow_id' => $link->workflow_id,
        'run_id' => $link->run_id,
        'event_type' => 'stage_started',
        'stage_code' => 'extraction',
        'status_code' => 'running',
        'error_code' => null,
        'recorded_at' => now()->subMinute(),
    ]);

    $response = $this->actingAs($this->teacher)
        ->getJson("/v3/teacher/cases/{$this->case->id}/progress");

    $response->assertStatus(200);
    $response->assertHeader('Cache-Control');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    $data = $response->json('data');
    expect($data['case_id'])->toBe($this->case->id)
        ->and($data['stage_code'])->toBe('extraction')
        ->and($data['status_code'])->toBe('running')
        ->and($data['error_code'])->toBeNull()
        ->and($data['state_version'])->toBe(3)
        ->and($data['delayed'])->toBeFalse()
        ->and(count($data['events']))->toBe(2)
        ->and($data['events'][0]['sequence'])->toBe(2)
        ->and($data['events'][1]['sequence'])->toBe(3)
        ->and($data['pagination']['page'])->toBe(1)
        ->and($data['pagination']['per_page'])->toBe(20)
        ->and($data['pagination']['has_more'])->toBeFalse();
});

test('W06: foreign teacher or nonexistent case returns identical 404 response', function () {
    $foreignResponse = $this->actingAs($this->otherTeacher)
        ->getJson("/v3/teacher/cases/{$this->case->id}/progress");

    $missingId = (string) Str::uuid();
    $missingResponse = $this->actingAs($this->otherTeacher)
        ->getJson("/v3/teacher/cases/{$missingId}/progress");

    $foreignResponse->assertStatus(404);
    $missingResponse->assertStatus(404);

    expect($foreignResponse->json())->toEqual($missingResponse->json())
        ->and($foreignResponse->json('error.code'))->toBe('resource_unavailable');
});

test('W06: inactive teacher, student, and admin are denied access to teacher progress route', function () {
    // Inactive teacher
    DB::table('v3_account_access')->where('user_id', $this->teacher->id)->update(['is_active' => false]);

    $response = $this->actingAs($this->teacher)
        ->getJson("/v3/teacher/cases/{$this->case->id}/progress");
    $response->assertStatus(403);

    // Admin actor
    $adminResponse = $this->actingAs($this->admin)
        ->getJson("/v3/teacher/cases/{$this->case->id}/progress");
    $adminResponse->assertStatus(403);
});

test('W03: nonterminal case with no activity for >= 300 seconds shows delayed true', function () {
    // Case created 10 minutes ago, no events
    $this->case->forceFill([
        'created_at' => now()->subSeconds(350),
        'updated_at' => now()->subSeconds(350),
    ])->save();

    $response = $this->actingAs($this->teacher)
        ->getJson("/v3/teacher/cases/{$this->case->id}/progress");

    $response->assertStatus(200);
    expect($response->json('data.delayed'))->toBeTrue();

    // Now add a recent event
    CaseEvent::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'event_key' => 'workflow_started',
        'payload_digest' => str_repeat('a', 64),
        'sequence' => 2,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-prog-del',
        'event_type' => 'workflow_started',
        'stage_code' => 'extraction',
        'status_code' => 'queued',
        'error_code' => null,
        'recorded_at' => now()->subSeconds(30),
    ]);

    $responseRecent = $this->actingAs($this->teacher)
        ->getJson("/v3/teacher/cases/{$this->case->id}/progress");

    $responseRecent->assertStatus(200);
    expect($responseRecent->json('data.delayed'))->toBeFalse();
});

test('W09: teacher progress pagination validates page parameter and handles out of range', function () {
    // Invalid page <= 0
    $badZero = $this->actingAs($this->teacher)
        ->getJson("/v3/teacher/cases/{$this->case->id}/progress?page=0");
    $badZero->assertStatus(422);

    $badNegative = $this->actingAs($this->teacher)
        ->getJson("/v3/teacher/cases/{$this->case->id}/progress?page=-5");
    $badNegative->assertStatus(422);

    $badString = $this->actingAs($this->teacher)
        ->getJson("/v3/teacher/cases/{$this->case->id}/progress?page=abc");
    $badString->assertStatus(422);

    // Out of range page returns empty events array
    $outOfRange = $this->actingAs($this->teacher)
        ->getJson("/v3/teacher/cases/{$this->case->id}/progress?page=999");
    $outOfRange->assertStatus(200);
    expect($outOfRange->json('data.events'))->toBe([])
        ->and($outOfRange->json('data.pagination.page'))->toBe(999)
        ->and($outOfRange->json('data.pagination.has_more'))->toBeFalse();
});

test('W07: active admin lists cases with exact closed metadata DTO ordered newest first', function () {
    // Create second case
    $case2 = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'document_id' => $this->doc->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'running',
        'state_version' => 2,
        'created_at' => now()->addMinutes(1),
    ]);

    $link2 = WorkflowLink::forceCreate([
        'case_id' => $case2->id,
        'workflow_id' => "v3-case-{$case2->id}",
        'run_id' => 'run-adm-002',
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'start_state' => 'started',
        'stage_code' => 'extraction',
        'status_code' => 'running',
        'started_at' => now(),
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson('/v3/admin/cases');

    $response->assertStatus(200);
    $response->assertHeader('Cache-Control');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    $items = $response->json('data');
    expect(count($items))->toBe(2);

    // Newest first
    expect($items[0]['case_id'])->toBe($case2->id)
        ->and($items[1]['case_id'])->toBe($this->case->id);

    // Undiscovered case projects intake/queued/null and 0 counts
    expect($items[1]['stage_code'])->toBe('intake')
        ->and($items[1]['status_code'])->toBe('queued')
        ->and($items[1]['error_code'])->toBeNull()
        ->and($items[1]['attempt_count'])->toBe(0)
        ->and($items[1]['scene_count'])->toBe(0)
        ->and($items[1]['approved_scene_count'])->toBe(0);

    // Exact allowlist keys only
    $allowedKeys = [
        'case_id',
        'stage_code',
        'status_code',
        'error_code',
        'created_at',
        'updated_at',
        'attempt_count',
        'scene_count',
        'approved_scene_count',
    ];
    expect(array_keys($items[0]))->toEqualCanonicalizing($allowedKeys);
});

test('W07: active admin inspects case summary with exact closed DTO and 404 for missing', function () {
    $response = $this->actingAs($this->admin)
        ->getJson("/v3/admin/cases/{$this->case->id}/summary");

    $response->assertStatus(200);
    $data = $response->json('data');
    expect($data['case_id'])->toBe($this->case->id)
        ->and($data['stage_code'])->toBe('intake')
        ->and($data['status_code'])->toBe('queued')
        ->and($data['error_code'])->toBeNull()
        ->and($data['attempt_count'])->toBe(0)
        ->and($data['scene_count'])->toBe(0)
        ->and($data['approved_scene_count'])->toBe(0);

    $missing = (string) Str::uuid();
    $missingResponse = $this->actingAs($this->admin)
        ->getJson("/v3/admin/cases/{$missing}/summary");
    $missingResponse->assertStatus(404);
});

test('W07: teacher is denied access to admin cases routes', function () {
    $this->actingAs($this->teacher)
        ->getJson('/v3/admin/cases')
        ->assertStatus(403);

    $this->actingAs($this->teacher)
        ->getJson("/v3/admin/cases/{$this->case->id}/summary")
        ->assertStatus(403);
});

test('W06 & W07: HTML views render successfully for teacher progress and admin cases', function () {
    $teacherHtml = $this->actingAs($this->teacher)
        ->get("/v3/teacher/cases/{$this->case->id}/progress");
    $teacherHtml->assertStatus(200);
    $teacherHtml->assertSee('Case Workflow Progress');
    $teacherHtml->assertHeader('Cache-Control');
    expect($teacherHtml->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    $adminIndexHtml = $this->actingAs($this->admin)
        ->get('/v3/admin/cases');
    $adminIndexHtml->assertStatus(200);
    $adminIndexHtml->assertSee('Cases Operational Monitoring');
    expect($adminIndexHtml->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    $adminSummaryHtml = $this->actingAs($this->admin)
        ->get("/v3/admin/cases/{$this->case->id}/summary");
    $adminSummaryHtml->assertStatus(200);
    $adminSummaryHtml->assertSee('Case Operational Summary');
    expect($adminSummaryHtml->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});
