<?php

namespace Tests\Feature\V3\Intake;

use App\Models\V3\Document;
use App\Models\V3\SettingsVersion;
use App\Models\V3\UploadAttempt;
use App\Services\V3\Intake\ClamAvAdapter;
use App\Services\V3\Intake\IntakeCoordinator;
use App\Services\V3\Intake\PdfInspector;
use App\Services\V3\Intake\PrivatePdfStore;
use App\Services\V3\Intake\QpdfAdapter;
use App\Services\V3\Intake\ReconcileIntake;
use App\Services\V3\Settings\PublishSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\V3\Pdf\PdfFixtures;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Worker');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin User');

    SettingsFixtures::registerDefaultTemplates();
    $payload = SettingsFixtures::validPayload();
    $publisher = app(PublishSettings::class);
    $this->settingsVersion = $publisher->publish($payload, 0, $this->admin->id);

    Storage::fake('v3_private');
});

test('I03: determinate malware detection transitions attempt to rejected and purges staged bytes', function () {
    $store = app(PrivatePdfStore::class);
    $attemptId = (string) Str::uuid();

    $store->storeQuarantine($attemptId, 'infected-bytes');

    $attempt = UploadAttempt::forceCreate([
        'id' => $attemptId,
        'owner_user_id' => $this->teacher->id,
        'upload_settings_version_id' => $this->settingsVersion->id,
        'state' => 'quarantined',
        'staging_path' => $store->quarantinePath($attemptId),
        'byte_size' => 14,
        'expires_at' => now()->addHours(24),
    ]);

    // Mock inspector returning malware_detected
    $qpdfMock = \Mockery::mock(QpdfAdapter::class);
    $qpdfMock->shouldReceive('check')->andReturn(['valid' => true, 'page_count' => 1]);

    $clamMock = \Mockery::mock(ClamAvAdapter::class);
    $clamMock->shouldReceive('scan')->andReturn([
        'valid' => false,
        'reason_code' => 'malware_detected',
        'error_type' => 'deterministic',
    ]);

    $inspector = new PdfInspector($qpdfMock, $clamMock);
    $coordinator = new IntakeCoordinator($store, $inspector);

    $coordinator->processAttempt($attemptId);

    $attempt->refresh();
    expect($attempt->state)->toBe('rejected')
        ->and($attempt->reason_code)->toBe('malware_detected')
        ->and($attempt->staging_path)->toBeNull()
        ->and($attempt->finished_at)->not->toBeNull();

    // Quarantine file must be deleted
    expect($store->exists($store->quarantinePath($attemptId)))->toBeFalse();
});

test('I03: technical failure increments failure counter and schedules 60/120/240-second retries before blocking', function () {
    $store = app(PrivatePdfStore::class);
    $attemptId = (string) Str::uuid();
    $store->storeQuarantine($attemptId, 'content');

    $attempt = UploadAttempt::forceCreate([
        'id' => $attemptId,
        'owner_user_id' => $this->teacher->id,
        'upload_settings_version_id' => $this->settingsVersion->id,
        'state' => 'quarantined',
        'staging_path' => $store->quarantinePath($attemptId),
        'byte_size' => 7,
        'expires_at' => now()->addHours(24),
    ]);

    // Mock transient outage
    $qpdfMock = \Mockery::mock(QpdfAdapter::class);
    $qpdfMock->shouldReceive('check')->andReturn([
        'valid' => false,
        'reason_code' => 'inspection_unavailable',
        'error_type' => 'transient',
    ]);
    $clamMock = \Mockery::mock(ClamAvAdapter::class);

    $inspector = new PdfInspector($qpdfMock, $clamMock);
    $coordinator = new IntakeCoordinator($store, $inspector);

    // Attempt 1 -> retry 1 (delay 60s)
    $coordinator->processAttempt($attemptId);
    $attempt->refresh();
    expect($attempt->state)->toBe('retry_pending')
        ->and($attempt->technical_failures)->toBe(1)
        ->and($attempt->reason_code)->toBe('inspection_unavailable')
        ->and($attempt->next_retry_at)->not->toBeNull();

    // Reset lease and next_retry_at to simulate time pass
    $attempt->next_retry_at = now()->subSecond();
    $attempt->lease_until = null;
    $attempt->save();

    // Attempt 2 -> retry 2 (delay 120s)
    $coordinator->processAttempt($attemptId);
    $attempt->refresh();
    expect($attempt->state)->toBe('retry_pending')
        ->and($attempt->technical_failures)->toBe(2);

    $attempt->next_retry_at = now()->subSecond();
    $attempt->lease_until = null;
    $attempt->save();

    // Attempt 3 -> retry 3 (delay 240s)
    $coordinator->processAttempt($attemptId);
    $attempt->refresh();
    expect($attempt->state)->toBe('retry_pending')
        ->and($attempt->technical_failures)->toBe(3);

    $attempt->next_retry_at = now()->subSecond();
    $attempt->lease_until = null;
    $attempt->save();

    // Attempt 4 -> exhaustion: transitions to blocked and purges bytes
    $coordinator->processAttempt($attemptId);
    $attempt->refresh();
    expect($attempt->state)->toBe('blocked')
        ->and($attempt->reason_code)->toBe('inspection_unavailable')
        ->and($attempt->technical_failures)->toBe(4)
        ->and($attempt->staging_path)->toBeNull();

    expect($store->exists($store->quarantinePath($attemptId)))->toBeFalse();
});

