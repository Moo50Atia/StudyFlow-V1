<?php

namespace App\Services\V3\Source;

use App\Models\V3\ExtractedSource;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SourceActivation;
use App\Models\V3\SourceEffect;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SourceEffectLedger
{
    /**
     * Claim an external provider effect under case lock.
     *
     * @return array{effect_id: string, disposition: string, provider_ref: ?string}
     * @throws \DomainException on conflict
     * @throws \InvalidArgumentException on invalid input
     */
    public function claimEffect(string $caseId, string $operation, string $activationId, string $requestDigest): array
    {
        $validOps = ['notebook_create', 'notebook_add_text', 'notebook_upload_pdf', 'notebook_upload_steps', 'chat_configure'];
        if (! in_array($operation, $validOps, true)) {
            throw new \InvalidArgumentException("Invalid effect operation: {$operation}");
        }

        return DB::transaction(function () use ($caseId, $operation, $activationId, $requestDigest) {
            // Lock case
            $case = ProcessingCase::where('id', $caseId)->lockForUpdate()->first();
            if (! $case) {
                throw new \DomainException('source_unavailable');
            }

            if (! app(\App\Services\V3\Admin\CaseOperationGuard::class)->isPermitted($caseId)) {
                throw new \DomainException('source_conflict');
            }

            $activation = SourceActivation::where('id', $activationId)
                ->where('case_id', $caseId)
                ->first();

            if (! $activation || ! in_array($activation->state, ['signaling', 'uncertain', 'accepted'], true)) {
                throw new \DomainException('source_unavailable');
            }

            $isDirect = ($case->pipeline_version === 'notebooklm_combined_v1');

            if ($isDirect) {
                if (! in_array($operation, ['notebook_create', 'notebook_upload_pdf', 'notebook_upload_steps', 'chat_configure'], true)) {
                    throw new \DomainException('source_conflict');
                }

                $coordinator = app(\App\Services\V3\NotebookLM\DirectSourceCoordinator::class);
                $binding = KnowledgeSourceBinding::where('case_id', $caseId)->lockForUpdate()->first();
                if (! $binding) {
                    $binding = $coordinator->prepare($case);
                }

                $targetFile = null;
                if ($operation === 'notebook_upload_pdf') {
                    $targetFile = \App\Models\V3\KnowledgeSourceFile::where('binding_id', $binding->id)->where('role', 'educational_pdf')->first();
                } elseif ($operation === 'notebook_upload_steps') {
                    $targetFile = \App\Models\V3\KnowledgeSourceFile::where('binding_id', $binding->id)->where('role', 'framework_steps')->first();
                }

                $canonicalDigest = $coordinator->computeCanonicalRequestDigest($case, $operation, $binding, $targetFile);
                if ($canonicalDigest !== $requestDigest) {
                    throw new \DomainException('source_conflict');
                }
            } else {
                $extractedSource = ExtractedSource::where('case_id', $caseId)->first();
                if (! $extractedSource || $extractedSource->status !== 'ready') {
                    throw new \DomainException('source_conflict');
                }

                // Ensure binding shell exists
                $binding = KnowledgeSourceBinding::where('case_id', $caseId)->lockForUpdate()->first();
                if (! $binding) {
                    $notebookTitle = "v3-case-{$caseId}";
                    $sourceTitle = "v3-text-{$caseId}-" . substr($extractedSource->text_sha256, 0, 16);

                    $binding = KnowledgeSourceBinding::forceCreate([
                        'id' => (string) Str::uuid(),
                        'case_id' => $caseId,
                        'extracted_source_id' => $extractedSource->id,
                        'adapter_kind' => 'notebooklm_mcp',
                        'adapter_version' => '0.3.1',
                        'notebook_title' => $notebookTitle,
                        'source_title' => $sourceTitle,
                        'notebook_id' => null,
                        'source_id' => null,
                        'state' => 'pending_notebook',
                        'text_sha256' => $extractedSource->text_sha256,
                        'text_bytes' => $extractedSource->text_bytes,
                        'transfer_status' => 'not_sent',
                    ]);
                }

                // Verify canonical request digest
                $canonicalDigest = null;
                if ($operation === 'notebook_create') {
                    $canonicalDigest = hash('sha256', "notebook_create\n{$caseId}\n{$binding->notebook_title}");
                } elseif ($operation === 'notebook_add_text') {
                    if (! $binding->notebook_id) {
                        throw new \DomainException('source_conflict');
                    }
                    $canonicalDigest = hash('sha256', "notebook_add_text\n{$caseId}\n{$binding->notebook_id}\n{$binding->source_title}\n{$binding->text_sha256}");
                }

                if ($canonicalDigest !== $requestDigest) {
                    throw new \DomainException('source_conflict');
                }
            }

            // Look up effect
            $effect = SourceEffect::where('case_id', $caseId)
                ->where('operation', $operation)
                ->lockForUpdate()
                ->first();

            if (! $effect) {
                // First claim -> send_now
                $effect = SourceEffect::forceCreate([
                    'id' => (string) Str::uuid(),
                    'case_id' => $caseId,
                    'operation' => $operation,
                    'request_digest' => $requestDigest,
                    'state' => 'sending',
                    'send_count' => 1,
                    'provider_ref' => null,
                    'started_at' => now(),
                    'last_checked_at' => now(),
                ]);

                if ($operation === 'notebook_add_text') {
                    $binding->update([
                        'state' => 'add_uncertain',
                        'transfer_status' => 'uncertain',
                        'send_attempted_at' => now(),
                    ]);
                }

                return [
                    'effect_id' => $effect->id,
                    'disposition' => 'send_now',
                    'provider_ref' => null,
                ];
            }

            // Effect already exists
            if ($effect->state === 'confirmed') {
                return [
                    'effect_id' => $effect->id,
                    'disposition' => 'confirmed',
                    'provider_ref' => $effect->provider_ref,
                ];
            }

            if ($effect->state === 'blocked') {
                throw new \DomainException('source_conflict');
            }

            // A known provider ID may be inspected, but the mutation cannot be sent again.
            return [
                'effect_id' => $effect->id,
                'disposition' => 'reconcile',
                'provider_ref' => $effect->provider_ref,
            ];
        });
    }

    /**
     * Record the result of an external effect.
     *
     * @return array{state: string, provider_ref: ?string}
     * @throws \DomainException on conflict or not found
     */
    public function recordResult(
        string $effectId,
        string $state,
        ?string $providerRef = null,
        ?string $proofTitle = null,
        ?string $proofSha256 = null,
        ?string $errorCode = null
    ): array {
        return DB::transaction(function () use ($effectId, $state, $providerRef, $proofTitle, $proofSha256, $errorCode) {
            $effect = SourceEffect::where('id', $effectId)->lockForUpdate()->first();
            if (! $effect) {
                throw new \DomainException('source_unavailable');
            }

            $binding = KnowledgeSourceBinding::where('case_id', $effect->case_id)->lockForUpdate()->first();
            if (! $binding) {
                throw new \DomainException('source_conflict');
            }

            // If already confirmed: exact replay is 200, changed state is conflict
            if ($effect->state === 'confirmed') {
                if ($state === 'confirmed' && $effect->provider_ref === $providerRef) {
                    return [
                        'state' => 'confirmed',
                        'provider_ref' => $effect->provider_ref,
                    ];
                }
                throw new \DomainException('source_conflict');
            }
            if ($effect->state === 'blocked') {
                throw new \DomainException('source_conflict');
            }

            if ($binding->source_mode === 'original_pdf') {
                $coordinator = app(\App\Services\V3\NotebookLM\DirectSourceCoordinator::class);
                if ($state === 'registered' && in_array($effect->operation, ['notebook_upload_pdf', 'notebook_upload_steps'], true)) {
                    if (! in_array($effect->state, ['sending', 'uncertain', 'registered'], true)) {
                        throw new \DomainException('source_conflict');
                    }
                    if (! $providerRef || ($effect->provider_ref && $effect->provider_ref !== $providerRef)) {
                        throw new \DomainException('source_conflict');
                    }
                    $role = $effect->operation === 'notebook_upload_pdf' ? 'educational_pdf' : 'framework_steps';
                    $file = \App\Models\V3\KnowledgeSourceFile::where('binding_id', $binding->id)
                        ->where('role', $role)->lockForUpdate()->first();
                    if (! $file || ($file->provider_source_id && $file->provider_source_id !== $providerRef)) {
                        throw new \DomainException('source_conflict');
                    }
                    if ($proofTitle !== $file->title || $proofSha256 !== $file->artifact_sha256) {
                        throw new \DomainException('source_conflict');
                    }
                    $file->update(['provider_source_id' => $providerRef, 'state' => 'processing']);
                    $effect->update(['provider_ref' => $providerRef, 'state' => 'registered', 'last_checked_at' => now()]);
                    $coordinator->reconcileBindingState($binding);
                    return ['state' => 'registered', 'provider_ref' => $providerRef];
                }
                if ($state === 'uncertain') {
                    $effect->update(['state' => 'uncertain', 'last_checked_at' => now()]);
                    return ['state' => 'uncertain', 'provider_ref' => $effect->provider_ref];
                }
                if ($state === 'blocked') {
                    $effect->update(['state' => 'blocked', 'error_code' => $errorCode ?: 'validation', 'last_checked_at' => now()]);
                    $binding->update(['state' => 'blocked', 'error_code' => $errorCode ?: 'validation']);
                    return ['state' => 'blocked', 'provider_ref' => null];
                }
                if ($state === 'confirmed') {
                    if (! $providerRef) {
                        throw new \DomainException('source_conflict');
                    }
                    if ($effect->provider_ref && $effect->provider_ref !== $providerRef) {
                        throw new \DomainException('source_conflict');
                    }
                    if ($effect->operation === 'notebook_create') {
                        $effect->update([
                            'state' => 'confirmed',
                            'provider_ref' => $providerRef,
                            'confirmed_at' => now(),
                            'last_checked_at' => now(),
                        ]);
                        $binding->update(['notebook_id' => $providerRef]);
                        $coordinator->reconcileBindingState($binding);
                    } elseif ($effect->operation === 'notebook_upload_pdf') {
                        $file = \App\Models\V3\KnowledgeSourceFile::where('binding_id', $binding->id)
                            ->where('role', 'educational_pdf')
                            ->first();
                        if (! $file || $proofTitle !== $file->title || $proofSha256 !== $file->artifact_sha256) {
                            throw new \DomainException('source_conflict');
                        }
                        $file?->update([
                            'state' => 'ready',
                            'provider_source_id' => $providerRef,
                            'ready_at' => now(),
                        ]);
                        $effect->update([
                            'state' => 'confirmed',
                            'provider_ref' => $providerRef,
                            'confirmed_at' => now(),
                            'last_checked_at' => now(),
                        ]);
                        $coordinator->reconcileBindingState($binding);
                    } elseif ($effect->operation === 'notebook_upload_steps') {
                        $file = \App\Models\V3\KnowledgeSourceFile::where('binding_id', $binding->id)
                            ->where('role', 'framework_steps')
                            ->first();
                        if (! $file || $proofTitle !== $file->title || $proofSha256 !== $file->artifact_sha256) {
                            throw new \DomainException('source_conflict');
                        }
                        $file?->update([
                            'state' => 'ready',
                            'provider_source_id' => $providerRef,
                            'ready_at' => now(),
                        ]);
                        $effect->update([
                            'state' => 'confirmed',
                            'provider_ref' => $providerRef,
                            'confirmed_at' => now(),
                            'last_checked_at' => now(),
                        ]);
                        $coordinator->reconcileBindingState($binding);
                    } elseif ($effect->operation === 'chat_configure') {
                        $effect->update([
                            'state' => 'confirmed',
                            'provider_ref' => $providerRef,
                            'confirmed_at' => now(),
                            'last_checked_at' => now(),
                        ]);
                        $binding->update(['chat_configuration_state' => 'ready']);
                        $coordinator->reconcileBindingState($binding);
                    }
                    return ['state' => 'confirmed', 'provider_ref' => $providerRef];
                }
                throw new \DomainException('source_conflict');
            }

            if ($state === 'uncertain') {
                $effect->update([
                    'state' => 'uncertain',
                    'last_checked_at' => now(),
                ]);

                if ($effect->operation === 'notebook_create') {
                    $binding->update(['state' => 'notebook_uncertain']);
                } elseif ($effect->operation === 'notebook_add_text') {
                    $binding->update(['state' => 'add_uncertain', 'transfer_status' => 'uncertain']);
                }

                return [
                    'state' => 'uncertain',
                    'provider_ref' => null,
                ];
            }

            if ($state === 'blocked') {
                $effect->update([
                    'state' => 'blocked',
                    'error_code' => $errorCode ?: 'validation',
                    'last_checked_at' => now(),
                ]);

                $binding->update([
                    'state' => 'blocked',
                    'error_code' => $errorCode ?: 'validation',
                ]);

                return [
                    'state' => 'blocked',
                    'provider_ref' => null,
                ];
            }

            if ($state === 'confirmed') {
                if (! $providerRef) {
                    throw new \DomainException('source_conflict');
                }

                if ($effect->operation === 'notebook_create') {
                    if ($proofTitle !== $binding->notebook_title) {
                        throw new \DomainException('source_conflict');
                    }

                    $effect->update([
                        'state' => 'confirmed',
                        'provider_ref' => $providerRef,
                        'confirmed_at' => now(),
                        'last_checked_at' => now(),
                    ]);

                    $binding->update([
                        'notebook_id' => $providerRef,
                        'state' => 'notebook_ready',
                    ]);
                } elseif ($effect->operation === 'notebook_add_text') {
                    if ($proofTitle !== $binding->source_title || $proofSha256 !== $binding->text_sha256) {
                        throw new \DomainException('source_conflict');
                    }

                    $effect->update([
                        'state' => 'confirmed',
                        'provider_ref' => $providerRef,
                        'confirmed_at' => now(),
                        'last_checked_at' => now(),
                    ]);

                    $binding->update([
                        'source_id' => $providerRef,
                        'state' => 'bound',
                        'transfer_status' => 'confirmed',
                        'confirmed_at' => now(),
                    ]);
                }

                return [
                    'state' => 'confirmed',
                    'provider_ref' => $providerRef,
                ];
            }

            throw new \DomainException('source_conflict');
        });
    }
}
