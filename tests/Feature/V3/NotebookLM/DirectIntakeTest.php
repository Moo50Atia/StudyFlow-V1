<?php

namespace Tests\Feature\V3\NotebookLM;

use App\Models\V3\Document;
use App\Models\V3\NotebookFrameworkVersion;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SourceActivation;
use App\Models\V3\WorkflowLink;
use App\Services\V3\NotebookLM\FrameworkCatalog;
use App\Services\V3\Settings\PublishSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\V3\Pdf\PdfFixtures;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\Support\V3\WorkflowFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Direct Intake');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Direct Intake');

    SettingsFixtures::registerDefaultTemplates();
    $payload = SettingsFixtures::validPayload();
    $publisher = app(PublishSettings::class);
    $this->settingsVersion = $publisher->publish($payload, 0, $this->admin->id);

    // Import framework
    $frameworkPath = base_path('steps.txt');
    $catalog = app(FrameworkCatalog::class);
    $this->framework = $catalog->importFramework(
        $frameworkPath,
        '96553bd6e326c3d30c5c7306a4b2e6611dc65838672aef21b4b417b50d4614ec',
        $this->admin->id
    );
});

test('N03: intake rejects invalid or malformed PDF files', function () {
    $invalidFile = UploadedFile::fake()->createWithContent('bad.pdf', 'This is definitely not a PDF file');

    $response = $this->actingAs($this->teacher)
        ->postJson('/v3/teacher/pdf-uploads', [
            'pdf' => $invalidFile,
        ]);

    $response->assertStatus(422);
});

test('N03: accepted direct PDF is streamed unchanged with exact original bytes', function () {
    $pdfContent = PdfFixtures::minimalValidPdf(3);
    $docSha256 = hash('sha256', $pdfContent);
    $docId = (string) Str::uuid();
    $docPath = "accepted/{$docId}.pdf";
    Storage::disk('v3_private')->put($docPath, $pdfContent);

    $document = Document::forceCreate([
        'id' => $docId,
        'owner_user_id' => $this->teacher->id,
        'sha256' => $docSha256,
        'byte_size' => strlen($pdfContent),
        'page_count' => 3,
        'state' => 'ready',
        'storage_path' => $docPath,
        'accepted_at' => now(),
    ]);

    $case = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'document_id' => $document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'pipeline_version' => 'notebooklm_combined_v1',
        'notebook_framework_version_id' => $this->framework->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'queued',
        'state_version' => 1,
    ]);

    $activation = SourceActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $case->id,
        'workflow_id' => "v3-case-{$case->id}",
        'state' => 'accepted',
        'signal_accepted_at' => now(),
    ]);

    $path = "/api/internal/v1/source-activations/{$activation->id}/pdf";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $resp = $this->withHeaders($headers)->get($path);
    $resp->assertStatus(200);
    expect($resp->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($resp->headers->get('X-V3-Case-ID'))->toBe($case->id)
        ->and($resp->headers->get('X-V3-Document-SHA256'))->toBe($docSha256)
        ->and($resp->streamedContent())->toBe($pdfContent);
});

test('N03: signed steps route streams captured framework text with exact hash header', function () {
    $pdfContent = PdfFixtures::minimalValidPdf(2);
    $docId = (string) Str::uuid();
    $docPath = "accepted/{$docId}.pdf";
    Storage::disk('v3_private')->put($docPath, $pdfContent);

    $document = Document::forceCreate([
        'id' => $docId,
        'owner_user_id' => $this->teacher->id,
        'sha256' => hash('sha256', $pdfContent),
        'byte_size' => strlen($pdfContent),
        'page_count' => 2,
        'state' => 'ready',
        'storage_path' => $docPath,
        'accepted_at' => now(),
    ]);

    $case = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'document_id' => $document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'pipeline_version' => 'notebooklm_combined_v1',
        'notebook_framework_version_id' => $this->framework->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'queued',
        'state_version' => 1,
    ]);

    $activation = SourceActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $case->id,
        'workflow_id' => "v3-case-{$case->id}",
        'state' => 'accepted',
        'signal_accepted_at' => now(),
    ]);

    $path = "/api/internal/v1/source-activations/{$activation->id}/steps";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $resp = $this->withHeaders($headers)->get($path);
    $resp->assertStatus(200);
    expect($resp->headers->get('Content-Type'))->toContain('text/plain')
        ->and($resp->headers->get('X-V3-Framework-SHA256'))->toBe($this->framework->body_sha256)
        ->and($resp->getContent())->toBe(file_get_contents(base_path('steps.txt')));
});

test('N03: steps route rejects unauthenticated or invalid signature requests', function () {
    $activationId = (string) Str::uuid();
    $path = "/api/internal/v1/source-activations/{$activationId}/steps";

    // Unsigned
    $this->getJson($path)->assertStatus(401);

    // Bad signature
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, [], 'wrong-secret');
    $this->withHeaders($headers)->get($path)->assertStatus(401);
});
