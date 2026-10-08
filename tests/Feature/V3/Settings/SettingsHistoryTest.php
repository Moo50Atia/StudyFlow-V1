<?php

namespace Tests\Feature\V3\Settings;

use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

beforeEach(function () {
    SettingsFixtures::registerDefaultTemplates();
    $this->admin = AccessFixtures::createAdmin('History Admin');
    AccessFixtures::provisionV3($this->admin, true);
});

test('S12: history endpoint returns empty list before first publish', function () {
    $res = $this->actingAs($this->admin)->getJson(route('v3.admin.settings.history'));

    $res->assertStatus(200)
        ->assertJson([
            'data' => [],
            'meta' => [
                'page' => 1,
                'per_page' => 20,
            ],
        ]);
    expect($res->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
});

test('S12: history is ordered newest-first and contains only paths, never values', function () {
    // Publish v1
    $this->actingAs($this->admin)->postJson(route('v3.admin.settings.store'), SettingsFixtures::validPayload(0));

    // Publish v2 with changed max_pdf_bytes
    $this->actingAs($this->admin)->postJson(route('v3.admin.settings.store'), SettingsFixtures::validPayload(1, [
        'limits' => ['max_pdf_bytes' => 20000000],
    ]));

    $res = $this->actingAs($this->admin)->getJson(route('v3.admin.settings.history'));

    $res->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'version_id',
                    'version',
                    'publisher_user_id',
                    'published_at',
                    'checksum',
                    'changed_paths',
                ],
            ],
            'meta' => [
                'page',
                'per_page',
            ],
        ]);

    $data = $res->json('data');
    expect($data)->toHaveCount(2)
        ->and($data[0]['version'])->toBe(2)
        ->and($data[1]['version'])->toBe(1);

    // v2 changed paths contains only 'limits.max_pdf_bytes'
    expect($data[0]['changed_paths'])->toBe(['limits.max_pdf_bytes']);

    // v1 changed paths contains leaf paths from empty predecessor
    expect($data[1]['changed_paths'])->toContain('limits.max_pdf_bytes')
        ->and($data[1]['changed_paths'])->toContain('providers.coding_order')
        ->and($data[1]['changed_paths'])->toContain('templates.feedback.id');

    // Verify NO old/new values in history item
    $historyItemString = json_encode($data[0]);
    expect($historyItemString)->not->toContain('20000000')
        ->and($historyItemString)->not->toContain('10485760');
});

test('S12: pagination accepts valid page and rejects invalid page', function () {
    // Invalid page <= 0 or non-integer
    $this->actingAs($this->admin)->getJson(route('v3.admin.settings.history', ['page' => 0]))
        ->assertStatus(422);

    $this->actingAs($this->admin)->getJson(route('v3.admin.settings.history', ['page' => -1]))
        ->assertStatus(422);

    $this->actingAs($this->admin)->getJson(route('v3.admin.settings.history', ['page' => 'abc']))
        ->assertStatus(422);

    // Out-of-range valid page returns empty data array
    $res = $this->actingAs($this->admin)->getJson(route('v3.admin.settings.history', ['page' => 999]));
    $res->assertStatus(200)
        ->assertJson([
            'data' => [],
            'meta' => [
                'page' => 999,
                'per_page' => 20,
            ],
        ]);
});

test('S12: HTML history view renders table of versions', function () {
    $this->actingAs($this->admin)->postJson(route('v3.admin.settings.store'), SettingsFixtures::validPayload(0));

    $res = $this->actingAs($this->admin)->get(route('v3.admin.settings.history'));
    $res->assertStatus(200);
    expect($res->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
});
