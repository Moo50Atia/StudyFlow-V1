<?php

namespace Tests\Feature\V3\Intake;

use App\Models\User;
use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\UploadAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Ensure foreign key constraints are strictly enforced in SQLite
    DB::statement('PRAGMA foreign_keys = ON;');

    $this->teacherA = AccessFixtures::createEnrolledTeacher('Teacher A');
    $this->teacherB = AccessFixtures::createEnrolledTeacher('Teacher B');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin User');
    $this->student = AccessFixtures::createStudent('Student User');

    // Create a registered settings version for tests
    SettingsFixtures::registerDefaultTemplates();
    $payload = SettingsFixtures::validPayload();
    $this->settingsVersion = SettingsVersion::create([
        'version' => 1,
        'schema_version' => 1,
        'payload' => $payload,
        'checksum' => hash('sha256', json_encode($payload)),
        'published_by_user_id' => $this->admin->id,
        'published_at' => now(),
    ]);
});

test('Document schema enforces unique owner and sha256 constraint', function () {
    $sha256 = hash('sha256', 'some pdf content');

    $docA1 = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherA->id,
        'sha256' => $sha256,
        'byte_size' => 1024,
        'page_count' => 1,
        'state' => 'ready',
        'storage_path' => 'accepted/doc1.pdf',
        'accepted_at' => now(),
    ]);

    expect($docA1)->toBeInstanceOf(Document::class);

    // Same owner, same sha256 must throw QueryException
    expect(function () use ($sha256) {
        Document::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacherA->id,
            'sha256' => $sha256,
            'byte_size' => 1024,
            'page_count' => 1,
            'state' => 'ready',
            'storage_path' => 'accepted/doc2.pdf',
            'accepted_at' => now(),
        ]);
    })->toThrow(QueryException::class);

    // Cross-owner same sha256 succeeds
    $docB = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherB->id,
        'sha256' => $sha256,
        'byte_size' => 1024,
        'page_count' => 1,
        'state' => 'ready',
        'storage_path' => 'accepted/doc3.pdf',
        'accepted_at' => now(),
    ]);

    expect($docB)->toBeInstanceOf(Document::class);
});

test('Foreign key restrict prevents deleting owner when Documents, Attempts or Cases exist', function () {
    $doc = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherA->id,
        'sha256' => hash('sha256', 'pdf-a'),
        'byte_size' => 1024,
        'page_count' => 1,
        'state' => 'ready',
        'storage_path' => 'accepted/doc-a.pdf',
        'accepted_at' => now(),
    ]);

    // Deleting owner of document must throw QueryException due to restrictOnDelete
    expect(function () {
        $this->teacherA->delete();
    })->toThrow(QueryException::class);
});

test('Document model prevents modifying immutable attributes after state is ready', function () {
    $doc = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherA->id,
        'sha256' => hash('sha256', 'pdf-immutable'),
        'byte_size' => 1024,
        'page_count' => 1,
        'state' => 'ready',
        'storage_path' => 'accepted/doc-imm.pdf',
        'accepted_at' => now(),
    ]);

    // Attempting to change sha256 or owner_user_id throws exception
    expect(function () use ($doc) {
        $doc->sha256 = hash('sha256', 'new-sha');
        $doc->save();
    })->toThrow(\InvalidArgumentException::class);

    expect(function () use ($doc) {
        $doc->owner_user_id = $this->teacherB->id;
        $doc->save();
    })->toThrow(\InvalidArgumentException::class);

    // Attempting to delete ready document throws LogicException
    expect(function () use ($doc) {
        $doc->delete();
    })->toThrow(\LogicException::class);
});

test('UploadAttempt enforces composite foreign key with Document owner', function () {
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

    // Valid attempt for teacher A linking to docA
    $attemptA = UploadAttempt::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherA->id,
        'upload_settings_version_id' => $this->settingsVersion->id,
        'document_id' => $docA->id,
        'state' => 'accepted',
        'expires_at' => now()->addHours(24),
    ]);
    expect($attemptA)->toBeInstanceOf(UploadAttempt::class);

    // Attempt for teacher B linking to docA (Teacher A's doc) fails composite FK constraint
    expect(function () use ($docA) {
        UploadAttempt::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacherB->id,
            'upload_settings_version_id' => $this->settingsVersion->id,
            'document_id' => $docA->id,
            'state' => 'accepted',
            'expires_at' => now()->addHours(24),
        ]);
    })->toThrow(QueryException::class);
});

