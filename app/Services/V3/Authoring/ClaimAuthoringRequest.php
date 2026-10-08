<?php

namespace App\Services\V3\Authoring;

use App\Models\V3\AuthoringRequest;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptQualification;
use App\Models\V3\PromptSectionState;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\TeacherSection;
use App\Services\V3\Prompts\PromptUnavailableException;
use App\Services\V3\Prompts\QualifiedPromptResolver;
use App\Services\V3\Settings\ApprovedTemplateRepository;
use App\Services\V3\Settings\SettingsUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClaimAuthoringRequest
{
    public function __construct(
        protected QualifiedPromptResolver $promptResolver,
        protected ApprovedTemplateRepository $templateRepository
    ) {}

    /**
     * List eligible current-pass prompt refs in 009 Section ordinal order.
     *
     * @return array<int, array{section_ref: string, prompt_version_ref: string}>
     */
    public function listEligiblePrompts(string $caseId): array
    {
        if (! Str::isUuid($caseId)) {
            throw new AuthoringUnavailableException("Invalid case ID.");
        }

        return DB::transaction(function () use ($caseId) {
            $case = ProcessingCase::find($caseId);
            if (! $case) {
                throw new AuthoringUnavailableException("Case not found.");
            }

            $sectionStates = PromptSectionState::join('v3_teacher_sections', 'v3_teacher_sections.id', '=', 'v3_prompt_section_states.section_id')
                ->where('v3_prompt_section_states.case_id', $caseId)
                ->where('v3_prompt_section_states.status', 'qualified')
                ->orderBy('v3_teacher_sections.ordinal_in_work', 'asc')
                ->select(
                    'v3_prompt_section_states.section_id',
                    'v3_prompt_section_states.current_prompt_version_id'
                )
                ->get();

            $results = [];
            foreach ($sectionStates as $state) {
                if (! $state->current_prompt_version_id) {
                    continue;
                }

                $hasPass = PromptQualification::where('prompt_version_id', $state->current_prompt_version_id)
                    ->where('decision', 'pass')
                    ->exists();

                if ($hasPass) {
                    $results[] = [
                        'section_ref' => (string) $state->section_id,
                        'prompt_version_ref' => (string) $state->current_prompt_version_id,
                    ];
                }
            }

            return $results;
        });
    }

    /**
     * Claim an initial authoring request for a current qualified prompt.
     *
     * @return array{is_replay: bool, request: AuthoringRequest}
     *
     * @throws AuthoringUnavailableException
     * @throws AuthoringTemplateUnavailableException
     */
    public function claimInitial(string $caseId, string $promptVersionRef): array
    {
        if (! Str::isUuid($caseId) || ! Str::isUuid($promptVersionRef)) {
            throw new AuthoringUnavailableException("Invalid UUID format.");
        }

        return DB::transaction(function () use ($caseId, $promptVersionRef) {
            $case = ProcessingCase::where('id', $caseId)->lockForUpdate()->first();
            if (! $case) {
                throw new AuthoringUnavailableException("Case not found.");
            }

            // Check replay
            $existing = AuthoringRequest::where('case_id', $caseId)
                ->where('prompt_version_id', $promptVersionRef)
                ->where('origin', 'initial')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return [
                    'is_replay' => true,
                    'request' => $existing,
                ];
            }

            // Resolve 010 prompt current pass
            try {
                $resolved = $this->promptResolver->resolve($caseId, $promptVersionRef);
            } catch (PromptUnavailableException $e) {
                throw new AuthoringUnavailableException("Prompt unavailable: " . $e->getMessage());
            }

            $settingsVersion = $case->settingsVersion;
            if (! $settingsVersion) {
                throw new AuthoringTemplateUnavailableException("Settings version unavailable.");
            }

            $payload = $settingsVersion->payload;
            $templateId = $payload['templates']['scene']['id'] ?? null;
            $templateChecksum = $payload['templates']['scene']['checksum'] ?? null;

            if (! $templateId || ! $templateChecksum) {
                throw new AuthoringTemplateUnavailableException("Scene template configuration missing.");
            }

            try {
                $this->templateRepository->getCaptured($templateId, $templateChecksum);
            } catch (SettingsUnavailable $e) {
                throw new AuthoringTemplateUnavailableException("Scene template unavailable: " . $e->getMessage());
            }

            $codingAttempts = (int) ($payload['limits']['coding_attempts'] ?? 3);
            $codingOrder = $payload['providers']['coding_order'] ?? [];
            $attemptLimit = min($codingAttempts, count($codingOrder));
            if ($attemptLimit < 1) {
                $attemptLimit = 1;
            }

            $request = AuthoringRequest::create([
                'id' => (string) Str::uuid(),
                'case_id' => $caseId,
                'section_id' => $resolved['section_ref'],
                'prompt_version_id' => $promptVersionRef,
                'qualification_id' => $resolved['qualification_ref'],
                'prompt_body_sha256' => $resolved['body_sha256'],
                'settings_checksum' => $settingsVersion->checksum,
                'template_sha256' => $templateChecksum,
                'settings_version_id' => $settingsVersion->id,
                'template_id' => $templateId,
                'origin' => 'initial',
                'state' => 'pending',
                'state_version' => 1,
                'attempt_limit' => $attemptLimit,
            ]);

            return [
                'is_replay' => false,
                'request' => $request,
            ];
        });
    }

    /**
     * Get request authoring context for worker.
     *
     * @throws AuthoringUnavailableException
     * @throws AuthoringTemplateUnavailableException
     */
    public function getContext(string $caseId, string $requestRef): array
    {
        if (! Str::isUuid($caseId) || ! Str::isUuid($requestRef)) {
            throw new AuthoringUnavailableException("Invalid UUID format.");
        }

        $request = AuthoringRequest::where('id', $requestRef)
            ->where('case_id', $caseId)
            ->first();

        if (! $request) {
            throw new AuthoringUnavailableException("Authoring request not found.");
        }

        if ($request->state === 'stale') {
            throw new AuthoringUnavailableException("Authoring request is stale.");
        }

        // Recheck 010 prompt current pass
        try {
            $resolved = $this->promptResolver->resolve($caseId, $request->prompt_version_id);
        } catch (PromptUnavailableException $e) {
            $request->update([
                'state' => 'stale',
                'state_version' => $request->state_version + 1,
            ]);
            throw new AuthoringUnavailableException("Prompt is superseded or unavailable.");
        }

        if ($resolved['body_sha256'] !== $request->prompt_body_sha256) {
            $request->update([
                'state' => 'stale',
                'state_version' => $request->state_version + 1,
            ]);
            throw new AuthoringUnavailableException("Prompt body digest mismatch.");
        }

        // Verify template
        try {
            $templateText = $this->templateRepository->getCaptured($request->template_id, $request->template_sha256);
        } catch (SettingsUnavailable $e) {
            throw new AuthoringTemplateUnavailableException("Scene template unavailable: " . $e->getMessage());
        }

        $settingsVersion = $request->settingsVersion;
        $payload = $settingsVersion ? $settingsVersion->payload : [];
        $codingOrder = $payload['providers']['coding_order'] ?? [];
        $codingAttempts = (int) ($payload['limits']['coding_attempts'] ?? 3);

        return [
            'request_ref' => $request->id,
            'section_ref' => $request->section_id,
            'prompt_version_ref' => $request->prompt_version_id,
            'qualification_ref' => $request->qualification_id,
            'prompt' => $resolved['prompt'],
            'body_sha256' => $request->prompt_body_sha256,
            'settings_version_id' => $request->settings_version_id,
            'settings_checksum' => $request->settings_checksum,
            'template_id' => $request->template_id,
            'template_sha256' => $request->template_sha256,
            'template_text' => $templateText,
            'coding_order' => $codingOrder,
            'coding_attempts' => $codingAttempts,
        ];
    }

    public function claimRepair(string $caseId, string $repairId): AuthoringRequest
    {
        /** @var \App\Models\V3\SceneRepair|null $repair */
        $repair = \App\Models\V3\SceneRepair::where('id', $repairId)
            ->where('case_id', $caseId)
            ->first();
        if (! $repair) {
            throw new AuthoringUnavailableException("Repair row not found.");
        }

        $fromCandidate = \App\Models\V3\SceneCandidate::find($repair->from_candidate_id);
        if (! $fromCandidate) {
            throw new AuthoringUnavailableException("Predecessor candidate not found.");
        }

        $prevRequest = AuthoringRequest::find($fromCandidate->request_id);
        if (! $prevRequest) {
            throw new AuthoringUnavailableException("Predecessor authoring request not found.");
        }

        $origin = 'repair_' . $repair->ordinal;

        $request = AuthoringRequest::create([
            'id' => (string) Str::uuid(),
            'case_id' => $caseId,
            'section_id' => $repair->section_id,
            'prompt_version_id' => $repair->prompt_version_id,
            'qualification_id' => $prevRequest->qualification_id,
            'prompt_body_sha256' => $prevRequest->prompt_body_sha256,
            'settings_checksum' => $prevRequest->settings_checksum,
            'template_sha256' => $prevRequest->template_sha256,
            'settings_version_id' => $prevRequest->settings_version_id,
            'template_id' => $prevRequest->template_id,
            'origin' => $origin,
            'state' => 'pending',
            'state_version' => 1,
            'attempt_limit' => $prevRequest->attempt_limit,
        ]);

        return $request;
    }
}
