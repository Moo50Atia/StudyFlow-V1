<?php

namespace App\Services\V3\SceneQualification;

use App\Models\V3\ProcessingCase;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckRun;
use App\Models\V3\SceneJevAttempt;
use App\Models\V3\SceneQualification;
use App\Models\V3\SettingsVersion;
use App\Services\V3\Prompts\PromptConflictException;
use App\Services\V3\Prompts\PromptUnavailableException;
use App\Services\V3\Prompts\PromptValidationException;
use App\Services\V3\Prompts\QualifiedPromptResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ClaimSceneJevAttempt
{
    public function __construct(
        protected SceneEvidenceAssembler $evidenceAssembler,
        protected QualifiedPromptResolver $promptResolver,
    ) {}

    public static function computeJevRequestDigest(
        string $candidateRef,
        string $manifestSha256,
        string $reportSha256,
        string $evidenceSha256,
        string $questionSha256,
        string $settingsChecksum,
        string $requestedModel
    ): string {
        $payload = "012-jev-v1\n"
            . $candidateRef . "\n"
            . $manifestSha256 . "\n"
            . $reportSha256 . "\n"
            . $evidenceSha256 . "\n"
            . $questionSha256 . "\n"
            . $settingsChecksum . "\n"
            . $requestedModel;

        return hash('sha256', $payload);
    }

    public function claim(string $caseId, array $data): array
    {
        $candidateRef = $data['candidate_ref'] ?? null;
        $checkRef = $data['check_ref'] ?? null;
        $attemptNo = (int) ($data['attempt_no'] ?? 0);

        if (! $candidateRef || ! $checkRef || $attemptNo < 1) {
            throw new PromptValidationException("Missing or invalid candidate_ref, check_ref, or attempt_no.");
        }

        return DB::transaction(function () use ($caseId, $candidateRef, $checkRef, $attemptNo) {
            /** @var ProcessingCase|null $case */
            $case = ProcessingCase::find($caseId);
            if (! $case) {
                throw new PromptUnavailableException("Case not found.");
            }

            if (! app(\App\Services\V3\Admin\CaseOperationGuard::class)->isPermitted($caseId)) {
                throw new PromptConflictException("Operation conflict: case is held or cancelled.");
            }

            /** @var SceneCandidate|null $candidate */
            $candidate = SceneCandidate::where('id', $candidateRef)
                ->where('case_id', $caseId)
                ->first();
            if (! $candidate) {
                throw new PromptUnavailableException("Candidate not found.");
            }

            /** @var SceneCandidateState|null $state */
            $state = SceneCandidateState::where('candidate_id', $candidateRef)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();
            if (! $state) {
                throw new PromptUnavailableException("Candidate state not found.");
            }

            // Verify prompt is current passing
            try {
                $this->promptResolver->resolve($caseId, $candidate->prompt_version_id);
            } catch (PromptUnavailableException $e) {
                $state->update(['status' => 'stale', 'safe_error_code' => 'validation']);
                throw new PromptUnavailableException("Prompt is not currently qualified.");
            }

            // Verify check run exists and passed
            /** @var SceneCheckRun|null $checkRun */
            $checkRun = SceneCheckRun::where('id', $checkRef)
                ->where('candidate_id', $candidateRef)
                ->where('case_id', $caseId)
                ->first();
            if (! $checkRun || $checkRun->verdict !== 'pass') {
                throw new PromptUnavailableException("No passing check run found for candidate.");
            }

            // Rehash candidate files
            $package = $candidate->package_payload ?? [];
            $files = $package['files'] ?? [];
            foreach ($files as $f) {
                $path = $f['path'] ?? '';
                $content = $f['content'] ?? '';
                if (empty($path) || empty($content)) {
                    throw new PromptUnavailableException("Candidate package files corrupted.");
                }
            }

            /** @var SettingsVersion|null $settings */
            $settings = SettingsVersion::find($case->settings_version_id);
            if (! $settings) {
                throw new PromptUnavailableException("Settings not found.");
            }

            $technicalRetries = (int) ($settings->payload['limits']['technical_retries'] ?? 3);
            if ($attemptNo > ($technicalRetries + 1)) {
                throw new PromptValidationException("Max technical retries exceeded.");
            }

            // Check if this attempt already exists
            /** @var SceneJevAttempt|null $existing */
            $existing = SceneJevAttempt::where('candidate_id', $candidateRef)
                ->where('attempt_no', $attemptNo)
                ->first();

            if ($existing) {
                if ($existing->state === 'claimed') {
                    return [
                        'status_code' => 200,
                        'data' => [
                            'attempt_ref' => $existing->id,
                            'state' => 'claimed',
                            'disposition' => 'reconcile',
                            'request_sha256' => $existing->request_sha256,
                            'qualification_ref' => null,
                        ],
                    ];
                }
                if ($existing->state === 'recorded') {
                    $qual = SceneQualification::where('winning_jev_attempt_id', $existing->id)->first();
                    return [
                        'status_code' => 200,
                        'data' => [
                            'attempt_ref' => $existing->id,
                            'state' => 'recorded',
                            'disposition' => 'recorded',
                            'request_sha256' => $existing->request_sha256,
                            'qualification_ref' => $qual?->id,
                        ],
                    ];
                }
                throw new PromptConflictException("Attempt already in terminal state: {$existing->state}.");
            }

            // Check prior attempt lease expiry
            if ($attemptNo > 1) {
                $prior = SceneJevAttempt::where('candidate_id', $candidateRef)
                    ->where('attempt_no', $attemptNo - 1)
                    ->first();
                if ($prior && $prior->state === 'claimed') {
                    $prior->update([
                        'state' => 'technical_failure',
                        'safe_error_code' => 'timeout',
                        'finished_at' => now(),
                    ]);
                }
            }

            // Assemble evidence
            $reportJson = Storage::disk('v3_private')->get($checkRun->report_path);
            $checkReport = json_decode($reportJson, true) ?? [];
            $evidenceArray = $this->evidenceAssembler->assemble($caseId, $candidateRef, $checkReport);
            $evidenceBytes = json_encode($evidenceArray, JSON_UNESCAPED_SLASHES);
            $evidenceSha = hash('sha256', $evidenceBytes);

            if (strlen($evidenceBytes) > 6291456) {
                throw new PromptUnavailableException("Evidence exceeds 6 MiB limit.");
            }

            // Store evidence on private disk
            $evidencePath = "scene-jev/{$caseId}/{$candidateRef}/{$evidenceSha}.json";
            Storage::disk('v3_private')->put($evidencePath, $evidenceBytes);

            // Read exact question bytes
            $questionAssetPath = resource_path('v3/jev/scene-questions-v1.json');
            if (! file_exists($questionAssetPath)) {
                throw new PromptUnavailableException("Scene questions asset missing.");
            }
            $questionBytes = file_get_contents($questionAssetPath);
            $questionSha = hash('sha256', $questionBytes);

            // Scene JEV is a separate evaluator integration. Never derive its
            // identity from the prompt-generation provider captured in settings.
            // On retries, reuse the captured model from the initial reservation immutably.
            $requestedModel = '';
            if ($attemptNo > 1) {
                $initialAttempt = SceneJevAttempt::where('candidate_id', $candidateRef)
                    ->where('attempt_no', 1)
                    ->first();
                if ($initialAttempt && ! empty($initialAttempt->requested_model)) {
                    $requestedModel = $initialAttempt->requested_model;
                }
            }
            if ($requestedModel === '') {
                $requestedModel = trim((string) config('services.v3.jev_model', ''));
            }

            if ($requestedModel === '') {
                throw new PromptUnavailableException('Dedicated JEV model is not configured.');
            }

            $requestSha = self::computeJevRequestDigest(
                $candidateRef,
                $candidate->manifest_sha256,
                $checkRun->report_sha256,
                $evidenceSha,
                $questionSha,
                $settings->checksum,
                $requestedModel
            );

            $attempt = SceneJevAttempt::forceCreate([
                'id' => (string) Str::uuid(),
                'candidate_id' => $candidateRef,
                'check_run_id' => $checkRun->id,
                'case_id' => $caseId,
                'attempt_no' => $attemptNo,
                'request_sha256' => $requestSha,
                'question_sha256' => $questionSha,
                'evidence_sha256' => $evidenceSha,
                'evidence_path' => $evidencePath,
                'requested_model' => $requestedModel,
                'state' => 'claimed',
                'sent_at' => now(),
                'lease_expires_at' => now()->addSeconds(90),
            ]);

            return [
                'status_code' => 201,
                'data' => [
                    'attempt_ref' => $attempt->id,
                    'state' => 'claimed',
                    'disposition' => 'send_now',
                    'request_sha256' => $requestSha,
                    'qualification_ref' => null,
                ],
            ];
        });
    }
}
