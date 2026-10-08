<?php

namespace App\Services\V3\SceneQualification;

use App\Models\V3\ProcessingCase;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckRun;
use App\Models\V3\SceneQualification;
use App\Models\V3\SceneRepair;
use App\Services\V3\Prompts\PromptUnavailableException;
use App\Services\V3\Prompts\QualifiedPromptResolver;

class SceneReviewEligibility
{
    public function __construct(
        protected QualifiedPromptResolver $promptResolver
    ) {}

    public function check(string $caseId, string $candidateRef): array
    {
        $candidate = SceneCandidate::where('id', $candidateRef)
            ->where('case_id', $caseId)
            ->first();
        if (! $candidate) {
            throw new PromptUnavailableException("Candidate not found.");
        }

        $state = SceneCandidateState::where('candidate_id', $candidateRef)
            ->where('case_id', $caseId)
            ->first();
        if (! $state || $state->status !== 'qualified') {
            throw new PromptUnavailableException("Candidate is not qualified.");
        }

        // Check if candidate is current chain tip
        $hasSuccessor = SceneRepair::where('from_candidate_id', $candidateRef)
            ->whereNotNull('to_candidate_id')
            ->exists();
        if ($hasSuccessor) {
            throw new PromptUnavailableException("Candidate is not chain tip.");
        }

        // Check prompt current pass
        try {
            $this->promptResolver->resolve($caseId, $candidate->prompt_version_id);
        } catch (\Throwable $e) {
            throw new PromptUnavailableException("Prompt is no longer qualified.");
        }

        // Verify check run and qualification pass
        $checkRun = SceneCheckRun::where('candidate_id', $candidateRef)->first();
        if (! $checkRun || $checkRun->verdict !== 'pass') {
            throw new PromptUnavailableException("No passing check run.");
        }

        $qual = SceneQualification::where('candidate_id', $candidateRef)->first();
        if (! $qual || $qual->decision !== 'pass') {
            throw new PromptUnavailableException("No passing qualification.");
        }

        return [
            'candidate_ref' => $candidate->id,
            'candidate_version' => $state->version_no,
            'section_ref' => $candidate->section_id,
            'prompt_version_ref' => $candidate->prompt_version_id,
            'qualification_ref' => $qual->id,
        ];
    }
}
