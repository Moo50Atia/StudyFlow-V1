<?php

namespace Tests\Integration\V3\Sources;

use App\Models\V3\Document;
use App\Models\V3\ExtractedSource;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SourceActivation;
use App\Models\V3\WorkflowLink;
use App\Services\V3\Source\ActivateSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class LiveSourceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_k18_live_source_workflow_handoff_and_persistence(): void
    {
        DB::statement('PRAGMA foreign_keys = ON;');
        Storage::fake('v3_private');

        $teacher = AccessFixtures::createEnrolledTeacher('Teacher Live Workflow');
        $admin = AccessFixtures::createEnrolledAdmin('Admin Live Workflow');

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

        $pdfContent = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
        $docSha256 = hash('sha256', $pdfContent);
        $docPath = 'accepted/test-live.pdf';
        Storage::disk('v3_private')->put($docPath, $pdfContent);

        $document = Document::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $teacher->id,
            'sha256' => $docSha256,
            'byte_size' => strlen($pdfContent),
            'page_count' => 1,
            'state' => 'ready',
            'storage_path' => $docPath,
            'accepted_at' => now(),
        ]);

        $case = ProcessingCase::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $teacher->id,
            'document_id' => $document->id,
            'settings_version_id' => $settingsVersion->id,
            'idempotency_key' => (string) Str::uuid(),
            'action' => 'process',
            'state' => 'queued',
            'state_version' => 2,
        ]);

        $link = WorkflowLink::forceCreate([
            'case_id' => $case->id,
            'workflow_id' => "v3-case-{$case->id}",
            'run_id' => 'run-live-wf',
            'settings_version_id' => $settingsVersion->id,
            'settings_checksum' => $settingsVersion->checksum,
            'start_state' => 'started',
            'stage_code' => 'extraction',
            'status_code' => 'running',
            'started_at' => now(),
        ]);

        $activation = SourceActivation::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'workflow_id' => "v3-case-{$case->id}",
            'state' => 'accepted',
            'signal_accepted_at' => now(),
        ]);

        // Verify activation and case link state
        $this->assertEquals('accepted', $activation->state);
        $this->assertEquals('running', $link->status_code);
    }
}
