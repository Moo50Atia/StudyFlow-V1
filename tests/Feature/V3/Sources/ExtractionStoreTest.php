<?php

namespace Tests\Feature\V3\Sources;

use App\Models\V3\Document;
use App\Models\V3\ExtractedSource;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SourceActivation;
use App\Models\V3\WorkflowLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\Support\V3\WorkflowFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Extraction Store');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Extraction Store');

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

    $this->pdfContent = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
    $this->docSha256 = hash('sha256', $this->pdfContent);
    $this->docPath = 'accepted/test-doc.pdf';
    Storage::disk('v3_private')->put($this->docPath, $this->pdfContent);

    $this->document = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'sha256' => $this->docSha256,
        'byte_size' => strlen($this->pdfContent),
        'page_count' => 1,
        'state' => 'ready',
        'storage_path' => $this->docPath,
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

    $this->link = WorkflowLink::forceCreate([
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'run_id' => 'run-wf-store',
        'settings_version_id' => $this->settingsVersion->id,
        'settings_checksum' => $this->settingsVersion->checksum,
        'start_state' => 'started',
        'stage_code' => 'extraction',
        'status_code' => 'running',
        'started_at' => now(),
    ]);

    $this->activation = SourceActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'accepted',
        'signal_accepted_at' => now(),
    ]);

    $this->canonicalText = "[PAGE 1/1]\nHello extracted world\n[/PAGE 1/1]\n";
    $this->textSha256 = hash('sha256', $this->canonicalText);
    $this->pageReport = [
        [
            'page' => 1,
            'method' => 'text',
            'chars' => strlen('Hello extracted world'),
            'ocr_confidence' => null,
            'gap_code' => null,
        ],
    ];
});

test('K07: GET extraction before POST returns absent status', function () {
    $path = "/api/internal/v1/source-activations/{$this->activation->id}/extraction";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $resp = $this->withHeaders($headers)->get($path);
    $resp->assertStatus(200)
        ->assertJson([
            'data' => [
                'status' => 'absent',
                'extracted_source_ref' => null,
                'text_sha256' => null,
                'page_count' => null,
            ],
        ]);
});

function postSigned($test, string $path, array $payload) {
    $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $payload);
    return $test->call('POST', $path, [], [], [], $headers, $rawBody);
}

test('K07: POST ready extraction stores verified private artifact and returns 201', function () {
    $path = "/api/internal/v1/source-activations/{$this->activation->id}/extraction";
    $payload = [
        'schema_version' => 1,
        'case_id' => $this->case->id,
        'status' => 'ready',
        'text' => $this->canonicalText,
        'text_sha256' => $this->textSha256,
        'page_report' => $this->pageReport,
        'extractor_version' => '0.1.0',
    ];

    $resp = postSigned($this, $path, $payload);
    $resp->assertStatus(201);
    $ref = $resp->json('data.extracted_source_ref');
    expect($ref)->not->toBeNull()
        ->and($resp->json('data.status'))->toBe('ready');

    // Verify row in database
    $row = ExtractedSource::find($ref);
    expect($row)->not->toBeNull()
        ->and($row->case_id)->toBe($this->case->id)
        ->and($row->activation_id)->toBe($this->activation->id)
        ->and($row->document_id)->toBe($this->document->id)
        ->and($row->settings_version_id)->toBe($this->settingsVersion->id)
        ->and($row->status)->toBe('ready')
        ->and($row->text_sha256)->toBe($this->textSha256)
        ->and($row->text_bytes)->toBe(strlen($this->canonicalText))
        ->and($row->page_count)->toBe(1)
        ->and($row->ocr_page_count)->toBe(0);

    // Verify artifact file on disk
    $expectedPath = "extracted/{$this->case->id}/{$this->textSha256}.txt";
    expect($row->artifact_path)->toBe($expectedPath);
    expect(Storage::disk('v3_private')->exists($expectedPath))->toBeTrue();
    expect(Storage::disk('v3_private')->get($expectedPath))->toBe($this->canonicalText);
});

test('K07: exact replay of POST ready returns 200 with same ref', function () {
    $path = "/api/internal/v1/source-activations/{$this->activation->id}/extraction";
    $payload = [
        'schema_version' => 1,
        'case_id' => $this->case->id,
        'status' => 'ready',
        'text' => $this->canonicalText,
        'text_sha256' => $this->textSha256,
        'page_report' => $this->pageReport,
        'extractor_version' => '0.1.0',
    ];

    $resp1 = postSigned($this, $path, $payload);
    $resp1->assertStatus(201);
    $ref1 = $resp1->json('data.extracted_source_ref');

    $resp2 = postSigned($this, $path, $payload);
    $resp2->assertStatus(200);
    expect($resp2->json('data.extracted_source_ref'))->toBe($ref1);
});

