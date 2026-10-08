<?php

namespace Tests\Feature\V3\Lessons;

use App\Models\V3\Document;
use App\Models\V3\ExtractedSource;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\LessonQuery;
use App\Models\V3\MapActivation;
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

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Worker Lesson Boundary');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Worker Lesson Boundary');

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

    $this->docContent = "PDF content";
    $this->docSha256 = hash('sha256', $this->docContent);
    $this->docPath = 'accepted/test-lesson-doc.pdf';
    Storage::disk('v3_private')->put($this->docPath, $this->docContent);

    $this->document = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'sha256' => $this->docSha256,
        'byte_size' => strlen($this->docContent),
        'page_count' => 3,
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
        'state_version' => 6,
    ]);

    $this->link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-lesson-bnd',
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
        'page_count' => 3,
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

test('L12: worker routes reject missing signature with 401 worker_unauthenticated', function () {
    $path = "/api/internal/v1/map-activations/{$this->mapActivation->id}/context";
    $this->getJson($path)->assertStatus(401)
        ->assertJson(['error' => ['code' => 'worker_unauthenticated']]);
});

test('L12: worker routes reject bad HMAC signature with 401 worker_unauthenticated', function () {
    $path = "/api/internal/v1/map-activations/{$this->mapActivation->id}/context";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, [], 'wrong-secret');
    $this->withHeaders($headers)->get($path)->assertStatus(401)
        ->assertJson(['error' => ['code' => 'worker_unauthenticated']]);
});

test('L12: worker routes reject stale timestamp (>300s) with 401 worker_unauthenticated', function () {
    $path = "/api/internal/v1/map-activations/{$this->mapActivation->id}/context";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, [], null, time() - 301);
    $this->withHeaders($headers)->get($path)->assertStatus(401)
        ->assertJson(['error' => ['code' => 'worker_unauthenticated']]);
});

test('L12: GET map activation context returns verified case and source binding metadata', function () {
    $path = "/api/internal/v1/map-activations/{$this->mapActivation->id}/context";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $resp = $this->withHeaders($headers)->get($path);
    $resp->assertStatus(200);
    expect($resp->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    $resp->assertJson([
        'data' => [
            'case_id' => $this->case->id,
            'binding_ref' => $this->binding->id,
            'map_ref' => null,
            'page_count' => 3,
            'source_sha256' => $this->extractedSource->text_sha256,
            'settings_version_id' => $this->settingsVersion->id,
            'state' => 'accepted',
            'work_ordinal' => null,
            'conversation_ref' => null,
        ],
    ]);
});

test('L12: GET unknown activation context returns 404 lesson_unavailable', function () {
    $unknownId = (string) Str::uuid();
    $path = "/api/internal/v1/map-activations/{$unknownId}/context";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $this->withHeaders($headers)->get($path)->assertStatus(404)
        ->assertJson(['error' => ['code' => 'lesson_unavailable']]);
});

test('L05: POST lesson-query claim creates sending claim with disposition send_now', function () {
    $path = "/api/internal/v1/lesson-queries/{$this->case->id}/divide/0/claim";
    $payload = [
        'schema_version' => 1,
        'activation_id' => $this->mapActivation->id,
        'binding_ref' => $this->binding->id,
        'query_text' => 'First, I want you to act as a Data Architect...',
        'conversation_in' => null,
    ];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $payload);

    $resp = $this->withHeaders($headers)->postJson($path, $payload);
    $resp->assertStatus(200);
    expect($resp->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    $resp->assertJson([
        'data' => [
            'disposition' => 'send_now',
        ],
    ]);

    $queryRef = $resp->json('data.query_ref');
    expect($queryRef)->not->toBeNull();

    $this->assertDatabaseHas('v3_lesson_queries', [
        'id' => $queryRef,
        'case_id' => $this->case->id,
        'kind' => 'divide',
        'target_work_ordinal' => 0,
        'state' => 'sending',
        'send_count' => 1,
    ]);
});

test('L05: POST lesson-query claim for duplicate identical query returns reconcile disposition', function () {
    $path = "/api/internal/v1/lesson-queries/{$this->case->id}/divide/0/claim";
    $payload = [
        'schema_version' => 1,
        'activation_id' => $this->mapActivation->id,
        'binding_ref' => $this->binding->id,
        'query_text' => 'First, I want you to act as a Data Architect...',
        'conversation_in' => null,
    ];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $payload);

    // First claim
    $resp1 = $this->withHeaders($headers)->postJson($path, $payload);
    $resp1->assertStatus(200)->assertJson(['data' => ['disposition' => 'send_now']]);

    // Second claim with identical body
    $resp2 = $this->withHeaders($headers)->postJson($path, $payload);
    $resp2->assertStatus(200)->assertJson(['data' => ['disposition' => 'reconcile']]);
});

test('L05: POST lesson-query claim exceeding 16384 bytes returns 422 lesson_invalid', function () {
    $path = "/api/internal/v1/lesson-queries/{$this->case->id}/divide/0/claim";
    $payload = [
        'schema_version' => 1,
        'activation_id' => $this->mapActivation->id,
        'binding_ref' => $this->binding->id,
        'query_text' => str_repeat('X', 16385),
        'conversation_in' => null,
    ];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $payload);

    $this->withHeaders($headers)->postJson($path, $payload)->assertStatus(422)
        ->assertJson(['error' => ['code' => 'lesson_invalid']]);
});

test('L10: GET lesson query status returns state without leaking raw answer', function () {
    $query = LessonQuery::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'kind' => 'divide',
        'target_work_ordinal' => 0,
        'state' => 'sending',
        'send_count' => 1,
        'request_sha256' => hash('sha256', 'request-1'),
    ]);

    $path = "/api/internal/v1/lesson-queries/{$query->id}";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $resp = $this->withHeaders($headers)->get($path);
    $resp->assertStatus(200);
    expect($resp->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    $resp->assertJson([
        'data' => [
            'state' => 'sending',
            'answer_sha256' => null,
            'conversation_out' => null,
            'error_code' => null,
        ],
    ]);
});

test('L12: GET cases lesson-context returns canonical lowercase digests', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/lesson-context/1";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $resp = $this->withHeaders($headers)->get($path);
    $resp->assertStatus(200);
    expect($resp->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    $resp->assertJson([
        'data' => [
            'canonical_digests' => [
                'divide' => '77620579df1bb2fb51876116b1266a32fe58ecd963486c3d76d5c98c46d012ff',
                    'execution' => '5ab2448f34c9b2b90199347772d10d7d217ba9fd468dffc28e3169bd0a7fdce9',
                    'first' => '04450e9fd7e0962ceb4f60a1f641e43b671d7be2622718e202af0ca92683b788',
                    'second' => 'a8776affc821a1b913a4685b309dc0304afc03c7389fcfe44b366b6f5683f203',
                    'third' => '07798d744b973ec11248897d2efe0e769ab481c4a21cce7562696dd131217955',
                ],
            ],
        ]);
});
