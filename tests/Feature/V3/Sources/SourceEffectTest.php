<?php

namespace Tests\Feature\V3\Sources;

use App\Models\V3\Document;
use App\Models\V3\ExtractedSource;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SourceActivation;
use App\Models\V3\SourceEffect;
use App\Models\V3\WorkflowLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\Support\V3\WorkflowFixtures;

uses(RefreshDatabase::class);

function postSignedJson($test, string $path, array $payload) {
    $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $payload);
    return $test->call('POST', $path, [], [], [], $headers, $rawBody);
}

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Source Effects');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Source Effects');

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

    $this->activation = SourceActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'accepted',
        'signal_accepted_at' => now(),
    ]);

    $this->canonicalText = "[PAGE 1/1]\nSource effect test text\n[/PAGE 1/1]\n";
    $this->textSha256 = hash('sha256', $this->canonicalText);

    $this->extractedSource = ExtractedSource::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'activation_id' => $this->activation->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'status' => 'ready',
        'artifact_path' => "extracted/{$this->case->id}/{$this->textSha256}.txt",
        'text_sha256' => $this->textSha256,
        'text_bytes' => strlen($this->canonicalText),
        'page_count' => 1,
        'ocr_page_count' => 0,
        'page_report' => [['page' => 1, 'method' => 'text', 'chars' => 23, 'gap_code' => null]],
        'extractor_version' => '0.1.0',
    ]);
});

test('K09: notebook_create claim commits send_count 1 and returns send_now', function () {
    $notebookTitle = "v3-case-{$this->case->id}";
    $digest = hash('sha256', "notebook_create\n{$this->case->id}\n{$notebookTitle}");

    $path = "/api/internal/v1/source-effects/{$this->case->id}/notebook_create/claim";
    $payload = [
        'schema_version' => 1,
        'activation_id' => $this->activation->id,
        'request_digest' => $digest,
    ];

    $resp = postSignedJson($this, $path, $payload);
    $resp->assertStatus(200);

    expect($resp->json('data.disposition'))->toBe('send_now')
        ->and($resp->json('data.effect_id'))->not->toBeNull()
        ->and($resp->json('data.provider_ref'))->toBeNull();

    $effect = SourceEffect::find($resp->json('data.effect_id'));
    expect($effect->send_count)->toBe(1)
        ->and($effect->state)->toBe('sending');

    // Repeated claim returns reconcile without incrementing send_count
    $resp2 = postSignedJson($this, $path, $payload);
    $resp2->assertStatus(200);
    expect($resp2->json('data.disposition'))->toBe('reconcile')
        ->and($resp2->json('data.effect_id'))->toBe($effect->id);

    $effect->refresh();
    expect($effect->send_count)->toBe(1);
});

test('K09: notebook_create claim with changed digest returns 409 source_conflict', function () {
    $wrongDigest = hash('sha256', "wrong_digest");
    $path = "/api/internal/v1/source-effects/{$this->case->id}/notebook_create/claim";
    $payload = [
        'schema_version' => 1,
        'activation_id' => $this->activation->id,
        'request_digest' => $wrongDigest,
    ];

    postSignedJson($this, $path, $payload)->assertStatus(409)
        ->assertJson(['error' => ['code' => 'source_conflict']]);
});

