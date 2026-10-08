<?php

namespace Tests\Feature\V3\Sources;

use App\Models\V3\Document;
use App\Models\V3\ExtractedSource;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SourceActivation;
use App\Models\V3\SourceEffect;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Sources');
    SettingsFixtures::registerDefaultTemplates();
    $payload = SettingsFixtures::validPayload();
    $this->settingsVersion = SettingsVersion::create([
        'version' => 1,
        'schema_version' => 1,
        'payload' => $payload,
        'checksum' => hash('sha256', json_encode($payload)),
        'published_by_user_id' => $this->teacher->id,
        'published_at' => now(),
    ]);

    $this->document = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'sha256' => hash('sha256', 'source test file'),
        'byte_size' => 4096,
        'page_count' => 3,
        'state' => 'ready',
        'storage_path' => 'accepted/doc-src.pdf',
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
});

test('exact v3 source tables and columns exist with required constraints', function () {
    expect(Schema::hasTable('v3_source_activations'))->toBeTrue();
    expect(Schema::hasTable('v3_extracted_sources'))->toBeTrue();
    expect(Schema::hasTable('v3_knowledge_source_bindings'))->toBeTrue();
    expect(Schema::hasTable('v3_source_effects'))->toBeTrue();

    expect(Schema::hasColumns('v3_source_activations', [
        'id', 'case_id', 'workflow_id', 'state', 'lease_until',
        'signal_attempted_at', 'signal_accepted_at', 'next_check_at',
        'signal_attempts', 'error_code', 'last_alert_code', 'last_alerted_at',
        'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasColumns('v3_extracted_sources', [
        'id', 'case_id', 'activation_id', 'document_id', 'settings_version_id',
        'status', 'artifact_path', 'text_sha256', 'text_bytes',
        'page_count', 'ocr_page_count', 'page_report', 'error_code',
        'extractor_version', 'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasColumns('v3_knowledge_source_bindings', [
        'id', 'case_id', 'extracted_source_id', 'adapter_kind', 'adapter_version',
        'notebook_title', 'source_title', 'notebook_id', 'source_id', 'state',
        'text_sha256', 'text_bytes', 'transfer_status', 'send_attempted_at',
        'confirmed_at', 'last_checked_at', 'error_code', 'last_alert_code',
        'last_alerted_at', 'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasColumns('v3_source_effects', [
        'id', 'case_id', 'operation', 'request_digest', 'state',
        'send_count', 'provider_ref', 'started_at', 'last_checked_at',
        'confirmed_at', 'error_code', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

test('SourceActivation schema enforces case uniqueness and foreign key delete restriction', function () {
    $activation = SourceActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'pending',
    ]);

    expect($activation)->toBeInstanceOf(SourceActivation::class);

    // Duplicate case_id must fail uniqueness
    expect(function () {
        SourceActivation::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'workflow_id' => "v3-case-{$this->case->id}",
            'state' => 'pending',
        ]);
    })->toThrow(QueryException::class);

    // Deleting processing case must fail foreign key restriction
    expect(function () {
        $this->case->delete();
    })->toThrow(QueryException::class);
});

test('ExtractedSource schema enforces uniqueness, captured settings, and delete restriction', function () {
    $activation = SourceActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'accepted',
    ]);

    $extracted = ExtractedSource::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'activation_id' => $activation->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'status' => 'ready',
        'artifact_path' => "extracted/{$this->case->id}/abc.txt",
        'text_sha256' => hash('sha256', 'canonical text'),
        'text_bytes' => 14,
        'page_count' => 3,
        'ocr_page_count' => 0,
        'page_report' => [
            ['page' => 1, 'method' => 'text', 'chars' => 10, 'ocr_confidence' => null, 'gap_code' => null],
        ],
        'extractor_version' => '0.1.0',
    ]);

    expect($extracted)->toBeInstanceOf(ExtractedSource::class);
    expect($extracted->page_report)->toBeArray();

    // Duplicate case_id or activation_id must fail
    expect(function () use ($activation) {
        ExtractedSource::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'activation_id' => (string) Str::uuid(),
            'document_id' => $this->document->id,
            'settings_version_id' => $this->settingsVersion->id,
            'status' => 'ready',
            'page_report' => [],
            'extractor_version' => '0.1.0',
        ]);
    })->toThrow(QueryException::class);

    // Deleting activation must fail foreign key restriction
    expect(function () use ($activation) {
        $activation->delete();
    })->toThrow(QueryException::class);

    // Deleting settings version must fail foreign key restriction
    expect(function () {
        DB::table('v3_settings_versions')->where('id', $this->settingsVersion->id)->delete();
    })->toThrow(QueryException::class);
});

