<?php

namespace Tests\Feature\V3\Intake;

use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\UploadAttempt;
use App\Services\V3\Intake\ClamAvAdapter;
use App\Services\V3\Intake\IntakeCoordinator;
use App\Services\V3\Intake\PdfInspector;
use App\Services\V3\Intake\PrivatePdfStore;
use App\Services\V3\Intake\QpdfAdapter;
use App\Services\V3\Settings\PublishSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\V3\Pdf\PdfFixtures;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->teacherA = AccessFixtures::createEnrolledTeacher('Teacher A');
    $this->teacherB = AccessFixtures::createEnrolledTeacher('Teacher B');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin User');

    SettingsFixtures::registerDefaultTemplates();
    $payload = SettingsFixtures::validPayload();
    $publisher = app(PublishSettings::class);
    $this->settingsVersion = $publisher->publish($payload, 0, $this->admin->id);

    Storage::fake('v3_private');
});

test('I07: same owner exact bytes upload links to existing Document with duplicate true and latest case', function () {
    $store = app(PrivatePdfStore::class);

    // Mock inspector so both pass
    $qpdfMock = \Mockery::mock(QpdfAdapter::class);
    $qpdfMock->shouldReceive('check')->andReturn(['valid' => true, 'page_count' => 1]);
    $clamMock = \Mockery::mock(ClamAvAdapter::class);
    $clamMock->shouldReceive('scan')->andReturn(['valid' => true]);
    $inspector = new PdfInspector($qpdfMock, $clamMock);
    $coordinator = new IntakeCoordinator($store, $inspector);

    $pdfContent = PdfFixtures::minimalValidPdf(1, 'Course Math 101');

    // 1. Teacher A uploads first time
    $attempt1Id = (string) Str::uuid();
    $store->storeQuarantine($attempt1Id, $pdfContent);
    UploadAttempt::forceCreate([
        'id' => $attempt1Id,
        'owner_user_id' => $this->teacherA->id,
        'upload_settings_version_id' => $this->settingsVersion->id,
        'state' => 'quarantined',
        'staging_path' => $store->quarantinePath($attempt1Id),
        'byte_size' => strlen($pdfContent),
        'expires_at' => now()->addHours(24),
    ]);

    $coordinator->processAttempt($attempt1Id);

    $attempt1 = UploadAttempt::findOrFail($attempt1Id);
    expect($attempt1->state)->toBe('accepted')
        ->and($attempt1->duplicate)->toBeFalse()
        ->and($attempt1->document_id)->not->toBeNull();

    $docId = $attempt1->document_id;
    $doc = Document::findOrFail($docId);
    expect($doc->state)->toBe('ready')
        ->and($doc->owner_user_id)->toBe($this->teacherA->id);

    // Create a processing case for Document 1
    $caseId = (string) Str::uuid();
    ProcessingCase::forceCreate([
        'id' => $caseId,
        'owner_user_id' => $this->teacherA->id,
        'document_id' => $docId,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'awaiting_workflow',
        'state_version' => 1,
    ]);

    // 2. Teacher A uploads exact same bytes a second time
    $attempt2Id = (string) Str::uuid();
    $store->storeQuarantine($attempt2Id, $pdfContent);
    UploadAttempt::forceCreate([
        'id' => $attempt2Id,
        'owner_user_id' => $this->teacherA->id,
        'upload_settings_version_id' => $this->settingsVersion->id,
        'state' => 'quarantined',
        'staging_path' => $store->quarantinePath($attempt2Id),
        'byte_size' => strlen($pdfContent),
        'expires_at' => now()->addHours(24),
    ]);

    $coordinator->processAttempt($attempt2Id);

    $attempt2 = UploadAttempt::findOrFail($attempt2Id);
    expect($attempt2->state)->toBe('accepted')
        ->and($attempt2->duplicate)->toBeTrue()
        ->and($attempt2->document_id)->toBe($docId)
        ->and($attempt2->staging_path)->toBeNull();

    // Verify polling API returns duplicate: true and latest_case_id
    $response = $this->actingAs($this->teacherA)
        ->get("/v3/teacher/pdf-uploads/{$attempt2Id}", ['Accept' => 'application/json']);

    $response->assertStatus(200)
        ->assertJsonPath('data.duplicate', true)
        ->assertJsonPath('data.latest_case_id', $caseId)
        ->assertJsonPath('data.document_id', $docId);

    // 3. One byte difference produces a new Document
    $pdfContentDiff = $pdfContent . " "; // 1 byte diff
    $attempt3Id = (string) Str::uuid();
    $store->storeQuarantine($attempt3Id, $pdfContentDiff);
    UploadAttempt::forceCreate([
        'id' => $attempt3Id,
        'owner_user_id' => $this->teacherA->id,
        'upload_settings_version_id' => $this->settingsVersion->id,
        'state' => 'quarantined',
        'staging_path' => $store->quarantinePath($attempt3Id),
        'byte_size' => strlen($pdfContentDiff),
        'expires_at' => now()->addHours(24),
    ]);

    $coordinator->processAttempt($attempt3Id);

    $attempt3 = UploadAttempt::findOrFail($attempt3Id);
    expect($attempt3->state)->toBe('accepted')
        ->and($attempt3->duplicate)->toBeFalse()
        ->and($attempt3->document_id)->not->toBe($docId);
});

