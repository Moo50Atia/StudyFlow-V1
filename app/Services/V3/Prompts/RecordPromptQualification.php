<?php

namespace App\Services\V3\Prompts;

use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptJevAttempt;
use App\Models\V3\PromptQualification;
use App\Models\V3\PromptSectionState;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SettingsVersion;
use App\Models\V3\TeacherSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RecordPromptQualification
{
    public const RULE_VERSION = 'prompt-rule-v1';
    public const QUESTION_VERSION = 'prompt-questions-v1';

    public static function computeJevRequestDigest(
        string $promptVersionId,
        string $promptBodySha,
        string $sectionFieldsSha,
        string $settingsChecksum,
        string $questionSha,
        string $requestedModel
    ): string {
        $payload = "010-jev-v1\n"
            . $promptVersionId . "\n"
            . $promptBodySha . "\n"
            . $sectionFieldsSha . "\n"
            . $settingsChecksum . "\n"
            . $questionSha . "\n"
            . $requestedModel;

        return hash('sha256', $payload);
    }

    public function getQualificationContext(string $caseId, string $promptVersionRef): array
    {
        /** @var ProcessingCase|null $case */
        $case = ProcessingCase::find($caseId);
        if (! $case) {
            throw new PromptUnavailableException("Case not found.");
        }

        /** @var ScenePromptVersion|null $promptVersion */
        $promptVersion = ScenePromptVersion::where('id', $promptVersionRef)
            ->where('case_id', $caseId)
            ->first();
        if (! $promptVersion) {
            throw new PromptUnavailableException("Prompt version not found.");
        }

        /** @var PromptSectionState|null $sectionState */
        $sectionState = PromptSectionState::where('section_id', $promptVersion->section_id)
            ->where('case_id', $caseId)
            ->first();
        if (! $sectionState || $sectionState->current_prompt_version_id !== $promptVersion->id) {
            throw new PromptUnavailableException("Prompt version is not current for its section.");
        }

        // Verify body on disk
        if (! Storage::disk('v3_private')->exists($promptVersion->body_path)) {
            throw new PromptUnavailableException("Prompt body file missing on private disk.");
        }
        $body = Storage::disk('v3_private')->get($promptVersion->body_path);
        if (hash('sha256', $body) !== $promptVersion->body_sha256) {
            throw new PromptUnavailableException("Prompt body digest mismatch.");
        }

        /** @var TeacherSection|null $section */
        $section = TeacherSection::where('id', $promptVersion->section_id)
            ->where('case_id', $caseId)
            ->first();
        if (! $section) {
            throw new PromptUnavailableException("Section not found.");
        }

        $fields = [
            'section_identity' => (string) $section->section_identity,
            'core_understanding' => (string) $section->core_understanding,
            'visual_first_explanation' => (string) $section->visual_first_explanation,
            'visual_scene_script' => (string) $section->visual_scene_script,
            'dynamic_view_blueprint' => (string) $section->dynamic_view_blueprint,
            'visual_learning_outcome' => (string) $section->visual_learning_outcome,
            'concept_check' => (string) $section->concept_check,
            'conceptual_summary' => (string) $section->conceptual_summary,
            'colloquial_egyptian_explanation' => (string) $section->colloquial_egyptian_explanation,
            'laws_if_any' => (string) $section->laws_if_any,
            'real_life_example' => (string) $section->real_life_example,
        ];
        $computedFieldsSha = PromptContextReader::computeSectionFieldsSha256($fields);
        if ($computedFieldsSha !== $promptVersion->section_fields_sha256) {
            throw new PromptUnavailableException("Section fields digest mismatch.");
        }

        $coveragePages = $section->lessonNode?->coverage_pages ?? [];

        /** @var SettingsVersion|null $settingsVersion */
        $settingsVersion = SettingsVersion::find($case->settings_version_id);
        if (! $settingsVersion) {
            throw new PromptUnavailableException("Captured settings are unavailable.");
        }

        $payload = $settingsVersion->payload ?? [];
        $minScore = (int) ($payload['qualification']['prompt']['min_score'] ?? 80);
        $minConfidence = (int) ($payload['qualification']['prompt']['min_confidence'] ?? 75);
        $technicalRetries = (int) ($payload['limits']['technical_retries'] ?? 3);
        $retryDelaySeconds = (int) ($payload['limits']['retry_delay_seconds'] ?? 60);

        return [
            'case_id' => $caseId,
            'section_ref' => $section->id,
            'prompt_version_ref' => $promptVersion->id,
            'prompt' => $body,
            'fields' => $fields,
            'coverage_pages' => $coveragePages,
            'settings_version_id' => $settingsVersion->id,
            'settings_checksum' => $settingsVersion->checksum,
            'min_score' => $minScore,
            'min_confidence' => $minConfidence,
            'technical_retries' => $technicalRetries,
            'retry_delay_seconds' => $retryDelaySeconds,
            'question_version' => self::QUESTION_VERSION,
        ];
    }

    public function claimAttempt(string $caseId, string $promptVersionRef, array $payload): array
    {
        if (($payload['schema_version'] ?? null) !== 1) {
            throw new PromptValidationException("Invalid schema_version.");
        }

        $requestSha = $payload['request_sha256'] ?? null;
        $questionSha = $payload['question_sha256'] ?? null;
        $requestedModel = $payload['requested_model'] ?? null;

        if (! $requestSha || ! $questionSha || ! $requestedModel) {
            throw new PromptValidationException("Missing required attempt parameters.");
        }

        $questionAssetPath = resource_path('v3/jev/prompt-questions-v1.json');
        if (! file_exists($questionAssetPath)) {
            throw new PromptUnavailableException("Question asset file missing.");
        }
        $actualQuestionSha = hash_file('sha256', $questionAssetPath);
        if ($actualQuestionSha !== $questionSha) {
            throw new PromptValidationException("Question SHA mismatch.");
        }

        /** @var ScenePromptVersion|null $promptVersion */
        $promptVersion = ScenePromptVersion::where('id', $promptVersionRef)
            ->where('case_id', $caseId)
            ->first();
        if (! $promptVersion) {
            throw new PromptUnavailableException("Prompt version not found.");
        }

        /** @var ProcessingCase|null $case */
        $case = ProcessingCase::find($caseId);
        if (! $case) {
            throw new PromptUnavailableException("Case not found.");
        }

        /** @var SettingsVersion|null $settingsVersion */
        $settingsVersion = SettingsVersion::find($case->settings_version_id);
        if (! $settingsVersion) {
            throw new PromptUnavailableException("Captured settings unavailable.");
        }

        $expectedRequestSha = self::computeJevRequestDigest(
            $promptVersion->id,
            $promptVersion->body_sha256,
            $promptVersion->section_fields_sha256,
            $settingsVersion->checksum,
            $questionSha,
            $requestedModel
        );

        if ($requestSha !== $expectedRequestSha) {
            throw new PromptValidationException("Request SHA mismatch.");
        }

        return DB::transaction(function () use ($caseId, $promptVersion, $settingsVersion, $requestSha, $questionSha, $requestedModel) {
            /** @var PromptSectionState|null $sectionState */
            $sectionState = PromptSectionState::where('section_id', $promptVersion->section_id)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();
            if (! $sectionState || $sectionState->current_prompt_version_id !== $promptVersion->id) {
                throw new PromptUnavailableException("Prompt version is not current for its section.");
            }

            // If qualification verdict already recorded, conflict
            $existingQual = PromptQualification::where('prompt_version_id', $promptVersion->id)->first();
            if ($existingQual) {
                throw new PromptConflictException("Prompt state changed: verdict already recorded.");
            }

            // Check if there is an active claimed attempt
            /** @var PromptJevAttempt|null $activeAttempt */
            $activeAttempt = PromptJevAttempt::where('prompt_version_id', $promptVersion->id)
                ->where('state', 'claimed')
                ->first();

            if ($activeAttempt) {
                if ($activeAttempt->request_sha256 === $requestSha && $activeAttempt->requested_model === $requestedModel) {
                    return [
                        'attempt_ref' => $activeAttempt->id,
                        'attempt_no' => $activeAttempt->attempt_no,
                        'disposition' => 'reconcile',
                        'request_sha256' => $activeAttempt->request_sha256,
                    ];
                }
                throw new PromptConflictException("Attempt already claimed with conflicting parameters.");
            }

            // Count attempts
            $attemptsCount = PromptJevAttempt::where('prompt_version_id', $promptVersion->id)->count();
            $maxAttempts = (int) ($settingsVersion->payload['limits']['technical_retries'] ?? 3) + 1;
            if ($attemptsCount >= $maxAttempts) {
                throw new PromptConflictException("Technical retries exhausted for this version.");
            }

            $attemptNo = $attemptsCount + 1;

            $attempt = PromptJevAttempt::forceCreate([
                'id' => (string) Str::uuid(),
                'prompt_version_id' => $promptVersion->id,
                'attempt_no' => $attemptNo,
                'request_sha256' => $requestSha,
                'question_sha256' => $questionSha,
                'requested_model' => $requestedModel,
                'settings_version_id' => $settingsVersion->id,
                'state' => 'claimed',
                'sent_at' => now(),
            ]);

            return [
                'attempt_ref' => $attempt->id,
                'attempt_no' => $attempt->attempt_no,
                'disposition' => 'send_now',
                'request_sha256' => $attempt->request_sha256,
            ];
        });
    }

    public function recordResult(string $caseId, string $attemptRef, array $payload): array
    {
        if (($payload['schema_version'] ?? null) !== 1) {
            throw new PromptValidationException("Invalid schema_version.");
        }

        $response = $payload['response'] ?? null;
        $requestSha = $payload['request_sha256'] ?? null;

        if (! is_array($response) || ! $requestSha) {
            throw new PromptValidationException("Missing response or request_sha256.");
        }

        /** @var PromptJevAttempt|null $attempt */
        $attempt = PromptJevAttempt::find($attemptRef);
        if (! $attempt) {
            throw new PromptUnavailableException("Attempt not found.");
        }

        /** @var ScenePromptVersion|null $promptVersion */
        $promptVersion = ScenePromptVersion::where('id', $attempt->prompt_version_id)
            ->where('case_id', $caseId)
            ->first();
        if (! $promptVersion) {
            throw new PromptUnavailableException("Prompt version not found.");
        }

        if ($attempt->request_sha256 !== $requestSha) {
            throw new PromptValidationException("Request SHA mismatch.");
        }

        $rawResponseJson = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($rawResponseJson) > 1048576) {
            throw new PromptValidationException("Response exceeds 1 MiB limit.");
        }
        $answerSha = hash('sha256', $rawResponseJson);
        $returnedModel = (string) ($response['model'] ?? '');

        return DB::transaction(function () use ($caseId, $attempt, $promptVersion, $response, $rawResponseJson, $answerSha, $returnedModel) {
            /** @var PromptSectionState|null $sectionState */
            $sectionState = PromptSectionState::where('section_id', $promptVersion->section_id)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();
            if (! $sectionState || $sectionState->current_prompt_version_id !== $promptVersion->id) {
                throw new PromptUnavailableException("Prompt version is not current for its section.");
            }

            /** @var PromptQualification|null $existingQual */
            $existingQual = PromptQualification::where('prompt_version_id', $promptVersion->id)->first();
            if ($existingQual) {
                if ($existingQual->answer_sha256 === $answerSha && $existingQual->returned_model === $returnedModel) {
                    return [
                        'status_code' => 200,
                        'data' => [
                            'qualification_ref' => $existingQual->id,
                            'decision' => $existingQual->decision,
                            'failed_checks' => $existingQual->failed_checks,
                        ],
                    ];
                }
                throw new PromptConflictException("Prompt state changed: conflicting result replay.");
            }

            // Validate TypeSafe response wire
            $answers = $response['answers'] ?? null;
            if (! is_array($answers) || ! isset($answers['choice'], $answers['score'], $answers['noul'])) {
                $attempt->update(['state' => 'technical_failure', 'error_code' => 'policy_denied', 'finished_at' => now()]);
                throw new PromptValidationException("JEV response missing required answers.");
            }

            $choiceAns = $answers['choice'];
            $scoreAns = $answers['score'];
            $noulAns = $answers['noul'];

            if (! is_array($choiceAns) || ! is_array($scoreAns) || ! is_array($noulAns)) {
                $attempt->update(['state' => 'technical_failure', 'error_code' => 'policy_denied', 'finished_at' => now()]);
                throw new PromptValidationException("JEV answers must be objects.");
            }

            $choiceVal = $choiceAns['choice'] ?? null;
            $choiceConf = $choiceAns['confidence'] ?? null;
            if (! in_array($choiceVal, ['pass', 'revise', 'block'], true) || ! is_numeric($choiceConf) || $choiceConf < 0.0 || $choiceConf > 1.0) {
                $attempt->update(['state' => 'technical_failure', 'error_code' => 'policy_denied', 'finished_at' => now()]);
                throw new PromptValidationException("Invalid choice answer values.");
            }

            $scoreVal = $scoreAns['score'] ?? null;
            $scoreConf = $scoreAns['confidence'] ?? null;
            if (! is_numeric($scoreVal) || $scoreVal < 0.0 || $scoreVal > 100.0 || ! is_numeric($scoreConf) || $scoreConf < 0.0 || $scoreConf > 1.0) {
                $attempt->update(['state' => 'technical_failure', 'error_code' => 'policy_denied', 'finished_at' => now()]);
                throw new PromptValidationException("Invalid score answer values.");
            }

            $noulVal = $noulAns['noul'] ?? null;
            if (! is_numeric($noulVal) || $noulVal < 0.0 || $noulVal > 1.0) {
                $attempt->update(['state' => 'technical_failure', 'error_code' => 'policy_denied', 'finished_at' => now()]);
                throw new PromptValidationException("Invalid noul answer values.");
            }

            /** @var SettingsVersion|null $settingsVersion */
            $settingsVersion = SettingsVersion::find($attempt->settings_version_id);
            if (! $settingsVersion) {
                throw new PromptUnavailableException("Captured settings unavailable.");
            }

            $payloadSettings = $settingsVersion->payload ?? [];
            $minScore = (int) ($payloadSettings['qualification']['prompt']['min_score'] ?? 80);
            $minConfidence = (int) ($payloadSettings['qualification']['prompt']['min_confidence'] ?? 75);
            $revisionLimit = (int) ($payloadSettings['limits']['prompt_revisions'] ?? 2);
            $revisionCount = (int) $sectionState->revision_count;

            $choiceConfF = (float) $choiceConf;
            $scoreValF = (float) $scoreVal;
            $scoreConfF = (float) $scoreConf;
            $noulValF = (float) $noulVal;
            $noulYes = $noulValF >= 0.5;

            // Direct policy checks (unrounded)
            $failedChecks = [];
            if ($choiceVal !== 'pass') {
                $failedChecks[] = 'choice';
            }
            if ($scoreValF < $minScore) {
                $failedChecks[] = 'score';
            }
            if ((100.0 * $choiceConfF) < $minConfidence) {
                $failedChecks[] = 'choice_confidence';
            }
            if ((100.0 * $scoreConfF) < $minConfidence) {
                $failedChecks[] = 'score_confidence';
            }
            if (! $noulYes) {
                $failedChecks[] = 'noul';
            }
            if ((100.0 * $noulValF) < $minConfidence) {
                $failedChecks[] = 'noul_confidence';
            }

            if (empty($failedChecks)) {
                $decision = 'pass';
            } elseif ($choiceVal === 'block') {
                $decision = 'block';
            } else {
                if ($revisionCount < $revisionLimit) {
                    $decision = 'revise';
                } else {
                    $decision = 'block';
                    $failedChecks[] = 'revision_budget_exhausted';
                }
            }

            // Write raw answer to v3_private disk
            $answerPath = "prompt-jev/{$caseId}/{$promptVersion->id}/{$answerSha}.json";
            Storage::disk('v3_private')->put($answerPath, $rawResponseJson);

            // Mark attempt recorded
            $attempt->update([
                'state' => 'recorded',
                'finished_at' => now(),
            ]);

            // Insert qualification
            $qual = PromptQualification::forceCreate([
                'id' => (string) Str::uuid(),
                'prompt_version_id' => $promptVersion->id,
                'winning_attempt_id' => $attempt->id,
                'case_id' => $caseId,
                'section_id' => $promptVersion->section_id,
                'settings_version_id' => $settingsVersion->id,
                'settings_checksum' => $settingsVersion->checksum,
                'request_sha256' => $attempt->request_sha256,
                'answer_sha256' => $answerSha,
                'question_sha256' => $attempt->question_sha256,
                'rule_version' => self::RULE_VERSION,
                'requested_model' => $attempt->requested_model,
                'returned_model' => $returnedModel,
                'choice' => $choiceVal,
                'choice_confidence' => (string) $choiceConfF,
                'score' => (string) $scoreValF,
                'score_confidence' => (string) $scoreConfF,
                'noul_probability' => (string) $noulValF,
                'noul_yes' => $noulYes,
                'min_score' => $minScore,
                'min_confidence' => $minConfidence,
                'decision' => $decision,
                'failed_checks' => $failedChecks,
                'answer_path' => $answerPath,
                'created_at' => now(),
            ]);

            // Update section state
            if ($decision === 'pass') {
                $sectionState->update([
                    'status' => 'qualified',
                    'last_qualification_id' => $qual->id,
                    'error_code' => null,
                ]);
            } elseif ($decision === 'revise') {
                $sectionState->update([
                    'status' => 'revising',
                    'last_qualification_id' => $qual->id,
                    'error_code' => null,
                ]);
            } else {
                $sectionState->update([
                    'status' => 'blocked',
                    'last_qualification_id' => $qual->id,
                    'error_code' => 'validation',
                ]);
            }

            return [
                'status_code' => 201,
                'data' => [
                    'qualification_ref' => $qual->id,
                    'decision' => $decision,
                    'failed_checks' => $failedChecks,
                ],
            ];
        });
    }

    public function recordTechnicalFailure(string $caseId, string $attemptRef, array $payload): array
    {
        if (($payload['schema_version'] ?? null) !== 1) {
            throw new PromptValidationException("Invalid schema_version.");
        }

        $errorCode = $payload['error_code'] ?? null;
        if (! in_array($errorCode, ['provider_unavailable', 'timeout', 'policy_denied'], true)) {
            throw new PromptValidationException("Invalid error_code '{$errorCode}'.");
        }

        /** @var PromptJevAttempt|null $attempt */
        $attempt = PromptJevAttempt::find($attemptRef);
        if (! $attempt) {
            throw new PromptUnavailableException("Attempt not found.");
        }

        /** @var ScenePromptVersion|null $promptVersion */
        $promptVersion = ScenePromptVersion::where('id', $attempt->prompt_version_id)
            ->where('case_id', $caseId)
            ->first();
        if (! $promptVersion) {
            throw new PromptUnavailableException("Prompt version not found.");
        }

        return DB::transaction(function () use ($caseId, $attempt, $promptVersion, $errorCode) {
            /** @var PromptSectionState|null $sectionState */
            $sectionState = PromptSectionState::where('section_id', $promptVersion->section_id)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();
            if (! $sectionState) {
                throw new PromptUnavailableException("Section state not found.");
            }

            $existingQual = PromptQualification::where('prompt_version_id', $promptVersion->id)->first();
            if ($existingQual) {
                throw new PromptConflictException("Prompt state changed: verdict already recorded.");
            }

            if ($attempt->state === 'technical_failure') {
                /** @var SettingsVersion|null $settingsVersion */
                $settingsVersion = SettingsVersion::find($attempt->settings_version_id);
                $maxAttempts = (int) ($settingsVersion?->payload['limits']['technical_retries'] ?? 3) + 1;
                $remaining = max(0, $maxAttempts - $attempt->attempt_no);

                return [
                    'state' => 'technical_failure',
                    'remaining_attempts' => $remaining,
                ];
            }

            if ($attempt->state === 'recorded') {
                throw new PromptConflictException("Attempt already recorded.");
            }

            $attempt->update([
                'state' => 'technical_failure',
                'error_code' => $errorCode,
                'finished_at' => now(),
            ]);

            /** @var SettingsVersion|null $settingsVersion */
            $settingsVersion = SettingsVersion::find($attempt->settings_version_id);
            $maxAttempts = (int) ($settingsVersion?->payload['limits']['technical_retries'] ?? 3) + 1;
            $remaining = max(0, $maxAttempts - $attempt->attempt_no);

            return [
                'state' => 'technical_failure',
                'remaining_attempts' => $remaining,
            ];
        });
    }
}
