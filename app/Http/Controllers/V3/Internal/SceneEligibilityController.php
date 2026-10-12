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
            if (! ($data['eligible'] ?? false)) {
                return response()->json(['error' => ['code' => 'scene_unavailable', 'message' => 'Scene unavailable']], 404, ['Cache-Control' => 'private, no-store']);
            }
            return response()->json(['data' => [
                'eligible' => true,
                'basis' => $data['basis'],
                'status' => $data['status'],
                'candidate_ref' => $candidate_ref,
                'section_ref' => $data['section_ref'],
                'candidate_version' => $data['candidate_version'],
                'prompt_version_ref' => $data['prompt_version_ref'],
                'check_run_id' => $data['check_run_id'],
                'qualification_ref' => $data['qualification_ref'],
                'gate_id' => $data['gate_id'],
                'missing_fields' => $data['missing_fields'],
            ]], 200, ['Cache-Control' => 'private, no-store']);
        } catch (PromptUnavailableException $e) {
            return response()->json(['error' => ['code' => 'scene_unavailable', 'message' => 'Scene unavailable']], 404, ['Cache-Control' => 'private, no-store']);
        }
    }
}