test('ProcessingCase enforces composite foreign key, owner/key uniqueness, and immutability', function () {
    $docA = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherA->id,
        'sha256' => hash('sha256', 'doc-case'),
        'byte_size' => 1024,
        'page_count' => 1,
        'state' => 'ready',
        'storage_path' => 'accepted/doc-case.pdf',
        'accepted_at' => now(),
    ]);

    $key1 = (string) Str::uuid();

    // Valid case creation
    $case1 = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherA->id,
        'document_id' => $docA->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => $key1,
        'action' => 'process',
        'state' => 'awaiting_workflow',
        'state_version' => 1,
    ]);
    expect($case1)->toBeInstanceOf(ProcessingCase::class);

    // Same owner with same idempotency key fails unique constraint
    expect(function () use ($docA, $key1) {
        ProcessingCase::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacherA->id,
            'document_id' => $docA->id,
            'settings_version_id' => $this->settingsVersion->id,
            'idempotency_key' => $key1,
            'action' => 'process',
            'state' => 'awaiting_workflow',
            'state_version' => 1,
        ]);
    })->toThrow(QueryException::class);

    // Cross-owner with same idempotency key succeeds
    $docB = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherB->id,
        'sha256' => hash('sha256', 'doc-b-case'),
        'byte_size' => 1024,
        'page_count' => 1,
        'state' => 'ready',
        'storage_path' => 'accepted/doc-b.pdf',
        'accepted_at' => now(),
    ]);

    $caseB = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherB->id,
        'document_id' => $docB->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => $key1,
        'action' => 'process',
        'state' => 'awaiting_workflow',
        'state_version' => 1,
    ]);
    expect($caseB)->toBeInstanceOf(ProcessingCase::class);

    // Same owner deliberate second run with different idempotency key succeeds
    $key2 = (string) Str::uuid();
    $case2 = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherA->id,
        'document_id' => $docA->id,
        'settings_version_id' => $this->settingsVersion->id,
        'idempotency_key' => $key2,
        'action' => 'process_again',
        'state' => 'awaiting_workflow',
        'state_version' => 1,
    ]);
    expect($case2)->toBeInstanceOf(ProcessingCase::class);

    // Cross-owner foreign key mismatch: case for teacher B pointing to docA
    expect(function () use ($docA) {
        ProcessingCase::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacherB->id,
            'document_id' => $docA->id,
            'settings_version_id' => $this->settingsVersion->id,
            'idempotency_key' => (string) Str::uuid(),
            'action' => 'process',
            'state' => 'awaiting_workflow',
            'state_version' => 1,
        ]);
    })->toThrow(QueryException::class);

    // Immutability: modifying document_id, settings_version_id, idempotency_key, action, or owner_user_id fails
    expect(function () use ($case1) {
        $case1->action = 'process_again';
        $case1->save();
    })->toThrow(\InvalidArgumentException::class);

    expect(function () use ($case1) {
        $case1->idempotency_key = (string) Str::uuid();
        $case1->save();
    })->toThrow(\InvalidArgumentException::class);

    expect(function () use ($case1) {
        $case1->document_id = (string) Str::uuid();
        $case1->save();
    })->toThrow(\InvalidArgumentException::class);
});

test('Policies enforce V3 ownership for Document, UploadAttempt, and ProcessingCase', function () {
    $doc = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherA->id,
        'sha256' => hash('sha256', 'policy-pdf'),
        'byte_size' => 1024,
        'page_count' => 1,
        'state' => 'ready',
        'storage_path' => 'accepted/policy.pdf',
        'accepted_at' => now(),
    ]);

    $attempt = UploadAttempt::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherA->id,
        'upload_settings_version_id' => $this->settingsVersion->id,
        'document_id' => $doc->id,
        'state' => 'accepted',
        'expires_at' => now()->addHours(24),
    ]);

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

    // Teacher A (owner) is allowed
    foreach ([$doc, $attempt, $case] as $resource) {
        expect(Gate::forUser($this->teacherA)->allows('view', $resource))->toBeTrue()
            ->and(Gate::forUser($this->teacherA)->allows('update', $resource))->toBeTrue()
            ->and(Gate::forUser($this->teacherA)->allows('review', $resource))->toBeTrue()
            ->and(Gate::forUser($this->teacherA)->allows('download', $resource))->toBeTrue();
    }

    // Teacher B (foreign teacher) is denied
    foreach ([$doc, $attempt, $case] as $resource) {
        expect(Gate::forUser($this->teacherB)->allows('view', $resource))->toBeFalse()
            ->and(Gate::forUser($this->teacherB)->allows('update', $resource))->toBeFalse()
            ->and(Gate::forUser($this->teacherB)->allows('review', $resource))->toBeFalse()
            ->and(Gate::forUser($this->teacherB)->allows('download', $resource))->toBeFalse();
    }

    // Admin is denied (metadata-only access, no teacher content)
    foreach ([$doc, $attempt, $case] as $resource) {
        expect(Gate::forUser($this->admin)->allows('view', $resource))->toBeFalse()
            ->and(Gate::forUser($this->admin)->allows('update', $resource))->toBeFalse()
            ->and(Gate::forUser($this->admin)->allows('review', $resource))->toBeFalse()
            ->and(Gate::forUser($this->admin)->allows('download', $resource))->toBeFalse();
    }

    // Student is denied
    foreach ([$doc, $attempt, $case] as $resource) {
        expect(Gate::forUser($this->student)->allows('view', $resource))->toBeFalse();
    }
});

test('ownedBy scope restricts query strictly to requesting active teacher', function () {
    $docA = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherA->id,
        'sha256' => hash('sha256', 'query-doc-a'),
        'byte_size' => 1024,
        'page_count' => 1,
        'state' => 'ready',
        'storage_path' => 'accepted/q-a.pdf',
        'accepted_at' => now(),
    ]);

    $docB = Document::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacherB->id,
        'sha256' => hash('sha256', 'query-doc-b'),
        'byte_size' => 2048,
        'page_count' => 2,
        'state' => 'ready',
        'storage_path' => 'accepted/q-b.pdf',
        'accepted_at' => now(),
    ]);

    expect(Document::ownedBy($this->teacherA)->count())->toBe(1)
        ->and(Document::ownedBy($this->teacherA)->first()->id)->toBe($docA->id);

    expect(Document::ownedBy($this->teacherB)->count())->toBe(1)
        ->and(Document::ownedBy($this->teacherB)->first()->id)->toBe($docB->id);

    // Ineligible user (admin or student) query yields 0 rows
    expect(Document::ownedBy($this->admin)->count())->toBe(0)
        ->and(Document::ownedBy($this->student)->count())->toBe(0);
});
