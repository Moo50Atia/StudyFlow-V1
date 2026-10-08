<?php

namespace App\Services\V3\SceneQualification;

use App\Models\V3\ProcessingCase;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneJevAttempt;
use App\Models\V3\SceneQualification;
use App\Models\V3\SettingsVersion;
use App\Services\V3\Prompts\PromptConflictException;
use App\Services\V3\Prompts\PromptUnavailableException;
use App\Services\V3\Prompts\PromptValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RecordSceneJevResult
{
    public const RULE_VERSION = 'scene-rule-v1';

    public function record(string $caseId, string $attemptRef, array $data): array
    {
        $rawAnswerJson = $data['raw_answer_json'] ?? '';
        $answerSha = $data['answer_sha256'] ?? '';
        $returnedModel = (string) ($data['returned_model'] ?? '');

        if (empty($rawAnswerJson) || empty($answerSha) || empty($returnedModel)) {
            throw new PromptValidationException("Missing raw_answer_json, answer_sha256, or returned_model.");
        }

        if (strlen($rawAnswerJson) > 1048576) {
            throw new PromptValidationException("Answer exceeds 1 MiB limit.");
        }

        if (hash('sha256', $rawAnswerJson) !== $answerSha) {
            throw new PromptValidationException("Answer digest mismatch.");
        }

        return DB::transaction(function () use ($caseId, $attemptRef, $rawAnswerJson, $answerSha, $returnedModel) {
            /** @var ProcessingCase|null $case */
            $case = ProcessingCase::find($caseId);
            if (! $case) {
                throw new PromptUnavailableException("Case not found.");
            }

            /** @var SceneJevAttempt|null $attempt */
            $attempt = SceneJevAttempt::where('id', $attemptRef)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();
            if (! $attempt) {
                throw new PromptUnavailableException("JEV attempt not found.");
            }

            // Check if already recorded
            if ($attempt->state === 'recorded') {
                $existingQual = SceneQualification::where('winning_jev_attempt_id', $attempt->id)->first();
                if ($existingQual) {
                    if ($existingQual->answer_sha256 === $answerSha && $existingQual->returned_model === $returnedModel) {
                        return [
                            'status_code' => 200,
                            'data' => [
                                'attempt_ref' => $attempt->id,
                                'state' => 'recorded',
                                'qualification_ref' => $existingQual->id,
                                'decision' => $existingQual->decision,
                                'failed_checks' => $existingQual->failed_checks,
                            ],
                        ];
                    }
                    throw new PromptConflictException("Conflicting replay for recorded JEV attempt.");
                }
            }

            if ($attempt->state === 'technical_failure') {
                throw new PromptConflictException("Cannot record result for technical failure attempt.");
            }

            // Check lease expiry
            if ($attempt->lease_expires_at && now()->isAfter($attempt->lease_expires_at)) {
                $attempt->update([
                    'state' => 'technical_failure',
                    'safe_error_code' => 'timeout',
                    'finished_at' => now(),
                ]);
                throw new PromptConflictException("Attempt lease expired.");
            }

            /** @var SceneCandidate|null $candidate */
            $candidate = SceneCandidate::find($attempt->candidate_id);
            if (! $candidate) {
                throw new PromptUnavailableException("Candidate not found.");
            }

            /** @var SceneCandidateState|null $candidateState */
            $candidateState = SceneCandidateState::where('candidate_id', $candidate->id)
                ->lockForUpdate()
                ->first();
            if (! $candidateState) {
                throw new PromptUnavailableException("Candidate state not found.");
            }

            // Parse response
            $response = json_decode($rawAnswerJson, true);
            $answers = $response['answers'] ?? null;

            $answerPath = "scene-jev/{$caseId}/{$candidate->id}/{$attempt->id}/{$answerSha}.json";

            // Wire validation
            $isValidWire = false;
            if (is_array($response) && is_array($answers) && isset($answers['choice'], $answers['score'], $answers['noul'])) {
                $c = $answers['choice'];
                $s = $answers['score'];
                $n = $answers['noul'];

                if (is_array($c) && is_array($s) && is_array($n)
                    && in_array($c['choice'] ?? null, ['pass', 'revise', 'block'], true)
                    && is_numeric($c['confidence'] ?? null) && (float)$c['confidence'] >= 0.0 && (float)$c['confidence'] <= 1.0
                    && is_numeric($s['score'] ?? null) && (float)$s['score'] >= 0.0 && (float)$s['score'] <= 100.0
                    && is_numeric($s['confidence'] ?? null) && (float)$s['confidence'] >= 0.0 && (float)$s['confidence'] <= 1.0
                    && is_numeric($n['noul'] ?? null) && (float)$n['noul'] >= 0.0 && (float)$n['noul'] <= 1.0
                ) {
                    $isValidWire = true;
                }
            }

            if (! $isValidWire) {
                // Malformed typed answer records raw evidence plus technical failure
                Storage::disk('v3_private')->put($answerPath, $rawAnswerJson);
                $attempt->update([
                    'state' => 'technical_failure',
                    'safe_error_code' => 'internal',
                    'answer_sha256' => $answerSha,
                    'answer_path' => $answerPath,
                    'finished_at' => now(),
                ]);

                return [
                    'status_code' => 200,
                    'data' => [
                        'attempt_ref' => $attempt->id,
                        'state' => 'technical_failure',
                        'qualification_ref' => null,
                    ],
                ];
            }

            /** @var SettingsVersion|null $settings */
            $settings = SettingsVersion::find($case->settings_version_id);
            if (! $settings) {
                throw new PromptUnavailableException("Settings not found.");
            }

            $payloadSettings = $settings->payload ?? [];
            $minScore = (int) ($payloadSettings['qualification']['scene']['min_score'] ?? 85);
            $minConfidence = (int) ($payloadSettings['qualification']['scene']['min_confidence'] ?? 80);
            $repairLimit = (int) ($payloadSettings['limits']['scene_repairs'] ?? 2);

            $choiceVal = (string) $answers['choice']['choice'];
            $choiceConf = (float) $answers['choice']['confidence'];
            $scoreVal = (float) $answers['score']['score'];
            $scoreConf = (float) $answers['score']['confidence'];
            $noulVal = (float) $answers['noul']['noul'];
            $noulYes = $noulVal >= 0.5;

            // Direct 005 rule checks
            $failedChecks = [];
            if ($choiceVal !== 'pass') {
                $failedChecks[] = 'choice';
            }
            if ($scoreVal < $minScore) {
                $failedChecks[] = 'score';
            }
            if ((100.0 * $choiceConf) < $minConfidence) {
                $failedChecks[] = 'choice_confidence';
            }
            if ((100.0 * $scoreConf) < $minConfidence) {
                $failedChecks[] = 'score_confidence';
            }
            if (! $noulYes) {
                $failedChecks[] = 'noul';
            }
            if ((100.0 * $noulVal) < $minConfidence) {
                $failedChecks[] = 'noul_confidence';
            }

            if (empty($failedChecks)) {
                $decision = 'pass';
            } elseif ($choiceVal === 'block') {
                $decision = 'block';
            } else {
                $repairCount = Schema::hasTable('v3_scene_repairs')
                    ? DB::table('v3_scene_repairs')->where('root_candidate_id', $candidateState->root_candidate_id)->count()
                    : 0;

                if ($repairCount < $repairLimit) {
                    $decision = 'revise';
                } else {
                    $decision = 'block';
                    $failedChecks[] = 'repair_budget_exhausted';
                }
            }

            // Write valid answer to disk
            Storage::disk('v3_private')->put($answerPath, $rawAnswerJson);

            // Update attempt
            $attempt->update([
                'state' => 'recorded',
                'result_sha256' => $answerSha,
                'answer_sha256' => $answerSha,
                'answer_path' => $answerPath,
                'finished_at' => now(),
            ]);

            // Create qualification
            $qual = SceneQualification::forceCreate([
                'id' => (string) Str::uuid(),
                'candidate_id' => $candidate->id,
                'check_run_id' => $attempt->check_run_id,
                'winning_jev_attempt_id' => $attempt->id,
                'case_id' => $caseId,
                'section_id' => $candidate->section_id,
                'prompt_version_id' => $candidate->prompt_version_id,
                'settings_version_id' => $settings->id,
                'settings_checksum' => $settings->checksum,
                'manifest_sha256' => $candidate->manifest_sha256,
                'evidence_sha256' => $attempt->evidence_sha256,
                'request_sha256' => $attempt->request_sha256,
                'question_sha256' => $attempt->question_sha256,
                'answer_sha256' => $answerSha,
                'evidence_path' => $attempt->evidence_path,
                'answer_path' => $answerPath,
                'rule_version' => self::RULE_VERSION,
                'requested_model' => $attempt->requested_model,
                'returned_model' => $returnedModel,
                'choice' => $choiceVal,
                'choice_confidence' => $choiceConf,
                'score' => $scoreVal,
                'score_confidence' => $scoreConf,
                'noul_probability' => $noulVal,
                'noul_yes' => $noulYes,
                'min_score' => $minScore,
                'min_confidence' => $minConfidence,
                'decision' => $decision,
                'failed_checks' => $failedChecks,
                'created_at' => now(),
            ]);

            // Transition candidate state
            if ($decision === 'pass') {
                $candidateState->update([
                    'status' => 'qualified',
                    'qualification_id' => $qual->id,
                    'safe_error_code' => null,
                    'state_version' => $candidateState->state_version + 1,
                ]);
            } elseif ($decision === 'revise') {
                $candidateState->update([
                    'status' => 'repair_pending',
                    'qualification_id' => $qual->id,
                    'safe_error_code' => 'validation',
                    'state_version' => $candidateState->state_version + 1,
                ]);
            } else {
                $candidateState->update([
                    'status' => 'blocked',
                    'qualification_id' => $qual->id,
                    'safe_error_code' => 'validation',
                    'state_version' => $candidateState->state_version + 1,
                ]);
            }

            return [
                'status_code' => 201,
                'data' => [
                    'attempt_ref' => $attempt->id,
                    'state' => 'recorded',
                    'qualification_ref' => $qual->id,
                    'decision' => $decision,
                    'failed_checks' => $failedChecks,
                ],
            ];
        });
    }

    public function recordTechnicalFailure(string $caseId, string $attemptRef, array $data): array
    {
        $errorCode = (string) ($data['error_code'] ?? 'internal');
        if (! in_array($errorCode, ['timeout', 'provider_unavailable', 'internal'], true)) {
            $errorCode = 'internal';
        }

        return DB::transaction(function () use ($caseId, $attemptRef, $errorCode) {
            /** @var SceneJevAttempt|null $attempt */
            $attempt = SceneJevAttempt::where('id', $attemptRef)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();
            if (! $attempt) {
                throw new PromptUnavailableException("JEV attempt not found.");
            }

            if ($attempt->state === 'recorded') {
                throw new PromptConflictException("Cannot mark recorded attempt as technical failure.");
            }

            $attempt->update([
                'state' => 'technical_failure',
                'safe_error_code' => $errorCode,
                'finished_at' => now(),
            ]);

            return [
                'status_code' => 200,
                'data' => [
                    'attempt_ref' => $attempt->id,
                    'state' => 'technical_failure',
                ],
            ];
        });
    }
}
