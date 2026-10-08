<?php

namespace Tests\Feature\V3\Sources;

use App\Models\V3\Document;
use App\Models\V3\ExtractedSource;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SourceActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Source Access');
    $this->otherTeacher = AccessFixtures::createEnrolledTeacher('Other Teacher Access');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Source Access');

    SettingsFixtures::registerDefaultTemplates();
    $payload = SettingsFixtures::validPayload();
    $canonical = \App\Services\V3\Settings\CanonicalSettings::canonicalize($payload);
    $checksum = hash('sha256', \App\Services\V3\Settings\CanonicalSettings::encode($canonical));

    $this->settingsVersion = SettingsVersion::create([
        'version' => 1,
        'schema_version' => 1,
        'payload' => $canonical,
        'checksum' => $checksum,
        'published_by_user_id' => $this->admin->id,
        'published_at' => now(),
    ]);

    $this->document = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'sha256' => hash('sha256', 'dummy'),
        'byte_size' => 100,
        'page_count' => 1,
        'state' => 'ready',
        'storage_path' => 'dummy.pdf',
        'accepted_at' => now(),
    ]);

    $this->case = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'queued',
        'state_version' => 2,
    ]);

    $this->activation = SourceActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'accepted',
    ]);
});

test('K08: teacher owner can view source-status as JSON and HTML', function () {
    $path = "/v3/teacher/cases/{$this->case->id}/source-status";

    // JSON view
    $resp = $this->actingAs($this->teacher)->getJson($path);
    $resp->assertStatus(200)
        ->assertJson([
            'data' => [
                'case_id' => $this->case->id,
                'state' => 'extracting',
                'gaps' => [],
            ],
        ]);
    expect($resp->headers->get('Cache-Control'))->toContain('private')
        ->and($resp->headers->get('Cache-Control'))->toContain('no-store');

    // HTML view
    $htmlResp = $this->actingAs($this->teacher)->get($path);
    $htmlResp->assertStatus(200);
    expect($htmlResp->getContent())->toContain('Knowledge Source Status');
});

test('K08: foreign teacher receives 404 resource_unavailable', function () {
    $path = "/v3/teacher/cases/{$this->case->id}/source-status";

    $this->actingAs($this->otherTeacher)->getJson($path)->assertStatus(404)
        ->assertJson([
            'error' => [
                'code' => 'resource_unavailable',
            ],
        ]);
});

test('K08: suspended teacher receives 403 access_denied', function () {
    $path = "/v3/teacher/cases/{$this->case->id}/source-status";
    DB::table('v3_account_access')
        ->where('user_id', $this->teacher->id)
        ->update(['is_active' => false]);

    $this->actingAs($this->teacher)->getJson($path)->assertStatus(403)
        ->assertJson([
            'error' => [
                'code' => 'access_denied',
            ],
        ]);
});

test('K08: admin cannot access teacher source-status route', function () {
    $path = "/v3/teacher/cases/{$this->case->id}/source-status";

    $this->actingAs($this->admin)->getJson($path)->assertStatus(403);
});

test('K08: source-status displays gaps and safe state without provider details', function () {
    $extracted = ExtractedSource::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'activation_id' => $this->activation->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'status' => 'blocked',
        'artifact_path' => null,
        'text_sha256' => null,
        'text_bytes' => 0,
        'page_count' => 2,
        'ocr_page_count' => 1,
        'page_report' => [
            ['page' => 1, 'method' => 'text', 'chars' => 10, 'gap_code' => null],
            ['page' => 2, 'method' => 'ocr', 'chars' => 0, 'gap_code' => 'visual_unrepresented'],
        ],
        'error_code' => 'validation',
        'extractor_version' => '0.1.0',
    ]);

    $path = "/v3/teacher/cases/{$this->case->id}/source-status";
    $resp = $this->actingAs($this->teacher)->getJson($path);

    $resp->assertStatus(200)
        ->assertJson([
            'data' => [
                'case_id' => $this->case->id,
                'state' => 'blocked',
                'page_count' => 2,
                'ocr_page_count' => 1,
                'gaps' => [
                    ['page' => 2, 'code' => 'visual_unrepresented'],
                ],
                'error_code' => 'validation',
            ],
        ]);

    $content = $resp->getContent();
    expect($content)->not->toContain('notebook_id')
        ->and($content)->not->toContain('source_id')
        ->and($content)->not->toContain('artifact_path');
});
