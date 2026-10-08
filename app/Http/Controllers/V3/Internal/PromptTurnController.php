<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Models\V3\PromptTurn;
use App\Models\V3\PromptWorkItem;
use App\Services\V3\Prompts\ClaimPromptWorkItem;
use App\Services\V3\Prompts\PromptConflictException;
use App\Services\V3\Prompts\PromptUnavailableException;
use App\Services\V3\Prompts\PromptValidationException;
use App\Services\V3\Prompts\RecordPromptTurn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromptTurnController extends Controller
{
    public function claimWorkItem(string $caseId, int $workOrdinal, Request $request, ClaimPromptWorkItem $service): JsonResponse
    {
        try {
            $payload = $request->all();
            $res = $service->claim($caseId, $workOrdinal, $payload);

            return response()->json([
                'data' => [
                    'work_item_ref' => $res['work_item']->id,
                    'state' => $res['work_item']->state,
                    'section_refs' => $res['work_item']->section_refs,
                ],
            ], $res['status'])->header('Cache-Control', 'private, no-store');
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

    public function claimTurn(string $caseId, string $workItemRef, string $step, Request $request, RecordPromptTurn $service): JsonResponse
    {
        try {
            $payload = $request->all();
            $res = $service->claimTurn($caseId, $workItemRef, $step, $payload);

            return response()->json([
                'data' => [
                    'turn_ref' => $res['turn']->id,
                    'disposition' => $res['disposition'],
                    'request_sha256' => $res['request_sha256'],
                ],
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

    public function getTurn(string $caseId, string $turnRef): JsonResponse
    {
        try {
            /** @var PromptTurn|null $turn */
            $turn = PromptTurn::where('id', $turnRef)->first();
            if (! $turn) {
                return response()->json([
                    'error' => ['code' => 'prompt_unavailable', 'message' => 'Prompt is unavailable.'],
                ], 404)->header('Cache-Control', 'private, no-store');
            }

            /** @var PromptWorkItem|null $workItem */
            $workItem = PromptWorkItem::where('id', $turn->work_item_id)
                ->where('case_id', $caseId)
                ->first();
            if (! $workItem) {
                return response()->json([
                    'error' => ['code' => 'prompt_unavailable', 'message' => 'Prompt is unavailable.'],
                ], 404)->header('Cache-Control', 'private, no-store');
            }

            return response()->json([
                'data' => [
                    'state' => $turn->state,
                    'answer_sha256' => $turn->answer_sha256,
                    'conversation_out' => $turn->conversation_out,
                    'error_code' => $turn->error_code,
                ],
            ], 200)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            return response()->json([
                'error' => ['code' => 'prompt_unavailable', 'message' => 'Prompt is unavailable.'],
            ], 503)->header('Cache-Control', 'private, no-store');
        }
    }

    public function result(string $caseId, string $turnRef, Request $request, RecordPromptTurn $service): JsonResponse
    {
        try {
            $payload = $request->all();
            $res = $service->recordResult($caseId, $turnRef, $payload);

            return response()->json([
                'data' => [
                    'state' => $res['turn']->state,
                    'turn_ref' => $res['turn']->id,
                    'original_prompt_refs' => $res['original_prompt_refs'],
                ],
            ], 201)->header('Cache-Control', 'private, no-store');
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

    public function uncertain(string $caseId, string $turnRef, Request $request, RecordPromptTurn $service): JsonResponse
    {
        try {
            $errorCode = (string) $request->input('error_code', 'timeout');
            $turn = $service->recordUncertain($caseId, $turnRef, $errorCode);

            return response()->json([
                'data' => [
                    'state' => $turn->state,
                ],
            ], 200)->header('Cache-Control', 'private, no-store');
        } catch (PromptUnavailableException $e) {
            return response()->json([
                'error' => ['code' => 'prompt_unavailable', 'message' => 'Prompt is unavailable.'],
            ], 404)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            return response()->json([
                'error' => ['code' => 'prompt_unavailable', 'message' => 'Prompt is unavailable.'],
            ], 503)->header('Cache-Control', 'private, no-store');
        }
    }
}
