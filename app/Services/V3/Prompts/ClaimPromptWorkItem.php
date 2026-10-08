<?php

namespace App\Services\V3\Prompts;

use App\Models\V3\LessonCursor;
use App\Models\V3\LessonNode;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptWorkItem;
use App\Models\V3\TeacherSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClaimPromptWorkItem
{
    public function claim(string $caseId, int $workOrdinal, array $payload): array
    {
        if (($payload['schema_version'] ?? null) !== 1) {
            throw new PromptValidationException("Invalid schema_version.");
        }

        foreach (['map_ref', 'work_node_ref', 'section_refs', 'expected_cursor_version'] as $req) {
            if (! isset($payload[$req])) {
                throw new PromptValidationException("Missing required parameter: {$req}");
            }
        }

        return DB::transaction(function () use ($caseId, $workOrdinal, $payload) {
            /** @var ProcessingCase|null $case */
            $case = ProcessingCase::where('id', $caseId)->lockForUpdate()->first();
            if (! $case) {
                throw new PromptUnavailableException("Case not found.");
            }

            if (! app(\App\Services\V3\Admin\CaseOperationGuard::class)->isPermitted($caseId)) {
                throw new PromptConflictException("Operation conflict: case is held or cancelled.");
            }

            $existing = PromptWorkItem::where('case_id', $caseId)
                ->where('work_ordinal', $workOrdinal)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                // Check if exact match
                if (
                    $existing->map_id === $payload['map_ref']
                    && $existing->work_node_id === $payload['work_node_ref']
                    && (int) $existing->expected_cursor_version === (int) $payload['expected_cursor_version']
                    && $existing->section_refs === $payload['section_refs']
                ) {
                    return [
                        'status' => 200,
                        'work_item' => $existing,
                    ];
                }

                throw new PromptConflictException("Prompt work item already exists with different payload.");
            }

            /** @var LessonCursor|null $cursor */
            $cursor = LessonCursor::where('case_id', $caseId)->lockForUpdate()->first();
            if (! $cursor) {
                throw new PromptUnavailableException("Lesson cursor not found.");
            }

            if ((int) $cursor->current_work_ordinal !== $workOrdinal || $cursor->phase !== 'awaiting_010') {
                throw new PromptConflictException("Cursor is not awaiting_010 for work ordinal {$workOrdinal}.");
            }

            if ((int) $cursor->version !== (int) $payload['expected_cursor_version']) {
                throw new PromptConflictException("Cursor version conflict. Expected: {$cursor->version}, got: {$payload['expected_cursor_version']}.");
            }

            /** @var LessonNode|null $workNode */
            $workNode = LessonNode::where('map_id', $payload['map_ref'])
                ->where('id', $payload['work_node_ref'])
                ->where('work_ordinal', $workOrdinal)
                ->first();
            if (! $workNode) {
                throw new PromptValidationException("Work node does not match map or ordinal.");
            }

            $sections = TeacherSection::where('case_id', $caseId)
                ->where('work_node_id', $workNode->id)
                ->orderBy('ordinal_in_work')
                ->get();

            $actualSectionRefs = $sections->pluck('id')->all();
            if ($actualSectionRefs !== $payload['section_refs']) {
                throw new PromptValidationException("Section refs do not match current sections in ordinal order.");
            }

            $sectionQueryId = $sections->first()->query_id;
            $initialState = ($workOrdinal === 1) ? 'awaiting_execution' : 'awaiting_second';

            $workItem = PromptWorkItem::create([
                'id' => (string) Str::uuid(),
                'case_id' => $caseId,
                'map_id' => $payload['map_ref'],
                'work_node_id' => $payload['work_node_ref'],
                'work_ordinal' => $workOrdinal,
                'expected_cursor_version' => $payload['expected_cursor_version'],
                'section_query_id' => $sectionQueryId,
                'state' => $initialState,
                'section_refs' => $payload['section_refs'],
            ]);

            return [
                'status' => 201,
                'work_item' => $workItem,
            ];
        });
    }
}
