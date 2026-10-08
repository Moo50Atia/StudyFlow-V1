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

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Privacy Test');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Privacy Test');

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
        'storage_path' => 'accepted/secret-doc-path.pdf',
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

    $this->secretText = "Confidential educational lecture notes and curriculum.";
    $this->textSha256 = hash('sha256', $this->secretText);
    $this->secretNotebookId = "nb-secret-provider-id-999";
    $this->secretSourceId = "src-secret-provider-id-888";

    $this->activation = SourceActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'accepted',
        'signal_accepted_at' => now(),
    ]);

    $this->extractedSource = ExtractedSource::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'activation_id' => $this->activation->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'status' => 'ready',
        'artifact_path' => "extracted/{$this->case->id}/{$this->textSha256}.txt",
        'text_sha256' => $this->textSha256,
        'text_bytes' => strlen($this->secretText),
        'page_count' => 1,
        'ocr_page_count' => 0,
        'page_report' => [['page' => 1, 'method' => 'text', 'chars' => strlen($this->secretText), 'gap_code' => null]],
        'extractor_version' => '0.1.0',
    ]);

    $this->binding = KnowledgeSourceBinding::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'extracted_source_id' => $this->extractedSource->id,
        'adapter_kind' => 'notebooklm_mcp',
        'adapter_version' => '0.3.1',
        'notebook_title' => "v3-case-{$this->case->id}",
        'source_title' => "v3-text-{$this->case->id}-" . substr($this->textSha256, 0, 16),
        'notebook_id' => $this->secretNotebookId,
        'source_id' => $this->secretSourceId,
        'state' => 'bound',
        'text_sha256' => $this->textSha256,
        'text_bytes' => strlen($this->secretText),
        'transfer_status' => 'confirmed',
    ]);
});

test('K15: admin case summary DTO contains no provider IDs, text, or private paths', function () {
    $path = "/v3/admin/cases/{$this->case->id}/summary";
    $resp = $this->actingAs($this->admin)->getJson($path);

    $resp->assertStatus(200);
    $content = $resp->getContent();

    expect($content)->not->toContain($this->secretNotebookId)
        ->and($content)->not->toContain($this->secretSourceId)
        ->and($content)->not->toContain($this->secretText)
        ->and($content)->not->toContain('secret-doc-path.pdf');
});

test('K15: teacher source status contains no provider IDs, text, or private paths', function () {
    $path = "/v3/teacher/cases/{$this->case->id}/source-status";
    $resp = $this->actingAs($this->teacher)->getJson($path);

    $resp->assertStatus(200);
    $content = $resp->getContent();

    expect($content)->not->toContain($this->secretNotebookId)
        ->and($content)->not->toContain($this->secretSourceId)
        ->and($content)->not->toContain($this->secretText)
        ->and($content)->not->toContain('secret-doc-path.pdf');
});
