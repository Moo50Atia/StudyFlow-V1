<?php

namespace App\Http\Controllers\V3\Teacher;

use App\Http\Controllers\Controller;
use App\Models\V3\ProcessingCase;
use App\Services\V3\SceneQualification\SceneQualificationStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SceneQualificationStatusController extends Controller
{
    public function __construct(
        protected SceneQualificationStatus $projector
    ) {}

    public function show(Request $request, string $case): JsonResponse|Response
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

        $payload = $this->projector->project($caseModel);

        return response()->json(['data' => $payload], 200)
            ->header('Cache-Control', 'private, no-store');
    }
}
