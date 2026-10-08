<?php

namespace Tests\Feature\V3\Settings;

use Illuminate\Support\Facades\DB;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

beforeEach(function () {
    SettingsFixtures::registerDefaultTemplates();
    $this->admin = AccessFixtures::createAdmin('Settings Admin');
    AccessFixtures::provisionV3($this->admin, true);
});

test('S03: validation rejects missing top-level keys', function () {
    $payload = SettingsFixtures::validPayload();
    unset($payload['limits']);

    $response = $this->actingAs($this->admin)
        ->postJson(route('v3.admin.settings.store'), $payload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['limits']);

    expect(DB::table('v3_settings_versions')->count())->toBe(0)
        ->and(DB::table('v3_settings_publications')->count())->toBe(0);
});

test('S03: validation rejects extra top-level or nested keys', function () {
    // Extra top-level
    $payload = SettingsFixtures::validPayload(0, ['unexpected_field' => 'forbidden']);

    $response = $this->actingAs($this->admin)
        ->postJson(route('v3.admin.settings.store'), $payload);

    $response->assertStatus(422);

    // Extra nested in limits
    $payloadNested = SettingsFixtures::validPayload();
    $payloadNested['limits']['extra_limit'] = 123;

    $response = $this->actingAs($this->admin)
        ->postJson(route('v3.admin.settings.store'), $payloadNested);

    $response->assertStatus(422);

    // Extra nested in provider descriptor
    $payloadProvider = SettingsFixtures::validPayload();
    $payloadProvider['providers']['prompt']['api_key'] = 'secret-sentinel';

    $response = $this->actingAs($this->admin)
        ->postJson(route('v3.admin.settings.store'), $payloadProvider);

    $response->assertStatus(422);

    expect(DB::table('v3_settings_versions')->count())->toBe(0);
});

test('S03: validation rejects out of range limits and max_ocr_pages exceeding max_pdf_pages', function () {
    // max_ocr_pages > max_pdf_pages
    $payload = SettingsFixtures::validPayload(0, [
        'limits' => [
            'max_pdf_pages' => 10,
            'max_ocr_pages' => 15,
        ],
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson(route('v3.admin.settings.store'), $payload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['limits.max_ocr_pages']);

    // max_pdf_bytes out of range
    $payloadBytes = SettingsFixtures::validPayload(0, [
        'limits' => ['max_pdf_bytes' => 104857601],
    ]);
    $this->actingAs($this->admin)
        ->postJson(route('v3.admin.settings.store'), $payloadBytes)
        ->assertStatus(422);

    // coding_attempts out of range (0 or > 20)
    $payloadCoding = SettingsFixtures::validPayload(0, [
        'limits' => ['coding_attempts' => 0],
    ]);
    $this->actingAs($this->admin)
        ->postJson(route('v3.admin.settings.store'), $payloadCoding)
        ->assertStatus(422);
});

test('S03: validation rejects floats, booleans as integers, and exponent strings', function () {
    $payloadFloat = SettingsFixtures::validPayload(0, [
        'limits' => ['max_pdf_pages' => 10.5],
    ]);
    $this->actingAs($this->admin)
        ->postJson(route('v3.admin.settings.store'), $payloadFloat)
        ->assertStatus(422);

    $payloadBool = SettingsFixtures::validPayload(0, [
        'limits' => ['technical_retries' => true],
    ]);
    $this->actingAs($this->admin)
        ->postJson(route('v3.admin.settings.store'), $payloadBool)
        ->assertStatus(422);

    $payloadExp = SettingsFixtures::validPayload(0, [
        'limits' => ['max_pdf_bytes' => '1e6'],
    ]);
    $this->actingAs($this->admin)
        ->postJson(route('v3.admin.settings.store'), $payloadExp)
        ->assertStatus(422);
});

test('S03: validation rejects unknown providers, mismatched credentials, and duplicate coding order', function () {
    // Unknown provider ID
    $payloadUnknown = SettingsFixtures::validPayload();
    $payloadUnknown['providers']['prompt'] = [
        'id' => 'openai_api',
        'model' => 'gpt-4o',
        'credential_ref' => 'OPENAI_API_KEY',
    ];
    $this->actingAs($this->admin)
        ->postJson(route('v3.admin.settings.store'), $payloadUnknown)
        ->assertStatus(422);

    // Mismatched credential ref
    $payloadMismatched = SettingsFixtures::validPayload();
    $payloadMismatched['providers']['prompt'] = [
        'id' => 'gemini_api',
        'model' => 'gemini-2.5-pro',
        'credential_ref' => 'DEEPSEEK_API_KEY',
    ];
    $this->actingAs($this->admin)
        ->postJson(route('v3.admin.settings.store'), $payloadMismatched)
        ->assertStatus(422);

    // Duplicate IDs in coding order
    $payloadDup = SettingsFixtures::validPayload();
    $payloadDup['providers']['coding_order'] = [
        [
            'id' => 'gemini_api',
            'model' => 'gemini-2.5-pro',
            'credential_ref' => 'GEMINI_API_KEY',
        ],
        [
            'id' => 'gemini_api',
            'model' => 'gemini-2.5-flash',
            'credential_ref' => 'GEMINI_API_KEY',
        ],
    ];
    $this->actingAs($this->admin)
        ->postJson(route('v3.admin.settings.store'), $payloadDup)
        ->assertStatus(422);
});

test('S03: validation rejects canonical prompt overrides', function () {
    $payload = SettingsFixtures::validPayload(0, [
        'dividing_prompt' => 'Override dividing prompt',
    ]);
    $this->actingAs($this->admin)
        ->postJson(route('v3.admin.settings.store'), $payload)
        ->assertStatus(422);

    $payloadNested = SettingsFixtures::validPayload();
    $payloadNested['qualification']['canonical_prompt'] = 'Forbidden';
    $this->actingAs($this->admin)
        ->postJson(route('v3.admin.settings.store'), $payloadNested)
        ->assertStatus(422);
});

test('S15: validation rejects unknown, wrong-kind, or checksum-mismatched templates', function () {
    // Unknown template ID
    $payloadUnknown = SettingsFixtures::validPayload();
    $payloadUnknown['templates']['feedback'] = [
        'id' => 'unknown-feedback-template',
        'checksum' => str_repeat('a', 64),
    ];
    $this->actingAs($this->admin)
        ->postJson(route('v3.admin.settings.store'), $payloadUnknown)
        ->assertStatus(422);

    // Wrong kind: pass scene template for feedback
    $payloadWrongKind = SettingsFixtures::validPayload();
    $payloadWrongKind['templates']['feedback'] = [
        'id' => SettingsFixtures::VALID_SCENE_TEMPLATE_ID,
        'checksum' => hash('sha256', SettingsFixtures::VALID_SCENE_BODY),
    ];
    $this->actingAs($this->admin)
        ->postJson(route('v3.admin.settings.store'), $payloadWrongKind)
        ->assertStatus(422);

    // Checksum mismatch
    $payloadMismatch = SettingsFixtures::validPayload();
    $payloadMismatch['templates']['feedback']['checksum'] = str_repeat('0', 64);
    $this->actingAs($this->admin)
        ->postJson(route('v3.admin.settings.store'), $payloadMismatch)
        ->assertStatus(422);
});
