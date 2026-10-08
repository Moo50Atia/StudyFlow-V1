<?php

namespace Tests\Feature\V3\Settings;

use App\Models\V3\SettingsPublication;
use App\Models\V3\SettingsVersion;
use Illuminate\Support\Facades\DB;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

beforeEach(function () {
    SettingsFixtures::registerDefaultTemplates();
    $this->admin = AccessFixtures::createAdmin('Publishing Admin');
    AccessFixtures::provisionV3($this->admin, true);
});

test('S01: first publication creates version 1, moves pointer, and records publication audit', function () {
    $payload = SettingsFixtures::validPayload(0);

    $response = $this->actingAs($this->admin)
        ->postJson(route('v3.admin.settings.store'), $payload);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'data' => [
                'version_id',
                'version',
                'checksum',
                'active_version',
            ],
        ]);
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');

    $data = $response->json('data');
    expect($data['version'])->toBe(1)
        ->and($data['active_version'])->toBe(1);

    // DB state
    $version = SettingsVersion::where('version', 1)->first();
    expect($version)->not->toBeNull()
        ->and($version->published_by_user_id)->toBe($this->admin->id)
        ->and($version->checksum)->toBe($data['checksum']);

    // Pointer state
    $active = DB::table('v3_active_settings')->where('key', 'pilot')->first();
    expect($active->active_settings_version_id)->toBe($version->id);

    // Publication audit state
    $publication = SettingsPublication::where('settings_version_id', $version->id)->first();
    expect($publication)->not->toBeNull()
        ->and($publication->publisher_user_id)->toBe($this->admin->id)
        ->and($publication->previous_version_id)->toBeNull()
        ->and(is_array($publication->changed_paths))->toBeTrue()
        ->and(count($publication->changed_paths))->toBeGreaterThan(0);
});

test('S02: replacement publication increments version and preserves previous row unchanged', function () {
    // 1. Publish version 1
    $res1 = $this->actingAs($this->admin)->postJson(route('v3.admin.settings.store'), SettingsFixtures::validPayload(0));
    $res1->assertStatus(201);
    $v1 = SettingsVersion::where('version', 1)->first();
    expect($v1)->not->toBeNull();
    $v1Checksum = $v1->checksum;
    $v1PublishedAt = $v1->published_at;

    // 2. Publish replacement version 2 with updated limit
    $payload2 = SettingsFixtures::validPayload(1, [
        'limits' => [
            'max_pdf_bytes' => 20971520,
        ],
    ]);

    $res2 = $this->actingAs($this->admin)->postJson(route('v3.admin.settings.store'), $payload2);
    $res2->assertStatus(201)
        ->assertJson([
            'data' => [
                'version' => 2,
                'active_version' => 2,
            ],
        ]);

    // Active pointer points to version 2
    $active = DB::table('v3_active_settings')->where('key', 'pilot')->first();
    $v2 = SettingsVersion::where('version', 2)->first();
    expect($active->active_settings_version_id)->toBe($v2->id);

    // Version 1 row remains completely unchanged
    $v1After = SettingsVersion::where('version', 1)->first();
    expect($v1After->checksum)->toBe($v1Checksum)
        ->and($v1After->published_at->toIso8601String())->toBe($v1PublishedAt->toIso8601String());

    // Publication audit for v2 points to v1 and records changed path
    $pub2 = SettingsPublication::where('settings_version_id', $v2->id)->first();
    expect($pub2->previous_version_id)->toBe($v1->id)
        ->and($pub2->changed_paths)->toContain('limits.max_pdf_bytes');
});

test('S05: stale expected version returns 409 conflict and creates no rows', function () {
    // First publish version 1
    $this->actingAs($this->admin)->postJson(route('v3.admin.settings.store'), SettingsFixtures::validPayload(0))->assertStatus(201);

    // Attempt second publish with stale expected_active_version = 0 (current is 1)
    $staleRes = $this->actingAs($this->admin)->postJson(route('v3.admin.settings.store'), SettingsFixtures::validPayload(0));
    $staleRes->assertStatus(409)
        ->assertJson([
            'error' => [
                'code' => 'settings_conflict',
                'message' => 'Settings changed. Reload and retry.',
            ],
        ]);
    expect($staleRes->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');

    expect(SettingsVersion::count())->toBe(1)
        ->and(SettingsPublication::count())->toBe(1);
});

test('S02: identical payload with fresh expected version creates new version', function () {
    $this->actingAs($this->admin)->postJson(route('v3.admin.settings.store'), SettingsFixtures::validPayload(0))->assertStatus(201);

    // Submit identical payload with expected_active_version = 1
    $res = $this->actingAs($this->admin)->postJson(route('v3.admin.settings.store'), SettingsFixtures::validPayload(1));
    $res->assertStatus(201)
        ->assertJson([
            'data' => [
                'version' => 2,
                'active_version' => 2,
            ],
        ]);

    expect(SettingsVersion::count())->toBe(2);
});

test('S06: failure in publication rolls back version and pointer', function () {
    // Injected storage failure
    \App\Services\V3\Settings\PublishSettings::$forceFailure = true;

    $res = $this->actingAs($this->admin)->postJson(route('v3.admin.settings.store'), SettingsFixtures::validPayload(0));
    $res->assertStatus(503)
        ->assertJson([
            'error' => [
                'code' => 'settings_unavailable',
                'message' => 'V3 settings are unavailable.',
            ],
        ]);
    expect($res->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');

    \App\Services\V3\Settings\PublishSettings::$forceFailure = false;
    expect(SettingsVersion::count())->toBe(0)
        ->and(SettingsPublication::count())->toBe(0)
        ->and(DB::table('v3_active_settings')->where('key', 'pilot')->value('active_settings_version_id'))->toBeNull();
});

test('browser publication redirects 303 to show on success', function () {
    $res = $this->actingAs($this->admin)->post(route('v3.admin.settings.store'), SettingsFixtures::validPayload(0));
    $res->assertStatus(303)
        ->assertRedirect(route('v3.admin.settings.show'));
});