test('K09: notebook_create confirmation requires matching proof_title and updates binding', function () {
    $notebookTitle = "v3-case-{$this->case->id}";
    $digest = hash('sha256', "notebook_create\n{$this->case->id}\n{$notebookTitle}");

    $claimPath = "/api/internal/v1/source-effects/{$this->case->id}/notebook_create/claim";
    $claimResp = postSignedJson($this, $claimPath, [
        'schema_version' => 1,
        'activation_id' => $this->activation->id,
        'request_digest' => $digest,
    ]);
    $effectId = $claimResp->json('data.effect_id');

    // Result with mismatched title fails
    $resultPath = "/api/internal/v1/source-effects/{$effectId}/result";
    $mismatchedPayload = [
        'schema_version' => 1,
        'state' => 'confirmed',
        'provider_ref' => 'nb-provider-123',
        'proof_title' => 'wrong-title',
        'proof_sha256' => null,
        'error_code' => null,
    ];
    postSignedJson($this, $resultPath, $mismatchedPayload)->assertStatus(409)
        ->assertJson(['error' => ['code' => 'source_conflict']]);

    // Result with exact matching title succeeds
    $validPayload = [
        'schema_version' => 1,
        'state' => 'confirmed',
        'provider_ref' => 'nb-provider-123',
        'proof_title' => $notebookTitle,
        'proof_sha256' => null,
        'error_code' => null,
    ];
    $resp = postSignedJson($this, $resultPath, $validPayload);
    $resp->assertStatus(200)
        ->assertJson([
            'data' => [
                'state' => 'confirmed',
                'provider_ref' => 'nb-provider-123',
            ],
        ]);

    $effect = SourceEffect::find($effectId);
    expect($effect->state)->toBe('confirmed')
        ->and($effect->provider_ref)->toBe('nb-provider-123')
        ->and($effect->confirmed_at)->not->toBeNull();

    $binding = KnowledgeSourceBinding::where('case_id', $this->case->id)->first();
    expect($binding->notebook_id)->toBe('nb-provider-123')
        ->and($binding->state)->toBe('notebook_ready');
});

test('K10: notebook_add_text claim and confirmation binds source with exact content proof', function () {
    // 1. Confirm notebook_create first
    $notebookTitle = "v3-case-{$this->case->id}";
    $nbDigest = hash('sha256', "notebook_create\n{$this->case->id}\n{$notebookTitle}");
    $claimNbResp = postSignedJson($this, "/api/internal/v1/source-effects/{$this->case->id}/notebook_create/claim", [
        'schema_version' => 1,
        'activation_id' => $this->activation->id,
        'request_digest' => $nbDigest,
    ]);
    $nbEffectId = $claimNbResp->json('data.effect_id');
    postSignedJson($this, "/api/internal/v1/source-effects/{$nbEffectId}/result", [
        'schema_version' => 1,
        'state' => 'confirmed',
        'provider_ref' => 'nb-provider-456',
        'proof_title' => $notebookTitle,
        'proof_sha256' => null,
        'error_code' => null,
    ])->assertStatus(200);

    // 2. Claim notebook_add_text
    $sourceTitle = "v3-text-{$this->case->id}-" . substr($this->textSha256, 0, 16);
    $addDigest = hash('sha256', "notebook_add_text\n{$this->case->id}\nnb-provider-456\n{$sourceTitle}\n{$this->textSha256}");
    $addClaimPath = "/api/internal/v1/source-effects/{$this->case->id}/notebook_add_text/claim";

    $addClaimResp = postSignedJson($this, $addClaimPath, [
        'schema_version' => 1,
        'activation_id' => $this->activation->id,
        'request_digest' => $addDigest,
    ]);
    $addClaimResp->assertStatus(200);
    $addEffectId = $addClaimResp->json('data.effect_id');
    expect($addClaimResp->json('data.disposition'))->toBe('send_now');

    $binding = KnowledgeSourceBinding::where('case_id', $this->case->id)->first();
    expect($binding->transfer_status)->toBe('uncertain')
        ->and($binding->send_attempted_at)->not->toBeNull()
        ->and($binding->state)->toBe('add_uncertain');

    // 3. Confirm with matching title and sha256
    $addResultPath = "/api/internal/v1/source-effects/{$addEffectId}/result";
    $confirmPayload = [
        'schema_version' => 1,
        'state' => 'confirmed',
        'provider_ref' => 'source-provider-789',
        'proof_title' => $sourceTitle,
        'proof_sha256' => $this->textSha256,
        'error_code' => null,
    ];

    $confirmResp = postSignedJson($this, $addResultPath, $confirmPayload);
    $confirmResp->assertStatus(200);
    expect($confirmResp->json('data.state'))->toBe('confirmed')
        ->and($confirmResp->json('data.provider_ref'))->toBe('source-provider-789');

    $binding->refresh();
    expect($binding->state)->toBe('bound')
        ->and($binding->source_id)->toBe('source-provider-789')
        ->and($binding->transfer_status)->toBe('confirmed')
        ->and($binding->confirmed_at)->not->toBeNull();

    // 4. Verify binding GET endpoints
    $bindingPath = "/api/internal/v1/source-activations/{$this->activation->id}/binding";
    $bHeaders = WorkflowFixtures::signCallbackRequest('GET', $bindingPath, []);
    $this->withHeaders($bHeaders)->get($bindingPath)->assertStatus(200)
        ->assertJson([
            'data' => [
                'binding_ref' => $binding->id,
                'case_id' => $this->case->id,
                'state' => 'bound',
                'notebook_id' => 'nb-provider-456',
                'source_id' => 'source-provider-789',
                'text_sha256' => $this->textSha256,
            ],
        ]);

    $queryBindingPath = "/api/internal/v1/cases/{$this->case->id}/source-bindings/{$binding->id}";
    $qbHeaders = WorkflowFixtures::signCallbackRequest('GET', $queryBindingPath, []);
    $this->withHeaders($qbHeaders)->get($queryBindingPath)->assertStatus(200)
        ->assertJson([
            'data' => [
                'case_id' => $this->case->id,
                'extracted_source_ref' => $this->extractedSource->id,
                'notebook_id' => 'nb-provider-456',
                'source_id' => 'source-provider-789',
                'state' => 'bound',
                'text_sha256' => $this->textSha256,
            ],
        ]);
});

