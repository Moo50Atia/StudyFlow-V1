<?php

namespace App\Http\Controllers\V3\Teacher;

use App\Http\Controllers\Controller;
use App\Models\V3\ProcessingCase;
use App\Models\V3\TeacherSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TeacherSectionController extends Controller
{
    public function show(Request $request, string $case, string $section): JsonResponse|View|Response
    {
        if (! Str::isUuid($case) || ! Str::isUuid($section)) {
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

        $sectionModel = TeacherSection::where('id', $section)
            ->where('case_id', $caseModel->id)
            ->first();

        if (! $sectionModel) {
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

        $payload = [
            'section_ref' => $sectionModel->id,
            'case_id' => $sectionModel->case_id,
            'work_node_ref' => $sectionModel->work_node_id,
            'ordinal_in_work' => (int) $sectionModel->ordinal_in_work,
            'fields' => [
                'section_identity' => $sectionModel->section_identity,
                'core_understanding' => $sectionModel->core_understanding,
                'visual_first_explanation' => $sectionModel->visual_first_explanation,
                'visual_scene_script' => $sectionModel->visual_scene_script,
                'dynamic_view_blueprint' => $sectionModel->dynamic_view_blueprint,
                'visual_learning_outcome' => $sectionModel->visual_learning_outcome,
                'concept_check' => $sectionModel->concept_check,
                'conceptual_summary' => $sectionModel->conceptual_summary,
                'colloquial_egyptian_explanation' => $sectionModel->colloquial_egyptian_explanation,
                'laws_if_any' => $sectionModel->laws_if_any,
                'real_life_example' => $sectionModel->real_life_example,
            ],
        ];

        if ($request->expectsJson()) {
            return response()->json(['data' => $payload], 200)->header('Cache-Control', 'private, no-store');
        }

        return response()->view('v3.teacher.lessons.section', [
            'section' => $payload,
            'case' => $caseModel,
        ])->header('Cache-Control', 'private, no-store');
    }
}
