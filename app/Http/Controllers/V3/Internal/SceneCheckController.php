<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Models\V3\SceneCheckAttempt;
use App\Models\V3\SceneCheckRun;
use App\Services\V3\SceneQualification\ClaimSceneCheck;
use App\Services\V3\SceneQualification\RecordSceneCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class SceneCheckController extends Controller
{
    public function __construct(
        protected ClaimSceneCheck $claimService,
        protected RecordSceneCheck $recordService
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
        } catch (NotFoundHttpException $e) {
            return response()->json(['error' => ['code' => 'scene_unavailable', 'message' => $e->getMessage()]], 404, ['Cache-Control' => 'private, no-store']);
        } catch (\Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException $e) {
            return response()->json(['error' => ['code' => 'scene_unavailable', 'message' => $e->getMessage()]], 503, ['Cache-Control' => 'private, no-store']);
        }
    }

    public function show(Request $request, string $case_id, string $attempt_ref): JsonResponse
    {
        $attempt = SceneCheckAttempt::where('id', $attempt_ref)
            ->where('case_id', $case_id)
            ->first();

        if (!$attempt) {
            return response()->json(['error' => ['code' => 'scene_unavailable', 'message' => 'Attempt not found']], 404, ['Cache-Control' => 'private, no-store']);
        }

        $run = SceneCheckRun::where('winning_attempt_id', $attempt->id)->first();
        $disposition = ($attempt->state === 'recorded' && $run) ? 'recorded' : 'reconcile';

        return response()->json([
            'data' => [
                'attempt_ref' => $attempt->id,
                'candidate_ref' => $attempt->candidate_id,
                'state' => $attempt->state,
                'disposition' => $disposition,
                'check_ref' => $run?->id,
                'verdict' => $run?->verdict,
                'result_sha256' => $attempt->result_sha256,
            ]
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function result(Request $request, string $case_id, string $attempt_ref): JsonResponse
    {
        $payload = $request->json()->all();
        if (($payload['schema_version'] ?? null) !== 1) {
            return response()->json(['error' => ['code' => 'contract_invalid', 'message' => 'Schema version 1 required']], 422, ['Cache-Control' => 'private, no-store']);
        }

        try {
            $result = $this->recordService->record($case_id, $attempt_ref, $payload, $request->getContent());
            return response()->json(['data' => $result['data']], $result['status_code'], ['Cache-Control' => 'private, no-store']);
        } catch (NotFoundHttpException $e) {
            return response()->json(['error' => ['code' => 'scene_unavailable', 'message' => $e->getMessage()]], 404, ['Cache-Control' => 'private, no-store']);
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $e) {
            return response()->json(['error' => ['code' => 'scene_conflict', 'message' => $e->getMessage()]], 409, ['Cache-Control' => 'private, no-store']);
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException $e) {
            return response()->json(['error' => ['code' => 'contract_invalid', 'message' => $e->getMessage()]], 422, ['Cache-Control' => 'private, no-store']);
        }
    }

    public function technicalFailure(Request $request, string $case_id, string $attempt_ref): JsonResponse
    {
        $payload = $request->json()->all();
        try {
            $result = $this->recordService->recordTechnicalFailure($case_id, $attempt_ref, $payload);
            return response()->json(['data' => $result['data']], $result['status_code'], ['Cache-Control' => 'private, no-store']);
        } catch (NotFoundHttpException $e) {
            return response()->json(['error' => ['code' => 'scene_unavailable', 'message' => $e->getMessage()]], 404, ['Cache-Control' => 'private, no-store']);
        }
    }
}
