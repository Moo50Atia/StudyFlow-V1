<?php

namespace App\Services\V3\Authoring;

use App\Models\V3\AuthoringRequest;
use App\Models\V3\CodingAttempt;
use App\Models\V3\ProcessingCase;
use App\Services\V3\Prompts\QualifiedPromptResolver;
use App\Services\V3\Prompts\PromptUnavailableException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClaimCodingAttempt
{
    public function __construct(
        protected QualifiedPromptResolver $promptResolver
    ) {}

    /**
     * Reserve an ordinal attempt for an authoring request under lock.
     *
     * @param string $caseId
     * @param string $requestRef
     * @param array $payload
     * @return array
     */
    public function claim(string $caseId, string $requestRef, array $payload): array
    {
        $schemaVersion = $payload['schema_version'] ?? null;
        $ordinal = $payload['ordinal'] ?? null;

        if ($schemaVersion !== 1 || ! is_int($ordinal) || $ordinal < 1) {
            throw new AuthoringValidationException('Invalid attempt reservation payload.');
        }

        // Strict unknown keys
        $allowedKeys = ['schema_version', 'ordinal'];
        if (count(array_diff(array_keys($payload), $allowedKeys)) > 0) {
            throw new AuthoringValidationException('Unknown keys in attempt reservation payload.');
        }

        return DB::transaction(function () use ($caseId, $requestRef, $ordinal) {
            // Lock case and request row
            $case = ProcessingCase::where('id', $caseId)->lockForUpdate()->first();
            if (! $case) {
                throw new AuthoringUnavailableException('Case not found.');
            }

            if (! app(\App\Services\V3\Admin\CaseOperationGuard::class)->isPermitted($caseId)) {
                throw new AuthoringConflictException('Operation conflict: case is held or cancelled.');
            }

            $request = AuthoringRequest::where('id', $requestRef)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();

            if (! $request) {
                throw new AuthoringUnavailableException('Authoring request not found.');
            }

            // Recheck 010 prompt qualification current pass
            try {
                $this->promptResolver->resolve($caseId, $request->prompt_version_id);
            } catch (PromptUnavailableException $e) {
                if (! in_array($request->state, ['stale', 'candidate_ready'], true)) {
                    $request->update([
                        'state' => 'stale',
                        'state_version' => $request->state_version + 1,
                    ]);
                }
                throw new AuthoringUnavailableException('Prompt version is no longer qualified or current pass.');
            }

            // Check request state
            if ($request->state === 'stale') {
                throw new AuthoringUnavailableException('Authoring request is stale.');
            }

            if (in_array($request->state, ['candidate_ready', 'blocked', 'uncertain'], true)) {
                throw new AuthoringConflictException("Cannot reserve attempt for request in {$request->state} state.");
            }

            // Check if attempt with this ordinal already exists (idempotent replay)
            $existingAttempt = CodingAttempt::where('request_id', $request->id)
                ->where('ordinal', $ordinal)
                ->lockForUpdate()
                ->first();

            if ($existingAttempt) {
                return [
                    'status_code' => 200,
                    'data' => [
                        'attempt_ref' => $existingAttempt->id,
                        'ordinal' => $existingAttempt->ordinal,
                        'provider' => [
                            'id' => $existingAttempt->provider_id,
                            'model' => $existingAttempt->requested_model,
                            'credential_ref' => $existingAttempt->credential_ref,
                        ],
                        'state' => $existingAttempt->state,
                    ],
                ];
            }

            // Budget check: ordinal cannot exceed attempt_limit
            if ($ordinal > $request->attempt_limit) {
                throw new AuthoringConflictException("Attempt ordinal {$ordinal} exceeds attempt limit {$request->attempt_limit}.");
            }

            // Check active attempts: cannot have any send_reserved or uncertain attempt
            $activeAttempt = CodingAttempt::where('request_id', $request->id)
                ->whereIn('state', ['send_reserved', 'uncertain'])
                ->first();

            if ($activeAttempt) {
                throw new AuthoringConflictException('Another attempt is currently active or uncertain.');
            }

            // Ordinal cannot skip: expected next ordinal is count of existing attempts + 1
            $existingCount = CodingAttempt::where('request_id', $request->id)->count();
            $expectedOrdinal = $existingCount + 1;
            if ($ordinal !== $expectedOrdinal) {
                throw new AuthoringConflictException("Cannot skip ordinals. Expected ordinal {$expectedOrdinal}, got {$ordinal}.");
            }

            // If ordinal > 1, prior attempt must be known_failure
            if ($ordinal > 1) {
                $priorAttempt = CodingAttempt::where('request_id', $request->id)
                    ->where('ordinal', $ordinal - 1)
                    ->first();

                if (! $priorAttempt || $priorAttempt->state !== 'known_failure') {
                    throw new AuthoringConflictException('Next ordinal can only be reserved after prior attempt known failure.');
                }
            }

            // Retrieve provider descriptor from captured settings version
            $settingsVersion = $request->settingsVersion;
            if (! $settingsVersion) {
                throw new AuthoringUnavailableException('Settings version not found.');
            }

            $settingsPayload = is_string($settingsVersion->payload)
                ? json_decode($settingsVersion->payload, true)
                : $settingsVersion->payload;

            $codingOrder = $settingsPayload['providers']['coding_order'] ?? [];
            $descriptor = $codingOrder[$ordinal - 1] ?? null;

            if (! $descriptor) {
                throw new AuthoringUnavailableException("Provider descriptor for ordinal {$ordinal} not found in captured settings.");
            }

            // Verify unique provider_id across attempts for this request
            $usedProvider = CodingAttempt::where('request_id', $request->id)
                ->where('provider_id', $descriptor['id'])
                ->exists();

            if ($usedProvider) {
                throw new AuthoringConflictException("Provider {$descriptor['id']} has already been used for this request.");
            }

            // Create coding attempt
            $attempt = CodingAttempt::forceCreate([
                'id' => (string) Str::uuid(),
                'request_id' => $request->id,
                'case_id' => $caseId,
                'ordinal' => $ordinal,
                'provider_id' => $descriptor['id'],
                'requested_model' => $descriptor['model'],
                'credential_ref' => $descriptor['credential_ref'],
                'state' => 'send_reserved',
                'send_reserved_at' => now(),
            ]);

            // If request was pending, transition to authoring
            if ($request->state === 'pending') {
                $request->update([
                    'state' => 'authoring',
                    'state_version' => $request->state_version + 1,
                ]);
            }

            return [
                'status_code' => 201,
                'data' => [
                    'attempt_ref' => $attempt->id,
                    'ordinal' => $attempt->ordinal,
                    'provider' => [
                        'id' => $attempt->provider_id,
                        'model' => $attempt->requested_model,
                        'credential_ref' => $attempt->credential_ref,
                    ],
                    'state' => 'send_reserved',
                ],
            ];
        });
    }

    /**
     * Read back an attempt without raw content.
     *
     * @param string $caseId
     * @param string $attemptRef
     * @return array
     */
    public function getAttempt(string $caseId, string $attemptRef): array
    {
        $attempt = CodingAttempt::where('id', $attemptRef)
            ->where('case_id', $caseId)
            ->first();

        if (! $attempt) {
            throw new AuthoringUnavailableException('Coding attempt not found.');
        }

        return [
            'data' => [
                'attempt_ref' => $attempt->id,
                'request_ref' => $attempt->request_id,
                'ordinal' => $attempt->ordinal,
                'state' => $attempt->state,
                'candidate_ref' => $attempt->reserved_candidate_id,
                'result_sha256' => $attempt->result_sha256,
            ],
        ];
    }
}
