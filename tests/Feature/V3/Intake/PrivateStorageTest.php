<?php

namespace Tests\Feature\V3\Intake;

use App\Services\V3\Intake\PrivatePdfStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('v3_private disk is configured with serve false, throw true, and outside public symlink', function () {
    $diskConfig = Config::get('filesystems.disks.v3_private');

    expect($diskConfig)->not->toBeNull()
        ->and($diskConfig['driver'])->toBe('local')
        ->and($diskConfig['serve'] ?? false)->toBeFalse()
        ->and($diskConfig['throw'] ?? false)->toBeTrue();

    // Verify root is not under public or storage/app/public
    $root = $diskConfig['root'];
    expect($root)->not->toContain('public')
        ->and($root)->toBe(storage_path('app/v3'));
});

test('PrivatePdfStore handles quarantine staging, atomic promotion, and deletion', function () {
    Storage::fake('v3_private');
    $store = app(PrivatePdfStore::class);

    $attemptId = (string) Str::uuid();
    $docId = (string) Str::uuid();

    $content = "%PDF-1.4 sample content for private store test";

    // 1. Quarantine
    $store->storeQuarantine($attemptId, $content);
    $qPath = $store->quarantinePath($attemptId);

    expect($store->exists($qPath))->toBeTrue()
        ->and($store->size($qPath))->toBe(strlen($content))
        ->and($store->sha256($qPath))->toBe(hash('sha256', $content));

    // 2. Promote to accepted
    $aPath = $store->acceptedPath($docId);
    $store->promoteQuarantineToAccepted($attemptId, $docId);

    expect($store->exists($qPath))->toBeFalse()
        ->and($store->exists($aPath))->toBeTrue()
        ->and($store->size($aPath))->toBe(strlen($content));

    // 3. Delete accepted
    $store->deleteAccepted($docId);
    expect($store->exists($aPath))->toBeFalse();
});
