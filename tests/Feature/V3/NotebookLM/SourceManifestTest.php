<?php

namespace Tests\Feature\V3\NotebookLM;

use App\Models\V3\Document;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\KnowledgeSourceFile;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SourceActivation;
use App\Models\V3\SourceEffect;
use App\Models\V3\WorkflowLink;
use App\Services\V3\NotebookLM\DirectSourceCoordinator;
use App\Services\V3\NotebookLM\FrameworkCatalog;
use App\Services\V3\Settings\PublishSettings;
use App\Services\V3\Source\ActivateSource;
use App\Services\V3\Source\SourceEffectLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Manifest');
    $this->admin = AccessFixtures::createEnrolledAdmin('Admin Manifest');

    SettingsFixtures::registerDefaultTemplates();
    $payload = SettingsFixtures::validPayload();
    $publisher = app(PublishSettings::class);
    $this->settingsVersion = $publisher->publish($payload, 0, $this->admin->id);

    $catalog = app(FrameworkCatalog::class);
    $this->framework = $catalog->importFramework(
        base_path('steps.txt'),
        '96553bd6e326c3d30c5c7306a4b2e6611dc65838672aef21b4b417b50d4614ec',
        $this->admin->id
    );

    $pdfContent = PdfFixtures::minimalValidPdf(2);
    $this->docSha256 = hash('sha256', $pdfContent);
    $this->docId = (string) Str::uuid();
    $docPath = "accepted/{$this->docId}.pdf";
    Storage::disk('v3_private')->put($docPath, $pdfContent);

    $this->document = Document::forceCreate([
        'id' => $this->docId,
        'owner_user_id' => $this->teacher->id,
        'sha256' => $this->docSha256,
        'byte_size' => strlen($pdfContent),
        'page_count' => 2,
        'state' => 'ready',
        'storage_path' => $docPath,
        'accepted_at' => now(),
    ]);

    $this->case = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'pipeline_version' => 'notebooklm_combined_v1',
        'notebook_framework_version_id' => $this->framework->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'queued',
        'state_version' => 1,
    ]);

    $this->activation = SourceActivation::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'workflow_id' => "v3-case-{$this->case->id}",
        'state' => 'accepted',
        'signal_accepted_at' => now(),
    ]);
});

test('N04: coordinator prepare creates binding shell and two exact manifest files', function () {
    $coordinator = app(DirectSourceCoordinator::class);
    $binding = $coordinator->prepare($this->case);

    expect($binding)->toBeInstanceOf(KnowledgeSourceBinding::class)
        ->and($binding->source_mode)->toBe('original_pdf')
        ->and($binding->adapter_kind)->toBe('notebooklm_py')
        ->and($binding->adapter_version)->toBe('0.8.4')
        ->and($binding->document_id)->toBe($this->document->id)
        ->and($binding->framework_version_id)->toBe($this->framework->id)
        ->and($binding->notebook_title)->toBe("v3-case-{$this->case->id}")
        ->and($binding->state)->toBe('pending_notebook');

    $files = KnowledgeSourceFile::where('binding_id', $binding->id)->orderBy('role')->get();
    expect($files)->toHaveCount(2);

    $pdfFile = $files->firstWhere('role', 'educational_pdf');
    expect($pdfFile)->not->toBeNull()
        ->and($pdfFile->mime_type)->toBe('application/pdf')
        ->and($pdfFile->artifact_sha256)->toBe($this->docSha256)
        ->and($pdfFile->artifact_bytes)->toBe($this->document->byte_size)
        ->and($pdfFile->title)->toBe("v3-pdf-{$this->case->id}-" . substr($this->docSha256, 0, 16))
        ->and($pdfFile->state)->toBe('pending');

    $stepsFile = $files->firstWhere('role', 'framework_steps');
    expect($stepsFile)->not->toBeNull()
        ->and($stepsFile->mime_type)->toBe('text/plain')
        ->and($stepsFile->artifact_sha256)->toBe($this->framework->body_sha256)
        ->and($stepsFile->artifact_bytes)->toBe($this->framework->body_bytes)
        ->and($stepsFile->title)->toBe('steps.txt')
        ->and($stepsFile->state)->toBe('pending');
});

