<?php

namespace App\Services\V3\Prompts;

use App\Models\V3\LessonCursor;
use App\Models\V3\PromptWorkItem;
use App\Models\V3\ScenePromptVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;


class PromptReceiptSender
{
    public static function computeCompletionDigest(
        string $caseId,
        string $mapId,
        int $workOrdinal,
        int $expectedCursorVersion,
        array $sectionRefs,
        array $thirdRefs,
        string $finalConversationRef,
        string $completionRef
    ): string {
        $str = "009-completion-v1\n"
            . $caseId . "\n"
            . $mapId . "\n"
            . $workOrdinal . "\n"
            . $expectedCursorVersion . "\n"
            . implode(',', $sectionRefs) . "\n"
            . implode(',', $thirdRefs) . "\n"
            . strlen($finalConversationRef) . ":" . $finalConversationRef . "\n"
            . $completionRef;

        return strtolower(hash('sha256', $str));
    }

    public function sendReceipt(string $caseId, string $workItemId): array
    {
        return DB::transaction(function () use ($caseId, $workItemId) {
            /** @var PromptWorkItem|null $workItem */
            $workItem = PromptWorkItem::where('id', $workItemId)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();

            if (! $workItem) {
                throw new PromptUnavailableException("Work item not found.");
            }

            if (! in_array($workItem->state, ['originals_ready', 'receipt_committed'], true)) {
                throw new PromptConflictException("Work item not ready for completion receipt. Current state: {$workItem->state}.");
            }

            // Verify all version 1 prompt versions exist for all section_refs
            $promptVersions = ScenePromptVersion::where('work_item_id', $workItem->id)
                ->where('version_no', 1)
                ->get()
                ->keyBy('section_id');

            $thirdRefs = [];
            foreach ($workItem->section_refs as $secRef) {
                if (! $promptVersions->has($secRef)) {
                    throw new PromptConflictException("Missing original prompt version for section {$secRef}.");
                }
                $thirdRefs[] = $promptVersions->get($secRef)->id;
            }

            $completionRef = $workItem->completion_ref ?? "prompt-work-{$workItem->id}";
            $finalConv = (string) ($workItem->final_conversation_id ?? '');

            $digest = self::computeCompletionDigest(
                $caseId,
                $workItem->map_id,
                (int) $workItem->work_ordinal,
                (int) $workItem->expected_cursor_version,
                $workItem->section_refs,
                $thirdRefs,
                $finalConv,
                $completionRef
            );

            $workItem->update([
                'completion_ref' => $completionRef,
                'completion_sha256' => $digest,
                'state' => 'receipt_committed',
                'receipt_committed_at' => now(),
            ]);

            $payload = [
                'schema_version' => 1,
                'map_ref' => $workItem->map_id,
                'work_ordinal' => (int) $workItem->work_ordinal,
                'expected_cursor_version' => (int) $workItem->expected_cursor_version,
                'section_refs' => $workItem->section_refs,
                'third_output_refs' => $thirdRefs,
                'final_conversation_ref' => $finalConv,
                'completion_ref' => $completionRef,
                'completion_sha256' => $digest,
            ];

            return [
                'completion_ref' => $completionRef,
                'completion_sha256' => $digest,
                'payload' => $payload,
            ];
        });
    }
}
