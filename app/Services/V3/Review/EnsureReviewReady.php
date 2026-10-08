<?php

namespace App\Services\V3\Review;

use App\Models\V3\ProcessingCase;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\TeacherReviewItem;
use App\Services\V3\Authoring\SceneCandidateResolver;
use App\Services\V3\Prompts\PromptUnavailableException;
use App\Services\V3\SceneQualification\SceneReviewEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnsureReviewReady
{
    public function __construct(
        protected SceneReviewEligibility $eligibilityService,
        protected SceneCandidateResolver $candidateResolver
    ) {}

    public function ensure(string $caseId, string $candidateRef): array
    {
        // 1. Verify case exists
        $case = ProcessingCase::find($caseId);
        if (! $case) {
            return ['status' => 'unavailable', 'data' => null];
        }

        // 2. Verify 012 eligibility proof
        try {
            $eligibility = $this->eligibilityService->check($caseId, $candidateRef);
        } catch (\Throwable $e) {
            return ['status' => 'unavailable', 'data' => null];
        }

        // 3. Verify candidate files / prompt package integrity
        try {
            $this->candidateResolver->resolve($caseId, $candidateRef);
        } catch (\Throwable $e) {
            return ['status' => 'unavailable', 'data' => null];
        }

        $sectionRef = $eligibility['section_ref'];
        $candidateVersion = (int) $eligibility['candidate_version'];
        $promptVersionRef = $eligibility['prompt_version_ref'];
        $qualificationRef = $eligibility['qualification_ref'];

        return DB::transaction(function () use ($caseId, $candidateRef, $sectionRef, $candidateVersion, $promptVersionRef, $qualificationRef) {
            // Check if item already exists for this exact candidate
            $existing = TeacherReviewItem::where('candidate_id', $candidateRef)->first();
            if ($existing) {
                return [
                    'status' => 'existing',
                    'data' => [
                        'review_ref' => $existing->id,
                        'section_ref' => $existing->section_id,
                        'candidate_ref' => $existing->candidate_id,
                        'candidate_version' => $existing->candidate_version,
                    ],
                ];
            }

            // Lock or create ReviewSectionState
            $sectionState = ReviewSectionState::where('section_id', $sectionRef)->lockForUpdate()->first();
            if (! $sectionState) {
                $sectionState = ReviewSectionState::create([
                    'section_id' => $sectionRef,
                    'case_id' => $caseId,
                    'current_review_item_id' => null,
                    'current_approval_id' => null,
                    'state' => 'awaiting_qualification',
                    'state_version' => 1,
                ]);
            }

            // If section already has a current pending item for a different candidate, stale it
            if ($sectionState->current_review_item_id) {
                TeacherReviewItem::where('id', $sectionState->current_review_item_id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'stale',
                    ]);
            }

            // Create new review item
            $newItem = TeacherReviewItem::create([
                'id' => (string) Str::uuid(),
                'case_id' => $caseId,
                'section_id' => $sectionRef,
                'candidate_id' => $candidateRef,
                'qualification_id' => $qualificationRef,
                'prompt_version_id' => $promptVersionRef,
                'candidate_version' => $candidateVersion,
                'status' => 'pending',
                'state_version' => 1,
                'ready_at' => now(),
            ]);

            // Update ReviewSectionState: pointer points to new item, clear current approval pointer
            $sectionState->update([
                'current_review_item_id' => $newItem->id,
                'current_approval_id' => null,
                'state' => 'awaiting_review',
                'state_version' => $sectionState->state_version + 1,
                'error_code' => null,
            ]);

            return [
                'status' => 'created',
                'data' => [
                    'review_ref' => $newItem->id,
                    'section_ref' => $newItem->section_id,
                    'candidate_ref' => $newItem->candidate_id,
                    'candidate_version' => $newItem->candidate_version,
                ],
            ];
        });
    }

    public function reconcileAllPassing(): int
    {
        $qualifiedCandidates = SceneCandidateState::where('status', 'qualified')
            ->select('case_id', 'candidate_id')
            ->get();

        $count = 0;
        foreach ($qualifiedCandidates as $row) {
            $existing = TeacherReviewItem::where('candidate_id', $row->candidate_id)->exists();
            if (! $existing) {
                $res = $this->ensure($row->case_id, $row->candidate_id);
                if ($res['status'] === 'created') {
                    $count++;
                }
            }
        }

        return $count;
    }
}
