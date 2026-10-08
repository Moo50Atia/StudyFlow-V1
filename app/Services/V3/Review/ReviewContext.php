<?php

namespace App\Services\V3\Review;

use App\Models\V3\ApprovedScene;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ReviewPreviewSession;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\TeacherReviewItem;
use App\Services\V3\Authoring\SceneCandidateResolver;
use App\Services\V3\SceneQualification\SceneReviewEligibility;

class ReviewContext
{
    public function __construct(
        protected SceneReviewEligibility $eligibilityService,
        protected SceneCandidateResolver $candidateResolver
    ) {}

    public function resolve(ProcessingCase $case, string $reviewId): ?array
    {
        /** @var TeacherReviewItem|null $reviewItem */
        $reviewItem = TeacherReviewItem::with(['teacherSection', 'scenePromptVersion'])
            ->where('id', $reviewId)
            ->where('case_id', $case->id)
            ->first();

        if (! $reviewItem) {
            return null;
        }

        // Determine if item is viewable:
        // Pending or dormant item
        // OR decided approved item while its approval remains Section-current
        $sectionState = ReviewSectionState::where('section_id', $reviewItem->section_id)->first();
        if (! $sectionState) {
            return null;
        }

        if (in_array($reviewItem->status, ['pending', 'dormant'], true)) {
            // Must be current review item for the section
            if ($sectionState->current_review_item_id !== $reviewItem->id) {
                return null;
            }
        } elseif ($reviewItem->status === 'decided') {
            // Must have a current approval on the section pointing to this candidate
            if (! $sectionState->current_approval_id) {
                return null;
            }
            $currentApproval = ApprovedScene::find($sectionState->current_approval_id);
            if (! $currentApproval || $currentApproval->candidate_id !== $reviewItem->candidate_id) {
                return null;
            }
        } else {
            // Stale or invalid status
            return null;
        }

        // Recheck candidate file integrity
        try {
            $this->candidateResolver->resolve($case->id, $reviewItem->candidate_id);
        } catch (\Throwable $e) {
            return null;
        }

        // Recheck 012 qualification proof
        try {
            $this->eligibilityService->check($case->id, $reviewItem->candidate_id);
        } catch (\Throwable $e) {
            return null;
        }

        // Read and re-verify prompt body sha256 from private disk
        $promptVersion = $reviewItem->scenePromptVersion;
        if (! $promptVersion) {
            return null;
        }
        $disk = \Illuminate\Support\Facades\Storage::disk('v3_private');
        if (! $disk->exists($promptVersion->body_path)) {
            return null;
        }
        $promptBody = $disk->get($promptVersion->body_path);
        if (hash('sha256', $promptBody) !== $promptVersion->body_sha256) {
            return null;
        }

        // If pending/dormant, record private last_opened_at
        if (in_array($reviewItem->status, ['pending', 'dormant'], true)) {
            $reviewItem->update(['last_opened_at' => now()]);
        }

        $section = $reviewItem->teacherSection;
        if (! $section) {
            return null;
        }

        // Check active preview session
        $previewSession = ReviewPreviewSession::where('review_item_id', $reviewItem->id)
            ->whereIn('state', ['starting', 'ready'])
            ->latest('created_at')
            ->first();
        $currentApproval = $reviewItem->status === 'decided'
            ? ApprovedScene::where('section_id', $reviewItem->section_id)->where('candidate_id', $reviewItem->candidate_id)->first()
            : null;

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
            'laws_if_any' => $section->laws_if_any ?? '',
            'real_life_example' => (string) $section->real_life_example,
        ];

        return [
            'review_ref' => $reviewItem->id,
            'section_ref' => $reviewItem->section_id,
            'candidate_ref' => $reviewItem->candidate_id,
            'candidate_version' => (int) $reviewItem->candidate_version,
            'qualification_ref' => $reviewItem->qualification_id,
            'prompt_version_ref' => $reviewItem->prompt_version_id,
            'prompt' => $promptBody,
            'fields' => $fields,
            'review_state' => $reviewItem->status,
            'review_state_version' => (int) $reviewItem->state_version,
            'preview_state' => $previewSession?->state ?? null,
            'approval_ref' => $currentApproval?->id,
            'section_state_version' => (int) $sectionState->state_version,
        ];
    }
}
