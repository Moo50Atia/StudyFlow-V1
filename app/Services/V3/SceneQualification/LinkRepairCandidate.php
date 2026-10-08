<?php

namespace App\Services\V3\SceneQualification;

use App\Models\V3\ProcessingCase;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneRepair;
use App\Services\V3\Prompts\PromptConflictException;
use App\Services\V3\Prompts\PromptUnavailableException;
use App\Services\V3\Prompts\PromptValidationException;
use Illuminate\Support\Facades\DB;

class LinkRepairCandidate
{
    public function link(string $caseId, string $repairRef, array $data): array
    {
        $candidateRef = $data['candidate_ref'] ?? null;
        if (! $candidateRef) {
            throw new PromptValidationException("Missing candidate_ref.");
        }

        return DB::transaction(function () use ($caseId, $repairRef, $candidateRef) {
            /** @var ProcessingCase|null $case */
            $case = ProcessingCase::find($caseId);
            if (! $case) {
                throw new PromptUnavailableException("Case not found.");
            }

            /** @var SceneRepair|null $repair */
            $repair = SceneRepair::where('id', $repairRef)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();
            if (! $repair) {
                throw new PromptUnavailableException("Repair not found.");
            }

            /** @var SceneCandidate|null $candidate */
            $candidate = SceneCandidate::where('id', $candidateRef)
                ->where('case_id', $caseId)
                ->first();
            if (! $candidate) {
                throw new PromptUnavailableException("Candidate not found.");
            }

            // Verify the same repair request created that 011 candidate
            if ($candidate->request_id !== $repair->authoring_request_id) {
                throw new PromptConflictException("Candidate was not produced by this repair authoring request.");
            }

            // Check replay
            if ($repair->to_candidate_id) {
                if ($repair->to_candidate_id === $candidateRef) {
                    $cState = SceneCandidateState::where('candidate_id', $candidateRef)->first();
                    return [
                        'status_code' => 200,
                        'data' => [
                            'repair_ref' => $repair->id,
                            'candidate_ref' => $candidate->id,
                            'candidate_version' => $cState?->version_no ?? 2,
                            'state' => 'candidate_ready',
                        ],
                    ];
                }
                throw new PromptConflictException("Conflicting candidate already linked to repair.");
            }

            /** @var SceneCandidateState|null $fromState */
            $fromState = SceneCandidateState::where('candidate_id', $repair->from_candidate_id)
                ->lockForUpdate()
                ->first();
            if (! $fromState) {
                throw new PromptUnavailableException("Predecessor candidate state not found.");
            }

            $newVersionNo = $fromState->version_no + 1;

            // Transition predecessor to superseded
            $fromState->update([
                'status' => 'superseded',
                'state_version' => $fromState->state_version + 1,
            ]);

            // Link repair
            $repair->update([
                'to_candidate_id' => $candidate->id,
                'state' => 'candidate_ready',
                'candidate_ready_at' => now(),
            ]);

            // Create candidate state for new repaired candidate
            SceneCandidateState::forceCreate([
                'candidate_id' => $candidate->id,
                'root_candidate_id' => $repair->root_candidate_id,
                'case_id' => $caseId,
                'section_id' => $repair->section_id,
                'prompt_version_id' => $repair->prompt_version_id,
                'version_no' => $newVersionNo,
                'status' => 'awaiting_check',
                'check_run_id' => null,
                'qualification_id' => null,
                'safe_error_code' => null,
                'state_version' => 1,
            ]);

            return [
                'status_code' => 201,
                'data' => [
                    'repair_ref' => $repair->id,
                    'candidate_ref' => $candidate->id,
                    'candidate_version' => $newVersionNo,
                    'state' => 'candidate_ready',
                ],
            ];
        });
    }
}