test('N04: ledger enforces canonical request digest for notebook_create and uploads', function () {
    $coordinator = app(DirectSourceCoordinator::class);
    $binding = $coordinator->prepare($this->case);
    $ledger = app(SourceEffectLedger::class);

    // 1. Claim notebook_create
    $canonicalCreateDigest = hash('sha256', json_encode([
        'protocol' => 'notebooklm_combined_v1',
        'case_id' => $this->case->id,
        'operation' => 'notebook_create',
        'notebook_title' => $binding->notebook_title,
        'notebook_id' => null,
        'role' => null,
        'title' => null,
        'artifact_sha256' => null,
        'artifact_bytes' => null,
        'response_format_sha256' => null,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    // Wrong digest fails
    expect(fn () => $ledger->claimEffect($this->case->id, 'notebook_create', $this->activation->id, 'wrong-digest'))
        ->toThrow(\DomainException::class, 'source_conflict');

    // Correct digest succeeds
    $claim = $ledger->claimEffect($this->case->id, 'notebook_create', $this->activation->id, $canonicalCreateDigest);
    expect($claim['disposition'])->toBe('send_now');

    // Record create confirmed
    $ledger->recordResult($claim['effect_id'], 'confirmed', 'nb-12345', null, null);
    $binding->refresh();
    expect($binding->notebook_id)->toBe('nb-12345');
});

test('N08: binding reaches bound only when notebook confirmed, both sources ready and chat configured', function () {
    $coordinator = app(DirectSourceCoordinator::class);
    $binding = $coordinator->prepare($this->case);
    $ledger = app(SourceEffectLedger::class);

    // Initial state is pending_notebook
    expect($binding->state)->toBe('pending_notebook');

    // Confirm notebook
    $binding->update(['notebook_id' => 'nb-abc']);
    $coordinator->reconcileBindingState($binding);
    expect($binding->fresh()->state)->toBe('pending_sources');

    // Set PDF source ready
    $pdfFile = KnowledgeSourceFile::where('binding_id', $binding->id)->where('role', 'educational_pdf')->first();
    $pdfFile->update(['state' => 'ready', 'provider_source_id' => 'src-pdf-1']);
    $coordinator->reconcileBindingState($binding);
    expect($binding->fresh()->state)->toBe('pending_sources');

    // Set steps source ready
    $stepsFile = KnowledgeSourceFile::where('binding_id', $binding->id)->where('role', 'framework_steps')->first();
    $stepsFile->update(['state' => 'ready', 'provider_source_id' => 'src-steps-2']);
    $coordinator->reconcileBindingState($binding);
    expect($binding->fresh()->state)->toBe('pending_configuration');

    // Configure chat format
    $binding->update(['chat_configuration_state' => 'ready']);
    $coordinator->reconcileBindingState($binding);
    expect($binding->fresh()->state)->toBe('bound');
});

test('N08: duplicate cross-case provider identity cannot bind', function () {
    $coordinator = app(DirectSourceCoordinator::class);
    $binding1 = $coordinator->prepare($this->case);

    // Create another case
    $case2 = ProcessingCase::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $this->teacher->id,
        'document_id' => $this->document->id,
        'settings_version_id' => $this->settingsVersion->id,
        'pipeline_version' => 'notebooklm_combined_v1',
        'notebook_framework_version_id' => $this->framework->id,
        'idempotency_key' => (string) Str::uuid(),
        'action' => 'process',
        'state' => 'queued',
        'state_version' => 1,
    ]);
    $binding2 = $coordinator->prepare($case2);

    $binding1->update(['notebook_id' => 'nb-case-1', 'chat_configuration_state' => 'ready']);
    $files1 = KnowledgeSourceFile::where('binding_id', $binding1->id)->get();
    $files1[0]->update(['state' => 'ready', 'provider_source_id' => 'src-unique-1']);
    $files1[1]->update(['state' => 'ready', 'provider_source_id' => 'src-unique-2']);
    $coordinator->reconcileBindingState($binding1);
    expect($binding1->fresh()->state)->toBe('bound');

    // 1. Duplicate notebook_id across cases violates DB uniqueness
    expect(function () use ($binding2) {
        $binding2->update(['notebook_id' => 'nb-case-1']);
    })->toThrow(\Illuminate\Database\QueryException::class);

    // 2. Duplicate source ID across cases violates coordinator isolation
    $binding2->update(['notebook_id' => 'nb-case-2', 'chat_configuration_state' => 'ready']);
    $files2 = KnowledgeSourceFile::where('binding_id', $binding2->id)->get();
    $files2[0]->update(['state' => 'ready', 'provider_source_id' => 'src-unique-1']);
    $files2[1]->update(['state' => 'ready', 'provider_source_id' => 'src-unique-4']);

    expect(fn () => $coordinator->reconcileBindingState($binding2))
        ->toThrow(\DomainException::class, 'source_conflict');
});

test('Registered provider ID remains processing until a separate ready confirmation', function () {
    $coordinator = app(DirectSourceCoordinator::class);
    $binding = $coordinator->prepare($this->case);
    $ledger = app(SourceEffectLedger::class);
    $binding->update(['notebook_id' => 'nb-processing']);
    $coordinator->reconcileBindingState($binding);

    $pdf = KnowledgeSourceFile::where('binding_id', $binding->id)->where('role', 'educational_pdf')->first();
    $digest = $coordinator->computeCanonicalRequestDigest($this->case, 'notebook_upload_pdf', $binding, $pdf);
    $claim = $ledger->claimEffect($this->case->id, 'notebook_upload_pdf', $this->activation->id, $digest);
    expect($claim['disposition'])->toBe('send_now');

    $registered = $ledger->recordResult($claim['effect_id'], 'registered', 'src-pdf-processing', $pdf->title, $pdf->artifact_sha256);
    expect($registered['state'])->toBe('registered')
        ->and($pdf->fresh()->state)->toBe('processing')
        ->and($binding->fresh()->state)->toBe('pending_sources');

    $reclaim = $ledger->claimEffect($this->case->id, 'notebook_upload_pdf', $this->activation->id, $digest);
    expect($reclaim['disposition'])->toBe('reconcile')
        ->and($reclaim['provider_ref'])->toBe('src-pdf-processing');

    $ledger->recordResult($claim['effect_id'], 'confirmed', 'src-pdf-processing', $pdf->title, $pdf->artifact_sha256);
    expect($pdf->fresh()->state)->toBe('ready')
        ->and($binding->fresh()->state)->toBe('pending_sources');
});
