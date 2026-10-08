<?php

namespace Tests\Feature\V3\Intake;

use App\Models\V3\SettingsVersion;
use App\Models\V3\UploadAttempt;
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
    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Uploader');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin User');
    $this->student = AccessFixtures::createStudent('Student User');

    SettingsFixtures::registerDefaultTemplates();
    $payload = SettingsFixtures::validPayload(0, [
        'limits' => [
            'max_pdf_bytes' => 1024 * 1024, // 1 MiB limit
            'max_pdf_pages' => 50,
        ],
    ]);

    $publisher = app(\App\Services\V3\Settings\PublishSettings::class);
    $this->settingsVersion = $publisher->publish($payload, 0, $this->admin->id);

    // Use fake or local v3_private disk
    Storage::fake('v3_private');
});

test('I01: authenticated teacher uploads valid PDF and receives 202 with attempt_id and quarantined state', function () {
    $pdfContent = PdfFixtures::minimalValidPdf(1);
    $file = UploadedFile::fake()->createWithContent('lesson.pdf', $pdfContent);

    $response = $this->actingAs($this->teacher)
        ->post('/v3/teacher/pdf-uploads', [
            'pdf' => $file,
        ], ['Accept' => 'application/json']);

    $response->assertStatus(202);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $response->assertJsonStructure([
            'data' => [
                'attempt_id',
                'state',
                'status_url',
            ],
        ]);

    $attemptId = $response->json('data.attempt_id');
    expect($response->json('data.state'))->toBe('quarantined');

    $attempt = UploadAttempt::findOrFail($attemptId);
    expect($attempt->owner_user_id)->toBe($this->teacher->id)
        ->and($attempt->state)->toBe('quarantined')
        ->and($attempt->upload_settings_version_id)->toBe($this->settingsVersion->id)
        ->and($attempt->document_id)->toBeNull();

    // Verify quarantined file stored in v3_private/quarantine/{id}.pdf
    Storage::disk('v3_private')->assertExists("quarantine/{$attemptId}.pdf");
});

test('I02: upload with non-PDF extension or missing %PDF- header is rejected with 422 and creates attempt row', function () {
    // 1. Non-pdf extension
    $fileTxt = UploadedFile::fake()->createWithContent('lesson.txt', 'Hello text');

    $responseTxt = $this->actingAs($this->teacher)
        ->post('/v3/teacher/pdf-uploads', [
            'pdf' => $fileTxt,
        ], ['Accept' => 'application/json']);

    $responseTxt->assertStatus(422);
    expect($responseTxt->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $responseTxt->assertJsonPath('error.code', 'not_pdf')
        ->assertJsonStructure(['error', 'attempt_id']);

    $attemptId = $responseTxt->json('attempt_id');
    $attempt = UploadAttempt::findOrFail($attemptId);
    expect($attempt->state)->toBe('rejected')
        ->and($attempt->reason_code)->toBe('not_pdf');

    // 2. Renamed file (starts with MZ, not %PDF-)
    $fileExe = UploadedFile::fake()->createWithContent('malware.pdf', PdfFixtures::notPdf());

    $responseExe = $this->actingAs($this->teacher)
        ->post('/v3/teacher/pdf-uploads', [
            'pdf' => $fileExe,
        ], ['Accept' => 'application/json']);

    $responseExe->assertStatus(422)
        ->assertJsonPath('error.code', 'not_pdf')
        ->assertJsonStructure(['error', 'attempt_id']);
});

test('I02: upload exceeding active byte limit returns 422 size_exceeded with attempt_id', function () {
    // Limit is 1 MiB. Send 2 MiB.
    $bigPdf = "%PDF-1.4\n" . str_repeat("0", 2 * 1024 * 1024);
    $file = UploadedFile::fake()->createWithContent('big.pdf', $bigPdf);

    $response = $this->actingAs($this->teacher)
        ->post('/v3/teacher/pdf-uploads', [
            'pdf' => $file,
        ], ['Accept' => 'application/json']);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'size_exceeded')
        ->assertJsonStructure(['error', 'attempt_id']);

    $attemptId = $response->json('attempt_id');
    $attempt = UploadAttempt::findOrFail($attemptId);
    expect($attempt->state)->toBe('rejected')
        ->and($attempt->reason_code)->toBe('size_exceeded');

    // Staged bytes must not be retained
    Storage::disk('v3_private')->assertMissing("quarantine/{$attemptId}.pdf");
});

