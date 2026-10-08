<?php

namespace Tests\Feature\V3\NotebookLM;

use App\Models\V3\NotebookFrameworkVersion;
use App\Services\V3\NotebookLM\FrameworkCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin One');
    $this->inactiveAdmin = AccessFixtures::createEnrolledAdmin('Admin Inactive', null, false);
    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher One');

    Storage::fake('v3_private');
});

test('N02: command rejects missing file', function () {
    $exitCode = Artisan::call('v3:import-notebook-framework', [
        '--path' => 'nonexistent_steps.txt',
        '--expected-sha256' => '96553bd6e326c3d30c5c7306a4b2e6611dc65838672aef21b4b417b50d4614ec',
        '--admin-user' => $this->admin->id,
    ]);

    expect($exitCode)->not->toBe(0);
});

test('N02: command rejects unauthorized or inactive admin actor', function () {
    // 1. Teacher actor fails
    $exitCode1 = Artisan::call('v3:import-notebook-framework', [
        '--path' => 'steps.txt',
        '--expected-sha256' => '96553bd6e326c3d30c5c7306a4b2e6611dc65838672aef21b4b417b50d4614ec',
        '--admin-user' => $this->teacher->id,
    ]);
    expect($exitCode1)->not->toBe(0);

    // 2. Inactive admin actor fails
    $exitCode2 = Artisan::call('v3:import-notebook-framework', [
        '--path' => 'steps.txt',
        '--expected-sha256' => '96553bd6e326c3d30c5c7306a4b2e6611dc65838672aef21b4b417b50d4614ec',
        '--admin-user' => $this->inactiveAdmin->id,
    ]);
    expect($exitCode2)->not->toBe(0);

    // 3. Nonexistent user fails
    $exitCode3 = Artisan::call('v3:import-notebook-framework', [
        '--path' => 'steps.txt',
        '--expected-sha256' => '96553bd6e326c3d30c5c7306a4b2e6611dc65838672aef21b4b417b50d4614ec',
        '--admin-user' => 999999,
    ]);
    expect($exitCode3)->not->toBe(0);
});

test('N02: command rejects mismatched expected sha256', function () {
    $exitCode = Artisan::call('v3:import-notebook-framework', [
        '--path' => 'steps.txt',
        '--expected-sha256' => str_repeat('0', 64),
        '--admin-user' => $this->admin->id,
    ]);

    expect($exitCode)->not->toBe(0);
    expect(DB::table('v3_notebook_framework_versions')->count())->toBe(0);
});

test('N02: command imports approved framework with exact slices and updates pointer', function () {
    $approvedSha = '96553bd6e326c3d30c5c7306a4b2e6611dc65838672aef21b4b417b50d4614ec';

    $exitCode = Artisan::call('v3:import-notebook-framework', [
        '--path' => 'steps.txt',
        '--expected-sha256' => $approvedSha,
        '--admin-user' => $this->admin->id,
    ]);

    expect($exitCode)->toBe(0);

    $version = DB::table('v3_notebook_framework_versions')->where('body_sha256', $approvedSha)->first();
    expect($version)->not->toBeNull()
        ->and($version->profile)->toBe('expanded-root-v1')
        ->and($version->body_bytes)->toBe(23622)
        ->and($version->approved_by_user_id)->toBe($this->admin->id)
        ->and($version->first_sha256)->toBe('10cebd8977aa86fbfed4760cb9b4669ffcbb2a5314d7e67b4ce39b773ccd4037')
        ->and($version->second_sha256)->toBe('8116bf1f518cd1968f4ff93b7b4c3ad66d7ee90ae42f90a45b5a29fa6c084e1e')
        ->and($version->third_sha256)->toBe('fbad0853cabbfcbc787da9198c8ed9c9de8e69e0e591b678a02865eee861b85c')
        ->and($version->response_format_sha256)->toBe('b7c49fb0ef3b62264c5b81d5673ace865e9d497ee1100f2e22675abbc614e105');

    // Stored privately
    expect(Storage::disk('v3_private')->exists($version->body_path))->toBeTrue();

    // Pointer updated
    $pointer = DB::table('v3_notebook_framework_pointer')->where('id', 1)->first();
    expect($pointer)->not->toBeNull()
        ->and($pointer->version_id)->toBe($version->id);
});

test('N02: duplicate import of identical framework returns existing version without duplicating', function () {
    $approvedSha = '96553bd6e326c3d30c5c7306a4b2e6611dc65838672aef21b4b417b50d4614ec';

    $exit1 = Artisan::call('v3:import-notebook-framework', [
        '--path' => 'steps.txt',
        '--expected-sha256' => $approvedSha,
        '--admin-user' => $this->admin->id,
    ]);
    expect($exit1)->toBe(0);

    $countBefore = DB::table('v3_notebook_framework_versions')->count();
    expect($countBefore)->toBe(1);

    $exit2 = Artisan::call('v3:import-notebook-framework', [
        '--path' => 'steps.txt',
        '--expected-sha256' => $approvedSha,
        '--admin-user' => $this->admin->id,
    ]);
    expect($exit2)->toBe(0);

    $countAfter = DB::table('v3_notebook_framework_versions')->count();
    expect($countAfter)->toBe(1);
});

test('N02: framework catalog resolves active framework from pointer', function () {
    $approvedSha = '96553bd6e326c3d30c5c7306a4b2e6611dc65838672aef21b4b417b50d4614ec';

    Artisan::call('v3:import-notebook-framework', [
        '--path' => 'steps.txt',
        '--expected-sha256' => $approvedSha,
        '--admin-user' => $this->admin->id,
    ]);

    $catalog = app(FrameworkCatalog::class);
    $active = $catalog->getActiveFramework();

    expect($active)->not->toBeNull()
        ->and($active->body_sha256)->toBe($approvedSha)
        ->and($active->profile)->toBe('expanded-root-v1');
});
