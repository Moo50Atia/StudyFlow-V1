<?php

namespace App\Http\Controllers\V3\Teacher;

use App\Http\Controllers\Controller;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptSectionState;
use App\Models\V3\TeacherSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PromptStatusController extends Controller
{
    public function show(Request $request, string $case): JsonResponse|View|Response
    {
        if (! Str::isUuid($case)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'resource_unavailable',
                        'message' => 'V3 resource is unavailable.',
                    ],
                ], 404)->header('Cache-Control', 'private, no-store');
            }
            abort(404, 'V3 resource is unavailable.');
        }

        /** @var ProcessingCase|null $caseModel */
        $caseModel = ProcessingCase::find($case);

        if (! $caseModel || ! Gate::forUser($request->user())->allows('view', $caseModel)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'resource_unavailable',
                        'message' => 'V3 resource is unavailable.',
                    ],
                ], 404)->header('Cache-Control', 'private, no-store');
            }
            abort(404, 'V3 resource is unavailable.');
        }

        $teacherSections = TeacherSection::with('lessonNode')
            ->where('case_id', $caseModel->id)
            ->get();

        $sectionStates = PromptSectionState::where('case_id', $caseModel->id)
            ->get()
            ->keyBy('section_id');

        $sectionsList = [];
        foreach ($teacherSections as $ts) {
            $state = $sectionStates->get($ts->id);
            $sectionsList[] = [
                'section_ref' => (string) $ts->id,
                'work_ordinal' => (int) ($ts->lessonNode?->work_ordinal ?? 1),
                'ordinal_in_work' => (int) $ts->ordinal_in_work,
                'status' => $state?->status ?? 'pending',
                'error_code' => $state?->error_code,
                'revision_count' => (int) ($state?->revision_count ?? 0),
            ];
        }

        usort($sectionsList, function ($a, $b) {
            return ($a['work_ordinal'] <=> $b['work_ordinal'])
                ?: ($a['ordinal_in_work'] <=> $b['ordinal_in_work']);
        });

        $payload = [
            'case_id' => $caseModel->id,
            'sections' => array_values($sectionsList),
            'updated_at' => $caseModel->updated_at->toISOString(),
        ];

        if ($request->expectsJson()) {
            return response()->json(['data' => $payload], 200)->header('Cache-Control', 'private, no-store');
        }

        return response()->view('v3.teacher.prompts.status', [
            'data' => $payload,
            'case' => $caseModel,
        ])->header('Cache-Control', 'private, no-store');
    }
}