test('I02: upload with extra application fields is rejected with 422 upload_invalid', function () {
    $pdfContent = PdfFixtures::minimalValidPdf(1);
    $file = UploadedFile::fake()->createWithContent('lesson.pdf', $pdfContent);

    $response = $this->actingAs($this->teacher)
        ->post('/v3/teacher/pdf-uploads', [
            'pdf' => $file,
            'owner_user_id' => 999, // forbidden extra field
        ], ['Accept' => 'application/json']);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'upload_invalid')
        ->assertJsonStructure(['error', 'attempt_id']);
});

test('I05: missing active 005 settings creates blocked attempt and returns 503 settings_unavailable', function () {
    // Delete pointer
    \Illuminate\Support\Facades\DB::table('v3_active_settings')->delete();

    $pdfContent = PdfFixtures::minimalValidPdf(1);
    $file = UploadedFile::fake()->createWithContent('lesson.pdf', $pdfContent);

    $response = $this->actingAs($this->teacher)
        ->post('/v3/teacher/pdf-uploads', [
            'pdf' => $file,
        ], ['Accept' => 'application/json']);

    $response->assertStatus(503)
        ->assertJsonPath('error.code', 'settings_unavailable')
        ->assertJsonStructure(['error', 'attempt_id']);

    $attemptId = $response->json('attempt_id');
    $attempt = UploadAttempt::findOrFail($attemptId);
    expect($attempt->state)->toBe('blocked')
        ->and($attempt->reason_code)->toBe('settings_unavailable')
        ->and($attempt->upload_settings_version_id)->toBeNull();
});

test('I07: teacher can poll own attempt and list attempts without exposing sensitive metadata', function () {
    $attempt = UploadAttempt::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'upload_settings_version_id' => $this->settingsVersion->id,
        'state' => 'quarantined',
        'expires_at' => now()->addHours(24),
    ]);

    // Single attempt GET
    $response = $this->actingAs($this->teacher)
        ->get("/v3/teacher/pdf-uploads/{$attempt->id}", ['Accept' => 'application/json']);

    $response->assertStatus(200);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $response->assertExactJson([
            'data' => [
                'attempt_id' => $attempt->id,
                'state' => 'quarantined',
                'reason_code' => null,
                'document_id' => null,
                'duplicate' => null,
                'latest_case_id' => null,
                'created_at' => $attempt->created_at->toISOString(),
                'finished_at' => null,
            ],
        ]);

    // Index GET
    $indexResponse = $this->actingAs($this->teacher)
        ->get('/v3/teacher/pdf-uploads', ['Accept' => 'application/json']);

    $indexResponse->assertStatus(200);
    expect($indexResponse->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $indexResponse->assertJsonStructure([
            'data' => [
                '*' => [
                    'attempt_id',
                    'state',
                    'reason_code',
                    'document_id',
                    'duplicate',
                    'latest_case_id',
                    'created_at',
                    'finished_at',
                ],
            ],
            'pagination' => ['page', 'per_page', 'has_more'],
        ]);
});

test('I08: foreign teacher or admin cannot access teacher attempt', function () {
    $foreignTeacher = AccessFixtures::createEnrolledTeacher('Foreign Teacher');

    $attempt = UploadAttempt::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'upload_settings_version_id' => $this->settingsVersion->id,
        'state' => 'quarantined',
        'expires_at' => now()->addHours(24),
    ]);

    // Foreign teacher gets 404 resource_unavailable
    $foreignResponse = $this->actingAs($foreignTeacher)
        ->get("/v3/teacher/pdf-uploads/{$attempt->id}", ['Accept' => 'application/json']);

    $foreignResponse->assertStatus(404)
        ->assertJsonPath('error.code', 'resource_unavailable');

    // Admin gets 403 access_denied from teacher route
    $adminResponse = $this->actingAs($this->admin)
        ->get("/v3/teacher/pdf-uploads/{$attempt->id}", ['Accept' => 'application/json']);

    $adminResponse->assertStatus(403)
        ->assertJsonPath('error.code', 'access_denied');
});
