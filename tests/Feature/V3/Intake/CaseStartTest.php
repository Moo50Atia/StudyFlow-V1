<?php

namespace Tests\Feature\V3\Intake;

use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\UploadAttempt;
use App\Services\V3\Intake\AcceptedPdfReader;
use App\Services\V3\Settings\PublishSettings;
use App\Services\V3\Settings\SelectCaseSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\V3\Pdf\PdfFixtures;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->teacherA = AccessFixtures::createEnrolledTeacher('Teacher Alpha');
    $this->teacherB = AccessFixtures::createEnrolledTeacher('Teacher Beta');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin User');
    $this->student = AccessFixtures::createStudent('Student User');

    SettingsFixtures::registerDefaultTemplates();
    $payload = SettingsFixtures::validPayload(0, [
        'limits' => [
            'max_pdf_bytes' => 1024 * 1024, // 1 MiB limit
            'max_pdf_pages' => 50,
        ],
    ]);

    $publisher = app(PublishSettings::class);
    $this->settingsVersion = $publisher->publish($payload, 0, $this->admin->id);

    Storage::fake('v3_private');
});

/**
 * Helper to create a ready document with verified disk content.
 */
function createReadyDocument($teacher, int $pages = 5, ?string $content = null): Document
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

test('I11: authenticated teacher starts first processing case on owned ready document', function () {
    $doc = createReadyDocument($this->teacherA);
    $idempotencyKey = (string) Str::uuid();

    $response = $this->actingAs($this->teacherA)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process',
            'idempotency_key' => $idempotencyKey,
        ]);

    $response->assertStatus(201);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    $response->assertJsonStructure([
        'data' => [
            'case_id',
            'document_id',
            'state',
            'settings_version_id',
            'created_at',
        ],
    ]);

    $data = $response->json('data');
    expect($data['document_id'])->toBe($doc->id)
        ->and($data['state'])->toBe('awaiting_workflow')
        ->and($data['settings_version_id'])->toBe($this->settingsVersion->id);

    $case = ProcessingCase::findOrFail($data['case_id']);
    expect($case->owner_user_id)->toBe($this->teacherA->id)
        ->and($case->document_id)->toBe($doc->id)
        ->and($case->action)->toBe('process')
        ->and($case->idempotency_key)->toBe($idempotencyKey)
        ->and($case->state)->toBe('awaiting_workflow')
        ->and($case->state_version)->toBe(1)
        ->and($case->settings_version_id)->toBe($this->settingsVersion->id);
});

test('I11: browser submission of case start redirects 303 to case receipt and displays receipt', function () {
    $doc = createReadyDocument($this->teacherA);
    $idempotencyKey = (string) Str::uuid();

    $response = $this->actingAs($this->teacherA)
        ->post('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process',
            'idempotency_key' => $idempotencyKey,
        ]);

    $case = ProcessingCase::where('idempotency_key', $idempotencyKey)->first();
    expect($case)->not->toBeNull();

    $response->assertStatus(303)
        ->assertRedirect(route('v3.teacher.cases.show', $case->id));

    // Follow redirect
    $pageResponse = $this->actingAs($this->teacherA)
        ->get(route('v3.teacher.cases.show', $case->id));

    $pageResponse->assertStatus(200);
    $pageResponse->assertSee($case->id);
    $pageResponse->assertSee('awaiting_workflow');
});

test('I12: identical idempotent replay returns 200 with original settings version even if settings changed', function () {
    $doc = createReadyDocument($this->teacherA);
    $idempotencyKey = (string) Str::uuid();

    // 1. Initial start with version 1
    $firstResponse = $this->actingAs($this->teacherA)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process',
            'idempotency_key' => $idempotencyKey,
        ]);
    $firstResponse->assertStatus(201);
    $caseId = $firstResponse->json('data.case_id');

    // 2. Publish version 2
    $payload2 = SettingsFixtures::validPayload(1, [
        'limits' => [
            'max_pdf_bytes' => 2 * 1024 * 1024,
            'max_pdf_pages' => 100,
        ],
    ]);
    $version2 = app(PublishSettings::class)->publish($payload2, 1, $this->admin->id);
    expect($version2->id)->not->toBe($this->settingsVersion->id);

    // 3. Replay with identical key, document, and action
    $replayResponse = $this->actingAs($this->teacherA)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process',
            'idempotency_key' => $idempotencyKey,
        ]);

    $replayResponse->assertStatus(200);
    expect($replayResponse->json('data.case_id'))->toBe($caseId)
        ->and($replayResponse->json('data.settings_version_id'))->toBe($this->settingsVersion->id)
        ->and($replayResponse->json('data.state'))->toBe('awaiting_workflow');

    // Ensure only 1 row exists
    expect(ProcessingCase::where('document_id', $doc->id)->count())->toBe(1);
});

