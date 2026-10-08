<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Models\V3\LessonCursor;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\MapActivation;
use App\Models\V3\ProcessingCase;
use App\Models\V3\TeacherSection;
use App\Services\V3\Lessons\LessonQueryLedger;
use Illuminate\Http\JsonResponse;

class MapContextController extends Controller
{
    /**
     * GET /api/internal/v1/map-activations/{activation_id}/context
     */
    public function getContext(string $activationId): JsonResponse
    {
        $activation = MapActivation::where('id', $activationId)->first();
        if (! $activation || $activation->state !== 'accepted') {
            return response()->json([
                'error' => [
                    'code' => 'lesson_unavailable',
                    'message' => 'Lesson is unavailable.',
                ],
            ], 404)->header('Cache-Control', 'private, no-store');
        }

        $case = $activation->processingCase;
        $binding = $activation->knowledgeSourceBinding;
        if (! $case || ! $binding || $binding->state !== 'bound') {
            return response()->json([
                'error' => [
                    'code' => 'lesson_unavailable',
                    'message' => 'Lesson is unavailable.',
                ],
            ], 404)->header('Cache-Control', 'private, no-store');
        }

        $extracted = $binding->extractedSource;
        $pageCount = null;
        $sourceSha256 = null;

        if ($case->isDirectProtocol()) {
            $document = $case->document;
            $pageCount = $document?->page_count ?? 1;
            $sourceSha256 = $document?->sha256;
        } else {
            if (! $extracted || $extracted->status !== 'ready') {
                return response()->json([
                    'error' => [
                        'code' => 'lesson_unavailable',
                        'message' => 'Lesson is unavailable.',
                    ],
                ], 404)->header('Cache-Control', 'private, no-store');
            }
            $pageCount = $extracted->page_count;
            $sourceSha256 = $extracted->text_sha256;
        }

        $lessonMap = LessonMap::where('case_id', $case->id)->first();
        $cursor = LessonCursor::where('case_id', $case->id)->first();
        $workItems = [];
        if ($lessonMap) {
            $nodes = LessonNode::where('map_id', $lessonMap->id)->get()->keyBy('id');
            foreach ($nodes->whereNotNull('work_ordinal')->sortBy('work_ordinal') as $workNode) {
                $path = [];
                $node = $workNode;
                while ($node) {
                    array_unshift($path, $node->title);
                    $node = $node->parent_id ? $nodes->get($node->parent_id) : null;
                }
                $workItems[] = [
                    'work_ordinal' => (int) $workNode->work_ordinal,
                    'work_path' => implode(' / ', $path),
                ];
            }
        }

        return response()->json([
            'data' => [
                'case_id' => $case->id,
                'binding_ref' => $binding->id,
                'map_ref' => $lessonMap?->id,
                'map_state' => $lessonMap?->state,
                'coverage_mode' => $lessonMap?->coverage_mode,
                'work_count' => $lessonMap?->work_count,
                'work_items' => $workItems,
                'divide_conversation_ref' => $lessonMap?->divideQuery?->conversation_out,
                'page_count' => $pageCount,
                'source_sha256' => $sourceSha256,
                'settings_version_id' => $case->settings_version_id,
                'state' => $activation->state,
                'work_ordinal' => $cursor?->current_work_ordinal,
                'conversation_ref' => $cursor?->conversation_id,
            ],
        ], 200)->header('Cache-Control', 'private, no-store');
    }

    /**
     * GET /api/internal/v1/cases/{case_id}/lesson-context/{work_ordinal}
     */
    public function lessonContext(string $caseId, int $workOrdinal): JsonResponse
    {
        $case = ProcessingCase::where('id', $caseId)->first();
        if (! $case) {
            return response()->json([
                'error' => [
                    'code' => 'lesson_unavailable',
                    'message' => 'Lesson is unavailable.',
                ],
            ], 404)->header('Cache-Control', 'private, no-store');
        }

        $map = LessonMap::where('case_id', $caseId)->first();
        $workNode = null;
        $sectionRefs = [];
        if ($map) {
            $workNode = LessonNode::where('map_id', $map->id)
                ->where('work_ordinal', $workOrdinal)
                ->first();

            if ($workNode) {
                $sectionRefs = TeacherSection::where('work_node_id', $workNode->id)
                    ->orderBy('ordinal_in_work')
                    ->pluck('id')
                    ->toArray();
            }
        }

        $cursor = LessonCursor::where('case_id', $caseId)->first();
        $completion = $case->isDirectProtocol()
            ? \App\Models\V3\LessonCompletion::where('case_id', $caseId)->where('work_ordinal', $workOrdinal)->first()
            : null;
        $bundle = $completion
            ? \App\Models\V3\NotebookLessonBundle::where('completion_id', $completion->id)->first()
            : null;
        $workPath = null;
        if ($workNode) {
            $nodes = LessonNode::where('map_id', $map->id)->get()->keyBy('id');
            $path = [];
            $node = $workNode;
            while ($node) {
                array_unshift($path, $node->title);
                $node = $node->parent_id ? $nodes->get($node->parent_id) : null;
            }
            $workPath = implode(' / ', $path);
        }

        return response()->json([
            'data' => [
                'map_ref' => $map?->id,
                'work_node_ref' => $workNode?->id,
                'work_path' => $workPath,
                'section_refs' => $sectionRefs,
                'bundle_ref' => $bundle?->id,
                'completion_ref' => $completion?->completion_ref,
                'completion_cursor_version' => $bundle?->committed_cursor_version,
                'original_prompt_refs' => $completion?->third_output_refs,
                'conversation_ref' => $cursor?->conversation_id,
                'phase' => $cursor?->phase ?? 'first_pending',
                'cursor_version' => $cursor?->version,
                'canonical_digests' => [
                    'divide' => LessonQueryLedger::CANONICAL_DIVIDE_SHA256,
                    'execution' => LessonQueryLedger::CANONICAL_EXECUTION_SHA256,
                    'first' => LessonQueryLedger::CANONICAL_FIRST_SHA256,
                    'second' => LessonQueryLedger::CANONICAL_SECOND_SHA256,
                    'third' => LessonQueryLedger::CANONICAL_THIRD_SHA256,
                ],
            ],
        ], 200)->header('Cache-Control', 'private, no-store');
    }
}
