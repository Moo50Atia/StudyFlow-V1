<?php

namespace Tests\Feature\V3\Sources;

use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SourceActivation;
use App\Models\V3\WorkflowLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\Support\V3\WorkflowFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Worker Boundary');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Worker Boundary');

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

    $this->pdfContent = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
    $this->docSha256 = hash('sha256', $this->pdfContent);
    $this->docPath = 'accepted/test-doc.pdf';
    Storage::disk('v3_private')->put($this->docPath, $this->pdfContent);

    $this->document = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'sha256' => $this->docSha256,
        'byte_size' => strlen($this->pdfContent),
        'page_count' => 2,
        'state' => 'ready',
        'storage_path' => $this->docPath,
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
        'run_id' => 'run-wf-bnd',
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'start_state' => 'started',
        'stage_code' => 'extraction',
        'status_code' => 'running',
        'started_at' => now(),
    ]);

    $this->activation = SourceActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'accepted',
        'signal_accepted_at' => now(),
    ]);
});

test('K02: signed GET with matching activation streams verified PDF with strict headers', function () {
    $path = "/api/internal/v1/source-activations/{$this->activation->id}/pdf";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $resp = $this->withHeaders($headers)->get($path);

    $resp->assertStatus(200);
    expect($resp->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($resp->headers->get('Cache-Control'))->toContain('private')
        ->and($resp->headers->get('Cache-Control'))->toContain('no-store')
        ->and($resp->headers->get('X-V3-Case-ID'))->toBe($this->case->id)
        ->and($resp->headers->get('X-V3-Document-SHA256'))->toBe($this->docSha256)
        ->and((int) $resp->headers->get('X-V3-Page-Count'))->toBe(2)
        ->and((int) $resp->headers->get('X-V3-Max-OCR-Pages'))->toBe(20)
        ->and((int) $resp->headers->get('X-V3-Max-PDF-Bytes'))->toBe(10485760)
        ->and((int) $resp->headers->get('X-V3-Settings-Version-ID'))->toBe($this->settingsVersion->id)
        ->and($resp->streamedContent())->toBe($this->pdfContent);
});

test('K02: signed PDF stream remains available after workflow starts extraction', function () {
    $this->case->state = 'running';
    $this->case->save();

    $path = "/api/internal/v1/source-activations/{$this->activation->id}/pdf";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $this->withHeaders($headers)->get($path)
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');
});

test('K02: missing or invalid signature returns 401 worker_unauthenticated', function () {
    $path = "/api/internal/v1/source-activations/{$this->activation->id}/pdf";

    // No signature headers
    $this->getJson($path)->assertStatus(401)
        ->assertJson(['error' => ['code' => 'worker_unauthenticated']]);

    // Wrong signature
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, [], 'wrong-secret');
    $this->withHeaders($headers)->get($path)->assertStatus(401)
        ->assertJson(['error' => ['code' => 'worker_unauthenticated']]);
});

test('K02: stale signature timestamp beyond 300 seconds returns 401 worker_unauthenticated', function () {
    $path = "/api/internal/v1/source-activations/{$this->activation->id}/pdf";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, [], null, time() - 301);

    $this->withHeaders($headers)->get($path)->assertStatus(401)
        ->assertJson(['error' => ['code' => 'worker_unauthenticated']]);
});

test('K02: unknown activation returns 404 source_unavailable', function () {
    $unknownId = (string) Str::uuid();
    $path = "/api/internal/v1/source-activations/{$unknownId}/pdf";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $this->withHeaders($headers)->get($path)->assertStatus(404)
        ->assertJson(['error' => ['code' => 'source_unavailable', 'message' => 'Source is unavailable.']]);
});

test('K02: activation in pending state returns 404 source_unavailable', function () {
    $this->activation->update(['state' => 'pending']);

    $path = "/api/internal/v1/source-activations/{$this->activation->id}/pdf";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $this->withHeaders($headers)->get($path)->assertStatus(404)
        ->assertJson(['error' => ['code' => 'source_unavailable', 'message' => 'Source is unavailable.']]);
});

test('K02: corrupted or missing accepted PDF bytes returns 503 source_integrity_failure', function () {
    // Corrupt the PDF file in storage
    Storage::disk('v3_private')->put($this->docPath, "corrupted content");

    $path = "/api/internal/v1/source-activations/{$this->activation->id}/pdf";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $this->withHeaders($headers)->get($path)->assertStatus(503)
        ->assertJson(['error' => ['code' => 'source_integrity_failure', 'message' => 'Source integrity check failed.']]);
});

test('K02: missing or corrupted captured settings returns 503 settings_unavailable', function () {
    // Corrupt settings checksum
    DB::table('v3_settings_versions')->where('id', $this->settingsVersion->id)->update(['checksum' => 'wrong_checksum']);

    $path = "/api/internal/v1/source-activations/{$this->activation->id}/pdf";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $this->withHeaders($headers)->get($path)->assertStatus(503)
        ->assertJson(['error' => ['code' => 'settings_unavailable', 'message' => 'Captured settings are unavailable.']]);
});

test('K15: browser session or admin credentials cannot authorize the worker route without HMAC', function () {
    $path = "/api/internal/v1/source-activations/{$this->activation->id}/pdf";

    // Authenticated as admin with session
    $this->actingAs($this->admin)->getJson($path)->assertStatus(401)
        ->assertJson(['error' => ['code' => 'worker_unauthenticated']]);

    // Authenticated as teacher owner with session
    $this->actingAs($this->teacher)->getJson($path)->assertStatus(401)
        ->assertJson(['error' => ['code' => 'worker_unauthenticated']]);
});