test('I08: cross-owner identical bytes upload produces separate Document and reveals nothing', function () {
    $store = app(PrivatePdfStore::class);

    $qpdfMock = \Mockery::mock(QpdfAdapter::class);
    $qpdfMock->shouldReceive('check')->andReturn(['valid' => true, 'page_count' => 1]);
    $clamMock = \Mockery::mock(ClamAvAdapter::class);
    $clamMock->shouldReceive('scan')->andReturn(['valid' => true]);
    $inspector = new PdfInspector($qpdfMock, $clamMock);
    $coordinator = new IntakeCoordinator($store, $inspector);

    $pdfContent = PdfFixtures::minimalValidPdf(1, 'Shared Curriculum');

    // Teacher A uploads
    $attemptAId = (string) Str::uuid();
    $store->storeQuarantine($attemptAId, $pdfContent);
    UploadAttempt::forceCreate([
        'id' => $attemptAId,
        'owner_user_id' => $this->teacherA->id,
        'upload_settings_version_id' => $this->settingsVersion->id,
        'state' => 'quarantined',
        'staging_path' => $store->quarantinePath($attemptAId),
        'byte_size' => strlen($pdfContent),
        'expires_at' => now()->addHours(24),
    ]);
    $coordinator->processAttempt($attemptAId);
    $attemptA = UploadAttempt::findOrFail($attemptAId);

    // Teacher B uploads identical bytes
    $attemptBId = (string) Str::uuid();
    $store->storeQuarantine($attemptBId, $pdfContent);
    UploadAttempt::forceCreate([
        'id' => $attemptBId,
        'owner_user_id' => $this->teacherB->id,
        'upload_settings_version_id' => $this->settingsVersion->id,
        'state' => 'quarantined',
        'staging_path' => $store->quarantinePath($attemptBId),
        'byte_size' => strlen($pdfContent),
        'expires_at' => now()->addHours(24),
    ]);
    $coordinator->processAttempt($attemptBId);
    $attemptB = UploadAttempt::findOrFail($attemptBId);

    // Document rows are completely distinct
    expect($attemptA->document_id)->not->toBe($attemptB->document_id)
        ->and($attemptA->duplicate)->toBeFalse()
        ->and($attemptB->duplicate)->toBeFalse();

    $docA = Document::findOrFail($attemptA->document_id);
    $docB = Document::findOrFail($attemptB->document_id);

    expect($docA->owner_user_id)->toBe($this->teacherA->id)
        ->and($docB->owner_user_id)->toBe($this->teacherB->id)
        ->and($docA->storage_path)->not->toBe($docB->storage_path);

    // Physical storage files are distinct
    expect($store->exists($docA->storage_path))->toBeTrue()
        ->and($store->exists($docB->storage_path))->toBeTrue();

    // Teacher B polling Teacher A attempt receives 404
    $resForeign = $this->actingAs($this->teacherB)
        ->get("/v3/teacher/pdf-uploads/{$attemptAId}", ['Accept' => 'application/json']);

    $resAbsent = $this->actingAs($this->teacherB)
        ->get('/v3/teacher/pdf-uploads/' . (string) Str::uuid(), ['Accept' => 'application/json']);

    expect($resForeign->status())->toBe(404)
        ->and($resAbsent->status())->toBe(404)
        ->and($resForeign->json())->toEqual($resAbsent->json());
});
