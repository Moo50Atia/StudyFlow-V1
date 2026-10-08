<?php

namespace Tests\Feature\V3\Settings;

use App\Services\V3\Settings\ApprovedTemplateRepository;
use App\Services\V3\Settings\PublishSettings;
use App\Services\V3\Settings\SelectCaseSettings;
use App\Services\V3\Settings\SettingsUnavailable;
use App\Services\V3\Settings\SettingsVersionRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

beforeEach(function () {
    SettingsFixtures::registerDefaultTemplates();
    $this->admin = AccessFixtures::createAdmin('Capture Admin');
    AccessFixtures::provisionV3($this->admin, true);

    // Create test-only consumer table (explicitly not a ProcessingCase)
    if (! Schema::hasTable('v3_test_consumer_cases')) {
        Schema::create('v3_test_consumer_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settings_version_id')->constrained('v3_settings_versions')->restrictOnDelete();
            $table->timestamps();
        });
    }
});

test('S08: selectForNewCase throws SettingsUnavailable when no active settings version exists', function () {
    $selector = app(SelectCaseSettings::class);

    expect(fn () => DB::transaction(function () use ($selector) {
        $selector->selectForNewCase(DB::connection());
    }))->toThrow(SettingsUnavailable::class);

    expect(DB::table('v3_test_consumer_cases')->count())->toBe(0);
});

test('S09: selectForNewCase under transaction returns active immutable SettingsVersion', function () {
    $publisher = app(PublishSettings::class);
    $v1 = $publisher->publish(SettingsFixtures::validPayload(0), 0, $this->admin->id);

    $selector = app(SelectCaseSettings::class);

    $captured = DB::transaction(function () use ($selector, $v1) {
        $selected = $selector->selectForNewCase(DB::connection());
        expect($selected->id)->toBe($v1->id)
            ->and($selected->version)->toBe(1);

        DB::table('v3_test_consumer_cases')->insert([
            'settings_version_id' => $selected->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $selected;
    });

    expect($captured->id)->toBe($v1->id)
        ->and(DB::table('v3_test_consumer_cases')->count())->toBe(1);
});

test('S10: test consumer retains captured version A after version B publishes, new select gets B', function () {
    $publisher = app(PublishSettings::class);
    $vA = $publisher->publish(SettingsFixtures::validPayload(0), 0, $this->admin->id);

    // Case 1 selects version A
    $selector = app(SelectCaseSettings::class);
    $case1VersionId = DB::transaction(function () use ($selector) {
        $selected = $selector->selectForNewCase(DB::connection());
        DB::table('v3_test_consumer_cases')->insert([
            'settings_version_id' => $selected->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $selected->id;
    });
    expect($case1VersionId)->toBe($vA->id);

    // Publish version B
    $vB = $publisher->publish(SettingsFixtures::validPayload(1, [
        'limits' => ['max_pdf_bytes' => 50000000],
    ]), 1, $this->admin->id);

    // Case 1 still resolves version A
    $repo = app(SettingsVersionRepository::class);
    $loadedCase1Version = $repo->getCaptured($case1VersionId);
    expect($loadedCase1Version->id)->toBe($vA->id)
        ->and($loadedCase1Version->version)->toBe(1)
        ->and($loadedCase1Version->payload['limits']['max_pdf_bytes'])->toBe(10485760);

    // Case 2 selects version B
    $case2VersionId = DB::transaction(function () use ($selector) {
        $selected = $selector->selectForNewCase(DB::connection());

        return $selected->id;
    });
    expect($case2VersionId)->toBe($vB->id);
    $loadedCase2Version = $repo->getCaptured($case2VersionId);
    expect($loadedCase2Version->payload['limits']['max_pdf_bytes'])->toBe(50000000);
});

test('S11: SettingsVersionRepository fails closed on missing or corrupt captured version', function () {
    $repo = app(SettingsVersionRepository::class);

    // Missing ID throws SettingsUnavailable and never falls back to active
    expect(fn () => $repo->getCaptured(9999))
        ->toThrow(SettingsUnavailable::class);

    // Publish a valid version
    $publisher = app(PublishSettings::class);
    $version = $publisher->publish(SettingsFixtures::validPayload(0), 0, $this->admin->id);

    // Tamper with checksum directly in database
    DB::table('v3_settings_versions')
        ->where('id', $version->id)
        ->update(['checksum' => str_repeat('f', 64)]);

    expect(fn () => $repo->getCaptured($version->id))
        ->toThrow(SettingsUnavailable::class);
});

test('S14: captured provider order is preserved in payload and immutable', function () {
    $publisher = app(PublishSettings::class);
    $version = $publisher->publish(SettingsFixtures::validPayload(0), 0, $this->admin->id);

    $repo = app(SettingsVersionRepository::class);
    $captured = $repo->getCaptured($version->id);

    expect($captured->payload['providers']['coding_order'])->toHaveCount(2)
        ->and($captured->payload['providers']['coding_order'][0]['id'])->toBe('gemini_api')
        ->and($captured->payload['providers']['coding_order'][1]['id'])->toBe('deepseek_api');
});

test('S15: ApprovedTemplateRepository returns exact bytes and protects older cases after newer import', function () {
    $repo = app(ApprovedTemplateRepository::class);

    $feedbackChecksum = hash('sha256', SettingsFixtures::VALID_FEEDBACK_BODY);
    $bytes = $repo->getCaptured(SettingsFixtures::VALID_FEEDBACK_TEMPLATE_ID, $feedbackChecksum);
    expect($bytes)->toBe(SettingsFixtures::VALID_FEEDBACK_BODY);

    // Register a newer approved template with different ID
    $newBody = "You are a revised feedback assistant v2.\nStrict rules apply.";
    $newTemplate = SettingsFixtures::registerApprovedTemplate('feedback', 'feedback-v2-tmpl', $newBody);

    // Reading old template still returns exact old bytes
    $oldBytesAgain = $repo->getCaptured(SettingsFixtures::VALID_FEEDBACK_TEMPLATE_ID, $feedbackChecksum);
    expect($oldBytesAgain)->toBe(SettingsFixtures::VALID_FEEDBACK_BODY);

    // Checksum mismatch throws SettingsUnavailable
    expect(fn () => $repo->getCaptured(SettingsFixtures::VALID_FEEDBACK_TEMPLATE_ID, str_repeat('0', 64)))
        ->toThrow(SettingsUnavailable::class);

    // Unknown template ID throws SettingsUnavailable
    expect(fn () => $repo->getCaptured('non-existent-tmpl', str_repeat('0', 64)))
        ->toThrow(SettingsUnavailable::class);
});
