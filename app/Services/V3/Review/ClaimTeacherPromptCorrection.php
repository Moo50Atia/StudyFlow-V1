<?php

namespace App\Services\V3\Review;

use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptSectionState;
use App\Models\V3\ReviewRoute;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SettingsVersion;
use App\Models\V3\TeacherPromptCorrection;
use App\Services\V3\Prompts\PromptConflictException;
use App\Services\V3\Prompts\PromptUnavailableException;
use App\Services\V3\Prompts\PromptValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClaimTeacherPromptCorrection
{
    public function claimFromTeacherRoute(string $caseId, string $routeRef): array
    {
        return DB::transaction(function () use ($caseId, $routeRef) {
            $route = ReviewRoute::where('id', $routeRef)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();

            if (! $route) {
                throw new PromptUnavailableException("Review route not found.");
            }

            if ($route->kind !== 'prompt_requalification') {
                throw new PromptValidationException("Review route is not a prompt_requalification route.");
            }

            if ($route->state === 'blocked') {
                return [
                    'status_code' => 409,
                    'error' => 'Review route is blocked.',
                ];
            }

            $decision = $route->reviewDecision;
            $postapproval = $route->postapprovalCorrection;

            if ($decision) {
                $candidateId = $decision->candidate_id;
                $noteSha = $decision->note_sha256;
            } elseif ($postapproval) {
                $candidateId = $postapproval->approval->candidate_id;
                $noteSha = $postapproval->note_sha256;
            } else {
                throw new PromptUnavailableException("No review decision or correction linked to route.");
            }

            $candidate = SceneCandidate::where('id', $candidateId)->where('case_id', $caseId)->first();
            if (! $candidate) {
                throw new PromptUnavailableException("Candidate not found.");
            }

            $fromPromptVersion = ScenePromptVersion::where('id', $candidate->prompt_version_id)
                ->where('case_id', $caseId)
                ->first();
            if (! $fromPromptVersion) {
                throw new PromptUnavailableException("Prompt version not found.");
            }

            $case = ProcessingCase::find($caseId);
            $settings = SettingsVersion::find($case->settings_version_id);
            $settingsPayload = $settings->payload ?? [];
            $revisionLimit = (int) ($settingsPayload['limits']['prompt_revisions'] ?? 2);
            $templateConfig = $settingsPayload['templates']['feedback'] ?? null;
            $providerConfig = $settingsPayload['providers']['feedback'] ?? null;

            $secState = PromptSectionState::where('section_id', $fromPromptVersion->section_id)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();

            $candState = SceneCandidateState::where('candidate_id', $candidateId)
                ->lockForUpdate()
                ->first();

            // Check budget and required template/provider
            if (
                $revisionLimit === 0
                || ($secState && $secState->revision_count >= $revisionLimit)
                || empty($templateConfig['id'])
                || empty($templateConfig['checksum'])
                || empty($providerConfig['id'])
            ) {
                $route->update([
                    'state' => 'blocked',
                    'error_code' => 'validation',
                ]);
                $candState?->update([
                    'status' => 'blocked',
                    'safe_error_code' => 'validation',
                    'state_version' => $candState->state_version + 1,
                ]);

                return [
                    'status_code' => 409,
                    'error' => 'Prompt revision budget exhausted or template missing.',
                ];
            }

            // Existing effect on this route
            $existing = TeacherPromptCorrection::where('review_route_id', $route->id)->first();
            if ($existing) {
                $disposition = ($existing->state === 'recorded') ? 'recorded' : 'reconcile';
                return [
                    'status_code' => 200,
                    'data' => [
                        'effect_ref' => $existing->id,
                        'disposition' => $disposition,
                    ],
                ];
            }

            // Check if another effect already linked to from_prompt_version_id
            $competing = TeacherPromptCorrection::where('from_prompt_version_id', $fromPromptVersion->id)->first();
            if ($competing) {
                throw new PromptConflictException("A correction effect already exists for this prompt version.");
            }

            $templateSha = $templateConfig['checksum'];
            $providerId = $providerConfig['id'];
            $providerModel = $providerConfig['model'];
            $credentialRef = $providerConfig['credential_ref'];
            $settingsChecksum = $settings->checksum;

            $payloadDigest = "013-teacher-correction-v1\n"
                . $route->id . "\n"
                . $fromPromptVersion->id . "\n"
                . $fromPromptVersion->body_sha256 . "\n"
                . $noteSha . "\n"
                . $templateSha . "\n"
                . $providerId . "\n"
                . $providerModel . "\n"
                . $credentialRef . "\n"
                . $settingsChecksum;

            $requestSha = hash('sha256', $payloadDigest);

            $effect = TeacherPromptCorrection::forceCreate([
                'id' => (string) Str::uuid(),
                'review_route_id' => $route->id,
                'case_id' => $caseId,
                'section_id' => $fromPromptVersion->section_id,
                'from_prompt_version_id' => $fromPromptVersion->id,
                'request_sha256' => $requestSha,
                'template_sha256' => $templateSha,
                'note_sha256' => $noteSha,
                'template_id' => $templateConfig['id'],
                'provider_id' => $providerId,
                'provider_model' => $providerModel,
                'credential_ref' => $credentialRef,
                'state' => 'claimed',
                'send_count' => 1,
                'sent_at' => now(),
            ]);

            $route->update([
                'state' => 'claimed',
                'target_ref' => $effect->id,
            ]);

            return [
                'status_code' => 201,
                'data' => [
                    'effect_ref' => $effect->id,
                    'disposition' => 'send_now',
                ],
            ];
        });
    }
}
