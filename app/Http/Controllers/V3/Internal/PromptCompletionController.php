<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Services\V3\Lessons\LessonCursorService;
use App\Services\V3\Prompts\PromptConflictException;
use App\Services\V3\Prompts\PromptReceiptSender;
use App\Services\V3\Prompts\PromptUnavailableException;
use App\Services\V3\Prompts\PromptValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PromptCompletionController extends Controller
{
    public function __invoke(
        string $caseId,
        string $workItemRef,
        Request $request,
        PromptReceiptSender $receiptSender,
        LessonCursorService $cursorService,
    ): JsonResponse {
        try {
            if ($request->input('schema_version') !== 1) {
                throw new \InvalidArgumentException('Unsupported completion schema.');
            }
            $result = DB::transaction(function () use ($caseId, $workItemRef, $receiptSender, $cursorService) {
                $receipt = $receiptSender->sendReceipt($caseId, $workItemRef);
                $cursor = $cursorService->recordCompletion($caseId, $receipt['payload']);

                return [
                    'completion_ref' => $receipt['completion_ref'],
                    'completion_sha256' => $receipt['completion_sha256'],
                    'phase' => $cursor['phase'],
                    'cursor_version' => $cursor['cursor_version'],
                    'next_work_ordinal' => $cursor['next_work_ordinal'],
                ];
            });

            return response()->json(['data' => $result], 200)
                ->header('Cache-Control', 'private, no-store');
        } catch (PromptUnavailableException $exception) {
            return response()->json(['error' => ['code' => 'prompt_unavailable', 'message' => 'Prompt is unavailable.']], 404)
                ->header('Cache-Control', 'private, no-store');
        } catch (PromptConflictException $exception) {
            return response()->json(['error' => ['code' => 'prompt_conflict', 'message' => 'Prompt state changed.']], 409)
                ->header('Cache-Control', 'private, no-store');
        } catch (PromptValidationException|\InvalidArgumentException $exception) {
            return response()->json(['error' => ['code' => 'prompt_invalid', 'message' => 'Prompt completion is invalid.']], 422)
                ->header('Cache-Control', 'private, no-store');
        } catch (\RuntimeException $exception) {
            $status = $exception->getCode() === 409 ? 409 : 404;
            return response()->json(['error' => ['code' => $status === 409 ? 'lesson_conflict' : 'lesson_unavailable', 'message' => 'Lesson state is unavailable.']], $status)
                ->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $exception) {
            return response()->json(['error' => ['code' => 'lesson_unavailable', 'message' => 'Lesson state is unavailable.']], 503)
                ->header('Cache-Control', 'private, no-store');
        }
    }
}
