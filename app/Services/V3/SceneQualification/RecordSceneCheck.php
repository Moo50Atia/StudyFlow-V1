<?php

namespace App\Services\V3\SceneQualification;

use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckAttempt;
use App\Models\V3\SceneCheckRun;
use App\Services\V3\Authoring\SceneCandidateResolver;
use App\Services\V3\Prompts\QualifiedPromptResolver;
use App\Services\V3\Review\EnsureReviewReady;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class RecordSceneCheck
{
    public const REQUIRED_CHECKS = [
        'integrity',
        'entrypoint',
        'assets',
        'runtime',
        'network',
        'interaction',
        'accessibility',
    ];

    public const MAX_RAW_BYTES = 3145728;      // 3 MiB
    public const MAX_REPORT_BYTES = 262144;    // 256 KiB
    public const MAX_SCREENSHOT_BYTES = 2097152; // 2 MiB
    public const MAX_DURATION_MS = 60000;      // 60 seconds

    protected RecordQualificationGate $qualificationGateService;

    public function __construct(
        protected SceneCandidateResolver $candidateResolver,
        protected QualifiedPromptResolver $promptResolver,
        ?RecordQualificationGate $qualificationGateService = null
    ) {
        $this->qualificationGateService = $qualificationGateService ?? app(RecordQualificationGate::class);
    }

    public function record(string $caseId, string $attemptRef, array $payload, string $rawBytes): array
    {
        if (strlen($rawBytes) > self::MAX_RAW_BYTES) {
            throw new UnprocessableEntityHttpException("Request payload exceeds limit of 3 MiB");
        }

        $reportJson = $payload['report_json'] ?? '';
        $reportSha = $payload['report_sha256'] ?? '';
        $screenshotB64 = $payload['screenshot_png_base64'] ?? '';
        $screenshotSha = $payload['screenshot_sha256'] ?? '';

        if (!$reportJson || !$reportSha || !$screenshotSha || !$screenshotB64) {
            throw new UnprocessableEntityHttpException("Missing required check result fields");
        }

        if (strlen($reportJson) > self::MAX_REPORT_BYTES) {
            throw new UnprocessableEntityHttpException("Report JSON exceeds limit of 262144 bytes");
        }

        if (hash('sha256', $reportJson) !== $reportSha) {
            throw new UnprocessableEntityHttpException("report_sha256 mismatch");
        }

        $screenshotBytes = base64_decode($screenshotB64, true);
        if ($screenshotBytes === false) {
            throw new UnprocessableEntityHttpException("Invalid base64 screenshot encoding");
        }

        if (strlen($screenshotBytes) > self::MAX_SCREENSHOT_BYTES) {
            throw new UnprocessableEntityHttpException("Screenshot exceeds limit of 2097152 bytes");
        }

        if (hash('sha256', $screenshotBytes) !== $screenshotSha) {
            throw new UnprocessableEntityHttpException("screenshot_sha256 mismatch");
        }

        // Validate decoded PNG signature and dimensions (1280x720 at 1x)
        if (!str_starts_with($screenshotBytes, "\x89PNG\r\n\x1a\n")) {
            throw new UnprocessableEntityHttpException("Screenshot is not a valid PNG image");
        }

        $imgInfo = @getimagesizefromstring($screenshotBytes);
        if ($imgInfo === false || ($imgInfo[2] ?? 0) !== IMAGETYPE_PNG) {
            throw new UnprocessableEntityHttpException("Screenshot is not a valid PNG image");
        }

        if (($imgInfo[0] ?? 0) !== 1280 || ($imgInfo[1] ?? 0) !== 720) {
            throw new UnprocessableEntityHttpException(
                "Screenshot dimensions must be 1280x720, got {$imgInfo[0]}x{$imgInfo[1]}"
            );
        }

        $report = json_decode($reportJson, true);
        if (!is_array($report) || ($report['schema_version'] ?? 0) !== 1) {
            throw new UnprocessableEntityHttpException("Invalid report schema");
        }

        $attempt = SceneCheckAttempt::where('id', $attemptRef)
            ->where('case_id', $caseId)
            ->first();

        if (!$attempt) {
            throw new NotFoundHttpException("Attempt not found");
        }

        $candidate = SceneCandidate::where('id', $attempt->candidate_id)->first();
        if (!$candidate) {
            throw new NotFoundHttpException("Candidate not found");
        }

        $resultSha256 = hash('sha256', $rawBytes);

        if ($attempt->state === 'recorded') {
            if ($attempt->result_sha256 === $resultSha256) {
                $existingRun = SceneCheckRun::where('winning_attempt_id', $attempt->id)->first();
                return [
                    'status_code' => 200,
                    'data' => [
                        'attempt_ref' => $attempt->id,
                        'check_ref' => $existingRun ? $existingRun->id : null,
                        'state' => $existingRun ? $existingRun->verdict : 'fail',
                        'report_sha256' => $reportSha,
                    ]
                ];
            }
            throw new ConflictHttpException("Scene check result conflict");
        }

        if (!in_array($attempt->state, ['send_reserved', 'pending', 'claimed'], true)) {
            throw new ConflictHttpException("Attempt is not in an active claim state");
        }

        // Enforce 012 report identity
        if (($report['candidate_ref'] ?? '') !== $candidate->id) {
            throw new UnprocessableEntityHttpException("Report candidate_ref does not match claimed candidate");
        }

        if (($report['manifest_sha256'] ?? '') !== $candidate->manifest_sha256) {
            throw new UnprocessableEntityHttpException("Report manifest_sha256 does not match claimed candidate");
        }

        if (($report['checker_version'] ?? '') !== 'chromium-pw-1.63.0-v1') {
            throw new UnprocessableEntityHttpException("Report checker_version must be chromium-pw-1.63.0-v1");
        }

        if (($report['screenshot_sha256'] ?? '') !== $screenshotSha) {
            throw new UnprocessableEntityHttpException("Report screenshot_sha256 does not match screenshot bytes");
        }

        // Enforce the 7 prescribed ordered checks
        $checks = $report['checks'] ?? null;
        if (!is_array($checks) || count($checks) !== 7) {
            throw new UnprocessableEntityHttpException("Report checks must contain exactly the 7 prescribed checks");
        }

        $checkCodesPath = resource_path('v3/scene/check-codes-v1.json');
        $allowedDetailCodes = file_exists($checkCodesPath)
            ? (json_decode(file_get_contents($checkCodesPath), true)['detail_codes'] ?? [])
            : [];

        $verdict = 'pass';
        $failedCodes = [];

        for ($i = 0; $i < 7; $i++) {
            $chk = $checks[$i] ?? null;
            if (!is_array($chk)) {
                throw new UnprocessableEntityHttpException("Check at index {$i} must be an object");
            }

            $code = $chk['code'] ?? null;
            if ($code !== self::REQUIRED_CHECKS[$i]) {
                throw new UnprocessableEntityHttpException(
                    "Check at index {$i} must have code '" . self::REQUIRED_CHECKS[$i] . "', got '{$code}'"
                );
            }

            $status = $chk['status'] ?? null;
            if ($status !== 'pass' && $status !== 'fail') {
                throw new UnprocessableEntityHttpException("Check '{$code}' status must be 'pass' or 'fail'");
            }

            $detailCodes = $chk['detail_codes'] ?? [];
            if (!is_array($detailCodes)) {
                throw new UnprocessableEntityHttpException("Check '{$code}' detail_codes must be an array");
            }

            foreach ($detailCodes as $dc) {
                if (!in_array($dc, $allowedDetailCodes, true)) {
                    throw new UnprocessableEntityHttpException("Unknown detail code '{$dc}' in check '{$code}'");
                }
            }

            if ($status !== 'pass') {
                $verdict = 'fail';
                foreach ($detailCodes as $dc) {
                    if ($dc !== 'not_run_due_to_prior_failure') {
                        $failedCodes[] = $dc;
                    }
                }
            }
        }
        $failedCodes = array_values(array_unique($failedCodes));

        // Enforce timestamps and derive measured duration_ms
        $startedAtStr = $report['started_at'] ?? '';
        $finishedAtStr = $report['finished_at'] ?? '';
        if (!$startedAtStr || !$finishedAtStr) {
            throw new UnprocessableEntityHttpException("Missing started_at or finished_at timestamps");
        }

        try {
            $startedAt = Carbon::parse($startedAtStr);
            $finishedAt = Carbon::parse($finishedAtStr);
        } catch (\Throwable $e) {
            throw new UnprocessableEntityHttpException("Invalid timestamp format");
        }

        if ($finishedAt->lessThan($startedAt)) {
            throw new UnprocessableEntityHttpException("finished_at cannot be earlier than started_at");
        }

        $durationMs = (int) round($finishedAt->getPreciseTimestamp(3) - $startedAt->getPreciseTimestamp(3));
        if ($durationMs <= 0 || $durationMs > self::MAX_DURATION_MS) {
            throw new UnprocessableEntityHttpException(
                "duration_ms must be between 1 and " . self::MAX_DURATION_MS . " ms, got {$durationMs}"
            );
        }

        // Staging write before exclusive commit
        $disk = Storage::disk('v3_private');
        $stagingDir = "scene-checks/.staging/{$caseId}/{$attemptRef}";
        $stagingReport = "{$stagingDir}/report.json";
        $stagingScreenshot = "{$stagingDir}/screenshot.png";
        $disk->put($stagingReport, $reportJson);
        $disk->put($stagingScreenshot, $screenshotBytes);

        $reportPath = "scene-checks/{$caseId}/{$candidate->id}/{$attemptRef}/{$reportSha}.json";
        $screenshotPath = "scene-checks/{$caseId}/{$candidate->id}/{$attemptRef}/{$screenshotSha}.png";

        $disk->put($reportPath, $reportJson);
        $disk->put($screenshotPath, $screenshotBytes);
        $disk->delete([$stagingReport, $stagingScreenshot]);

        return DB::transaction(function () use (
            $attempt, $candidate, $caseId, $reportSha, $screenshotSha, $reportPath, $screenshotPath, $verdict, $failedCodes, $resultSha256, $durationMs
        ) {
            $runId = (string) Str::uuid();
            $run = SceneCheckRun::create([
                'id' => $runId,
                'candidate_id' => $candidate->id,
                'winning_attempt_id' => $attempt->id,
                'case_id' => $caseId,
                'section_id' => $candidate->section_id,
                'prompt_version_id' => $candidate->prompt_version_id,
                'settings_version_id' => $candidate->settings_version_id,
                'manifest_sha256' => $candidate->manifest_sha256,
                'report_sha256' => $reportSha,
                'screenshot_sha256' => $screenshotSha,
                'report_path' => $reportPath,
                'screenshot_path' => $screenshotPath,
                'checker_version' => 'chromium-pw-1.63.0-v1',
                'verdict' => $verdict,
                'failed_checks' => $failedCodes,
                'duration_ms' => $durationMs,
            ]);

            $attempt->update([
                'state' => 'recorded',
                'result_sha256' => $resultSha256,
                'finished_at' => now(),
            ]);

            $state = SceneCandidateState::where('candidate_id', $candidate->id)->lockForUpdate()->first();
            if ($state) {
                if ($verdict === 'pass') {
                    $isDemoOrManual = ($candidate->origin === 'manual_import') || ($candidate->prompt_gate_id !== null);
                    if ($isDemoOrManual) {
                        $nextStatus = 'demo_review_ready';

                        $commandId = null;
                        $promptGate = null;
                        if ($candidate->prompt_gate_id) {
                            $promptGate = DB::table('v3_qualification_gates')->where('id', $candidate->prompt_gate_id)->first();
                            $commandId = $promptGate?->command_id;
                        }
                        if (!$commandId && $candidate->manual_import_id) {
                            $manualImport = DB::table('v3_manual_scene_imports')->where('id', $candidate->manual_import_id)->first();
                            $commandId = $manualImport?->command_id;
                        }

                        if ($commandId) {
                            $case = DB::table('v3_processing_cases')->where('id', $caseId)->first();
                            $promptVersion = DB::table('v3_scene_prompt_versions')->where('id', $candidate->prompt_version_id)->first();

                            $this->qualificationGateService->record([
                                'case_id' => $caseId,
                                'section_id' => $candidate->section_id,
                                'prompt_version_id' => $candidate->prompt_version_id,
                                'candidate_id' => $candidate->id,
                                'gate_stage' => 'scene',
                                'basis' => 'owner_waived_demo',
                                'real_qualification_id' => null,
                                'waived_by_owner_id' => $promptGate ? $promptGate->waived_by_owner_id : ($case ? $case->owner_user_id : null),
                                'policy_version' => $promptGate ? $promptGate->policy_version : 'spec_019_web_demo_v1',
                                'policy_sha256' => $promptGate ? $promptGate->policy_sha256 : hash('sha256', 'spec_019_web_demo_v1'),
                                'prompt_sha256' => $promptGate ? $promptGate->prompt_sha256 : ($promptVersion ? $promptVersion->body_sha256 : hash('sha256', 'prompt')),
                                'candidate_manifest_sha256' => $candidate->manifest_sha256,
                                'missing_fields' => $promptGate ? json_decode($promptGate->missing_fields_json ?? '[]', true) : [],
                                'command_id' => $commandId,
                            ]);
                        }
                    } else {
                        $nextStatus = 'awaiting_jev';
                    }

                    $state->update([
                        'status' => $nextStatus,
                        'check_run_id' => $run->id,
                        'state_version' => $state->state_version + 1,
                    ]);
                    if ($nextStatus === 'demo_review_ready') {
                        $review = app(EnsureReviewReady::class)->ensure($caseId, $candidate->id);
                        if (($review['status'] ?? 'unavailable') === 'unavailable') {
                            throw new ConflictHttpException('Passing candidate could not enter owner review.');
                        }
                    }
                } else {
                    $state->update([
                        'status' => 'repair_pending',
                        'check_run_id' => $run->id,
                        'safe_error_code' => 'validation',
                        'state_version' => $state->state_version + 1,
                    ]);
                }
            }

            return [
                'status_code' => 201,
                'data' => [
                    'attempt_ref' => $attempt->id,
                    'check_ref' => $run->id,
                    'state' => $verdict,
                    'report_sha256' => $reportSha,
                ]
            ];
        });
    }

    public function recordTechnicalFailure(string $caseId, string $attemptRef, array $payload): array
    {
        $attempt = SceneCheckAttempt::where('id', $attemptRef)
            ->where('case_id', $caseId)
            ->first();

        if (!$attempt) {
            throw new NotFoundHttpException("Attempt not found");
        }

        $attempt->update([
            'state' => 'technical_failure',
            'safe_error_code' => $payload['error_code'] ?? 'internal',
            'finished_at' => now(),
        ]);

        return [
            'status_code' => 200,
            'data' => [
                'attempt_ref' => $attempt->id,
                'state' => 'technical_failure',
            ]
        ];
    }
}
