<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Services\V3\Prompts\PromptConflictException;
use App\Services\V3\Prompts\PromptUnavailableException;
use App\Services\V3\Prompts\PromptValidationException;
use App\Services\V3\SceneQualification\ClaimSceneRepair;
use App\Services\V3\SceneQualification\LinkRepairCandidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SceneRepairController extends Controller
{
    public function __construct(
        protected ClaimSceneRepair $claimService,
        protected LinkRepairCandidate $linkService
    ) {}

    public function claim(Request $request, string $case_id): JsonResponse
    {
        $payload = $request->json()->all();
        if (($payload['schema_version'] ?? null) !== 1) {
            return response()->json(['error' => ['code' => 'contract_invalid', 'message' => 'Schema version 1 required']], 422, ['Cache-Control' => 'private, no-store']);
        }

        try {
            $result = $this->claimService->claim($case_id, $payload);
            return response()->json(['data' => $result['data']], $result['status_code'], ['Cache-Control' => 'private, no-store']);
        } catch (PromptUnavailableException $e) {
            return response()->json(['error' => ['code' => 'scene_unavailable', 'message' => $e->getMessage()]], 404, ['Cache-Control' => 'private, no-store']);
        } catch (PromptConflictException $e) {
            return response()->json(['error' => ['code' => 'scene_conflict', 'message' => $e->getMessage()]], 409, ['Cache-Control' => 'private, no-store']);
        } catch (PromptValidationException $e) {
            return response()->json(['error' => ['code' => 'contract_invalid', 'message' => $e->getMessage()]], 422, ['Cache-Control' => 'private, no-store']);
        }
    }

    public function context(Request $request, string $case_id, string $repair_ref): JsonResponse
    {
        try {
            $data = $this->claimService->getContext($case_id, $repair_ref);
            return response()->json(['data' => $data], 200, ['Cache-Control' => 'private, no-store']);
        } catch (PromptUnavailableException $e) {
            return response()->json(['error' => ['code' => 'scene_unavailable', 'message' => $e->getMessage()]], 404, ['Cache-Control' => 'private, no-store']);
        }
    }

    public function linkCandidate(Request $request, string $case_id, string $repair_ref): JsonResponse
    {
        $payload = $request->json()->all();
        if (($payload['schema_version'] ?? null) !== 1) {
            return response()->json(['error' => ['code' => 'contract_invalid', 'message' => 'Schema version 1 required']], 422, ['Cache-Control' => 'private, no-store']);
        }

        try {
            $result = $this->linkService->link($case_id, $repair_ref, $payload);
            return response()->json(['data' => $result['data']], $result['status_code'], ['Cache-Control' => 'private, no-store']);
        } catch (PromptUnavailableException $e) {
            return response()->json(['error' => ['code' => 'scene_unavailable', 'message' => $e->getMessage()]], 404, ['Cache-Control' => 'private, no-store']);
        } catch (PromptConflictException $e) {
            return response()->json(['error' => ['code' => 'scene_conflict', 'message' => $e->getMessage()]], 409, ['Cache-Control' => 'private, no-store']);
        } catch (PromptValidationException $e) {
            return response()->json(['error' => ['code' => 'contract_invalid', 'message' => $e->getMessage()]], 422, ['Cache-Control' => 'private, no-store']);
        }
    }
}
