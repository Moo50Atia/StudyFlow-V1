<?php

namespace Tests\Feature\V3\NotebookLM;

use App\Models\V3\Document;
use App\Models\V3\NotebookFrameworkVersion;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Services\V3\Settings\PublishSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\V3\Pdf\PdfFixtures;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Alpha');
    $this->otherTeacher = AccessFixtures::createEnrolledTeacher('Teacher Beta');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin User');

    SettingsFixtures::registerDefaultTemplates();
    $payload = SettingsFixtures::validPayload(0, [
        'limits' => [
            'max_pdf_bytes' => 1024 * 1024,
            'max_pdf_pages' => 50,
        ],
    ]);

    $publisher = app(PublishSettings::class);
    $this->settingsVersion = $publisher->publish($payload, 0, $this->admin->id);

    Storage::fake('v3_private');
});

function createTestReadyDocument($teacher, int $pages = 3, ?string $content = null): Document
{
    $docId = (string) Str::uuid();
    $pdfContent = $content ?? PdfFixtures::minimalValidPdf($pages);
    $sha256 = hash('sha256', $pdfContent);
    $byteSize = strlen($pdfContent);

    $storagePath = "accepted/{$docId}.pdf";
    Storage::disk('v3_private')->put($storagePath, $pdfContent);

    return Document::forceCreate([
        'id' => $docId,
        'owner_user_id' => $teacher->id,
        'sha256' => $sha256,
        'byte_size' => $byteSize,
        'page_count' => $pages,
        'state' => 'ready',
        'storage_path' => $storagePath,
        'accepted_at' => now(),
    ]);
}

test('N01: default case creation captures legacy_staged_v1 pipeline_version and null direct policy', function () {
    config(['services.v3.notebook_protocol' => 'legacy_staged_v1']);

    $doc = createTestReadyDocument($this->teacher);
    $idempotencyKey = (string) Str::uuid();

    $response = $this->actingAs($this->teacher)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process',
            'idempotency_key' => $idempotencyKey,
        ]);

    $response->assertStatus(201);
    $caseId = $response->json('data.case_id');

    $case = ProcessingCase::findOrFail($caseId);
    expect($case->pipeline_version)->toBe('legacy_staged_v1')
        ->and($case->notebook_framework_version_id)->toBeNull()
        ->and($case->notebook_policy)->toBeNull();
});

test('N01: direct mode creation without active framework returns 503 framework_unavailable', function () {
    config(['services.v3.notebook_protocol' => 'notebooklm_combined_v1']);

    $doc = createTestReadyDocument($this->teacher);
    $idempotencyKey = (string) Str::uuid();

    $response = $this->actingAs($this->teacher)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process',
            'idempotency_key' => $idempotencyKey,
        ]);

    $response->assertStatus(503);
    $response->assertJson([
        'error' => [
            'code' => 'framework_unavailable',
        ],
    ]);

    expect(ProcessingCase::where('idempotency_key', $idempotencyKey)->exists())->toBeFalse();
});

test('N01: direct mode creation captures immutable framework version and direct notebook_policy', function () {
    config(['services.v3.notebook_protocol' => 'notebooklm_combined_v1']);

    // Create an approved framework version and pointer
    $frameworkId = (string) Str::uuid();
    $frameworkSha = '96553bd6e326c3d30c5c7306a4b2e6611dc65838672aef21b4b417b50d4614ec';
    Storage::disk('v3_private')->put("frameworks/{$frameworkSha}.txt", "dummy framework");

    DB::table('v3_notebook_framework_versions')->insert([
        'id' => $frameworkId,
        'profile' => 'expanded-root-v1',
        'body_path' => "frameworks/{$frameworkSha}.txt",
        'body_sha256' => $frameworkSha,
        'body_bytes' => 15,
        'first_sha256' => str_repeat('1', 64),
        'second_sha256' => str_repeat('2', 64),
        'third_sha256' => str_repeat('3', 64),
        'response_format_sha256' => str_repeat('4', 64),
        'approved_by_user_id' => $this->admin->id,
        'approved_at' => now(),
        'created_at' => now(),
    ]);

    DB::table('v3_notebook_framework_pointer')->updateOrInsert(
        ['id' => 1],
        ['version_id' => $frameworkId, 'updated_at' => now()]
    );

    $doc = createTestReadyDocument($this->teacher);
    $idempotencyKey = (string) Str::uuid();

    $response = $this->actingAs($this->teacher)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process',
            'idempotency_key' => $idempotencyKey,
        ]);

    $response->assertStatus(201);
    $caseId = $response->json('data.case_id');

    $case = ProcessingCase::findOrFail($caseId);
    expect($case->pipeline_version)->toBe('notebooklm_combined_v1')
        ->and($case->notebook_framework_version_id)->toBe($frameworkId)
        ->and($case->notebook_policy)->toBeArray()
        ->and($case->notebook_policy['adapter_kind'])->toBe('notebooklm_py')
        ->and($case->notebook_policy['adapter_version'])->toBe('0.8.4')
        ->and($case->notebook_policy['credential_profile'])->toBe('v3-local')
        ->and($case->notebook_policy['response_format_version'])->toBe('combined-envelope-v1');
});

