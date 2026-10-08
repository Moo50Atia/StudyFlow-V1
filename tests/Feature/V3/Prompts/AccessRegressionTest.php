<?php

namespace Tests\Feature\V3\Prompts;

use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptSectionState;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SettingsVersion;
use App\Models\V3\TeacherSection;
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

    $this->ownerTeacher = AccessFixtures::createEnrolledTeacher('Owner Teacher');
    $this->otherTeacher = AccessFixtures::createEnrolledTeacher('Other Teacher');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin User');

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

    $this->docContent = "PDF content";
    $this->docPath = 'accepted/test-access.pdf';
    Storage::disk('v3_private')->put($this->docPath, $this->docContent);

    $this->document = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->ownerTeacher->id,
        'sha256' => hash('sha256', $this->docContent),
        'storage_path' => $this->docPath,
        'byte_size' => strlen($this->docContent),
        'page_count' => 3,
        'state' => 'ready',
    ]);

    $this->case = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->ownerTeacher->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'state' => 'running',
        'idempotency_key' => 'idem-acc-' . Str::uuid(),
        'action' => 'process',
    ]);
});

test('P14: prompt-status route denies guest with 401/redirect', function () {
    $path = "/v3/teacher/cases/{$this->case->id}/prompt-status";
    $resp = $this->getJson($path);
    expect($resp->status())->toBeIn([401, 403]);
});

test('P14: prompt-status route denies other teacher with 404 resource_unavailable', function () {
    $path = "/v3/teacher/cases/{$this->case->id}/prompt-status";
    $resp = $this->actingAs($this->otherTeacher)->getJson($path);
    $resp->assertStatus(404)
        ->assertJson(['error' => ['code' => 'resource_unavailable']]);
});

test('P14: prompt-status route denies admin with 403', function () {
    $path = "/v3/teacher/cases/{$this->case->id}/prompt-status";
    $resp = $this->actingAs($this->admin)->getJson($path);
    $resp->assertStatus(403);
});

test('P14: original active teacher can access prompt-status route with 200 and no-store', function () {
    $path = "/v3/teacher/cases/{$this->case->id}/prompt-status";
    $resp = $this->actingAs($this->ownerTeacher)->getJson($path);
    $resp->assertStatus(200);
    expect($resp->headers->get('Cache-Control'))->toContain('no-store');
});

test('P14: V2 routes do not resolve V3 prompt or section UUIDs', function () {
    $fakeUuid = (string) Str::uuid();

    // V2 /sections/{id} or /lectures
    $respSection = $this->actingAs($this->ownerTeacher)->get("/sections/{$fakeUuid}");
    expect($respSection->status())->toBeIn([404, 302]);

    $respLecture = $this->actingAs($this->ownerTeacher)->get("/lectures/{$fakeUuid}");
    expect($respLecture->status())->toBeIn([404, 302]);
});