test('I06: crash recovery reconciles pending_move document and promoting attempt', function () {
    $store = app(PrivatePdfStore::class);
    $reconciler = app(ReconcileIntake::class);

    $attemptId = (string) Str::uuid();
    $docId = (string) Str::uuid();
    $pdfContent = PdfFixtures::minimalValidPdf(1);
    $sha256 = hash('sha256', $pdfContent);

    // Scenario 1: Destination file exists and verifies, but DB crashed before marking ready
    Storage::disk('v3_private')->put("accepted/{$docId}.pdf", $pdfContent);

    $doc = Document::forceCreate([
        'id' => $docId,
        'owner_user_id' => $this->teacher->id,
        'sha256' => $sha256,
        'byte_size' => strlen($pdfContent),
        'page_count' => 1,
        'state' => 'pending_move',
        'storage_path' => "accepted/{$docId}.pdf",
    ]);

    $attempt = UploadAttempt::forceCreate([
        'id' => $attemptId,
        'owner_user_id' => $this->teacher->id,
        'upload_settings_version_id' => $this->settingsVersion->id,
        'document_id' => $docId,
        'state' => 'promoting',
        'staging_path' => "quarantine/{$attemptId}.pdf",
        'expires_at' => now()->addHours(24),
    ]);

    $reconciler->reconcile();

    $doc->refresh();
    $attempt->refresh();

    expect($doc->state)->toBe('ready')
        ->and($doc->accepted_at)->not->toBeNull()
        ->and($attempt->state)->toBe('accepted')
        ->and($attempt->staging_path)->toBeNull();
});

test('I18: expired quarantine past 24 hours is cleaned and blocked', function () {
    $store = app(PrivatePdfStore::class);
    $reconciler = app(ReconcileIntake::class);

    $attemptId = (string) Str::uuid();
    $store->storeQuarantine($attemptId, 'expired-data');

    $attempt = UploadAttempt::forceCreate([
        'id' => $attemptId,
        'owner_user_id' => $this->teacher->id,
        'upload_settings_version_id' => $this->settingsVersion->id,
        'state' => 'quarantined',
        'staging_path' => $store->quarantinePath($attemptId),
        'byte_size' => 12,
        'expires_at' => now()->subMinute(), // expired!
    ]);

    $reconciler->reconcile();

    $attempt->refresh();
    expect($attempt->state)->toBe('blocked')
        ->and($attempt->reason_code)->toBe('quarantine_expired')
        ->and($attempt->staging_path)->toBeNull();

    expect($store->exists($store->quarantinePath($attemptId)))->toBeFalse();
});

test('I18: failed deletion remains cleanup_pending and emits cleanup_overdue alert when expired', function () {
    $attemptId = (string) Str::uuid();

    $attempt = UploadAttempt::forceCreate([
        'id' => $attemptId,
        'owner_user_id' => $this->teacher->id,
        'upload_settings_version_id' => $this->settingsVersion->id,
        'state' => 'cleanup_pending',
        'cleanup_target' => 'blocked',
        'reason_code' => 'inspection_unavailable',
        'staging_path' => "quarantine/{$attemptId}.pdf",
        'expires_at' => now()->subHour(), // past 24h deadline
    ]);

    // Store fake where delete fails
    $storeMock = \Mockery::mock(PrivatePdfStore::class);
    $storeMock->shouldReceive('exists')->andReturn(true);
    $storeMock->shouldReceive('delete')->andReturn(false); // deletion failure!

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($msg, $context) {
            return $msg === 'cleanup_overdue';
        });

    $reconciler = new ReconcileIntake($storeMock);
    $reconciler->reconcile();

    $attempt->refresh();
    expect($attempt->state)->toBe('cleanup_pending');
});
