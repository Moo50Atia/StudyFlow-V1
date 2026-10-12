<?php

namespace App\Http\Controllers\V3\Teacher;

use App\Http\Controllers\Controller;
use App\Models\V3\ApprovedScene;
use App\Models\V3\ProcessingCase;
use App\Services\V3\Review\SubmitPostapprovalCorrection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class PostapprovalCorrectionController extends Controller
{
    public function __construct(
        protected SubmitPostapprovalCorrection $submitService
    ) {}

    public function correct(Request $request, string $case, string $approval): JsonResponse|Response
    {
        if (! Str::isUuid($case) || ! Str::isUuid($approval)) {
            return $this->errorNotFound($request);
        }

        /** @var ProcessingCase|null $caseModel */
        $caseModel = ProcessingCase::find($case);
        if (! $caseModel || ! Gate::forUser($request->user())->allows('update', $caseModel)) {
            return $this->errorNotFound($request);
        }

        $approvalModel = ApprovedScene::where('id', $approval)->where('case_id', $caseModel->id)->first();
        if (! $approvalModel) {
            return $this->errorNotFound($request);
        }

        $res = $this->submitService->submit(
            $caseModel,
            $approvalModel,
            $request->all(),
            $request->user()
        );

        if (isset($res['error'])) {
            return response()->json(['error' => $res['error']], $res['status'])
                ->header('Cache-Control', 'private, no-store');
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($approvalModel, $request) {
            \Illuminate\Support\Facades\DB::table('v3_scene_publications')->where('approval_id', $approvalModel->id)->delete();
            $source = $approvalModel->section;
            if ($source) {
                $link = \Illuminate\Support\Facades\DB::table('v3_section_pipeline_links')->where('teacher_section_id', $source->id)->first();
                if ($link) \Illuminate\Support\Facades\DB::table('sections')->where('id', $link->section_id)->update(['dynamic_view_link' => null]);
                \Illuminate\Support\Facades\DB::table('v3_lesson_publication_events')->insert([
                    'work_node_id' => $source->work_node_id, 'actor_user_id' => $request->user()->id,
                    'action' => 'scene_correction', 'details' => json_encode(['approval_id' => $approvalModel->id]), 'created_at' => now(),
                ]);
            }
        });

        return response()->json(['data' => $res['data']], $res['status'])
            ->header('Cache-Control', 'private, no-store');
    }

    protected function errorNotFound(Request $request): JsonResponse|Response
    {
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
}
