<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Services\V3\Prompts\PromptConflictException;
use App\Services\V3\Prompts\PromptContextReader;
use App\Services\V3\Prompts\PromptUnavailableException;
use App\Services\V3\Prompts\PromptValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromptContextController extends Controller
{
    public function getContext(string $caseId, int $workOrdinal, PromptContextReader $reader): JsonResponse
    {
        try {
            $data = $reader->read($caseId, $workOrdinal);

            return response()->json(['data' => $data], 200)
                ->header('Cache-Control', 'private, no-store');
        } catch (PromptUnavailableException $e) {
            return response()->json([
                'error' => [
                    'code' => 'prompt_unavailable',
                    'message' => 'Prompt is unavailable.',
                ],
            ], 404)->header('Cache-Control', 'private, no-store');
        } catch (PromptConflictException $e) {
            return response()->json([
                'error' => [
                    'code' => 'prompt_conflict',
                    'message' => 'Prompt state changed.',
                ],
            ], 409)->header('Cache-Control', 'private, no-store');
        } catch (PromptValidationException $e) {
            return response()->json([
                'error' => [
                    'code' => 'prompt_invalid',
                    'message' => 'Prompt request is invalid.',
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
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
