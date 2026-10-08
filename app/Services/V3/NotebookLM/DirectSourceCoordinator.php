<?php

namespace App\Services\V3\NotebookLM;

use App\Models\V3\Document;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\KnowledgeSourceFile;
use App\Models\V3\NotebookFrameworkVersion;
use App\Models\V3\ProcessingCase;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DirectSourceCoordinator
{
    /**
     * Prepare the binding shell and two manifest file records for direct protocol case.
     */
    public function prepare(ProcessingCase $case): KnowledgeSourceBinding
    {
        return DB::transaction(function () use ($case) {
            $document = Document::findOrFail($case->document_id);
            $framework = $case->frameworkVersion ?? NotebookFrameworkVersion::findOrFail($case->notebook_framework_version_id);

            $binding = KnowledgeSourceBinding::where('case_id', $case->id)->lockForUpdate()->first();
            if (! $binding) {
                $binding = KnowledgeSourceBinding::forceCreate([
                    'id' => (string) Str::uuid(),
                    'case_id' => $case->id,
                    'extracted_source_id' => null,
                    'source_mode' => 'original_pdf',
                    'adapter_kind' => 'notebooklm_py',
                    'adapter_version' => '0.8.4',
                    'document_id' => $document->id,
                    'framework_version_id' => $framework->id,
                    'response_format_sha256' => $framework->response_format_sha256 ?? FrameworkCatalog::RESPONSE_FORMAT_SHA256,
                    'notebook_title' => "v3-case-{$case->id}",
                    'source_title' => null,
                    'notebook_id' => null,
                    'source_id' => null,
                    'state' => 'pending_notebook',
                    'chat_configuration_state' => 'pending',
                    'text_sha256' => null,
                    'text_bytes' => null,
                    'transfer_status' => 'not_sent',
                ]);
            }

            // Ensure educational_pdf file
            $pdfTitle = "v3-pdf-{$case->id}-" . substr($document->sha256, 0, 16);
            KnowledgeSourceFile::firstOrCreate(
                [
                    'binding_id' => $binding->id,
                    'role' => 'educational_pdf',
                ],
                [
                    'id' => (string) Str::uuid(),
                    'title' => $pdfTitle,
                    'artifact_sha256' => $document->sha256,
                    'artifact_bytes' => $document->byte_size,
                    'mime_type' => 'application/pdf',
                    'provider_source_id' => null,
                    'state' => 'pending',
                ]
            );

            // Ensure framework_steps file
            KnowledgeSourceFile::firstOrCreate(
                [
                    'binding_id' => $binding->id,
                    'role' => 'framework_steps',
                ],
                [
                    'id' => (string) Str::uuid(),
                    'title' => 'steps.txt',
                    'artifact_sha256' => $framework->body_sha256,
                    'artifact_bytes' => $framework->body_bytes,
                    'mime_type' => 'text/plain',
                    'provider_source_id' => null,
                    'state' => 'pending',
                ]
            );

            return $binding;
        });
    }

    /**
     * Compute canonical request digest for direct protocol source effects.
     */
    public function computeCanonicalRequestDigest(ProcessingCase $case, string $operation, ?KnowledgeSourceBinding $binding = null, ?KnowledgeSourceFile $file = null): string
    {
        $notebookTitle = $binding?->notebook_title ?? "v3-case-{$case->id}";
        $notebookId = $binding?->notebook_id;
        $role = $file?->role;
        $title = $file?->title;
        $artifactSha256 = $file?->artifact_sha256;
        $artifactBytes = $file?->artifact_bytes;
        $responseFormatSha256 = ($operation === 'chat_configure') ? ($binding?->response_format_sha256 ?? FrameworkCatalog::RESPONSE_FORMAT_SHA256) : null;

        $payload = [
            'protocol' => 'notebooklm_combined_v1',
            'case_id' => $case->id,
            'operation' => $operation,
            'notebook_title' => ($operation === 'notebook_create') ? $notebookTitle : null,
            'notebook_id' => ($operation !== 'notebook_create') ? $notebookId : null,
            'role' => $role,
            'title' => $title,
            'artifact_sha256' => $artifactSha256,
            'artifact_bytes' => $artifactBytes,
            'response_format_sha256' => $responseFormatSha256,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Reconcile binding state based on notebook and source files readiness.
     */
    public function reconcileBindingState(KnowledgeSourceBinding $binding): KnowledgeSourceBinding
    {
        return DB::transaction(function () use ($binding) {
            $locked = KnowledgeSourceBinding::where('id', $binding->id)->lockForUpdate()->firstOrFail();

            if (! $locked->notebook_id) {
                $locked->update(['state' => 'pending_notebook']);
                return $locked;
            }

            // Check cross-case notebook isolation
            $duplicateNotebook = KnowledgeSourceBinding::where('id', '!=', $locked->id)
                ->where('notebook_id', $locked->notebook_id)
                ->exists();
            if ($duplicateNotebook) {
                throw new DomainException('source_conflict');
            }

            $files = KnowledgeSourceFile::where('binding_id', $locked->id)->lockForUpdate()->get();
            if ($files->count() !== 2) {
                throw new DomainException('source_conflict');
            }

            // Check cross-case source ID isolation
            foreach ($files as $file) {
                if ($file->provider_source_id) {
                    $duplicateSource = KnowledgeSourceFile::where('binding_id', '!=', $locked->id)
                        ->where('provider_source_id', $file->provider_source_id)
                        ->exists();
                    if ($duplicateSource) {
                        throw new DomainException('source_conflict');
                    }
                }
            }

            $pdfFile = $files->firstWhere('role', 'educational_pdf');
            $stepsFile = $files->firstWhere('role', 'framework_steps');

            if (! $pdfFile || ! $stepsFile) {
                throw new DomainException('source_conflict');
            }

            if ($pdfFile->state === 'ready' && $stepsFile->state === 'ready') {
                if ($locked->chat_configuration_state === 'ready') {
                    $locked->update([
                        'state' => 'bound',
                        'transfer_status' => 'confirmed',
                    ]);
                } else {
                    $locked->update(['state' => 'pending_configuration']);
                }
            } else {
                $locked->update(['state' => 'pending_sources']);
            }

            return $locked->fresh();
        });
    }
}
