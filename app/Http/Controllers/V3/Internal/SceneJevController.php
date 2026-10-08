<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Models\V3\SceneJevAttempt;
use App\Models\V3\SceneQualification;
use App\Services\V3\Prompts\PromptConflictException;
use App\Services\V3\Prompts\PromptUnavailableException;
use App\Services\V3\Prompts\PromptValidationException;
use App\Services\V3\SceneQualification\ClaimSceneJevAttempt;
use App\Services\V3\SceneQualification\RecordSceneJevResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SceneJevController extends Controller
{
    public function __construct(
        protected ClaimSceneJevAttempt $claimService,
        protected RecordSceneJevResult $recordService
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

    public function show(Request $request, string $case_id, string $attempt_ref): JsonResponse
    {
        /** @var SceneJevAttempt|null $attempt */
        $attempt = SceneJevAttempt::where('id', $attempt_ref)
            ->where('case_id', $case_id)
            ->first();

        if (! $attempt) {
            return response()->json(['error' => ['code' => 'scene_unavailable', 'message' => 'Attempt not found']], 404, ['Cache-Control' => 'private, no-store']);
        }

        $qual = SceneQualification::where('winning_jev_attempt_id', $attempt->id)->first();
        $disposition = ($attempt->state === 'recorded') ? 'recorded' : 'reconcile';

        return response()->json([
            'data' => [
                'attempt_ref' => $attempt->id,
                'candidate_ref' => $attempt->candidate_id,
                'state' => $attempt->state,
                'disposition' => $disposition,
                'qualification_ref' => $qual?->id,
                'result_sha256' => $attempt->result_sha256,
            ],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function context(Request $request, string $case_id, string $attempt_ref): JsonResponse
    {
        /** @var SceneJevAttempt|null $attempt */
        $attempt = SceneJevAttempt::where('id', $attempt_ref)
            ->where('case_id', $case_id)
            ->first();

        if (! $attempt) {
            return response()->json(['error' => ['code' => 'scene_unavailable', 'message' => 'Attempt not found']], 404, ['Cache-Control' => 'private, no-store']);
        }

        if (! Storage::disk('v3_private')->exists($attempt->evidence_path)) {
            return response()->json(['error' => ['code' => 'scene_unavailable', 'message' => 'Evidence file missing']], 503, ['Cache-Control' => 'private, no-store']);
        }

        $evidenceBytes = Storage::disk('v3_private')->get($attempt->evidence_path);
        $evidence = json_decode($evidenceBytes, true);

        return response()->json([
            'data' => [
                'attempt_ref' => $attempt->id,
                'model' => $attempt->requested_model,
                'question_sha256' => $attempt->question_sha256,
                'evidence' => $evidence,
            ],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function result(Request $request, string $case_id, string $attempt_ref): JsonResponse
    {
        $payload = $request->json()->all();
        if (($payload['schema_version'] ?? null) !== 1) {
            return response()->json(['error' => ['code' => 'contract_invalid', 'message' => 'Schema version 1 required']], 422, ['Cache-Control' => 'private, no-store']);
        }

        try {
            $result = $this->recordService->record($case_id, $attempt_ref, $payload);
            return response()->json(['data' => $result['data']], $result['status_code'], ['Cache-Control' => 'private, no-store']);
        } catch (PromptUnavailableException $e) {
            return response()->json(['error' => ['code' => 'scene_unavailable', 'message' => $e->getMessage()]], 404, ['Cache-Control' => 'private, no-store']);
        } catch (PromptConflictException $e) {
            return response()->json(['error' => ['code' => 'scene_conflict', 'message' => $e->getMessage()]], 409, ['Cache-Control' => 'private, no-store']);
        } catch (PromptValidationException $e) {
            return response()->json(['error' => ['code' => 'contract_invalid', 'message' => $e->getMessage()]], 422, ['Cache-Control' => 'private, no-store']);
        }
    }

    public function technicalFailure(Request $request, string $case_id, string $attempt_ref): JsonResponse
    {
        $payload = $request->json()->all();
        try {
            $result = $this->recordService->recordTechnicalFailure($case_id, $attempt_ref, $payload);
            return response()->json(['data' => $result['data']], $result['status_code'], ['Cache-Control' => 'private, no-store']);
        } catch (PromptUnavailableException $e) {
            return response()->json(['error' => ['code' => 'scene_unavailable', 'message' => $e->getMessage()]], 404, ['Cache-Control' => 'private, no-store']);
        }
    }
}
