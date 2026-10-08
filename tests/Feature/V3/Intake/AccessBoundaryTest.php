<?php

namespace Tests\Feature\V3\Intake;

use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\UploadAttempt;
use App\Services\V3\Settings\PublishSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\V3\Pdf\PdfFixtures;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->teacherA = AccessFixtures::createEnrolledTeacher('Teacher Alpha');
    $this->teacherB = AccessFixtures::createEnrolledTeacher('Teacher Beta');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin User');
    $this->student = AccessFixtures::createStudent('Student User');

    SettingsFixtures::registerDefaultTemplates();
    $payload = SettingsFixtures::validPayload();
    $publisher = app(PublishSettings::class);
    $this->settingsVersion = $publisher->publish($payload, 0, $this->admin->id);

    Storage::fake('v3_private');

    // Create resources owned by Teacher A
    $pdfContent = PdfFixtures::minimalValidPdf(2);
    $this->docAId = (string) Str::uuid();
    $storagePath = "accepted/{$this->docAId}.pdf";
    Storage::disk('v3_private')->put($storagePath, $pdfContent);

    $this->docA = Document::forceCreate([
        'id' => $this->docAId,
        'owner_user_id' => $this->teacherA->id,
        'sha256' => hash('sha256', $pdfContent),
        'byte_size' => strlen($pdfContent),
        'page_count' => 2,
        'state' => 'ready',
        'storage_path' => $storagePath,
        'accepted_at' => now(),
    ]);

    $this->attemptA = UploadAttempt::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherA->id,
        'upload_settings_version_id' => $this->settingsVersion->id,
        'document_id' => $this->docA->id,
        'state' => 'accepted',
        'duplicate' => false,
        'expires_at' => now()->addHours(24),
        'finished_at' => now(),
    ]);

    $this->caseA = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherA->id,
        'document_id' => $this->docA->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'awaiting_workflow',
        'state_version' => 1,
    ]);
});

test('I14/I17: unauthenticated and non-teacher roles are denied from all 006 teacher endpoints', function () {
    $endpoints = [
        ['GET', '/v3/teacher/pdf-uploads'],
        ['GET', "/v3/teacher/pdf-uploads/{$this->attemptA->id}"],
        ['GET', "/v3/teacher/documents/{$this->docA->id}/download"],
        ['GET', "/v3/teacher/cases/{$this->caseA->id}"],
        ['POST', '/v3/teacher/pdf-uploads'],
        ['POST', '/v3/teacher/cases'],
    ];

    foreach ($endpoints as [$method, $uri]) {
        // 1. Unauthenticated -> 401 (or redirect for web)
        auth()->logout();
        $unauthResp = $this->json($method, $uri);
        expect(in_array($unauthResp->status(), [401, 302], true))->toBeTrue();

        // 2. Admin -> 403
        $adminResp = $this->actingAs($this->admin)->json($method, $uri);
        expect($adminResp->status())->toBe(403);

        // 3. Student -> 403
        $studentResp = $this->actingAs($this->student)->json($method, $uri);
        expect($studentResp->status())->toBe(403);
    }
});

test('I14/I17: inactive teacher is denied with 403', function () {
    DB::table('v3_account_access')->where('user_id', $this->teacherA->id)->update(['is_active' => false]);

    $response = $this->actingAs($this->teacherA)->getJson("/v3/teacher/documents/{$this->docA->id}/download");
    expect($response->status())->toBe(403);
});

test('I08/I14: foreign teacher receives identical 404 resource_unavailable as missing resources', function () {
    $nonExistentId = (string) Str::uuid();

    // 1. Upload Attempt Show: foreign vs absent
    $respForeignAttempt = $this->actingAs($this->teacherB)->getJson("/v3/teacher/pdf-uploads/{$this->attemptA->id}");
    $respAbsentAttempt = $this->actingAs($this->teacherB)->getJson("/v3/teacher/pdf-uploads/{$nonExistentId}");
    expect($respForeignAttempt->status())->toBe(404)
        ->and($respAbsentAttempt->status())->toBe(404)
        ->and($respForeignAttempt->json())->toEqual($respAbsentAttempt->json())
        ->and($respForeignAttempt->json('error.code'))->toBe('resource_unavailable');

    // 2. Document Download: foreign vs absent
    $respForeignDoc = $this->actingAs($this->teacherB)->getJson("/v3/teacher/documents/{$this->docA->id}/download");
    $respAbsentDoc = $this->actingAs($this->teacherB)->getJson("/v3/teacher/documents/{$nonExistentId}/download");
    expect($respForeignDoc->status())->toBe(404)
        ->and($respAbsentDoc->status())->toBe(404)
        ->and($respForeignDoc->json())->toEqual($respAbsentDoc->json())
        ->and($respForeignDoc->json('error.code'))->toBe('resource_unavailable');

    // 3. Case Show: foreign vs absent
    $respForeignCase = $this->actingAs($this->teacherB)->getJson("/v3/teacher/cases/{$this->caseA->id}");
    $respAbsentCase = $this->actingAs($this->teacherB)->getJson("/v3/teacher/cases/{$nonExistentId}");
    expect($respForeignCase->status())->toBe(404)
        ->and($respAbsentCase->status())->toBe(404)
        ->and($respForeignCase->json())->toEqual($respAbsentCase->json())
        ->and($respForeignCase->json('error.code'))->toBe('resource_unavailable');
});

test('I17: verified owner downloads private stream with proper headers and no redirect or storage URL', function () {
    $response = $this->actingAs($this->teacherA)->get("/v3/teacher/documents/{$this->docA->id}/download");

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="source.pdf"')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    // Must be raw stream, not a redirect
    expect($response->isRedirect())->toBeFalse();

    // Verify content matches stored PDF
    $content = $response->streamedContent();
    expect(hash('sha256', $content))->toBe($this->docA->sha256);
});

test('I17: pending document cannot be downloaded and returns 409 document_not_ready', function () {
    $pendingDoc = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherA->id,
        'sha256' => hash('sha256', 'pending-bytes'),
        'byte_size' => 100,
        'page_count' => 1,
        'state' => 'pending_move',
        'storage_path' => 'accepted/pending.pdf',
        'accepted_at' => null,
    ]);

    $response = $this->actingAs($this->teacherA)->getJson("/v3/teacher/documents/{$pendingDoc->id}/download");
    $response->assertStatus(409)
        ->assertJson([
            'error' => [
                'code' => 'document_not_ready',
            ],
        ]);
});

test('I17: storage missing or corrupt accepted file returns 503 storage_unavailable', function () {
    // Delete file
    Storage::disk('v3_private')->delete($this->docA->storage_path);

    $response = $this->actingAs($this->teacherA)->getJson("/v3/teacher/documents/{$this->docA->id}/download");
    $response->assertStatus(503)
        ->assertJson([
            'error' => [
                'code' => 'storage_unavailable',
            ],
        ]);
});