test('K07: changed replay returns 409 source_conflict without overwrite', function () {
    $path = "/api/internal/v1/source-activations/{$this->activation->id}/extraction";
    $payload = [
        'schema_version' => 1,
        'case_id' => $this->case->id,
        'status' => 'ready',
        'text' => $this->canonicalText,
        'text_sha256' => $this->textSha256,
        'page_report' => $this->pageReport,
        'extractor_version' => '0.1.0',
    ];

    postSigned($this, $path, $payload)->assertStatus(201);

    // Changed payload
    $diffPayload = $payload;
    $diffText = "[PAGE 1/1]\nChanged text\n[/PAGE 1/1]\n";
    $diffPayload['text'] = $diffText;
    $diffPayload['text_sha256'] = hash('sha256', $diffText);

    postSigned($this, $path, $diffPayload)->assertStatus(409)
        ->assertJson(['error' => ['code' => 'source_conflict']]);
});

test('K07: POST blocked extraction stores blocked row without artifact', function () {
    $path = "/api/internal/v1/source-activations/{$this->activation->id}/extraction";
    $blockedReport = [
        [
            'page' => 1,
            'method' => 'text',
            'chars' => 0,
            'ocr_confidence' => null,
            'gap_code' => 'visual_unrepresented',
        ],
    ];

    $payload = [
        'schema_version' => 1,
        'case_id' => $this->case->id,
        'status' => 'blocked',
        'text' => null,
        'text_sha256' => null,
        'page_report' => $blockedReport,
        'extractor_version' => '0.1.0',
    ];

    $resp = postSigned($this, $path, $payload);

    $resp->assertStatus(201);
    $ref = $resp->json('data.extracted_source_ref');
    expect($resp->json('data.status'))->toBe('blocked');

    $row = ExtractedSource::find($ref);
    expect($row->status)->toBe('blocked')
        ->and($row->artifact_path)->toBeNull()
        ->and($row->text_sha256)->toBeNull()
        ->and($row->text_bytes)->toBe(0)
        ->and($row->error_code)->toBe('validation');
});

test('K07: GET text endpoint returns UTF-8 text with strict headers', function () {
    // First create ready extraction
    $postPath = "/api/internal/v1/source-activations/{$this->activation->id}/extraction";
    $payload = [
        'schema_version' => 1,
        'case_id' => $this->case->id,
        'status' => 'ready',
        'text' => $this->canonicalText,
        'text_sha256' => $this->textSha256,
        'page_report' => $this->pageReport,
        'extractor_version' => '0.1.0',
    ];
    $ref = postSigned($this, $postPath, $payload)->json('data.extracted_source_ref');

    $textPath = "/api/internal/v1/source-activations/{$this->activation->id}/extracted-sources/{$ref}/text";
    $textHeaders = WorkflowFixtures::signCallbackRequest('GET', $textPath, []);

    $resp = $this->withHeaders($textHeaders)->get($textPath);
    $resp->assertStatus(200);
    expect($resp->headers->get('Content-Type'))->toContain('text/plain')
        ->and($resp->headers->get('Cache-Control'))->toContain('private')
        ->and($resp->headers->get('Cache-Control'))->toContain('no-store')
        ->and($resp->getContent())->toBe($this->canonicalText);
});

test('K07: GET text when artifact file is corrupted or missing returns 503 source_integrity_failure', function () {
    $postPath = "/api/internal/v1/source-activations/{$this->activation->id}/extraction";
    $payload = [
        'schema_version' => 1,
        'case_id' => $this->case->id,
        'status' => 'ready',
        'text' => $this->canonicalText,
        'text_sha256' => $this->textSha256,
        'page_report' => $this->pageReport,
        'extractor_version' => '0.1.0',
    ];
    $ref = postSigned($this, $postPath, $payload)->json('data.extracted_source_ref');

    // Delete artifact file from disk
    Storage::disk('v3_private')->delete("extracted/{$this->case->id}/{$this->textSha256}.txt");

    $textPath = "/api/internal/v1/source-activations/{$this->activation->id}/extracted-sources/{$ref}/text";
    $textHeaders = WorkflowFixtures::signCallbackRequest('GET', $textPath, []);

    $this->withHeaders($textHeaders)->get($textPath)->assertStatus(503)
        ->assertJson(['error' => ['code' => 'source_integrity_failure']]);
});
