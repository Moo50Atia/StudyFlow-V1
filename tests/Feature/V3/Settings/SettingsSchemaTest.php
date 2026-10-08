<?php

namespace Tests\Feature\V3\Settings;

use App\Models\User;
use App\Models\V3\ApprovedTemplate;
use App\Models\V3\SettingsPublication;
use App\Models\V3\SettingsVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\V3\AccessFixtures;

test('exact v3 settings tables and columns exist with initial state', function () {
    expect(Schema::hasTable('v3_approved_templates'))->toBeTrue()
        ->and(Schema::hasTable('v3_settings_versions'))->toBeTrue()
        ->and(Schema::hasTable('v3_active_settings'))->toBeTrue()
        ->and(Schema::hasTable('v3_settings_publications'))->toBeTrue();

    // v3_approved_templates columns
    expect(Schema::hasColumns('v3_approved_templates', [
        'id', 'kind', 'body', 'checksum', 'registered_at',
    ]))->toBeTrue();

    // v3_settings_versions columns
    expect(Schema::hasColumns('v3_settings_versions', [
        'id', 'version', 'schema_version', 'payload', 'checksum', 'published_by_user_id', 'published_at',
    ]))->toBeTrue();

    // v3_active_settings columns
    expect(Schema::hasColumns('v3_active_settings', [
        'key', 'active_settings_version_id', 'revision', 'updated_at',
    ]))->toBeTrue();

    // v3_settings_publications columns
    expect(Schema::hasColumns('v3_settings_publications', [
        'id', 'settings_version_id', 'publisher_user_id', 'previous_version_id', 'changed_paths', 'published_at',
    ]))->toBeTrue();

    // Catalog, versions and publications start empty
    expect(DB::table('v3_approved_templates')->count())->toBe(0)
        ->and(DB::table('v3_settings_versions')->count())->toBe(0)
        ->and(DB::table('v3_settings_publications')->count())->toBe(0);

    // Singleton active settings pointer row is seeded with null version and revision 0
    $active = DB::table('v3_active_settings')->where('key', 'pilot')->first();
    expect($active)->not->toBeNull()
        ->and($active->active_settings_version_id)->toBeNull()
        ->and((int) $active->revision)->toBe(0)
        ->and(DB::table('v3_active_settings')->count())->toBe(1);
});

test('restrictive foreign key constraints prevent deleting referenced records', function () {
    $admin = AccessFixtures::createAdmin('Admin FK Test');
    AccessFixtures::provisionV3($admin, true);

    $version = SettingsVersion::create([
        'version' => 1,
        'schema_version' => 1,
        'payload' => ['limits' => ['max_pdf_bytes' => 1048576]],
        'checksum' => hash('sha256', json_encode(['limits' => ['max_pdf_bytes' => 1048576]])),
        'published_by_user_id' => $admin->id,
        'published_at' => now(),
    ]);

    // Active settings references version
    DB::table('v3_active_settings')
        ->where('key', 'pilot')
        ->update(['active_settings_version_id' => $version->id]);

    // Deleting admin user via DB query should throw QueryException due to restrictive FK on settings version
    expect(fn () => DB::table('users')->where('id', $admin->id)->delete())
        ->toThrow(QueryException::class);

    // Deleting version via DB query should throw QueryException due to FK from active settings
    expect(fn () => DB::table('v3_settings_versions')->where('id', $version->id)->delete())
        ->toThrow(QueryException::class);
});

test('S07: historical versions, approved templates, and publications are immutable', function () {
    $admin = AccessFixtures::createAdmin('Admin Immutability');
    AccessFixtures::provisionV3($admin, true);

    $template = ApprovedTemplate::create([
        'id' => 'feedback-immutable-v1',
        'kind' => 'feedback',
        'body' => 'Approved content',
        'checksum' => hash('sha256', 'Approved content'),
        'registered_at' => now(),
    ]);

    expect(fn () => $template->update(['body' => 'Modified body']))
        ->toThrow(\LogicException::class);

    expect(fn () => $template->delete())
        ->toThrow(\LogicException::class);

    $version = SettingsVersion::create([
        'version' => 1,
        'schema_version' => 1,
        'payload' => ['sample' => 'data'],
        'checksum' => hash('sha256', json_encode(['sample' => 'data'])),
        'published_by_user_id' => $admin->id,
        'published_at' => now(),
    ]);

    expect(fn () => $version->update(['checksum' => 'tampered']))
        ->toThrow(\LogicException::class);

    expect(fn () => $version->delete())
        ->toThrow(\LogicException::class);

    $publication = SettingsPublication::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'settings_version_id' => $version->id,
        'publisher_user_id' => $admin->id,
        'previous_version_id' => null,
        'changed_paths' => ['limits.max_pdf_bytes'],
        'published_at' => now(),
    ]);

    expect(fn () => $publication->update(['changed_paths' => ['modified']]))
        ->toThrow(\LogicException::class);

    expect(fn () => $publication->delete())
        ->toThrow(\LogicException::class);
});
