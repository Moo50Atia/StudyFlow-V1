<?php

namespace App\Services\V3\Authoring;

use App\Models\V3\AuthoringRequest;
use App\Models\V3\CodingAttempt;
use App\Models\V3\SceneCandidate;
use App\Services\V3\Prompts\QualifiedPromptResolver;
use App\Services\V3\Prompts\PromptUnavailableException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecordAuthoringResult
{
    public function __construct(
        protected QualifiedPromptResolver $promptResolver,
        protected SceneArtifactStore $artifactStore
    ) {}

    public function record(string $caseId, string $attemptRef, array $payload, string $rawBytes): array
    {
        $schemaVersion = $payload['schema_version'] ?? null;
        $outcome = $payload['outcome'] ?? null;

        if ($schemaVersion !== 1 || ! in_array($outcome, ['candidate', 'known_failure', 'uncertain'], true)) {
            throw new AuthoringValidationException('Invalid result payload schema.');
        }

        $resultSha256 = hash('sha256', $rawBytes);

        return DB::transaction(function () use ($caseId, $attemptRef, $payload, $outcome, $resultSha256) {
            $attempt = CodingAttempt::where('id', $attemptRef)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();

            if (! $attempt) {
                throw new AuthoringUnavailableException('Attempt not found.');
            }

            $request = AuthoringRequest::where('id', $attempt->request_id)
                ->lockForUpdate()
                ->first();

            if (! $request) {
                throw new AuthoringUnavailableException('Request not found.');
            }

            // Check if attempt already completed (idempotent replay or conflict)
            if ($attempt->state !== 'send_reserved') {
                if ($attempt->result_sha256 === $resultSha256) {
                    return [
                        'status_code' => 200,
                        'data' => [
                            'attempt_ref' => $attempt->id,
                            'state' => $attempt->state,
                            'candidate_ref' => $attempt->reserved_candidate_id,
                            'result_sha256' => $attempt->result_sha256,
                        ],
                    ];
                }

                throw new AuthoringConflictException('Result replay conflict with changed payload.');
            }

            // Recheck 010 prompt current pass
            try {
                $this->promptResolver->resolve($caseId, $request->prompt_version_id);
            } catch (PromptUnavailableException $e) {
                $request->update([
                    'state' => 'stale',
                    'state_version' => $request->state_version + 1,
                ]);
                throw new AuthoringUnavailableException('Prompt version is no longer current pass.');
            }

            $durationMs = isset($payload['duration_ms']) ? (int) $payload['duration_ms'] : 0;
            $returnedModel = $payload['returned_model'] ?? null;
            $providerResponseId = $payload['provider_response_id'] ?? null;
            $failureCode = $payload['failure_code'] ?? null;

            $usage = $payload['usage'] ?? null;
            $inputTokens = $usage['input_tokens'] ?? null;
            $outputTokens = $usage['output_tokens'] ?? null;
            $costMinorUnits = $usage['cost_minor_units'] ?? null;
            $currency = $usage['currency'] ?? null;

            // Handle Candidate Outcome
            if ($outcome === 'candidate') {
                $package = $payload['package'] ?? null;
                $isPkgValid = false;

                if (is_array($package)) {
                    try {
                        $this->artifactStore->validatePackage($package);
                        $isPkgValid = true;
                    } catch (AuthoringValidationException $e) {
                        $isPkgValid = false;
                    }
                }

                if (! $isPkgValid) {
                    // Structurally invalid package -> known_failure/validation with same digest
                    $attempt->update([
                        'state' => 'known_failure',
                        'result_sha256' => $resultSha256,
                        'returned_model' => $returnedModel,
                        'provider_response_id' => $providerResponseId,
                        'failure_code' => 'validation',
                        'duration_ms' => $durationMs,
                        'input_tokens' => $inputTokens,
                        'output_tokens' => $outputTokens,
                        'cost_minor_units' => $costMinorUnits,
                        'currency' => $currency,
                        'completed_at' => now(),
                    ]);

                    if ($attempt->ordinal >= $request->attempt_limit) {
                        $request->update([
                            'state' => 'blocked',
                            'safe_error_code' => 'authoring_provider_unavailable',
                            'state_version' => $request->state_version + 1,
                        ]);
                    }

                    return [
                        'status_code' => 201,
                        'data' => [
                            'attempt_ref' => $attempt->id,
                            'state' => 'known_failure',
                            'candidate_ref' => null,
                            'result_sha256' => $resultSha256,
                        ],
                    ];
                }

                // Valid package: stage and commit private artifacts
                $candidateRef = (string) Str::uuid();
                $attempt->update(['reserved_candidate_id' => $candidateRef]);

                try {
                    $artifactInfo = $this->artifactStore->stageAndCommit(
                        $caseId,
                        $candidateRef,
                        $request,
                        $attempt,
                        $package,
                        $returnedModel
                    );
                } catch (\Throwable $e) {
                    // Storage write failure leaves attempt uncertain
                    $attempt->update([
                        'state' => 'uncertain',
                        'failure_code' => 'internal',
                    ]);
                    $request->update([
                        'state' => 'uncertain',
                        'state_version' => $request->state_version + 1,
                    ]);
                    throw new AuthoringUnavailableException('Artifact storage commit failed: ' . $e->getMessage());
                }

                // Insert SceneCandidate row
                SceneCandidate::forceCreate([
                    'id' => $candidateRef,
                    'request_id' => $request->id,
                    'attempt_id' => $attempt->id,
                    'case_id' => $caseId,
                    'section_id' => $request->section_id,
                    'prompt_version_id' => $request->prompt_version_id,
                    'qualification_id' => $request->qualification_id,
                    'settings_version_id' => $request->settings_version_id,
                    'manifest_path' => $artifactInfo['manifest_path'],
                    'manifest_sha256' => $artifactInfo['manifest_sha256'],
                    'entrypoint' => 'index.html',
                    'file_count' => $artifactInfo['file_count'],
                    'total_bytes' => $artifactInfo['total_bytes'],
                    'collected_at' => now(),
                ]);

                // Update attempt
                $attempt->update([
                    'state' => 'candidate_ready',
                    'result_sha256' => $resultSha256,
                    'returned_model' => $returnedModel,
                    'provider_response_id' => $providerResponseId,
                    'failure_code' => null,
                    'duration_ms' => $durationMs,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'cost_minor_units' => $costMinorUnits,
                    'currency' => $currency,
                    'completed_at' => now(),
                ]);

                // Update request
                $request->update([
                    'state' => 'candidate_ready',
                    'state_version' => $request->state_version + 1,
                ]);

                return [
                    'status_code' => 201,
                    'data' => [
                        'attempt_ref' => $attempt->id,
                        'state' => 'candidate_ready',
                        'candidate_ref' => $candidateRef,
                        'result_sha256' => $resultSha256,
                    ],
                ];
            }

            // Handle Known Failure Outcome
            if ($outcome === 'known_failure') {
                $attempt->update([
                    'state' => 'known_failure',
                    'result_sha256' => $resultSha256,
                    'returned_model' => $returnedModel,
                    'provider_response_id' => $providerResponseId,
                    'failure_code' => $failureCode ?: 'provider_unavailable',
                    'duration_ms' => $durationMs,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'cost_minor_units' => $costMinorUnits,
                    'currency' => $currency,
                    'completed_at' => now(),
                ]);

                if ($attempt->ordinal >= $request->attempt_limit) {
                    $request->update([
                        'state' => 'blocked',
                        'safe_error_code' => 'authoring_provider_unavailable',
                        'state_version' => $request->state_version + 1,
                    ]);
                }

                return [
                    'status_code' => 201,
                    'data' => [
                        'attempt_ref' => $attempt->id,
                        'state' => 'known_failure',
                        'candidate_ref' => null,
                        'result_sha256' => $resultSha256,
                    ],
                ];
            }

            // Handle Uncertain Outcome
            if ($outcome === 'uncertain') {
                $attempt->update([
                    'state' => 'uncertain',
                    'result_sha256' => $resultSha256,
                    'returned_model' => $returnedModel,
                    'provider_response_id' => $providerResponseId,
                    'failure_code' => $failureCode ?: 'timeout',
                    'duration_ms' => $durationMs,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'cost_minor_units' => $costMinorUnits,
                    'currency' => $currency,
                    'completed_at' => now(),
                ]);

                $request->update([
                    'state' => 'uncertain',
                    'state_version' => $request->state_version + 1,
                ]);

                return [
                    'status_code' => 201,
                    'data' => [
                        'attempt_ref' => $attempt->id,
                        'state' => 'uncertain',
                        'candidate_ref' => null,
                        'result_sha256' => $resultSha256,
                    ],
                ];
            }

            throw new AuthoringValidationException('Unhandled outcome type.');
        });
    }
}
