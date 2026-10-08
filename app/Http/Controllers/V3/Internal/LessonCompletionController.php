<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Services\V3\Lessons\LessonCursorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LessonCompletionController extends Controller
{
    public function __construct(
        protected LessonCursorService $cursorService
    ) {}

    /**
     * POST /api/internal/v1/cases/{case_id}/lesson-completions
     */
    public function store(Request $request, string $caseId): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];

        try {
            $result = $this->cursorService->recordCompletion($caseId, $payload);

            return response()->json([
                'data' => $result,
            ], 200)->header('Cache-Control', 'private, no-store');
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'lesson_invalid',
                    'message' => 'Lesson request is invalid.',
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 409) {
                return response()->json([
                    'error' => [
                        'code' => 'lesson_conflict',
                        'message' => 'Lesson state changed.',
                    ],
                ], 409)->header('Cache-Control', 'private, no-store');
            }

            return response()->json([
                'error' => [
                    'code' => 'lesson_unavailable',
                    'message' => 'Lesson is unavailable.',
                ],
            ], 404)->header('Cache-Control', 'private, no-store');
        }
    }
}
