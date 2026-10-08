<?php

namespace Tests\Feature\V3\Settings;

use Illuminate\Support\Facades\DB;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

beforeEach(function () {
    SettingsFixtures::registerDefaultTemplates();
    $this->admin = AccessFixtures::createAdmin('Privacy Admin');
    AccessFixtures::provisionV3($this->admin, true);
});

test('S13: credential sentinel in rejected request is absent from DB, responses, and session old input', function () {
    $sentinelSecret = 'SENTINEL_SECRET_DO_NOT_LEAK_123456';

    $payload = SettingsFixtures::validPayload();
    $payload['providers']['prompt']['api_key'] = $sentinelSecret;

    // Browser POST
    $resBrowser = $this->actingAs($this->admin)->post(route('v3.admin.settings.store'), $payload);
    $resBrowser->assertStatus(302);

    // Old input must NOT be flashed in session
    expect(session()->hasOldInput())->toBeFalse()
        ->and(session()->getOldInput('providers.prompt.api_key'))->toBeNull();

    // JSON POST
    $resJson = $this->actingAs($this->admin)->postJson(route('v3.admin.settings.store'), $payload);
    $resJson->assertStatus(422);
    expect($resJson->getContent())->not->toContain($sentinelSecret);

    // Verify DB does not contain the sentinel
    expect(DB::table('v3_settings_versions')->count())->toBe(0)
        ->and(DB::table('v3_settings_publications')->count())->toBe(0);

    $tables = ['users', 'v3_access_events', 'v3_active_settings'];
    foreach ($tables as $t) {
        $rows = DB::table($t)->get();
        expect(json_encode($rows))->not->toContain($sentinelSecret);
    }
});

test('S15: template catalog list endpoint returns metadata only and never exposes template body', function () {
    $res = $this->actingAs($this->admin)->getJson(route('v3.admin.settings.templates'));

    $res->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'kind',
                    'checksum',
                    'registered_at',
                ],
            ],
        ]);
    expect($res->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');

    $content = $res->getContent();
    // Template bodies must NOT appear anywhere in the response
    expect($content)->not->toContain(SettingsFixtures::VALID_FEEDBACK_BODY)
        ->and($content)->not->toContain(SettingsFixtures::VALID_SCENE_BODY);

    $data = $res->json('data');
    expect($data)->toHaveCount(2);
    // Sorted by kind, id: 'feedback' before 'scene'
    expect($data[0]['kind'])->toBe('feedback')
        ->and($data[1]['kind'])->toBe('scene');
});

test('S13: settings show returns null empty state before publish and valid projection after publish', function () {
    // Before publish
    $resBefore = $this->actingAs($this->admin)->getJson(route('v3.admin.settings.show'));
    $resBefore->assertStatus(200)
        ->assertJson([
            'data' => [
                'version_id' => null,
                'active_version' => 0,
                'settings' => null,
                'checksum' => null,
                'published_at' => null,
            ],
        ]);
    expect($resBefore->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');

    // After publish
    $this->actingAs($this->admin)->postJson(route('v3.admin.settings.store'), SettingsFixtures::validPayload(0));

    $resAfter = $this->actingAs($this->admin)->getJson(route('v3.admin.settings.show'));
    $resAfter->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'version_id',
                'active_version',
                'settings',
                'checksum',
                'published_at',
            ],
        ]);

    $data = $resAfter->json('data');
    expect($data['active_version'])->toBe(1)
        ->and($data['settings']['providers']['prompt']['credential_ref'])->toBe('GEMINI_API_KEY')
        // No expected_active_version in settings payload
        ->and(array_key_exists('expected_active_version', $data['settings']))->toBeFalse();
});
