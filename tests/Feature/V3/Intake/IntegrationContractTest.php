<?php

namespace Tests\Feature\V3\Intake;

use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Services\V3\Intake\AcceptedPdfReader;
use App\Services\V3\Settings\PublishSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    SettingsFixtures::registerDefaultTemplates();
    $payload = SettingsFixtures::validPayload();
    $publisher = app(PublishSettings::class);
    $this->settingsVersion = $publisher->publish($payload, 0, $this->admin->id);

    Storage::fake('v3_private');
});

test('I16: 007 discovery query cleanly locates cases in awaiting_workflow with durable immutable identity', function () {
    $docA = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherA->id,
        'sha256' => hash('sha256', 'doc-a'),
        'byte_size' => 1024,
        'page_count' => 1,
        'state' => 'ready',
        'storage_path' => 'accepted/doc-a.pdf',
        'accepted_at' => now(),
    ]);

    $case = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherA->id,
        'document_id' => $docA->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'awaiting_workflow',
        'state_version' => 1,
    ]);

    // Simulated 007 poller/worker query
    $pendingCases = ProcessingCase::where('state', 'awaiting_workflow')
        ->orderBy('created_at')
        ->orderBy('id')
        ->get();

    expect($pendingCases)->toHaveCount(1);
    $discovered = $pendingCases->first();

    expect($discovered->id)->toBe($case->id)
        ->and($discovered->owner_user_id)->toBe($this->teacherA->id)
        ->and($discovered->document_id)->toBe($docA->id)
        ->and($discovered->settings_version_id)->toBe($this->settingsVersion->id)
        ->and($discovered->state)->toBe('awaiting_workflow')
        ->and($discovered->state_version)->toBe(1);
});

test('I16: 008 adapter consumes verified stream and fails closed without cross-owner fallback', function () {
    $pdfContent = PdfFixtures::minimalValidPdf(2, 'Lesson Stream Content');
    $sha256 = hash('sha256', $pdfContent);

    // Teacher A's document
    $docAId = (string) Str::uuid();
    $storagePathA = "accepted/{$docAId}.pdf";
    Storage::disk('v3_private')->put($storagePathA, $pdfContent);

    $docA = Document::forceCreate([
        'id' => $docAId,
        'owner_user_id' => $this->teacherA->id,
        'sha256' => $sha256,
        'byte_size' => strlen($pdfContent),
        'page_count' => 2,
        'state' => 'ready',
        'storage_path' => $storagePathA,
        'accepted_at' => now(),
    ]);

    $caseA = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherA->id,
        'document_id' => $docA->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'awaiting_workflow',
        'state_version' => 1,
    ]);

    // Teacher B's document with identical content
    $docBId = (string) Str::uuid();
    $storagePathB = "accepted/{$docBId}.pdf";
    Storage::disk('v3_private')->put($storagePathB, $pdfContent);

    $docB = Document::forceCreate([
        'id' => $docBId,
        'owner_user_id' => $this->teacherB->id,
        'sha256' => $sha256,
        'byte_size' => strlen($pdfContent),
        'page_count' => 2,
        'state' => 'ready',
        'storage_path' => $storagePathB,
        'accepted_at' => now(),
    ]);

    $reader = app(AcceptedPdfReader::class);

    // 1. Successful stream extraction
    $stream = $reader->openForCase($caseA);
    expect($stream)->toBeResource();
    $readContent = stream_get_contents($stream);
    fclose($stream);
    expect($readContent)->toBe($pdfContent);

    // 2. Corrupt or delete Teacher A's file
    Storage::disk('v3_private')->delete($storagePathA);

    // Reader MUST NOT fallback to Teacher B's identical file; must fail closed
    expect(function () use ($reader, $caseA) {
        $reader->openForCase($caseA);
    })->toThrow(\RuntimeException::class);
});
