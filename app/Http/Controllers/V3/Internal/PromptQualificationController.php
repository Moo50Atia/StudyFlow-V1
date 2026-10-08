<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Services\V3\Prompts\PromptConflictException;
use App\Services\V3\Prompts\PromptUnavailableException;
use App\Services\V3\Prompts\PromptValidationException;
use App\Services\V3\Prompts\RecordPromptQualification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromptQualificationController extends Controller
{
    public function qualificationContext(
        string $caseId,
        string $promptVersionRef,
        RecordPromptQualification $service
    ): JsonResponse {
        try {
            $data = $service->getQualificationContext($caseId, $promptVersionRef);

            return response()->json([
                'data' => $data,
            ], 200)->header('Cache-Control', 'private, no-store');
        } catch (PromptUnavailableException $e) {
            return response()->json([
                'error' => ['code' => 'prompt_unavailable', 'message' => 'Prompt is unavailable.'],
            ], 404)->header('Cache-Control', 'private, no-store');
        } catch (PromptValidationException $e) {
            return response()->json([
                'error' => ['code' => 'prompt_invalid', 'message' => 'Prompt request is invalid.'],
            ], 422)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            return response()->json([
                'error' => ['code' => 'prompt_unavailable', 'message' => 'Prompt is unavailable.'],
            ], 503)->header('Cache-Control', 'private, no-store');
        }
    }

    public function claimAttempt(
        string $caseId,
        string $promptVersionRef,
        Request $request,
        RecordPromptQualification $service
    ): JsonResponse {
        try {
            $payload = $request->all();
            $data = $service->claimAttempt($caseId, $promptVersionRef, $payload);

            return response()->json([
                'data' => $data,
            ], 200)->header('Cache-Control', 'private, no-store');
        } catch (PromptUnavailableException $e) {
            return response()->json([
                'error' => ['code' => 'prompt_unavailable', 'message' => 'Prompt is unavailable.'],
            ], 404)->header('Cache-Control', 'private, no-store');
        } catch (PromptConflictException $e) {
            return response()->json([
                'error' => ['code' => 'prompt_conflict', 'message' => 'Prompt state changed.'],
            ], 409)->header('Cache-Control', 'private, no-store');
        } catch (PromptValidationException $e) {
            return response()->json([
                'error' => ['code' => 'prompt_invalid', 'message' => 'Prompt request is invalid.'],
            ], 422)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            return response()->json([
                'error' => ['code' => 'prompt_unavailable', 'message' => 'Prompt is unavailable.'],
            ], 503)->header('Cache-Control', 'private, no-store');
        }
    }

    public function recordResult(
        string $caseId,
        string $attemptRef,
        Request $request,
        RecordPromptQualification $service
    ): JsonResponse {
        try {
            $payload = $request->all();
            $res = $service->recordResult($caseId, $attemptRef, $payload);

            return response()->json([
                'data' => $res['data'],
            ], $res['status_code'])->header('Cache-Control', 'private, no-store');
        } catch (PromptUnavailableException $e) {
            return response()->json([
                'error' => ['code' => 'prompt_unavailable', 'message' => 'Prompt is unavailable.'],
            ], 404)->header('Cache-Control', 'private, no-store');
        } catch (PromptConflictException $e) {
            return response()->json([
                'error' => ['code' => 'prompt_conflict', 'message' => 'Prompt state changed.'],
            ], 409)->header('Cache-Control', 'private, no-store');
        } catch (PromptValidationException $e) {
            return response()->json([
                'error' => ['code' => 'prompt_invalid', 'message' => 'Prompt request is invalid.'],
            ], 422)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            return response()->json([
                'error' => ['code' => 'prompt_unavailable', 'message' => 'Prompt is unavailable.'],
            ], 503)->header('Cache-Control', 'private, no-store');
        }
    }

    public function recordTechnicalFailure(
        string $caseId,
        string $attemptRef,
        Request $request,
        RecordPromptQualification $service
    ): JsonResponse {
        try {
            $payload = $request->all();
            $data = $service->recordTechnicalFailure($caseId, $attemptRef, $payload);

            return response()->json([
                'data' => $data,
            ], 200)->header('Cache-Control', 'private, no-store');
        } catch (PromptUnavailableException $e) {
            return response()->json([
                'error' => ['code' => 'prompt_unavailable', 'message' => 'Prompt is unavailable.'],
            ], 404)->header('Cache-Control', 'private, no-store');
        } catch (PromptConflictException $e) {
            return response()->json([
                'error' => ['code' => 'prompt_conflict', 'message' => 'Prompt state changed.'],
            ], 409)->header('Cache-Control', 'private, no-store');
        } catch (PromptValidationException $e) {
            return response()->json([
                'error' => ['code' => 'prompt_invalid', 'message' => 'Prompt request is invalid.'],
            ], 422)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            return response()->json([
                'error' => ['code' => 'prompt_unavailable', 'message' => 'Prompt is unavailable.'],
            ], 503)->header('Cache-Control', 'private, no-store');
        }
    }

    public function workPromptVersions(string $caseId, int $workOrdinal): JsonResponse
    {
        $workItem = \App\Models\V3\PromptWorkItem::where('case_id', $caseId)
            ->where('work_ordinal', $workOrdinal)
            ->first();

        if (! $workItem) {
            return response()->json([
                'error' => ['code' => 'prompt_unavailable', 'message' => 'Work item not found.'],
            ], 404)->header('Cache-Control', 'private, no-store');
        }

        $promptVersions = \App\Models\V3\ScenePromptVersion::where('work_item_id', $workItem->id)
            ->where('version_no', 1)
            ->pluck('id')
            ->all();

        return response()->json([
            'data' => [
                'case_id' => $caseId,
                'work_ordinal' => $workOrdinal,
                'prompt_version_refs' => $promptVersions,
            ],
        ], 200)->header('Cache-Control', 'private, no-store');
    }
}

