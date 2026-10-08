<?php

namespace App\Services\V3\Review;

use App\Models\User;
use App\Models\V3\ApprovedScene;
use App\Models\V3\PostapprovalCorrection;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ReviewOutbox;
use App\Models\V3\ReviewPreviewSession;
use App\Models\V3\ReviewRoute;
use App\Models\V3\ReviewSectionState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SubmitPostapprovalCorrection
{
    public function submit(ProcessingCase $case, ApprovedScene $approval, array $payload, User $actor): array
    {
        // 014: Operation control fence check
        if (! app(\App\Services\V3\Admin\CaseOperationGuard::class)->isPermitted($case->id)) {
            return [
                'error' => ['code' => 'review_conflict', 'message' => 'Operation conflict: case is held or cancelled.'],
                'status' => 409,
            ];
        }

        // 1. Validate payload fields
        if (($payload['schema_version'] ?? null) !== 1) {
            return [
                'error' => ['code' => 'contract_invalid', 'message' => 'Invalid schema_version.'],
                'status' => 422,
            ];
        }

        $idempotencyKey = $payload['idempotency_key'] ?? null;
        if (! $idempotencyKey || ! Str::isUuid($idempotencyKey)) {
            return [
                'error' => ['code' => 'contract_invalid', 'message' => 'Invalid idempotency_key format.'],
                'status' => 422,
            ];
        }

        $expectedSectionVersion = $payload['expected_section_version'] ?? null;
        if (! is_int($expectedSectionVersion) || $expectedSectionVersion < 1) {
            return [
                'error' => ['code' => 'contract_invalid', 'message' => 'Invalid expected_section_version.'],
                'status' => 422,
            ];
        }

        $feedbackKind = $payload['feedback_kind'] ?? null;
        if (! in_array($feedbackKind, ['visual_interaction', 'factual_source', 'mixed'], true)) {
            return [
                'error' => ['code' => 'contract_invalid', 'message' => 'Invalid feedback_kind.'],
                'status' => 422,
            ];
        }

        $rawNotes = $payload['notes'] ?? '';
        if (! is_string($rawNotes)) {
            return [
                'error' => ['code' => 'contract_invalid', 'message' => 'Notes must be a string.'],
                'status' => 422,
            ];
        }

        $normalizedNotes = str_replace(["\r\n", "\r"], "\n", $rawNotes);
        $trimmedNotes = trim($normalizedNotes);
        $noteBytes = strlen($normalizedNotes);

        if ($trimmedNotes === '' || $noteBytes < 1 || $noteBytes > 8192) {
            return [
                'error' => ['code' => 'contract_invalid', 'message' => 'Notes must be between 1 and 8192 bytes and non-blank.'],
                'status' => 422,
            ];
        }

        // Canonical request SHA
        $canonicalData = [
            'expected_section_version' => $expectedSectionVersion,
            'feedback_kind' => $feedbackKind,
            'idempotency_key' => $idempotencyKey,
            'notes' => $normalizedNotes,
            'schema_version' => 1,
        ];
        ksort($canonicalData);
        $requestSha256 = hash('sha256', json_encode($canonicalData, JSON_UNESCAPED_SLASHES));

        // 2. Check Idempotency by idempotency_key within case
        $existing = PostapprovalCorrection::where('case_id', $case->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            if ($existing->request_sha256 === $requestSha256) {
                $route = ReviewRoute::where('postapproval_correction_id', $existing->id)->first();
                return [
                    'status' => 200,
                    'data' => [
                        'correction_ref' => $existing->id,
                        'route_ref' => $route?->id,
                        'state' => 'accepted',
                    ],
                ];
            }

            return [
                'error' => ['code' => 'review_conflict', 'message' => 'Idempotency conflict: payload mismatch.'],
                'status' => 409,
            ];
        }

        // 3. Pre-transaction checks on section state
        $secState = ReviewSectionState::where('section_id', $approval->section_id)
            ->where('case_id', $case->id)
            ->first();

        if (! $secState || $secState->current_approval_id !== $approval->id) {
            return [
                'error' => ['code' => 'review_conflict', 'message' => 'Approval is no longer section-current.'],
                'status' => 409,
            ];
        }

        if ((int) $secState->state_version !== $expectedSectionVersion) {
            return [
                'error' => ['code' => 'review_conflict', 'message' => 'Section state version mismatch.'],
                'status' => 409,
            ];
        }

        // 4. Atomic execution under transaction
        $correctionId = (string) Str::uuid();
        $noteSha = hash('sha256', $normalizedNotes);
        $notePath = "review-notes/{$case->id}/{$correctionId}/{$noteSha}.txt";

        // Stage note on v3_private disk
        Storage::disk('v3_private')->put($notePath, $normalizedNotes);

        try {
            return DB::transaction(function () use (
                $case,
                $approval,
                $actor,
                $correctionId,
                $idempotencyKey,
                $requestSha256,
                $feedbackKind,
                $notePath,
                $noteSha,
                $noteBytes,
                $expectedSectionVersion
            ) {
                $lockedSecState = ReviewSectionState::where('section_id', $approval->section_id)
                    ->where('case_id', $case->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedSecState || $lockedSecState->current_approval_id !== $approval->id) {
                    throw new \RuntimeException("Approval is no longer section-current.");
                }

                if ((int) $lockedSecState->state_version !== $expectedSectionVersion) {
                    throw new \RuntimeException("Section state version mismatch.");
                }

                $correction = PostapprovalCorrection::forceCreate([
                    'id' => $correctionId,
                    'case_id' => $case->id,
                    'section_id' => $approval->section_id,
                    'approval_id' => $approval->id,
                    'actor_user_id' => $actor->id,
                    'idempotency_key' => $idempotencyKey,
                    'request_sha256' => $requestSha256,
                    'feedback_kind' => $feedbackKind,
                    'note_path' => $notePath,
                    'note_sha256' => $noteSha,
                    'note_bytes' => $noteBytes,
                    'state' => 'accepted',
                ]);

                $routeId = (string) Str::uuid();
                $routeKind = ($feedbackKind === 'visual_interaction') ? 'scene_repair' : 'prompt_requalification';

                $route = ReviewRoute::forceCreate([
                    'id' => $routeId,
                    'case_id' => $case->id,
                    'section_id' => $approval->section_id,
                    'review_decision_id' => null,
                    'postapproval_correction_id' => $correction->id,
                    'kind' => $routeKind,
                    'state' => 'pending',
                    'target_ref' => null,
                ]);

                $workflowId = "v3-review-correction-{$correction->id}";
                ReviewOutbox::forceCreate([
                    'id' => (string) Str::uuid(),
                    'review_decision_id' => null,
                    'postapproval_correction_id' => $correction->id,
                    'operation' => 'start_correction',
                    'workflow_id' => $workflowId,
                    'run_id' => null,
                    'state' => 'pending',
                    'attempt_count' => 0,
                ]);

                // Clear current approval pointer
                $lockedSecState->current_approval_id = null;
                $lockedSecState->current_review_item_id = null;
                $lockedSecState->state = 'routing';
                $lockedSecState->state_version += 1;
                $lockedSecState->save();

                // Close active preview sessions for this candidate if any
                ReviewPreviewSession::where('candidate_id', $approval->candidate_id)
                    ->whereNull('closed_at')
                    ->update([
                        'state' => 'closed',
                        'closed_at' => now(),
                    ]);

                return [
                    'status' => 201,
                    'data' => [
                        'correction_ref' => $correction->id,
                        'route_ref' => $route->id,
                        'state' => 'accepted',
                    ],
                ];
            });
        } catch (\Throwable $e) {
            Storage::disk('v3_private')->delete($notePath);
            return [
                'error' => ['code' => 'review_conflict', 'message' => $e->getMessage()],
                'status' => 409,
            ];
        }
    }
}
