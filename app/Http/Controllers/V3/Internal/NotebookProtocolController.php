<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Services\V3\NotebookLM\AcceptCombinedLesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotebookProtocolController extends Controller
{
    public function combinedResult(
        string $queryRef,
        Request $request,
        AcceptCombinedLesson $service
    ): JsonResponse {
        try {
            $result = $service->acceptCombinedLesson($queryRef, $request->all());

            return response()->json([
                'data' => $result['data'],
            ], $result['status'] ?? 200)->header('Cache-Control', 'private, no-store');
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'protocol_validation',
                    'message' => 'Combined lesson is invalid.',
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            if ($code === 404) {
                return response()->json([
                    'error' => [
                        'code' => 'resource_unavailable',
                        'message' => 'Lesson is unavailable.',
                    ],
                ], 404)->header('Cache-Control', 'private, no-store');
            }

            if ($code === 409) {
                return response()->json([
                    'error' => [
                        'code' => 'protocol_conflict',
                        'message' => 'Lesson state changed.',
                    ],
                ], 409)->header('Cache-Control', 'private, no-store');
            }

            if ($code === 503) {
                return response()->json([
                    'error' => [
                        'code' => 'framework_unavailable',
                        'message' => 'Framework is unavailable.',
                    ],
                ], 503)->header('Cache-Control', 'private, no-store');
            }

            throw $e;
        }
    }
}
