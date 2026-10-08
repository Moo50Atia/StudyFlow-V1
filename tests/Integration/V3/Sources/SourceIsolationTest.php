<?php

namespace Tests\Integration\V3\Sources;

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
use Tests\TestCase;

class SourceIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_k13_independent_cases_with_same_pdf_are_strictly_isolated(): void
    {
        DB::statement('PRAGMA foreign_keys = ON;');
        Storage::fake('v3_private');

        $teacher1 = AccessFixtures::createEnrolledTeacher('Teacher 1 Isolation');
        $teacher2 = AccessFixtures::createEnrolledTeacher('Teacher 2 Isolation');
        $admin = AccessFixtures::createEnrolledAdmin('Admin Isolation');

        SettingsFixtures::registerDefaultTemplates();
        $payload = SettingsFixtures::validPayload();
        $canonical = \App\Services\V3\Settings\CanonicalSettings::canonicalize($payload);
        $checksum = hash('sha256', \App\Services\V3\Settings\CanonicalSettings::encode($canonical));

        $settingsVersion = SettingsVersion::create([
            'version' => 1,
            'schema_version' => 1,
            'payload' => $canonical,
            'checksum' => $checksum,
            'published_by_user_id' => $admin->id,
            'published_at' => now(),
        ]);

        $pdfBytes = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
        $pdfSha = hash('sha256', $pdfBytes);

        // Document for teacher 1
        $doc1 = Document::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $teacher1->id,
            'sha256' => $pdfSha,
            'byte_size' => strlen($pdfBytes),
            'page_count' => 1,
            'state' => 'ready',
            'storage_path' => 'accepted/doc1.pdf',
            'accepted_at' => now(),
        ]);

        // Document for teacher 2 (same PDF sha)
        $doc2 = Document::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $teacher2->id,
            'sha256' => $pdfSha,
            'byte_size' => strlen($pdfBytes),
            'page_count' => 1,
            'state' => 'ready',
            'storage_path' => 'accepted/doc2.pdf',
            'accepted_at' => now(),
        ]);

        $case1 = ProcessingCase::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $teacher1->id,
            'document_id' => $doc1->id,
            'settings_version_id' => $settingsVersion->id,
            'idempotency_key' => (string) Str::uuid(),
            'action' => 'process',
            'state' => 'queued',
            'state_version' => 2,
        ]);

        $case2 = ProcessingCase::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $teacher2->id,
            'document_id' => $doc2->id,
            'settings_version_id' => $settingsVersion->id,
            'idempotency_key' => (string) Str::uuid(),
            'action' => 'process',
            'state' => 'queued',
            'state_version' => 2,
        ]);

        $act1 = SourceActivation::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case1->id,
            'workflow_id' => "v3-case-{$case1->id}",
            'state' => 'accepted',
        ]);

        $act2 = SourceActivation::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case2->id,
            'workflow_id' => "v3-case-{$case2->id}",
            'state' => 'accepted',
        ]);

        $ext1 = ExtractedSource::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case1->id,
            'activation_id' => $act1->id,
            'document_id' => $doc1->id,
            'settings_version_id' => $settingsVersion->id,
            'status' => 'ready',
            'artifact_path' => "extracted/{$case1->id}/abcdef.txt",
            'text_sha256' => 'abcdef',
            'text_bytes' => 100,
            'page_count' => 1,
            'ocr_page_count' => 0,
            'page_report' => [['page' => 1, 'method' => 'text', 'chars' => 100, 'gap_code' => null]],
            'extractor_version' => '0.1.0',
        ]);

        $ext2 = ExtractedSource::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case2->id,
            'activation_id' => $act2->id,
            'document_id' => $doc2->id,
            'settings_version_id' => $settingsVersion->id,
            'status' => 'ready',
            'artifact_path' => "extracted/{$case2->id}/xyz123.txt",
            'text_sha256' => 'xyz123',
            'text_bytes' => 100,
            'page_count' => 1,
            'ocr_page_count' => 0,
            'page_report' => [['page' => 1, 'method' => 'text', 'chars' => 100, 'gap_code' => null]],
            'extractor_version' => '0.1.0',
        ]);

        $binding1 = KnowledgeSourceBinding::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case1->id,
            'extracted_source_id' => $ext1->id,
            'adapter_kind' => 'notebooklm_mcp',
            'adapter_version' => '0.3.1',
            'notebook_title' => "v3-case-{$case1->id}",
            'source_title' => "v3-text-{$case1->id}-abc",
            'notebook_id' => 'nb-1',
            'source_id' => 'src-1',
            'state' => 'bound',
            'text_sha256' => 'abcdef',
            'text_bytes' => 100,
            'transfer_status' => 'confirmed',
        ]);

        $binding2 = KnowledgeSourceBinding::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case2->id,
            'extracted_source_id' => $ext2->id,
            'adapter_kind' => 'notebooklm_mcp',
            'adapter_version' => '0.3.1',
            'notebook_title' => "v3-case-{$case2->id}",
            'source_title' => "v3-text-{$case2->id}-xyz",
            'notebook_id' => 'nb-2',
            'source_id' => 'src-2',
            'state' => 'bound',
            'text_sha256' => 'xyz123',
            'text_bytes' => 100,
            'transfer_status' => 'confirmed',
        ]);

        // 1. Check database isolation
        $this->assertNotEquals($binding1->id, $binding2->id);
        $this->assertNotEquals($binding1->notebook_title, $binding2->notebook_title);
        $this->assertNotEquals($binding1->notebook_id, $binding2->notebook_id);

        // 2. Check Teacher 1 cannot access Teacher 2's source status
        $this->actingAs($teacher1)->getJson("/v3/teacher/cases/{$case2->id}/source-status")
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'resource_unavailable']]);

        // 3. Check Teacher 2 cannot access Teacher 1's source status
        $this->actingAs($teacher2)->getJson("/v3/teacher/cases/{$case1->id}/source-status")
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'resource_unavailable']]);
    }
}
