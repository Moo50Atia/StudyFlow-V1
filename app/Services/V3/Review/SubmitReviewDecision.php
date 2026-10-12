<?php

namespace App\Services\V3\Review;

use App\Models\User;
use App\Models\V3\ApprovedScene;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ReviewOutbox;
use App\Models\V3\ReviewPreviewSession;
use App\Models\V3\ReviewRoute;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\SceneRepair;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\TeacherReviewDecision;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\TeacherPromptCorrection;
use App\Models\V3\WorkflowLink;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\V3\Workflow\BridgeRequestSigner;


class SubmitReviewDecision
{
    public const ALLOWED_DECISIONS = ['approve', 'request_changes'];
    public const ALLOWED_FEEDBACK_KINDS = ['visual_interaction', 'factual_source', 'mixed'];

    /**
     * Submit an owner review decision (approve or request_changes).
     *
     * @return array{data?: array, error?: array{code: string, message: string}, status: int}
     */
    public function submit(
        ProcessingCase $case,
        TeacherReviewItem $reviewItem,
        array $input,
        User $actor
    ): array {
        if ($reviewItem->case_id !== $case->id) {
            return [
                'error' => ['code' => 'resource_unavailable', 'message' => 'Review item not found.'],
                'status' => 404,
            ];
        }

        // 014: Operation control fence check
        if (! app(\App\Services\V3\Admin\CaseOperationGuard::class)->isPermitted($case->id)) {
            return [
                'error' => ['code' => 'review_conflict', 'message' => 'Operation conflict: case is held or cancelled.'],
                'status' => 409,
            ];
        }

        // 1. Validate ReviewDecisionV1 contract
        $schemaVersion = $input['schema_version'] ?? null;
        $idempotencyKey = $input['idempotency_key'] ?? null;
        $expectedReviewVersion = $input['expected_review_version'] ?? null;
        $candidateRef = $input['candidate_ref'] ?? null;
        $candidateVersion = $input['candidate_version'] ?? null;
        $decision = $input['decision'] ?? null;
        $feedbackKind = $input['feedback_kind'] ?? null;
        $notes = $input['notes'] ?? null;

        if (
            $schemaVersion !== 1
            || ! is_string($idempotencyKey) || ! Str::isUuid($idempotencyKey)
            || ! is_int($expectedReviewVersion) || $expectedReviewVersion < 1
            || ! is_string($candidateRef) || ! Str::isUuid($candidateRef)
            || ! is_int($candidateVersion) || $candidateVersion < 1
            || ! is_string($decision) || ! in_array($decision, self::ALLOWED_DECISIONS, true)
        ) {
            return [
                'error' => ['code' => 'contract_invalid', 'message' => 'Invalid review decision contract.'],
                'status' => 422,
            ];
        }

        // Decision-specific validation
        $normalizedNotes = null;
        if ($decision === 'approve') {
            if ($feedbackKind !== null || $notes !== null) {
                return [
                    'error' => ['code' => 'contract_invalid', 'message' => 'Approve decision must not include feedback_kind or notes.'],
                    'status' => 422,
                ];
            }

            // R11: Requires verified outward GET frame 0 proof
            $hasFrameProof = ReviewPreviewSession::where('review_item_id', $reviewItem->id)
                ->where('candidate_id', $candidateRef)
                ->whereNotNull('first_frame_at')
                ->exists();

            if (! $hasFrameProof) {
                return [
                    'error' => ['code' => 'review_conflict', 'message' => 'Approve requires successful outward frame 0 preview.'],
                    'status' => 409,
                ];
            }
        } elseif ($decision === 'request_changes') {
            if (! is_string($feedbackKind) || ! in_array($feedbackKind, self::ALLOWED_FEEDBACK_KINDS, true)) {
                return [
                    'error' => ['code' => 'contract_invalid', 'message' => 'Request changes requires valid feedback_kind.'],
                    'status' => 422,
                ];
            }

            if (! is_string($notes) || trim($notes) === '' || strlen($notes) > 8192) {
                return [
                    'error' => ['code' => 'contract_invalid', 'message' => 'Request changes requires nonblank notes <= 8192 bytes.'],
                    'status' => 422,
                ];
            }

            // LF normalization
            $normalizedNotes = str_replace(["\r\n", "\r"], "\n", $notes);
        }

        // Canonical request sha256
        $canonicalData = [
            'candidate_ref' => $candidateRef,
            'candidate_version' => $candidateVersion,
            'decision' => $decision,
            'expected_review_version' => $expectedReviewVersion,
            'feedback_kind' => $feedbackKind,
            'idempotency_key' => $idempotencyKey,
            'notes' => $normalizedNotes,
            'schema_version' => 1,
        ];
        ksort($canonicalData);
        $requestSha256 = hash('sha256', json_encode($canonicalData, JSON_UNESCAPED_SLASHES));

        // 2. Check Idempotency by idempotency_key within case
        $existing = TeacherReviewDecision::where('case_id', $case->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            if ($existing->request_sha256 === $requestSha256) {
                // Exact replay
                $routeRef = null;
                $approvalRef = null;
                if ($existing->decision === 'approve') {
                    $approval = ApprovedScene::where('review_decision_id', $existing->id)->first();
                    $approvalRef = $approval?->id;
                } else {
                    $route = ReviewRoute::where('review_decision_id', $existing->id)->first();
                    $routeRef = $route?->id;
                }

                return [
                    'data' => [
                        'decision_ref' => $existing->id,
                        'review_ref' => $existing->review_item_id,
                        'decision' => $existing->decision,
                        'route_ref' => $routeRef,
                        'approval_ref' => $approvalRef,
                        'state' => 'recorded',
                    ],
                    'status' => 200,
                ];
            }

            // Changed same-key body
            return [
                'error' => ['code' => 'review_conflict', 'message' => 'Idempotency conflict: payload mismatch.'],
                'status' => 409,
            ];
        }

        // 3. Pre-transaction checks on review item
        if ($reviewItem->status !== 'pending' && $reviewItem->status !== 'dormant') {
            return [
                'error' => ['code' => 'review_conflict', 'message' => 'Review item is already decided or stale.'],
                'status' => 409,
            ];
        }

        if ((int) $reviewItem->state_version !== $expectedReviewVersion) {
            return [
                'error' => ['code' => 'review_conflict', 'message' => 'Review state version mismatch.'],
                'status' => 409,
            ];
        }

        if ($reviewItem->candidate_id !== $candidateRef || (int) $reviewItem->candidate_version !== $candidateVersion) {
            return [
                'error' => ['code' => 'review_conflict', 'message' => 'Candidate mismatch.'],
                'status' => 409,
            ];
        }

        // Recheck 012 qualified proof
        $candState = SceneCandidateState::where('candidate_id', $candidateRef)->first();
        if (! $candState || ! in_array($candState->status, ['qualified', 'demo_review_ready'], true)) {
            return [
                'error' => ['code' => 'review_conflict', 'message' => 'Candidate is not qualified.'],
                'status' => 409,
            ];
        }

        $eligibility = app(\App\Services\V3\SceneQualification\SceneReviewEligibility::class)->check($case->id, $candidateRef);
        if (! ($eligibility['eligible'] ?? false)) {
            return [
                'error' => ['code' => 'review_conflict', 'message' => 'Candidate is not eligible for review.'],
                'status' => 409,
            ];
        }

        $closedSessionIds = [];

        // 4. Atomic Transaction
        $result = DB::transaction(function () use (
            $case,
            $reviewItem,
            $actor,
            $candidateRef,
            $candidateVersion,
            $expectedReviewVersion,
            $idempotencyKey,
            $requestSha256,
            $decision,
            $feedbackKind,
            $normalizedNotes,
            &$closedSessionIds
        ) {
            // Lock review item and section state
            $lockedItem = TeacherReviewItem::where('id', $reviewItem->id)->lockForUpdate()->first();
            $secState = ReviewSectionState::where('section_id', $lockedItem->section_id)->lockForUpdate()->first();

            if (! app(\App\Services\V3\Admin\CaseOperationGuard::class)->isPermitted($case->id)) {
                throw new \DomainException('review_conflict: case is held or cancelled');
            }

            if (! $lockedItem || ($lockedItem->status !== 'pending' && $lockedItem->status !== 'dormant')) {
                throw new \DomainException('review_conflict: already decided');
            }

            $decisionId = (string) Str::uuid();
            $notePath = null;
            $noteSha256 = null;
            $noteBytes = null;

            if ($decision === 'request_changes' && $normalizedNotes !== null) {
                $notePath = "review-notes/{$case->id}/{$decisionId}.txt";
                Storage::disk('v3_private')->put($notePath, $normalizedNotes);
                $noteSha256 = hash('sha256', $normalizedNotes);
                $noteBytes = strlen($normalizedNotes);
            }

            $basis = $lockedItem->eligibility_basis ?? ($eligibility['basis'] ?? 'real_jev');
            $gateId = $lockedItem->qualification_gate_id ?? ($eligibility['gate_id'] ?? null);

            $decisionRow = TeacherReviewDecision::forceCreate([
                'id' => $decisionId,
                'review_item_id' => $lockedItem->id,
                'case_id' => $case->id,
                'section_id' => $lockedItem->section_id,
                'candidate_id' => $candidateRef,
                'qualification_id' => $lockedItem->qualification_id,
                'actor_user_id' => $actor->id,
                'candidate_version' => $candidateVersion,
                'expected_review_version' => $expectedReviewVersion,
                'idempotency_key' => $idempotencyKey,
                'request_sha256' => $requestSha256,
                'decision' => $decision,
                'feedback_kind' => $feedbackKind,
                'note_path' => $notePath,
                'note_sha256' => $noteSha256,
                'note_bytes' => $noteBytes,
                'created_at' => now(),
                'eligibility_basis' => $basis,
                'qualification_gate_id' => $gateId,
            ]);

            $approvalRef = null;
            $routeRef = null;

            if ($decision === 'approve') {
                $approvalId = (string) Str::uuid();
                ApprovedScene::forceCreate([
                    'id' => $approvalId,
                    'case_id' => $case->id,
                    'section_id' => $lockedItem->section_id,
                    'candidate_id' => $candidateRef,
                    'qualification_id' => $lockedItem->qualification_id,
                    'review_decision_id' => $decisionId,
                    'candidate_version' => $candidateVersion,
                    'approved_at' => now(),
                    'eligibility_basis' => $basis,
                    'qualification_gate_id' => $gateId,
                ]);
                $approvalRef = $approvalId;

                $secState->current_approval_id = $approvalId;
                $secState->current_review_item_id = null;
                $secState->state = 'approved';
                $secState->state_version += 1;
                $secState->save();
            } else {
                $routeId = (string) Str::uuid();
                $routeKind = ($feedbackKind === 'visual_interaction') ? 'scene_repair' : 'prompt_requalification';
                ReviewRoute::forceCreate([
                    'id' => $routeId,
                    'case_id' => $case->id,
                    'section_id' => $lockedItem->section_id,
                    'review_decision_id' => $decisionId,
                    'postapproval_correction_id' => null,
                    'kind' => $routeKind,
                    'state' => 'pending',
                    'target_ref' => null,
                ]);
                $routeRef = $routeId;

                $secState->current_review_item_id = null;
                $secState->state = 'routing';
                $secState->state_version += 1;
                $secState->save();
            }

            $lockedItem->status = 'decided';
            $lockedItem->decided_at = now();
            $lockedItem->state_version += 1;
            $lockedItem->save();

            // Outbox entry
            $link = WorkflowLink::where('case_id', $case->id)->first();
            // Review successors produced from either a first-pass teacher
            // route or a postapproval correction are decided by their durable
            // correction workflow. Ordinary first-pass review items remain on
            // the case workflow.
            $repair = SceneRepair::where('to_candidate_id', $lockedItem->candidate_id)->first();
            $sceneRouteId = $repair?->teacher_review_route_id;
            $promptRouteId = TeacherPromptCorrection::where('to_prompt_version_id', $lockedItem->prompt_version_id)
                ->value('review_route_id');
            $successorRoute = ReviewRoute::find($sceneRouteId ?: $promptRouteId);
            $routeWorkflowRef = $successorRoute
                ? ($successorRoute->postapproval_correction_id ?: $successorRoute->id)
                : null;
            $isApplicationRun = $case->subject_run_item_id
                && DB::table('v3_subject_run_items as item')
                    ->join('v3_subject_runs as run', 'run.id', '=', 'item.run_id')
                    ->where('item.id', $case->subject_run_item_id)
                    ->whereIn('run.origin', ['web', 'historical_import'])
                    ->exists();
            $workflowId = $routeWorkflowRef
                ? "v3-review-correction-{$routeWorkflowRef}"
                : ($link ? $link->workflow_id
                    : ($isApplicationRun ? "v3-web-review-{$lockedItem->id}" : "v3-case-{$case->id}"));

            ReviewOutbox::forceCreate([
                'id' => (string) Str::uuid(),
                'review_decision_id' => $decisionId,
                'postapproval_correction_id' => null,
                'operation' => 'signal_decision',
                'workflow_id' => $workflowId,
                'run_id' => $link?->run_id,
                'state' => 'pending',
                'attempt_count' => 0,
            ]);

            // Close active preview sessions
            $activeSessions = ReviewPreviewSession::where('review_item_id', $lockedItem->id)
                ->whereNull('closed_at')
                ->get();

            foreach ($activeSessions as $s) {
                $closedSessionIds[] = $s->id;
                $s->state = 'closed';
                $s->closed_at = now();
                $s->save();
            }

            return [
                'data' => [
                    'decision_ref' => $decisionId,
                    'review_ref' => $lockedItem->id,
                    'decision' => $decision,
                    'route_ref' => $routeRef,
                    'approval_ref' => $approvalRef,
                    'state' => 'recorded',
                ],
                'status' => 201,
            ];
        });

        // Cleanup closed sessions on bridge after commit
        foreach ($closedSessionIds as $sessId) {
            Cache::forget("v3_preview_meta_{$sessId}");
            try {
                $bridgeUrl = config('services.v3.bridge_url', 'http://127.0.0.1:8765');
                $bridgePath = "/internal/v1/review-preview/sessions/{$sessId}";
                $headers = BridgeRequestSigner::signBridgeRequest('DELETE', $bridgePath, []);
                Http::withHeaders($headers)->timeout(2)->delete(rtrim($bridgeUrl, '/') . $bridgePath);
            } catch (\Throwable) {}
        }

        return $result;
    }
}
