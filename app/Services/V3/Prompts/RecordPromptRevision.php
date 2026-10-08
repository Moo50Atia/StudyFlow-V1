<?php

namespace App\Services\V3\Prompts;

use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptFeedbackEffect;
use App\Models\V3\PromptQualification;
use App\Models\V3\PromptSectionState;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SettingsVersion;
use App\Models\V3\TeacherSection;
use App\Services\V3\Settings\ApprovedTemplateRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RecordPromptRevision
{
    public const ALLOWED_SCENE_TYPES = [
        'Process',
        'Comparison',
        'Accumulation',
        'Parallel Flow',
        'Time Evolution',
    ];

    public static function computeFeedbackRequestDigest(
        string $failedVersionId,
        string $qualificationAnswerSha,
        string $templateSha,
        string $providerId,
        string $providerModel,
        string $credentialRef
    ): string {
        $payload = "010-feedback-v1\n"
            . $failedVersionId . "\n"
            . $qualificationAnswerSha . "\n"
            . $templateSha . "\n"
            . $providerId . "\n"
            . $providerModel . "\n"
            . $credentialRef;

        return hash('sha256', $payload);
    }

    public function getFeedbackContext(string $caseId, string $promptVersionRef): array
    {
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

        /** @var PromptQualification|null $qualification */
        $qualification = PromptQualification::where('prompt_version_id', $promptVersion->id)->first();
        if (! $qualification) {
            throw new PromptUnavailableException("Qualification result not found for this version.");
        }

        if ($qualification->decision === 'pass') {
            throw new PromptConflictException("Prompt version already qualified.");
        }

        if ($qualification->decision === 'block' || in_array('choice', $qualification->failed_checks, true) && $qualification->choice === 'block') {
            throw new PromptConflictException("Choice block is terminal; revision prohibited.");
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

        $payload = $settingsVersion->payload ?? [];
        $revisionLimit = (int) ($payload['limits']['prompt_revisions'] ?? 2);
        if ($sectionState->revision_count >= $revisionLimit) {
            throw new PromptConflictException("Revision budget exhausted.");
        }

        $feedbackTemplateConfig = $payload['templates']['feedback'] ?? null;
        if (! $feedbackTemplateConfig || empty($feedbackTemplateConfig['id']) || empty($feedbackTemplateConfig['checksum'])) {
            throw new PromptUnavailableException("Captured feedback template configuration missing.");
        }

        $templateId = $feedbackTemplateConfig['id'];
        $templateChecksum = $feedbackTemplateConfig['checksum'];

        $templateRepo = app(ApprovedTemplateRepository::class);
        $templateBytes = $templateRepo->getCaptured($templateId, $templateChecksum);

        $providerConfig = $payload['providers']['feedback'] ?? null;
        if (! $providerConfig || empty($providerConfig['id']) || empty($providerConfig['model']) || empty($providerConfig['credential_ref'])) {
            throw new PromptUnavailableException("Captured feedback provider configuration missing.");
        }

        if (! Storage::disk('v3_private')->exists($promptVersion->body_path)) {
            throw new PromptUnavailableException("Previous prompt body file missing.");
        }
        $previousPrompt = Storage::disk('v3_private')->get($promptVersion->body_path);

        /** @var TeacherSection|null $section */
        $section = TeacherSection::find($promptVersion->section_id);
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

        $typedValues = [
            'choice' => $qualification->choice,
            'choice_confidence' => (float) $qualification->choice_confidence,
            'score' => (float) $qualification->score,
            'score_confidence' => (float) $qualification->score_confidence,
            'noul_probability' => (float) $qualification->noul_probability,
            'noul_yes' => (bool) $qualification->noul_yes,
        ];

        return [
            'case_id' => $caseId,
            'section_ref' => $promptVersion->section_id,
            'prompt_version_ref' => $promptVersion->id,
            'previous_prompt' => $previousPrompt,
            'fields' => $fields,
            'qualification_ref' => $qualification->id,
            'qualification_answer_sha256' => $qualification->answer_sha256,
            'qualification_answer_sha' => $qualification->answer_sha256,
            'failed_checks' => $qualification->failed_checks ?? [],
            'typed_values' => $typedValues,
            'template_id' => $templateId,
            'template_sha256' => $templateChecksum,
            'template_bytes' => $templateBytes,
            'provider' => [
                'id' => $providerConfig['id'],
                'model' => $providerConfig['model'],
                'credential_ref' => $providerConfig['credential_ref'],
            ],
            'settings_version_id' => $settingsVersion->id,
            'revision_count' => $sectionState->revision_count,
            'revision_limit' => $revisionLimit,
        ];
    }

    public function claimFeedback(string $caseId, string $promptVersionRef, array $payload): array
    {
        if (($payload['schema_version'] ?? null) !== 1) {
            throw new PromptValidationException("Invalid schema_version.");
        }

        /** @var ScenePromptVersion|null $promptVersion */
        $promptVersion = ScenePromptVersion::where('id', $promptVersionRef)
            ->where('case_id', $caseId)
            ->first();
        if (! $promptVersion) {
            throw new PromptUnavailableException("Prompt version not found.");
        }

        /** @var PromptQualification|null $qualification */
        $qualification = PromptQualification::where('prompt_version_id', $promptVersion->id)->first();
        if (! $qualification) {
            throw new PromptUnavailableException("Qualification result not found.");
        }

        if ($qualification->id !== ($payload['qualification_ref'] ?? null)) {
            throw new PromptValidationException("Qualification ref mismatch.");
        }

        /** @var ProcessingCase|null $case */
        $case = ProcessingCase::find($caseId);
        /** @var SettingsVersion|null $settingsVersion */
        $settingsVersion = SettingsVersion::find($case->settings_version_id);
        $settingsPayload = $settingsVersion->payload ?? [];

        $templateConfig = $settingsPayload['templates']['feedback'] ?? [];
        if ($templateConfig['id'] !== ($payload['template_id'] ?? null) || $templateConfig['checksum'] !== ($payload['template_sha256'] ?? null)) {
            throw new PromptValidationException("Feedback template configuration mismatch.");
        }

        $providerConfig = $settingsPayload['providers']['feedback'] ?? [];
        if (
            $providerConfig['id'] !== ($payload['provider_id'] ?? null)
            || $providerConfig['model'] !== ($payload['provider_model'] ?? null)
            || $providerConfig['credential_ref'] !== ($payload['credential_ref'] ?? null)
        ) {
            throw new PromptValidationException("Feedback provider configuration mismatch.");
        }

        $expectedRequestSha = self::computeFeedbackRequestDigest(
            $promptVersion->id,
            $qualification->answer_sha256,
            $payload['template_sha256'],
            $payload['provider_id'],
            $payload['provider_model'],
            $payload['credential_ref']
        );

        if ($expectedRequestSha !== ($payload['request_sha256'] ?? null)) {
            throw new PromptValidationException("Request SHA mismatch.");
        }

        return DB::transaction(function () use ($caseId, $promptVersion, $payload, $settingsPayload) {
            /** @var PromptSectionState|null $sectionState */
            $sectionState = PromptSectionState::where('section_id', $promptVersion->section_id)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();
            if (! $sectionState || $sectionState->current_prompt_version_id !== $promptVersion->id) {
                throw new PromptUnavailableException("Prompt version is not current for its section.");
            }

            $revisionLimit = (int) ($settingsPayload['limits']['prompt_revisions'] ?? 2);
            if ($sectionState->revision_count >= $revisionLimit) {
                throw new PromptConflictException("Revision budget exhausted.");
            }

            /** @var PromptFeedbackEffect|null $existing */
            $existing = PromptFeedbackEffect::where('from_prompt_version_id', $promptVersion->id)->first();
            if ($existing) {
                if (
                    $existing->request_sha256 === $payload['request_sha256']
                    && $existing->provider_id === $payload['provider_id']
                    && $existing->provider_model === $payload['provider_model']
                ) {
                    $disposition = $existing->state === 'recorded' ? 'recorded' : 'reconcile';
                    return [
                        'effect_ref' => $existing->id,
                        'disposition' => $disposition,
                    ];
                }
                throw new PromptConflictException("Conflicting feedback effect already exists for this version.");
            }

            $effect = PromptFeedbackEffect::forceCreate([
                'id' => (string) Str::uuid(),
                'case_id' => $caseId,
                'section_id' => $promptVersion->section_id,
                'from_prompt_version_id' => $promptVersion->id,
                'request_sha256' => $payload['request_sha256'],
                'template_id' => $payload['template_id'],
                'template_sha256' => $payload['template_sha256'],
                'provider_id' => $payload['provider_id'],
                'provider_model' => $payload['provider_model'],
                'credential_ref' => $payload['credential_ref'],
                'state' => 'claimed',
                'send_count' => 1,
                'sent_at' => now(),
            ]);

            return [
                'effect_ref' => $effect->id,
                'disposition' => 'send_now',
            ];
        });
    }

    public function recordResult(string $caseId, string $effectRef, array $payload): array
    {
        if (($payload['schema_version'] ?? null) !== 1) {
            throw new PromptValidationException("Invalid schema_version.");
        }

        $revisedPrompt = $payload['revised_prompt'] ?? null;
        $outputSha = $payload['output_sha256'] ?? null;
        $rawJson = $payload['provider_response_json'] ?? null;
        $respSha = $payload['provider_response_sha256'] ?? null;
        $returnedModel = $payload['returned_model'] ?? null;
        $providerResponseId = $payload['provider_response_id'] ?? null;

        if (! $revisedPrompt || ! $outputSha || ! $rawJson || ! $respSha || ! $returnedModel) {
            throw new PromptValidationException("Missing required result fields.");
        }

        $revisedPrompt = trim($revisedPrompt);
        $revisedPromptBytes = strlen($revisedPrompt);
        if ($revisedPromptBytes === 0 || $revisedPromptBytes > 65536) {
            throw new PromptValidationException("Revised prompt exceeds 65536 bytes.");
        }

        if (strlen($rawJson) > 1048576) {
            throw new PromptValidationException("Provider response JSON exceeds 1 MiB.");
        }

        if (hash('sha256', $revisedPrompt) !== $outputSha) {
            throw new PromptValidationException("Output SHA mismatch.");
        }

        if (hash('sha256', $rawJson) !== $respSha) {
            throw new PromptValidationException("Provider response SHA mismatch.");
        }

        // Validate scene type
        $sceneType = null;
        foreach (self::ALLOWED_SCENE_TYPES as $st) {
            if (preg_match('/^' . preg_quote($st, '/') . '[\s:.,\-—]/i', $revisedPrompt)) {
                $sceneType = $st;
                break;
            }
        }
        if (! $sceneType) {
            throw new PromptValidationException("Revised prompt does not start with an allowed scene type.");
        }

        return DB::transaction(function () use (
            $caseId,
            $effectRef,
            $revisedPrompt,
            $outputSha,
            $rawJson,
            $respSha,
            $returnedModel,
            $providerResponseId,
            $sceneType
        ) {
            /** @var PromptFeedbackEffect|null $effect */
            $effect = PromptFeedbackEffect::where('id', $effectRef)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();
            if (! $effect) {
                throw new PromptUnavailableException("Feedback effect not found.");
            }

            if ($effect->state === 'recorded') {
                if ($effect->output_sha256 === $outputSha && $effect->response_sha256 === $respSha) {
                    $successor = ScenePromptVersion::find($effect->to_prompt_version_id);
                    return [
                        'status_code' => 200,
                        'data' => [
                            'prompt_version_ref' => $successor->id,
                            'version_no' => $successor->version_no,
                            'state' => 'awaiting_qualification',
                        ],
                    ];
                }
                throw new PromptConflictException("Conflicting feedback result replay.");
            }

            /** @var ScenePromptVersion|null $fromVersion */
            $fromVersion = ScenePromptVersion::find($effect->from_prompt_version_id);
            if (! $fromVersion) {
                throw new PromptUnavailableException("From prompt version not found.");
            }

            /** @var PromptSectionState|null $sectionState */
            $sectionState = PromptSectionState::where('section_id', $effect->section_id)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();
            if (! $sectionState || $sectionState->current_prompt_version_id !== $fromVersion->id) {
                throw new PromptUnavailableException("Prompt version is no longer current.");
            }

            $newVersionNo = $fromVersion->version_no + 1;

            // Write files to v3_private disk
            $outputPath = "prompt-bodies/{$caseId}/{$effect->section_id}/v{$newVersionNo}/{$outputSha}.txt";
            Storage::disk('v3_private')->put($outputPath, $revisedPrompt);

            $responsePath = "prompt-feedback/{$caseId}/{$effect->id}/{$respSha}.json";
            Storage::disk('v3_private')->put($responsePath, $rawJson);

            $successor = ScenePromptVersion::forceCreate([
                'id' => (string) Str::uuid(),
                'case_id' => $caseId,
                'work_item_id' => $fromVersion->work_item_id,
                'section_id' => $fromVersion->section_id,
                'version_no' => $newVersionNo,
                'predecessor_id' => $fromVersion->id,
                'origin' => 'feedback',
                'third_turn_id' => null,
                'feedback_effect_id' => $effect->id,
                'body_path' => $outputPath,
                'body_sha256' => $outputSha,
                'body_bytes' => strlen($revisedPrompt),
                'scene_type' => $sceneType,
                'section_fields_sha256' => $fromVersion->section_fields_sha256,
                'canonical_third_sha256' => $fromVersion->canonical_third_sha256,
                'created_at' => now(),
            ]);

            $effect->update([
                'state' => 'recorded',
                'output_path' => $outputPath,
                'output_sha256' => $outputSha,
                'response_path' => $responsePath,
                'response_sha256' => $respSha,
                'returned_model' => $returnedModel,
                'provider_response_id' => $providerResponseId,
                'output_bytes' => strlen($revisedPrompt),
                'to_prompt_version_id' => $successor->id,
                'recorded_at' => now(),
            ]);

            $sectionState->update([
                'current_prompt_version_id' => $successor->id,
                'revision_count' => $sectionState->revision_count + 1,
                'status' => 'awaiting_qualification',
                'error_code' => null,
            ]);

            return [
                'status_code' => 201,
                'data' => [
                    'prompt_version_ref' => $successor->id,
                    'version_no' => $successor->version_no,
                    'state' => 'awaiting_qualification',
                ],
            ];
        });
    }

    public function recordUncertain(string $caseId, string $effectRef, array $payload): array
    {
        if (($payload['schema_version'] ?? null) !== 1) {
            throw new PromptValidationException("Invalid schema_version.");
        }

        $errorCode = $payload['error_code'] ?? null;
        if (! in_array($errorCode, ['timeout', 'provider_unavailable'], true)) {
            throw new PromptValidationException("Invalid error_code '{$errorCode}'.");
        }

        return DB::transaction(function () use ($caseId, $effectRef, $errorCode) {
            /** @var PromptFeedbackEffect|null $effect */
            $effect = PromptFeedbackEffect::where('id', $effectRef)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();
            if (! $effect) {
                throw new PromptUnavailableException("Feedback effect not found.");
            }

            if ($effect->state === 'recorded') {
                throw new PromptConflictException("Feedback effect already recorded.");
            }

            $effect->update([
                'state' => 'uncertain',
                'error_code' => $errorCode,
            ]);

            /** @var PromptSectionState|null $sectionState */
            $sectionState = PromptSectionState::where('section_id', $effect->section_id)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();
            if ($sectionState) {
                $sectionState->update([
                    'status' => 'uncertain',
                    'error_code' => $errorCode,
                ]);
            }

            return [
                'state' => 'uncertain',
            ];
        });
    }
}
