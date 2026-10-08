<?php

namespace App\Services\V3\Review;

use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptQualification;
use App\Models\V3\PromptSectionState;
use App\Models\V3\ReviewRoute;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SettingsVersion;
use App\Models\V3\TeacherPromptCorrection;
use App\Models\V3\TeacherSection;
use App\Services\V3\Prompts\PromptConflictException;
use App\Services\V3\Prompts\PromptUnavailableException;
use App\Services\V3\Prompts\PromptValidationException;
use App\Services\V3\Settings\ApprovedTemplateRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RecordTeacherPromptCorrection
{
    public const ALLOWED_SCENE_TYPES = [
        'Process',
        'Comparison',
        'Accumulation',
        'Parallel Flow',
        'Time Evolution',
    ];

    public function getContext(string $caseId, string $effectRef): array
    {
        $effect = TeacherPromptCorrection::where('id', $effectRef)
            ->where('case_id', $caseId)
            ->first();

        if (! $effect) {
            throw new PromptUnavailableException("Teacher prompt correction effect not found.");
        }

        $route = ReviewRoute::find($effect->review_route_id);
        if (! $route) {
            throw new PromptUnavailableException("Review route not found.");
        }

        $fromPromptVersion = ScenePromptVersion::where('id', $effect->from_prompt_version_id)
            ->where('case_id', $caseId)
            ->first();
        if (! $fromPromptVersion) {
            throw new PromptUnavailableException("From prompt version not found.");
        }

        $section = TeacherSection::find($effect->section_id);
        if (! $section) {
            throw new PromptUnavailableException("Section not found.");
        }

        $case = ProcessingCase::find($caseId);
        $settingsVersion = SettingsVersion::find($case->settings_version_id);
        $settingsPayload = $settingsVersion->payload ?? [];

        $secState = PromptSectionState::where('section_id', $effect->section_id)
            ->where('case_id', $caseId)
            ->first();

        // Note bytes
        $decision = $route->reviewDecision;
        $postapproval = $route->postapprovalCorrection;
        $notePath = $decision ? $decision->note_path : $postapproval?->note_path;

        if (! $notePath || ! Storage::disk('v3_private')->exists($notePath)) {
            throw new PromptUnavailableException("Teacher note file missing.");
        }
        $teacherNote = Storage::disk('v3_private')->get($notePath);
        if (hash('sha256', $teacherNote) !== $effect->note_sha256) {
            throw new PromptUnavailableException("Teacher note SHA mismatch.");
        }

        // Previous prompt bytes
        if (! Storage::disk('v3_private')->exists($fromPromptVersion->body_path)) {
            throw new PromptUnavailableException("Previous prompt body file missing.");
        }
        $previousPrompt = Storage::disk('v3_private')->get($fromPromptVersion->body_path);
        if (hash('sha256', $previousPrompt) !== $fromPromptVersion->body_sha256) {
            throw new PromptUnavailableException("Previous prompt body SHA mismatch.");
        }

        // Template bytes
        $templateRepo = app(ApprovedTemplateRepository::class);
        $templateBytes = $templateRepo->getCaptured($effect->template_id, $effect->template_sha256);

        // Previous qualification
        $qual = PromptQualification::where('prompt_version_id', $fromPromptVersion->id)->first();
        $previousQualification = $qual ? [
            'choice' => $qual->choice,
            'choice_confidence' => (float) $qual->choice_confidence,
            'score' => (float) $qual->score,
            'score_confidence' => (float) $qual->score_confidence,
            'noul_probability' => (float) $qual->noul_probability,
        ] : [
            'choice' => 'pass',
            'choice_confidence' => 0.95,
            'score' => 90.0,
            'score_confidence' => 0.95,
            'noul_probability' => 0.95,
        ];

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

        return [
            'effect_ref' => $effect->id,
            'route_ref' => $route->id,
            'section_ref' => $section->id,
            'work_ordinal' => (int) ($section->lessonNode?->work_ordinal ?? 1),
            'from_prompt_version_ref' => $fromPromptVersion->id,
            'previous_prompt' => $previousPrompt,
            'fields' => $fields,
            'teacher_note' => $teacherNote,
            'previous_qualification' => $previousQualification,
            'template_id' => $effect->template_id,
            'template_sha256' => $effect->template_sha256,
            'template_bytes' => $templateBytes,
            'provider' => [
                'id' => $effect->provider_id,
                'model' => $effect->provider_model,
                'credential_ref' => $effect->credential_ref,
            ],
            'settings_version_id' => $settingsVersion->id,
            'settings_checksum' => $settingsVersion->checksum,
            'revision_count' => $secState ? $secState->revision_count : 0,
            'revision_limit' => (int) ($settingsPayload['limits']['prompt_revisions'] ?? 2),
            'request_sha256' => $effect->request_sha256,
        ];
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

        // Validate scene type prefix
        $sceneType = null;
        foreach (self::ALLOWED_SCENE_TYPES as $st) {
            if (preg_match('/^' . preg_quote($st, '/') . '[\s:.,\-—]/i', $revisedPrompt)) {
                $sceneType = $st;
                break;
            }
        }

        return DB::transaction(function () use (
            $caseId,
            $effectRef,
            $payload,
            $revisedPrompt,
            $outputSha,
            $rawJson,
            $respSha,
            $returnedModel,
            $providerResponseId,
            $sceneType
        ) {
            $effect = TeacherPromptCorrection::where('id', $effectRef)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();

            if (! $effect) {
                throw new PromptUnavailableException("Correction effect not found.");
            }

            $route = ReviewRoute::where('id', $effect->review_route_id)->lockForUpdate()->first();

            // Known invalid output: block without successor
            if (! $sceneType) {
                $effect->update([
                    'state' => 'blocked',
                    'error_code' => 'validation',
                ]);
                $route?->update([
                    'state' => 'blocked',
                    'error_code' => 'validation',
                ]);

                $candidate = SceneCandidate::where('prompt_version_id', $effect->from_prompt_version_id)->first();
                if ($candidate) {
                    $candState = SceneCandidateState::where('candidate_id', $candidate->id)->lockForUpdate()->first();
                    $candState?->update([
                        'status' => 'blocked',
                        'safe_error_code' => 'validation',
                        'state_version' => $candState->state_version + 1,
                    ]);
                }

                return [
                    'status_code' => 422,
                    'error' => [
                        'code' => 'contract_invalid',
                        'message' => 'Revised prompt does not start with an allowed scene type.',
                    ],
                ];
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
                throw new PromptConflictException("Conflicting prompt correction result replay.");
            }

            if ($effect->state !== 'claimed') {
                throw new PromptConflictException("Correction effect is not in claimed state.");
            }

            $fromVersion = ScenePromptVersion::find($effect->from_prompt_version_id);
            if (! $fromVersion) {
                throw new PromptUnavailableException("From prompt version not found.");
            }

            $secState = PromptSectionState::where('section_id', $effect->section_id)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();

            if (! $secState || $secState->current_prompt_version_id !== $fromVersion->id) {
                throw new PromptUnavailableException("Prompt version is no longer current.");
            }

            $newVersionNo = $fromVersion->version_no + 1;

            // Write files to v3_private disk
            $outputPath = "prompt-bodies/{$caseId}/{$effect->section_id}/v{$newVersionNo}/{$outputSha}.txt";
            Storage::disk('v3_private')->put($outputPath, $revisedPrompt);

            $responsePath = "teacher-corrections/{$caseId}/{$effect->id}/{$respSha}.json";
            Storage::disk('v3_private')->put($responsePath, $rawJson);

            $successor = ScenePromptVersion::forceCreate([
                'id' => (string) Str::uuid(),
                'case_id' => $caseId,
                'work_item_id' => $fromVersion->work_item_id,
                'section_id' => $fromVersion->section_id,
                'version_no' => $newVersionNo,
                'predecessor_id' => $fromVersion->id,
                'origin' => 'teacher_correction',
                'third_turn_id' => null,
                'feedback_effect_id' => null,
                'teacher_correction_id' => $effect->id,
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
                'result_sha256' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES)),
                'output_path' => $outputPath,
                'output_sha256' => $outputSha,
                'response_path' => $responsePath,
                'response_sha256' => $respSha,
                'returned_model' => $returnedModel,
                'provider_response_id' => $providerResponseId,
                'to_prompt_version_id' => $successor->id,
                'recorded_at' => now(),
            ]);

            $secState->update([
                'current_prompt_version_id' => $successor->id,
                'revision_count' => $secState->revision_count + 1,
                'status' => 'awaiting_qualification',
                'error_code' => null,
            ]);

            $route->update([
                'state' => 'completed',
                'target_ref' => $successor->id,
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
            $effect = TeacherPromptCorrection::where('id', $effectRef)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();

            if (! $effect) {
                throw new PromptUnavailableException("Correction effect not found.");
            }

            if ($effect->state === 'recorded') {
                throw new PromptConflictException("Correction effect already recorded.");
            }

            $effect->update([
                'state' => 'uncertain',
                'error_code' => $errorCode,
            ]);

            $route = ReviewRoute::where('id', $effect->review_route_id)->lockForUpdate()->first();
            $route?->update([
                'state' => 'uncertain',
                'error_code' => $errorCode,
            ]);

            $secState = PromptSectionState::where('section_id', $effect->section_id)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();

            $secState?->update([
                'status' => 'uncertain',
                'error_code' => $errorCode,
            ]);

            return [
                'status_code' => 200,
                'data' => [
                    'state' => 'uncertain',
                ],
            ];
        });
    }
}
