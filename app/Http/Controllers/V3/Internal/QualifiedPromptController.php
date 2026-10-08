<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Services\V3\Prompts\PromptUnavailableException;
use App\Services\V3\Prompts\QualifiedPromptResolver;
use Illuminate\Http\JsonResponse;

class QualifiedPromptController extends Controller
{
    public function show(
        string $caseId,
        string $promptVersionRef,
        QualifiedPromptResolver $resolver
    ): JsonResponse {
        try {
            $data = $resolver->resolve($caseId, $promptVersionRef);

            return response()->json([
                'data' => $data,
            ], 200)->header('Cache-Control', 'private, no-store');
        } catch (PromptUnavailableException $e) {
            return response()->json([
                'error' => [
                    'code' => 'prompt_unavailable',
                    'message' => 'Prompt is unavailable.',
                ],
            ], 404)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            return response()->json([
                'error' => [
                    'code' => 'prompt_unavailable',
                    'message' => 'Prompt is unavailable.',
                ],
            ], 503)->header('Cache-Control', 'private, no-store');
        }
    }
}