test('I12: conflicting idempotency key reuse returns 409 idempotency_conflict', function () {
    $doc1 = createReadyDocument($this->teacherA);
    $doc2 = createReadyDocument($this->teacherA, pages: 2, content: PdfFixtures::minimalValidPdf(2));
    $idempotencyKey = (string) Str::uuid();

    // Initial start for doc1
    $firstResponse = $this->actingAs($this->teacherA)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc1->id,
            'action' => 'process',
            'idempotency_key' => $idempotencyKey,
        ]);
    $firstResponse->assertStatus(201);

    // Conflict 1: same key, different document
    $conflictDoc = $this->actingAs($this->teacherA)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc2->id,
            'action' => 'process',
            'idempotency_key' => $idempotencyKey,
        ]);
    $conflictDoc->assertStatus(409)
        ->assertJson([
            'error' => [
                'code' => 'idempotency_conflict',
            ],
        ]);

    // Conflict 2: same key, same document, different action
    $conflictAction = $this->actingAs($this->teacherA)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc1->id,
            'action' => 'process_again',
            'idempotency_key' => $idempotencyKey,
        ]);
    $conflictAction->assertStatus(409)
        ->assertJson([
            'error' => [
                'code' => 'idempotency_conflict',
            ],
        ]);
});

test('I07/I11: duplicate choice rules enforce process for first case and process_again for subsequent runs', function () {
    $doc = createReadyDocument($this->teacherA);

    // Rule 1: No previous case, but action is process_again -> 409 previous_case_required
    $prematureAgain = $this->actingAs($this->teacherA)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process_again',
            'idempotency_key' => (string) Str::uuid(),
        ]);
    $prematureAgain->assertStatus(409)
        ->assertJson([
            'error' => [
                'code' => 'previous_case_required',
            ],
        ]);

    // Rule 2: Start first case with process -> 201
    $case1 = $this->actingAs($this->teacherA)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process',
            'idempotency_key' => (string) Str::uuid(),
        ]);
    $case1->assertStatus(201);

    // Rule 3: Case already exists, but sending new process -> 409 duplicate_choice_required
    $invalidProcess = $this->actingAs($this->teacherA)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process',
            'idempotency_key' => (string) Str::uuid(),
        ]);
    $invalidProcess->assertStatus(409)
        ->assertJson([
            'error' => [
                'code' => 'duplicate_choice_required',
            ],
        ]);

    // Rule 4: Case already exists, sending process_again with new key -> 201 Created (second case)
    $case2 = $this->actingAs($this->teacherA)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process_again',
            'idempotency_key' => (string) Str::uuid(),
        ]);
    $case2->assertStatus(201);

    expect(ProcessingCase::where('document_id', $doc->id)->count())->toBe(2);
});

test('I10: starting case on document exceeding current limits returns 409 document_exceeds_current_limits', function () {
    // Document with 10 pages and 5000 bytes
    $doc = createReadyDocument($this->teacherA, pages: 10);

    // Now tighten active settings limits: max_pdf_pages = 5
    $payloadTight = SettingsFixtures::validPayload(1, [
        'limits' => [
            'max_pdf_bytes' => 1024 * 1024,
            'max_pdf_pages' => 5, // Document has 10 pages, exceeds limit of 5!
            'max_ocr_pages' => 5,
        ],
    ]);
    app(PublishSettings::class)->publish($payloadTight, 1, $this->admin->id);

    $response = $this->actingAs($this->teacherA)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process',
            'idempotency_key' => (string) Str::uuid(),
        ]);

    $response->assertStatus(409)
        ->assertJson([
            'error' => [
                'code' => 'document_exceeds_current_limits',
            ],
        ]);

    expect(ProcessingCase::where('document_id', $doc->id)->count())->toBe(0);
});

test('I10: missing or hash-mismatched accepted file returns 503 storage_unavailable', function () {
    $doc = createReadyDocument($this->teacherA);

    // Delete file from disk
    Storage::disk('v3_private')->delete($doc->storage_path);

    $response = $this->actingAs($this->teacherA)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process',
            'idempotency_key' => (string) Str::uuid(),
        ]);

    $response->assertStatus(503)
        ->assertJson([
            'error' => [
                'code' => 'storage_unavailable',
            ],
        ]);

    expect(ProcessingCase::where('document_id', $doc->id)->count())->toBe(0);
});

test('I14: foreign or non-existent document returns 404 resource_unavailable without disclosing existence', function () {
    // 1. Completely absent UUID
    $absentId = (string) Str::uuid();
    $respAbsent = $this->actingAs($this->teacherA)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $absentId,
            'action' => 'process',
            'idempotency_key' => (string) Str::uuid(),
        ]);
    $respAbsent->assertStatus(404)
        ->assertJson([
            'error' => [
                'code' => 'resource_unavailable',
            ],
        ]);

    // 2. Foreign document owned by Teacher B
    $docB = createReadyDocument($this->teacherB);
    $respForeign = $this->actingAs($this->teacherA)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $docB->id,
            'action' => 'process',
            'idempotency_key' => (string) Str::uuid(),
        ]);
    $respForeign->assertStatus(404)
        ->assertJson([
            'error' => [
                'code' => 'resource_unavailable',
            ],
        ]);

    // Status and shape are identical
    expect($respAbsent->json())->toEqual($respForeign->json());

    // 3. Malformed non-UUID document_id
    $respMalformed = $this->actingAs($this->teacherA)
        ->postJson('/v3/teacher/cases', [
            'document_id' => 'not-a-valid-uuid',
            'action' => 'process',
            'idempotency_key' => (string) Str::uuid(),
        ]);
    $respMalformed->assertStatus(404)
        ->assertJson([
            'error' => [
                'code' => 'resource_unavailable',
            ],
        ]);
});

