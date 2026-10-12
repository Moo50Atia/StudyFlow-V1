<?php

namespace App\Http\Controllers\V3\Teacher;

use App\Http\Controllers\Controller;
use App\Models\V3\LessonCursor;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SubjectRunItem;
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

        // Keep the JSON lesson-map contract compact and stable. The HTML view
        // also needs owner-visible destinations for each saved section and
        // its exact prompt export so imported content is navigable from the
        // actual lesson map.
        $viewWorkItems = $workItems;
        $workNodeIds = $workNodes->pluck('id')->all();
        $sectionsByWorkNode = TeacherSection::whereIn('work_node_id', $workNodeIds)
            ->orderBy('ordinal_in_work')
            ->get(['id', 'work_node_id', 'ordinal_in_work', 'section_identity'])
            ->groupBy('work_node_id');
        $promptSectionIds = ScenePromptVersion::whereIn(
            'section_id',
            $sectionsByWorkNode->flatten(1)->pluck('id')->all()
        )->distinct()->pluck('section_id')->flip();
        $promptRunId = SubjectRunItem::where('case_id', $caseModel->id)->value('run_id');

        foreach ($viewWorkItems as $index => $item) {
            $viewWorkItems[$index]['sections'] = ($sectionsByWorkNode->get($item['work_node_ref']) ?? collect())
                ->map(fn (TeacherSection $section) => [
                    'id' => $section->id,
                    'ordinal' => (int) $section->ordinal_in_work,
                    'identity' => $section->section_identity,
                    'has_prompt' => $promptSectionIds->has($section->id),
                ])->all();
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
            'workItems' => $viewWorkItems,
            'promptRunId' => $promptRunId,
        ])->header('Cache-Control', 'private, no-store');
    }
}
