<?php

namespace App\Services\V3\Lessons;

use App\Jobs\V3\NotifyLessonCompletionJob;
use App\Models\V3\LessonCompletion;
use App\Models\V3\LessonCursor;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\ProcessingCase;
use App\Models\V3\TeacherSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LessonCursorService
{
    /**
     * Compute the canonical 009 completion digest.
     */
    public static function computeCompletionDigest(
        string $caseId,
        string $mapRef,
        int $workOrdinal,
        int $expectedCursorVersion,
        array $sectionRefs,
        array $thirdOutputRefs,
        string $finalConversationRef,
        string $completionRef
    ): string {
        $str = "009-completion-v1\n"
            . $caseId . "\n"
            . $mapRef . "\n"
            . $workOrdinal . "\n"
            . $expectedCursorVersion . "\n"
            . implode(',', $sectionRefs) . "\n"
            . implode(',', $thirdOutputRefs) . "\n"
            . strlen($finalConversationRef) . ":" . $finalConversationRef . "\n"
            . $completionRef;

        return strtolower(hash('sha256', $str));
    }

    /**
     * Record 010 completion receipt and transition cursor.
     *
     * @return array{phase: string, cursor_version: int, next_work_ordinal: ?int}
     */
    public function recordCompletion(string $caseId, array $payload): array
    {
        if (($payload['schema_version'] ?? 0) !== 1
            || empty($payload['map_ref'])
            || ! isset($payload['work_ordinal'])
            || ! isset($payload['expected_cursor_version'])
            || empty($payload['section_refs'])
            || ! is_array($payload['section_refs'])
            || empty($payload['third_output_refs'])
            || ! is_array($payload['third_output_refs'])
            || count($payload['section_refs']) !== count($payload['third_output_refs'])
            || empty($payload['final_conversation_ref'])
            || empty($payload['completion_ref'])
            || empty($payload['completion_sha256'])
        ) {
            throw new \InvalidArgumentException("Invalid completion receipt payload.", 422);
        }

        $case = ProcessingCase::where('id', $caseId)->first();
        if (! $case) {
            throw new \RuntimeException("Case is unavailable.", 404);
        }

        $map = LessonMap::where('id', $payload['map_ref'])
            ->where('case_id', $caseId)
            ->first();
        if (! $map || $map->state !== 'ready') {
            throw new \RuntimeException("Lesson map is unavailable.", 404);
        }

        // Verify digest
        $expectedDigest = self::computeCompletionDigest(
            caseId: $caseId,
            mapRef: $map->id,
            workOrdinal: (int) $payload['work_ordinal'],
            expectedCursorVersion: (int) $payload['expected_cursor_version'],
            sectionRefs: $payload['section_refs'],
            thirdOutputRefs: $payload['third_output_refs'],
            finalConversationRef: $payload['final_conversation_ref'],
            completionRef: $payload['completion_ref']
        );

        if (! hash_equals($expectedDigest, strtolower($payload['completion_sha256']))) {
            throw new \InvalidArgumentException("Mismatched completion digest.", 422);
        }

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                return DB::transaction(function () use ($caseId, $map, $payload) {
                    $cursor = LessonCursor::where('case_id', $caseId)->lockForUpdate()->first();
                    if (! $cursor) {
                        throw new \RuntimeException("Lesson cursor is unavailable.", 404);
                    }

                    // Check for existing completion (idempotent replay)
                    $existing = LessonCompletion::where('case_id', $caseId)
                        ->where('work_ordinal', (int) $payload['work_ordinal'])
                        ->first();

                    if ($existing) {
                        if ($existing->completion_ref === $payload['completion_ref']
                            && strtolower($existing->completion_sha256) === strtolower($payload['completion_sha256'])
                        ) {
                            $isLast = (int) $payload['work_ordinal'] >= $map->work_count;
                            return [
                                'phase' => $cursor->phase,
                                'cursor_version' => $cursor->version,
                                'next_work_ordinal' => $isLast ? null : (int) $payload['work_ordinal'] + 1,
                            ];
                        }
                        throw new \RuntimeException("Completion conflict: different receipt for same work ordinal.", 409);
                    }

                    // Validate cursor state
                    if ($cursor->phase !== 'awaiting_010'
                        || (int) $cursor->current_work_ordinal !== (int) $payload['work_ordinal']
                        || (int) $cursor->version !== (int) $payload['expected_cursor_version']
                    ) {
                        throw new \RuntimeException("Cursor conflict: state mismatch.", 409);
                    }

                    // Validate that section_refs match actual committed sections for this work ordinal
                    $workNode = LessonNode::where('map_id', $map->id)
                        ->where('work_ordinal', (int) $payload['work_ordinal'])
                        ->first();
                    if (! $workNode) {
                        throw new \RuntimeException("Work node is unavailable.", 404);
                    }

                    $actualSectionRefs = TeacherSection::where('work_node_id', $workNode->id)
                        ->orderBy('ordinal_in_work')
                        ->pluck('id')
                        ->toArray();

                    if ($actualSectionRefs !== $payload['section_refs']) {
                        throw new \RuntimeException("Mismatched section refs for work ordinal.", 409);
                    }

                    // Next phase and next work ordinal
                    $isLast = (int) $payload['work_ordinal'] >= $map->work_count;
                    $newPhase = $isLast ? 'complete' : 'ready_to_continue';
                    $nextWorkOrdinal = $isLast ? null : (int) $payload['work_ordinal'] + 1;

                    // Create completion record
                    $completion = LessonCompletion::create([
                        'id' => (string) Str::uuid(),
                        'case_id' => $caseId,
                        'map_id' => $map->id,
                        'work_node_id' => $workNode->id,
                        'work_ordinal' => (int) $payload['work_ordinal'],
                        'cursor_version' => $cursor->version + 1,
                        'completion_ref' => $payload['completion_ref'],
                        'completion_sha256' => strtolower($payload['completion_sha256']),
                        'section_refs' => $payload['section_refs'],
                        'third_output_refs' => $payload['third_output_refs'],
                        'final_conversation_id' => $payload['final_conversation_ref'],
                        'signal_state' => 'committed',
                        'signal_attempts' => 0,
                    ]);

                    // Advance cursor
                    $newCursorVersion = $completion->cursor_version;
                    $cursor->update([
                        'phase' => $newPhase,
                        'last_completion_id' => $completion->id,
                        'conversation_id' => $payload['final_conversation_ref'],
                        'version' => $newCursorVersion,
                    ]);

                    DB::afterCommit(function () use ($caseId, $completion) {
                        NotifyLessonCompletionJob::dispatch($caseId, $completion->id);
                    });

                    return [
                        'phase' => $newPhase,
                        'cursor_version' => $newCursorVersion,
                        'next_work_ordinal' => $nextWorkOrdinal,
                    ];
                });
            } catch (\Illuminate\Database\QueryException $e) {
                if ($attempt === 5) {
                    throw $e;
                }
                usleep(50000 * $attempt);
            }
        }

        throw new \RuntimeException("Transaction retry failed.", 500);
    }
}
