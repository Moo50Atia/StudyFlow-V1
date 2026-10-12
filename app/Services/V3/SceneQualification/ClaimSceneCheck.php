<?php

namespace App\Services\V3\SceneQualification;

use App\Models\V3\ProcessingCase;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckAttempt;
use App\Models\V3\SceneCheckRun;
use App\Services\V3\Authoring\SceneCandidateResolver;
use App\Services\V3\Prompts\QualifiedPromptResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class ClaimSceneCheck
{
    public function __construct(
        protected SceneCandidateResolver $candidateResolver,
        protected QualifiedPromptResolver $promptResolver,
        protected ?EligiblePromptResolver $eligibleResolver = null
    ) {
        $this->eligibleResolver = $eligibleResolver ?? app(EligiblePromptResolver::class);
    }

    public function claim(string $caseId, array $payload): array
    {
        $candidateRef = $payload['candidate_ref'] ?? null;
        $attemptNo = (int) ($payload['attempt_no'] ?? 1);

        if (!$candidateRef || $attemptNo < 1) {
            throw new \InvalidArgumentException("Invalid candidate_ref or attempt_no");
        }

        $case = ProcessingCase::find($caseId);
        if (!$case) {
            throw new NotFoundHttpException("Case not found");
        }

        $candidate = SceneCandidate::where('id', $candidateRef)
            ->where('case_id', $caseId)
            ->first();

        if (!$candidate) {
            throw new NotFoundHttpException("Candidate not found");
        }

        // Verify candidate files rehash and prompt remains current passing
        try {
            $resolvedCandidate = $this->candidateResolver->resolve($caseId, $candidateRef);
        } catch (\Throwable $e) {
            if ($e instanceof NotFoundHttpException) {
                throw $e;
            }
            throw new ServiceUnavailableHttpException(null, "Candidate verification failed: " . $e->getMessage());
        }

        if ($candidate->origin !== 'manual_import') {
            $resolver = ($case->isWebPipelineCase() || $candidate->prompt_gate_id !== null)
                ? $this->eligibleResolver
                : $this->promptResolver;

            try {
                $resolver->resolve($caseId, $candidate->prompt_version_id);
            } catch (\Throwable $e) {
                throw new NotFoundHttpException("Prompt is not currently qualified or valid: " . $e->getMessage());
            }
        }

        return DB::transaction(function () use ($case, $candidate, $attemptNo) {
            if (! app(\App\Services\V3\Admin\CaseOperationGuard::class)->isPermitted($case->id)) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException("Operation conflict: case is held or cancelled.");
            }

            // Find or create candidate state row
            $candidateState = SceneCandidateState::where('candidate_id', $candidate->id)->lockForUpdate()->first();
            if (!$candidateState) {
                $candidateState = SceneCandidateState::create([
                    'candidate_id' => $candidate->id,
                    'root_candidate_id' => $candidate->id,
                    'case_id' => $case->id,
                    'section_id' => $candidate->section_id,
                    'prompt_version_id' => $candidate->prompt_version_id,
                    'version_no' => 1,
                    'status' => 'checking',
                    'state_version' => 1,
                ]);
            }

            $checkerVersion = 'chromium-pw-1.63.0-v1';
            $settingsChecksum = $case->settingsVersion->checksum;
            $requestSha = hash('sha256', "012-check-v1\n{$candidate->id}\n{$candidate->manifest_sha256}\n{$checkerVersion}\n{$settingsChecksum}");

            $existingAttempt = SceneCheckAttempt::where('candidate_id', $candidate->id)
                ->where('attempt_no', $attemptNo)
                ->lockForUpdate()
                ->first();

            if ($existingAttempt) {
                $existingRun = SceneCheckRun::where('winning_attempt_id', $existingAttempt->id)->first();
                $disposition = ($existingAttempt->state === 'recorded' && $existingRun) ? 'recorded' : 'reconcile';
                return [
                    'status_code' => 200,
                    'data' => [
                        'attempt_ref' => $existingAttempt->id,
                        'candidate_ref' => $candidate->id,
                        'state' => $existingAttempt->state,
                        'disposition' => $disposition,
                        'request_sha256' => $existingAttempt->request_sha256,
                        'check_ref' => $existingRun?->id,
                        'verdict' => $existingRun?->verdict,
                    ]
                ];
            }

            $attemptId = (string) Str::uuid();
            $attempt = SceneCheckAttempt::create([
                'id' => $attemptId,
                'candidate_id' => $candidate->id,
                'case_id' => $case->id,
                'attempt_no' => $attemptNo,
                'manifest_sha256' => $candidate->manifest_sha256,
                'request_sha256' => $requestSha,
                'checker_version' => $checkerVersion,
                'state' => 'claimed',
                'started_at' => now(),
                'lease_expires_at' => now()->addSeconds(90),
            ]);

            $candidateState->update([
                'status' => 'checking',
                'state_version' => $candidateState->state_version + 1,
            ]);

            return [
                'status_code' => 201,
                'data' => [
                    'attempt_ref' => $attempt->id,
                    'candidate_ref' => $candidate->id,
                    'state' => 'claimed',
                    'disposition' => 'send_now',
                    'request_sha256' => $requestSha,
                    'check_ref' => null,
                ]
            ];
        });
    }
}
