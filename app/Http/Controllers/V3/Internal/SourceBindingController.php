<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\SourceActivation;
use Illuminate\Http\Request;

class SourceBindingController extends Controller
{
    public function getByActivation(string $activationId, Request $request)
    {
        $activation = SourceActivation::find($activationId);
        if (! $activation) {
            return response()->json([
                'error' => [
                    'code' => 'source_unavailable',
                    'message' => 'Source is unavailable.',
                ],
            ], 404, ['Cache-Control' => 'private, no-store']);
        }

        $binding = KnowledgeSourceBinding::where('case_id', $activation->case_id)->first();
        if (! $binding) {
            return response()->json([
                'error' => [
                    'code' => 'source_unavailable',
                    'message' => 'Source is unavailable.',
                ],
            ], 404, ['Cache-Control' => 'private, no-store']);
        }

        if ($binding->source_mode === 'original_pdf') {
            $case = $binding->processingCase;
            $coordinator = app(\App\Services\V3\NotebookLM\DirectSourceCoordinator::class);
            $files = \App\Models\V3\KnowledgeSourceFile::where('binding_id', $binding->id)->orderBy('role')->get();

            $nextRequest = null;
            if (! $binding->notebook_id) {
                $digest = $coordinator->computeCanonicalRequestDigest($case, 'notebook_create', $binding, null);
                $nextRequest = [
                    'operation' => 'notebook_create',
                    'kind' => 'create',
                    'ordinal' => 0,
                    'request_digest' => $digest,
                ];
            } else {
                $pdfFile = $files->firstWhere('role', 'educational_pdf');
                $stepsFile = $files->firstWhere('role', 'framework_steps');

                if ($pdfFile && $pdfFile->state !== 'ready') {
                    $digest = $coordinator->computeCanonicalRequestDigest($case, 'notebook_upload_pdf', $binding, $pdfFile);
                    $nextRequest = [
                        'operation' => 'notebook_upload_pdf',
                        'kind' => 'upload',
                        'role' => 'educational_pdf',
                        'title' => $pdfFile->title,
                        'mime_type' => 'application/pdf',
                        'artifact_sha256' => $pdfFile->artifact_sha256,
                        'request_digest' => $digest,
                    ];
                } elseif ($stepsFile && $stepsFile->state !== 'ready') {
                    $digest = $coordinator->computeCanonicalRequestDigest($case, 'notebook_upload_steps', $binding, $stepsFile);
                    $nextRequest = [
                        'operation' => 'notebook_upload_steps',
                        'kind' => 'upload',
                        'role' => 'framework_steps',
                        'title' => $stepsFile->title,
                        'mime_type' => 'text/plain',
                        'artifact_sha256' => $stepsFile->artifact_sha256,
                        'request_digest' => $digest,
                    ];
                } elseif ($binding->chat_configuration_state !== 'ready') {
                    $digest = $coordinator->computeCanonicalRequestDigest($case, 'chat_configure', $binding, null);
                    $nextRequest = [
                        'operation' => 'chat_configure',
                        'kind' => 'configure',
                        'request_digest' => $digest,
                    ];
                }
            }

            return response()->json([
                'data' => [
                    'binding_ref' => $binding->id,
                    'case_id' => $binding->case_id,
                    'protocol' => 'notebooklm_combined_v1',
                    'state' => $binding->state,
                    'source_mode' => 'original_pdf',
                    'notebook_id' => $binding->notebook_id,
                    'notebook_title' => $binding->notebook_title,
                    'chat_configuration_state' => $binding->chat_configuration_state,
                    'framework_version_id' => $binding->framework_version_id,
                    'framework_sha256' => $binding->frameworkVersion?->body_sha256,
                    'manifest' => $files->map(fn ($f) => [
                        'file_ref' => $f->id,
                        'role' => $f->role,
                        'title' => $f->title,
                        'mime_type' => $f->mime_type,
                        'state' => $f->state,
                        'provider_source_id' => $f->provider_source_id,
                        'artifact_sha256' => $f->artifact_sha256,
                        'artifact_bytes' => (int) $f->artifact_bytes,
                    ])->values()->all(),
                    'next_request' => $nextRequest,
                ],
            ], 200, ['Cache-Control' => 'private, no-store']);
        }

        return response()->json([
            'data' => [
                'binding_ref' => $binding->id,
                'case_id' => $binding->case_id,
                'state' => $binding->state,
                'notebook_id' => $binding->notebook_id,
                'source_id' => $binding->source_id,
                'text_sha256' => $binding->text_sha256,
            ],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function getByCaseAndRef(string $caseId, string $bindingRef, Request $request)
    {
        $binding = KnowledgeSourceBinding::where('id', $bindingRef)
            ->where('case_id', $caseId)
            ->first();

        if (! $binding || $binding->state !== 'bound') {
            return response()->json([
                'error' => [
                    'code' => 'source_unavailable',
                    'message' => 'Source is unavailable.',
                ],
            ], 404, ['Cache-Control' => 'private, no-store']);
        }

        if ($binding->source_mode === 'original_pdf') {
            $files = \App\Models\V3\KnowledgeSourceFile::where('binding_id', $binding->id)->orderBy('role')->get();
            return response()->json([
                'data' => [
                    'binding_ref' => $binding->id,
                    'case_id' => $binding->case_id,
                    'protocol' => 'notebooklm_combined_v1',
                    'source_mode' => 'original_pdf',
                    'notebook_id' => $binding->notebook_id,
                    'notebook_title' => $binding->notebook_title,
                    'state' => $binding->state,
                    'chat_configuration_state' => $binding->chat_configuration_state,
                    'framework_version_id' => $binding->framework_version_id,
                    'framework_sha256' => $binding->frameworkVersion?->body_sha256,
                    'manifest' => $files->map(fn ($f) => [
                        'file_ref' => $f->id,
                        'role' => $f->role,
                        'title' => $f->title,
                        'mime_type' => $f->mime_type,
                        'state' => $f->state,
                        'provider_source_id' => $f->provider_source_id,
                        'artifact_sha256' => $f->artifact_sha256,
                        'artifact_bytes' => (int) $f->artifact_bytes,
                    ])->values()->all(),
                ],
            ], 200, ['Cache-Control' => 'private, no-store']);
        }

        return response()->json([
            'data' => [
                'case_id' => $binding->case_id,
                'extracted_source_ref' => $binding->extracted_source_id,
                'notebook_id' => $binding->notebook_id,
                'source_id' => $binding->source_id,
                'state' => $binding->state,
                'text_sha256' => $binding->text_sha256,
            ],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }
}