test('KnowledgeSourceBinding schema enforces unique case, extraction, and provider constraints', function () {
    $activation = SourceActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'accepted',
    ]);

    $extracted = ExtractedSource::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'activation_id' => $activation->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'status' => 'ready',
        'artifact_path' => "extracted/{$this->case->id}/abc.txt",
        'text_sha256' => hash('sha256', 'canonical text'),
        'text_bytes' => 14,
        'page_count' => 3,
        'ocr_page_count' => 0,
        'page_report' => [],
        'extractor_version' => '0.1.0',
    ]);

    $binding = KnowledgeSourceBinding::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'extracted_source_id' => $extracted->id,
        'adapter_kind' => 'notebooklm_mcp',
        'adapter_version' => '0.3.1',
        'notebook_title' => "v3-case-{$this->case->id}",
        'source_title' => "v3-text-{$this->case->id}-abc",
        'notebook_id' => 'nb-123',
        'source_id' => 'src-456',
        'state' => 'bound',
        'text_sha256' => hash('sha256', 'canonical text'),
        'text_bytes' => 14,
        'transfer_status' => 'confirmed',
    ]);

    expect($binding)->toBeInstanceOf(KnowledgeSourceBinding::class);

    // Duplicate case_id or extracted_source_id must fail
    expect(function () use ($extracted) {
        KnowledgeSourceBinding::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'extracted_source_id' => (string) Str::uuid(),
            'adapter_kind' => 'notebooklm_mcp',
            'adapter_version' => '0.3.1',
            'notebook_title' => 'nb-title-2',
            'source_title' => 'src-title-2',
            'state' => 'pending_notebook',
            'text_sha256' => hash('sha256', 'other'),
            'text_bytes' => 10,
            'transfer_status' => 'not_sent',
        ]);
    })->toThrow(QueryException::class);

    // Duplicate provider notebook_id or source_id must fail
    $case2 = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process_again',
        'state' => 'queued',
        'state_version' => 2,
    ]);

    $activation2 = SourceActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $case2->id,
        'workflow_id' => "v3-case-{$case2->id}",
        'state' => 'accepted',
    ]);

    $extracted2 = ExtractedSource::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $case2->id,
        'activation_id' => $activation2->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'status' => 'ready',
        'artifact_path' => "extracted/{$case2->id}/def.txt",
        'text_sha256' => hash('sha256', 'canonical text 2'),
        'text_bytes' => 16,
        'page_count' => 3,
        'ocr_page_count' => 0,
        'page_report' => [],
        'extractor_version' => '0.1.0',
    ]);

    expect(function () use ($case2, $extracted2) {
        KnowledgeSourceBinding::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case2->id,
            'extracted_source_id' => $extracted2->id,
            'adapter_kind' => 'notebooklm_mcp',
            'adapter_version' => '0.3.1',
            'notebook_title' => "v3-case-{$case2->id}",
            'source_title' => "v3-text-{$case2->id}-def",
            'notebook_id' => 'nb-123', // Reused notebook_id!
            'source_id' => 'src-999',
            'state' => 'bound',
            'text_sha256' => hash('sha256', 'canonical text 2'),
            'text_bytes' => 16,
            'transfer_status' => 'confirmed',
        ]);
    })->toThrow(QueryException::class);

    // Deleting extracted source must fail foreign key restriction
    expect(function () use ($extracted) {
        $extracted->delete();
    })->toThrow(QueryException::class);
});

test('SourceEffect schema enforces unique case operation and delete restriction', function () {
    $effect = SourceEffect::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'operation' => 'notebook_create',
        'request_digest' => hash('sha256', 'req1'),
        'state' => 'sending',
        'send_count' => 1,
    ]);

    expect($effect)->toBeInstanceOf(SourceEffect::class);

    // Duplicate (case_id, operation) must fail
    expect(function () {
        SourceEffect::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'operation' => 'notebook_create',
            'request_digest' => hash('sha256', 'req2'),
            'state' => 'sending',
            'send_count' => 1,
        ]);
    })->toThrow(QueryException::class);

    // Deleting case must fail
    expect(function () {
        $this->case->delete();
    })->toThrow(QueryException::class);
});