test('N01: same-key replay preserves original pipeline_version and framework even if default changes', function () {
    // 1. Create a legacy case
    config(['services.v3.notebook_protocol' => 'legacy_staged_v1']);
    $doc = createTestReadyDocument($this->teacher);
    $legacyKey = (string) Str::uuid();

    $res1 = $this->actingAs($this->teacher)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process',
            'idempotency_key' => $legacyKey,
        ]);
    $res1->assertStatus(201);
    $legacyCaseId = $res1->json('data.case_id');

    // 2. Set up direct framework and switch default to notebooklm_combined_v1
    $frameworkId = (string) Str::uuid();
    $frameworkSha = '96553bd6e326c3d30c5c7306a4b2e6611dc65838672aef21b4b417b50d4614ec';
    Storage::disk('v3_private')->put("frameworks/{$frameworkSha}.txt", "dummy");

    DB::table('v3_notebook_framework_versions')->insert([
        'id' => $frameworkId,
        'profile' => 'expanded-root-v1',
        'body_path' => "frameworks/{$frameworkSha}.txt",
        'body_sha256' => $frameworkSha,
        'body_bytes' => 5,
        'first_sha256' => str_repeat('1', 64),
        'second_sha256' => str_repeat('2', 64),
        'third_sha256' => str_repeat('3', 64),
        'response_format_sha256' => str_repeat('4', 64),
        'approved_by_user_id' => $this->admin->id,
        'approved_at' => now(),
        'created_at' => now(),
    ]);
    DB::table('v3_notebook_framework_pointer')->updateOrInsert(['id' => 1], ['version_id' => $frameworkId, 'updated_at' => now()]);

    config(['services.v3.notebook_protocol' => 'notebooklm_combined_v1']);

    // 3. Replay legacyKey -> must return legacy case, unchanged
    $replayRes = $this->actingAs($this->teacher)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process',
            'idempotency_key' => $legacyKey,
        ]);
    $replayRes->assertStatus(200);
    expect($replayRes->json('data.case_id'))->toBe($legacyCaseId);

    $caseAfter = ProcessingCase::findOrFail($legacyCaseId);
    expect($caseAfter->pipeline_version)->toBe('legacy_staged_v1')
        ->and($caseAfter->notebook_framework_version_id)->toBeNull();

    // 4. Create direct case with process_again
    $directKey = (string) Str::uuid();
    $directRes = $this->actingAs($this->teacher)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process_again',
            'idempotency_key' => $directKey,
        ]);
    $directRes->assertStatus(201);
    $directCaseId = $directRes->json('data.case_id');

    // 5. Switch default back to legacy_staged_v1
    config(['services.v3.notebook_protocol' => 'legacy_staged_v1']);

    // 6. Replay directKey -> must return direct case, unchanged
    $directReplayRes = $this->actingAs($this->teacher)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process_again',
            'idempotency_key' => $directKey,
        ]);
    $directReplayRes->assertStatus(200);
    expect($directReplayRes->json('data.case_id'))->toBe($directCaseId);

    $directCaseAfter = ProcessingCase::findOrFail($directCaseId);
    expect($directCaseAfter->pipeline_version)->toBe('notebooklm_combined_v1')
        ->and($directCaseAfter->notebook_framework_version_id)->toBe($frameworkId);
});

test('N01: admin case summary DTO excludes pipeline_version, framework_id, and notebook_policy', function () {
    config(['services.v3.notebook_protocol' => 'legacy_staged_v1']);
    $doc = createTestReadyDocument($this->teacher);
    $idempotencyKey = (string) Str::uuid();

    $response = $this->actingAs($this->teacher)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process',
            'idempotency_key' => $idempotencyKey,
        ]);
    $caseId = $response->json('data.case_id');

    $adminRes = $this->actingAs($this->admin)->getJson("/v3/admin/cases/{$caseId}/summary");
    $adminRes->assertStatus(200);

    $adminData = $adminRes->json('data');
    expect(array_key_exists('pipeline_version', $adminData))->toBeFalse()
        ->and(array_key_exists('notebook_framework_version_id', $adminData))->toBeFalse()
        ->and(array_key_exists('notebook_policy', $adminData))->toBeFalse();
});