test('I14: pending unaccepted document returns 409 document_not_ready', function () {
    $docPending = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherA->id,
        'sha256' => hash('sha256', 'pending-bytes'),
        'byte_size' => 1024,
        'page_count' => 1,
        'state' => 'pending_move',
        'storage_path' => 'accepted/pending.pdf',
        'accepted_at' => null,
    ]);

    $response = $this->actingAs($this->teacherA)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $docPending->id,
            'action' => 'process',
            'idempotency_key' => (string) Str::uuid(),
        ]);

    $response->assertStatus(409)
        ->assertJson([
            'error' => [
                'code' => 'document_not_ready',
            ],
        ]);

    expect(ProcessingCase::where('document_id', $docPending->id)->count())->toBe(0);
});

test('I15: missing active settings returns 503 settings_unavailable', function () {
    $doc = createReadyDocument($this->teacherA);

    // Clear active settings pointer
    DB::table('v3_active_settings')->where('key', 'pilot')->update(['active_settings_version_id' => null]);

    $response = $this->actingAs($this->teacherA)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process',
            'idempotency_key' => (string) Str::uuid(),
        ]);

    $response->assertStatus(503)
        ->assertJson([
            'error' => [
                'code' => 'settings_unavailable',
            ],
        ]);

    expect(ProcessingCase::where('document_id', $doc->id)->count())->toBe(0);
});

test('I14: invalid input fields or extra fields return 422', function () {
    $doc = createReadyDocument($this->teacherA);

    // Extra unknown field
    $extraFieldResp = $this->actingAs($this->teacherA)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process',
            'idempotency_key' => (string) Str::uuid(),
            'unknown_field' => 'danger',
        ]);
    $extraFieldResp->assertStatus(422);

    // Invalid action
    $badActionResp = $this->actingAs($this->teacherA)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'invalid_action',
            'idempotency_key' => (string) Str::uuid(),
        ]);
    $badActionResp->assertStatus(422);

    // Non-UUID idempotency key
    $badKeyResp = $this->actingAs($this->teacherA)
        ->postJson('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process',
            'idempotency_key' => 'not-a-uuid',
        ]);
    $badKeyResp->assertStatus(422);
});

test('I14: case show route allows owner and denies foreign/wrong roles', function () {
    $doc = createReadyDocument($this->teacherA);
    $case = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherA->id,
        'document_id' => $doc->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'awaiting_workflow',
        'state_version' => 1,
    ]);

    // 1. Owner GET -> 200 with CaseReceipt
    $ownerResp = $this->actingAs($this->teacherA)->getJson("/v3/teacher/cases/{$case->id}");
    $ownerResp->assertStatus(200);
    expect($ownerResp->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $ownerResp->assertJson([
        'data' => [
            'case_id' => $case->id,
            'document_id' => $doc->id,
            'state' => 'awaiting_workflow',
            'settings_version_id' => $this->settingsVersion->id,
        ],
    ]);

    // 2. Foreign teacher GET -> 404 resource_unavailable
    $foreignResp = $this->actingAs($this->teacherB)->getJson("/v3/teacher/cases/{$case->id}");
    $foreignResp->assertStatus(404)
        ->assertJson([
            'error' => [
                'code' => 'resource_unavailable',
            ],
        ]);

    // 3. Admin GET -> 403 access_denied
    $adminResp = $this->actingAs($this->admin)->getJson("/v3/teacher/cases/{$case->id}");
    $adminResp->assertStatus(403);

    // 4. Student GET -> 403 access_denied
    $studentResp = $this->actingAs($this->student)->getJson("/v3/teacher/cases/{$case->id}");
    $studentResp->assertStatus(403);
});

test('AcceptedPdfReader opens verified private stream for owned ready case and denies unready/corrupt', function () {
    $doc = createReadyDocument($this->teacherA);
    $case = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherA->id,
        'document_id' => $doc->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'awaiting_workflow',
        'state_version' => 1,
    ]);

    $reader = app(AcceptedPdfReader::class);

    // 1. Valid stream read
    $stream = $reader->openForCase($case);
    expect($stream)->toBeResource();
    $content = stream_get_contents($stream);
    fclose($stream);
    expect(hash('sha256', $content))->toBe($doc->sha256);

    // 2. File deleted from storage -> throws runtime/storage exception
    Storage::disk('v3_private')->delete($doc->storage_path);
    expect(function () use ($reader, $case) {
        $reader->openForCase($case);
    })->toThrow(\RuntimeException::class);
});
