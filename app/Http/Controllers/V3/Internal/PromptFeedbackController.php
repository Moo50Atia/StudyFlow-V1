<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Services\V3\Prompts\PromptConflictException;
use App\Services\V3\Prompts\PromptUnavailableException;
use App\Services\V3\Prompts\PromptValidationException;
use App\Services\V3\Prompts\RecordPromptRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromptFeedbackController extends Controller
{
    public function feedbackContext(
        string $caseId,
        string $promptVersionRef,
        RecordPromptRevision $service
    ): JsonResponse {
        try {
            $data = $service->getFeedbackContext($caseId, $promptVersionRef);

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

    public function claimFeedback(
        string $caseId,
        string $promptVersionRef,
        Request $request,
        RecordPromptRevision $service
    ): JsonResponse {
        try {
            $payload = $request->all();
            $data = $service->claimFeedback($caseId, $promptVersionRef, $payload);

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

    public function result(
        string $caseId,
        string $effectRef,
        Request $request,
        RecordPromptRevision $service
    ): JsonResponse {
        try {
            $payload = $request->all();
            $res = $service->recordResult($caseId, $effectRef, $payload);

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

    public function uncertain(
        string $caseId,
        string $effectRef,
        Request $request,
        RecordPromptRevision $service
    ): JsonResponse {
        try {
            $payload = $request->all();
            $data = $service->recordUncertain($caseId, $effectRef, $payload);

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
}
