<?php

namespace App\Http\Controllers\V3\Teacher;

use App\Http\Controllers\Controller;
use App\Models\V3\LessonCursor;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\ProcessingCase;
use App\Models\V3\TeacherSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LessonMapController extends Controller
{
    public function show(Request $request, string $case): JsonResponse|View|Response
    {
        if (! Str::isUuid($case)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'resource_unavailable',
                        'message' => 'V3 access is unavailable.',
                    ],
                ], 404)->header('Cache-Control', 'private, no-store');
            }
            abort(404, 'V3 access is unavailable.');
        }

        /** @var ProcessingCase|null $caseModel */
        $caseModel = ProcessingCase::find($case);

        if (! $caseModel || ! Gate::forUser($request->user())->allows('view', $caseModel)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'resource_unavailable',
                        'message' => 'V3 access is unavailable.',
                    ],
                ], 404)->header('Cache-Control', 'private, no-store');
            }
            abort(404, 'V3 access is unavailable.');
        }

        $map = LessonMap::where('case_id', $caseModel->id)->first();
        if (! $map) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'resource_unavailable',
                        'message' => 'V3 access is unavailable.',
                    ],
                ], 404)->header('Cache-Control', 'private, no-store');
            }
            abort(404, 'V3 access is unavailable.');
        }

        $cursor = LessonCursor::where('case_id', $caseModel->id)->first();
        $workNodes = LessonNode::where('map_id', $map->id)
            ->whereNotNull('work_ordinal')
            ->orderBy('work_ordinal')
            ->get();

        $workItems = [];
        foreach ($workNodes as $node) {
            $sectionCount = TeacherSection::where('work_node_id', $node->id)->count();
            $workItems[] = [
                'work_node_ref' => $node->id,
                'ordinal' => (int) $node->work_ordinal,
                'path' => $node->title,
                'coverage_pages' => $node->coverage_pages ?? [],
                'section_count' => $sectionCount,
            ];
        }

        $payload = [
            'case_id' => $caseModel->id,
            'map_ref' => $map->id,
            'state' => $map->state,
            'work_items' => $workItems,
            'current_work_ordinal' => $cursor?->current_work_ordinal,
            'phase' => $cursor?->phase,
            'error_code' => $cursor?->error_code,
            'updated_at' => $map->updated_at->toISOString(),
        ];

        if ($request->expectsJson()) {
            return response()->json(['data' => $payload], 200)->header('Cache-Control', 'private, no-store');
        }

        return response()->view('v3.teacher.lessons.map', [
            'map' => $payload,
            'case' => $caseModel,
            'workItems' => $workItems,
        ])->header('Cache-Control', 'private, no-store');
    }
}