test('R03: real python source byte serialization with unicode, nulls, and one-byte tampering', function () {
    $notebookTitle = "v3-case-{$this->case->id}";
    $digest = hash('sha256', "notebook_create\n{$this->case->id}\n{$notebookTitle}");
    $claimPath = "/api/internal/v1/source-effects/{$this->case->id}/notebook_create/claim";
    $claimPayload = [
        'schema_version' => 1,
        'activation_id' => $this->activation->id,
        'request_digest' => $digest,
    ];
    $claimResp = postSignedJson($this, $claimPath, $claimPayload);
    $claimResp->assertStatus(200);
    $effectId = $claimResp->json('data.effect_id');

    // Prepare raw UTF-8 bytes containing Arabic Unicode provider_ref and null fields
    $resultPayload = [
        'schema_version' => 1,
        'state' => 'confirmed',
        'provider_ref' => 'nb-provider-مرحبا-123',
        'proof_title' => $notebookTitle,
        'proof_sha256' => null,
        'error_code' => null,
    ];
    $rawBytes = json_encode($resultPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $resultPath = "/api/internal/v1/source-effects/{$effectId}/result";
    $headers = \App\Services\V3\Workflow\BridgeRequestSigner::signCallbackRequest('POST', $resultPath, $rawBytes);

    $serverHeaders = [
        'HTTP_X_V3_TIMESTAMP' => $headers['X-V3-Timestamp'],
        'HTTP_X_V3_SIGNATURE' => $headers['X-V3-Signature'],
        'HTTP_X_V3_CONTRACT_VERSION' => '1',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];

    // 1. Valid signed request passes
    $validResp = $this->call('POST', $resultPath, [], [], [], $serverHeaders, $rawBytes);
    $validResp->assertStatus(200);
    expect($validResp->json('data.state'))->toBe('confirmed')
        ->and($validResp->json('data.provider_ref'))->toBe('nb-provider-مرحبا-123');

    // 2. Exact replay returns 200
    $replayResp = $this->call('POST', $resultPath, [], [], [], $serverHeaders, $rawBytes);
    $replayResp->assertStatus(200);

    // 3. Tampering by 1 byte fails with 401 worker_unauthenticated
    $tamperedBytes = substr($rawBytes, 0, -1) . ($rawBytes[strlen($rawBytes) - 1] === '}' ? ' ' : '}');
    $tamperedResp = $this->call('POST', $resultPath, [], [], [], $serverHeaders, $tamperedBytes);
    $tamperedResp->assertStatus(401)
        ->assertJson(['error' => ['code' => 'worker_unauthenticated']]);
});
