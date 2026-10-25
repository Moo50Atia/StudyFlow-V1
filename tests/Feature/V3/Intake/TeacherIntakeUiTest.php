<?php

namespace Tests\Feature\V3\Intake;

use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\UploadAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\V3\Pdf\PdfFixtures;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher UI');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin User');

    SettingsFixtures::registerDefaultTemplates();
    $payload = SettingsFixtures::validPayload();
    $publisher = app(\App\Services\V3\Settings\PublishSettings::class);
    $this->settingsVersion = $publisher->publish($payload, 0, $this->admin->id);

    Storage::fake('v3_private');
});

test('Teacher workspace renders at both the canonical and dashboard-linked URL', function (string $path) {
    $response = $this->actingAs($this->teacher)
        ->get($path);

    $response->assertStatus(200);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Teacher/Workspace')
        ->has('teacher'));
})->with(['/v3/teacher', '/v3/teacher/workspace']);

test('Browser upload POST redirects 303 to attempt show page even on application rejection', function () {
    // 1. Success upload
    $file = UploadedFile::fake()->createWithContent('lesson.pdf', PdfFixtures::minimalValidPdf(1));
    $response = $this->actingAs($this->teacher)
        ->post('/v3/teacher/pdf-uploads', [
            'pdf' => $file,
        ]);

    $response->assertStatus(303);
    $location = $response->headers->get('Location');
    expect($location)->toContain('/v3/teacher/pdf-uploads/');

    // 2. Application rejection (e.g. txt file)
    $badFile = UploadedFile::fake()->createWithContent('lesson.txt', 'not pdf');
    $responseBad = $this->actingAs($this->teacher)
        ->post('/v3/teacher/pdf-uploads', [
            'pdf' => $badFile,
        ]);

    $responseBad->assertStatus(303);
    $badLocation = $responseBad->headers->get('Location');
    expect($badLocation)->toContain('/v3/teacher/pdf-uploads/');
});

test('Attempt detail page displays appropriate UI for pending, rejected, and accepted states', function () {
    // 1. Pending attempt
    $pending = UploadAttempt::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'upload_settings_version_id' => $this->settingsVersion->id,
        'state' => 'quarantined',
        'expires_at' => now()->addHours(24),
    ]);

    $pendingResponse = $this->actingAs($this->teacher)
        ->get("/v3/teacher/pdf-uploads/{$pending->id}");

    $pendingResponse->assertStatus(200)->assertInertia(fn (Assert $page) => $page
        ->component('Teacher/PdfUploadShow')
        ->where('summary.state', 'quarantined'));

    // 2. Rejected attempt
    $rejected = UploadAttempt::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'upload_settings_version_id' => $this->settingsVersion->id,
        'state' => 'rejected',
        'reason_code' => 'not_pdf',
        'expires_at' => now()->addHours(24),
        'finished_at' => now(),
    ]);

    $rejectedResponse = $this->actingAs($this->teacher)
        ->get("/v3/teacher/pdf-uploads/{$rejected->id}");

    $rejectedResponse->assertStatus(200)->assertInertia(fn (Assert $page) => $page
        ->component('Teacher/PdfUploadShow')
        ->where('summary.state', 'rejected')
        ->where('summary.reason_code', 'not_pdf'));

    // 3. Accepted attempt with NO prior case -> offers process form
    $doc = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'sha256' => hash('sha256', 'content-no-case'),
        'byte_size' => 1024,
        'page_count' => 1,
        'state' => 'ready',
        'storage_path' => 'accepted/doc-nc.pdf',
        'accepted_at' => now(),
    ]);

    $acceptedNoCase = UploadAttempt::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'upload_settings_version_id' => $this->settingsVersion->id,
        'document_id' => $doc->id,
        'state' => 'accepted',
        'duplicate' => false,
        'expires_at' => now()->addHours(24),
        'finished_at' => now(),
    ]);

    $accResponse = $this->actingAs($this->teacher)
        ->get("/v3/teacher/pdf-uploads/{$acceptedNoCase->id}");

    $accResponse->assertStatus(200)->assertInertia(fn (Assert $page) => $page
        ->component('Teacher/PdfUploadShow')
        ->where('summary.state', 'accepted')
        ->where('summary.document_id', $doc->id));

    // 4. Accepted attempt WITH prior case -> offers open-previous link and process_again form
    $case = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'document_id' => $doc->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'awaiting_workflow',
        'state_version' => 1,
    ]);

    $accWithCaseResponse = $this->actingAs($this->teacher)
        ->get("/v3/teacher/pdf-uploads/{$acceptedNoCase->id}");

    $accWithCaseResponse->assertStatus(200)->assertInertia(fn (Assert $page) => $page
        ->component('Teacher/PdfUploadShow')
        ->where('summary.latest_case_id', $case->id));
});

test('Browser case POST creates case and redirects 303 to case show, and conflict renders 409 view', function () {
    $docId = (string) Str::uuid();
    $pdfContent = PdfFixtures::minimalValidPdf(1);
    $sha256 = hash('sha256', $pdfContent);
    Storage::disk('v3_private')->put("accepted/{$docId}.pdf", $pdfContent);

    $doc = Document::forceCreate([
        'id' => $docId,
        'owner_user_id' => $this->teacher->id,
        'sha256' => $sha256,
        'byte_size' => strlen($pdfContent),
        'page_count' => 1,
        'state' => 'ready',
        'storage_path' => "accepted/{$docId}.pdf",
        'accepted_at' => now(),
    ]);

    $idempotencyKey = (string) Str::uuid();

    // 1. Submit process form from browser
    $postResponse = $this->actingAs($this->teacher)
        ->post('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process',
            'idempotency_key' => $idempotencyKey,
        ]);

    $case = ProcessingCase::where('idempotency_key', $idempotencyKey)->first();
    expect($case)->not->toBeNull();

    $postResponse->assertStatus(303)
        ->assertRedirect(route('v3.teacher.cases.show', $case->id));

    // 2. Browser submission with conflict (e.g. duplicate choice required)
    $conflictResponse = $this->actingAs($this->teacher)
        ->post('/v3/teacher/cases', [
            'document_id' => $doc->id,
            'action' => 'process',
            'idempotency_key' => (string) Str::uuid(), // new key with action=process when case exists
        ]);

    $conflictResponse->assertStatus(409)->assertInertia(fn (Assert $page) => $page
        ->component('Teacher/CaseConflict')
        ->where('code', 'duplicate_choice_required'));
});

