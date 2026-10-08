<?php

namespace Tests\Feature\V3\Intake;

use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\UploadAttempt;
use App\Services\V3\Settings\PublishSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\V3\Pdf\PdfFixtures;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Privacy');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin User');

    SettingsFixtures::registerDefaultTemplates();
    $payload = SettingsFixtures::validPayload();
    $publisher = app(PublishSettings::class);
    $this->settingsVersion = $publisher->publish($payload, 0, $this->admin->id);

    Storage::fake('v3_private');
});

test('I18: AttemptSummary strictly adheres to allowlisted schema and exposes no sensitive internal metadata', function () {
    $docId = (string) Str::uuid();
    $pdfContent = PdfFixtures::minimalValidPdf(1);
    $storagePath = "accepted/{$docId}.pdf";
    Storage::disk('v3_private')->put($storagePath, $pdfContent);

    $doc = Document::forceCreate([
        'id' => $docId,
        'owner_user_id' => $this->teacher->id,
        'sha256' => hash('sha256', $pdfContent),
        'byte_size' => strlen($pdfContent),
        'page_count' => 1,
        'state' => 'ready',
        'storage_path' => $storagePath,
        'accepted_at' => now(),
    ]);

    $attempt = UploadAttempt::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'upload_settings_version_id' => $this->settingsVersion->id,
        'document_id' => $doc->id,
        'state' => 'accepted',
        'duplicate' => false,
        'staging_path' => null,
        'byte_size' => strlen($pdfContent),
        'expires_at' => now()->addHours(24),
        'finished_at' => now(),
    ]);

    // GET /v3/teacher/pdf-uploads/{attempt}
    $response = $this->actingAs($this->teacher)->getJson("/v3/teacher/pdf-uploads/{$attempt->id}");
    $response->assertStatus(200);

    $data = $response->json('data');

    // Exactly the allowlisted fields
    $expectedKeys = [
        'attempt_id',
        'state',
        'reason_code',
        'document_id',
        'duplicate',
        'latest_case_id',
        'created_at',
        'finished_at',
    ];
    sort($expectedKeys);
    $actualKeys = array_keys($data);
    sort($actualKeys);
    expect($actualKeys)->toBe($expectedKeys);

    // Forbidden keys MUST NOT be present
    $forbidden = [
        'filename', 'sha256', 'hash', 'staging_path', 'storage_path', 'byte_size',
        'page_count', 'scan_attempts', 'technical_failures', 'settings', 'payload',
    ];
    foreach ($forbidden as $key) {
        expect(array_key_exists($key, $data))->toBeFalse();
    }
});

test('I18: CaseReceipt strictly adheres to allowlisted schema and exposes no internal details', function () {
    $docId = (string) Str::uuid();
    $pdfContent = PdfFixtures::minimalValidPdf(1);
    $storagePath = "accepted/{$docId}.pdf";
    Storage::disk('v3_private')->put($storagePath, $pdfContent);

    $doc = Document::forceCreate([
        'id' => $docId,
        'owner_user_id' => $this->teacher->id,
        'sha256' => hash('sha256', $pdfContent),
        'byte_size' => strlen($pdfContent),
        'page_count' => 1,
        'state' => 'ready',
        'storage_path' => $storagePath,
        'accepted_at' => now(),
    ]);

    $case = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'document_id' => $doc->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'awaiting_workflow',
        'state_version' => 1,
    ]);

    $response = $this->actingAs($this->teacher)->getJson("/v3/teacher/cases/{$case->id}");
    $response->assertStatus(200);

    $data = $response->json('data');

    $expectedKeys = [
        'case_id',
        'document_id',
        'state',
        'settings_version_id',
        'created_at',
    ];
    sort($expectedKeys);
    $actualKeys = array_keys($data);
    sort($actualKeys);
    expect($actualKeys)->toBe($expectedKeys);

    $forbidden = [
        'filename', 'sha256', 'hash', 'path', 'storage_path', 'owner_user_id',
        'idempotency_key', 'action', 'workflow_id', 'settings',
    ];
    foreach ($forbidden as $key) {
        expect(array_key_exists($key, $data))->toBeFalse();
    }
});

test('I18: all 006 JSON and streaming endpoints enforce Cache-Control private, no-store', function () {
    $file = UploadedFile::fake()->createWithContent('lesson.pdf', PdfFixtures::minimalValidPdf(1));
    $uploadResp = $this->actingAs($this->teacher)->post('/v3/teacher/pdf-uploads', ['pdf' => $file], ['Accept' => 'application/json']);
    expect($uploadResp->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    $listResp = $this->actingAs($this->teacher)->getJson('/v3/teacher/pdf-uploads');
    expect($listResp->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});
