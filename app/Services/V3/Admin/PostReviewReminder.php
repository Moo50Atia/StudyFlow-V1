<?php

namespace App\Services\V3\Admin;

use App\Models\V3\AdminOperationAudit;
use App\Models\V3\CaseOperationControl;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ReviewReminder;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\TeacherReviewItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PostReviewReminder
{
    public function canonicalizePayload(array $input): array
    {
        $allowedKeys = ['schema_version', 'idempotency_key', 'expected_review_version'];
        foreach (array_keys($input) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                throw new \InvalidArgumentException('Invalid operation request.');
            }
        }

        if (($input['schema_version'] ?? null) !== 1) {
            throw new \InvalidArgumentException('Invalid operation request.');
        }

        $idempotencyKey = $input['idempotency_key'] ?? '';
        if (! is_string($idempotencyKey) || ! Str::isUuid($idempotencyKey)) {
            throw new \InvalidArgumentException('Invalid operation request.');
        }

        $expectedVersion = $input['expected_review_version'] ?? null;
        if (! is_int($expectedVersion) || $expectedVersion < 1) {
            throw new \InvalidArgumentException('Invalid operation request.');
        }

        return [
            'schema_version' => 1,
            'idempotency_key' => strtolower($idempotencyKey),
            'expected_review_version' => $expectedVersion,
        ];
    }

    public function post(string $caseId, string $reviewId, int $actorUserId, array $input): array
    {
        $canonical = $this->canonicalizePayload($input);
        $requestSha256 = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES));

        // 1. Check existing reminder by (case_id, idempotency_key)
        $existing = ReviewReminder::where('case_id', $caseId)
            ->where('idempotency_key', $canonical['idempotency_key'])
            ->first();

        if ($existing) {
            if ($existing->request_sha256 !== $requestSha256) {
                throw new \DomainException('Operation changed. Reload and retry.');
            }

            return [
                'replay' => true,
                'reminder_ref' => (string) $existing->id,
                'state' => $existing->state,
            ];
        }

        // 2. Lock and mutate in transaction
        return DB::transaction(function () use ($caseId, $reviewId, $actorUserId, $canonical, $requestSha256) {
            $case = ProcessingCase::where('id', $caseId)->lockForUpdate()->first();
            if (! $case) {
                throw new ModelNotFoundException('Case not found.');
            }

            $control = CaseOperationControl::where('case_id', $caseId)->lockForUpdate()->first();

            $reviewItem = TeacherReviewItem::where('id', $reviewId)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();

            if (! $reviewItem) {
                throw new ModelNotFoundException('Review item not found.');
            }

            // Verify Section-current
            $sectionState = ReviewSectionState::where('case_id', $caseId)
                ->where('section_id', $reviewItem->section_id)
                ->lockForUpdate()
                ->first();

            if (! $sectionState || $sectionState->current_review_item_id !== $reviewItem->id) {
                throw new ModelNotFoundException('Review item is not Section-current.');
            }

            // Verify status dormant
            if ($reviewItem->status !== 'dormant') {
                throw new \DomainException('Operation changed. Reload and retry.');
            }

            // Verify expected version
            if ((int) $reviewItem->state_version !== $canonical['expected_review_version']) {
                throw new \DomainException('Operation changed. Reload and retry.');
            }

            // Check if reminder already exists for this review item
            $existingForItem = ReviewReminder::where('review_item_id', $reviewItem->id)->first();
            if ($existingForItem) {
                throw new \DomainException('Operation changed. Reload and retry.');
            }

            $reminderId = (string) Str::uuid();
            $reminder = ReviewReminder::create([
                'id' => $reminderId,
                'case_id' => $caseId,
                'review_item_id' => $reviewItem->id,
                'actor_user_id' => $actorUserId,
                'idempotency_key' => $canonical['idempotency_key'],
                'request_sha256' => $requestSha256,
                'state' => 'posted',
                'posted_at' => now(),
            ]);

            AdminOperationAudit::create([
                'id' => (string) Str::uuid(),
                'case_id' => $caseId,
                'actor_user_id' => $actorUserId,
                'source_kind' => 'reminder',
                'source_ref' => $reminderId,
                'event' => 'reminder_posted',
                'action_code' => 'remind',
                'reason_code' => null,
                'safe_denial_code' => null,
                'old_control_mode' => $control?->mode ?? 'normal',
                'new_control_mode' => $control?->mode ?? 'normal',
                'old_control_version' => $control?->control_version ?? 0,
                'new_control_version' => $control?->control_version ?? 0,
                'occurred_at' => now(),
            ]);

            return [
                'replay' => false,
                'reminder_ref' => $reminderId,
                'state' => 'posted',
            ];
        });
    }
}
