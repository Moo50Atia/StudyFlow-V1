<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Services\V3\Prompts\PromptUnavailableException;
use App\Services\V3\SceneQualification\SceneReviewEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SceneEligibilityController extends Controller
{
    public function __construct(
        protected SceneReviewEligibility $eligibilityService
    ) {}

    public function show(Request $request, string $case_id, string $candidate_ref): JsonResponse
    {
        try {
            $data = $this->eligibilityService->check($case_id, $candidate_ref);
            return response()->json(['data' => $data], 200, ['Cache-Control' => 'private, no-store']);
        } catch (PromptUnavailableException $e) {
            return response()->json(['error' => ['code' => 'scene_unavailable', 'message' => 'Scene unavailable']], 404, ['Cache-Control' => 'private, no-store']);
        }
    }
}
