<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Services\V3\Authoring\AuthoringUnavailableException;
use App\Services\V3\Authoring\SceneCandidateResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SceneCandidateController extends Controller
{
    public function __construct(
        protected SceneCandidateResolver $resolver
    ) {}

    public function show(Request $request, string $caseId, string $candidateRef): JsonResponse
    {
        try {
            $data = $this->resolver->resolve($caseId, $candidateRef);

            return response()->json($data, 200)
                ->header('Cache-Control', 'private, no-store');
        } catch (AuthoringUnavailableException $e) {
            $msg = $e->getMessage();
            $statusCode = 404;

            if (str_contains($msg, 'missing') || str_contains($msg, 'mismatch') || str_contains($msg, 'corrupt')) {
                $statusCode = 503;
            }

            return response()->json([
                'error' => [
                    'code' => 'authoring_unavailable',
                    'message' => $msg,
                ],
            ], $statusCode)->header('Cache-Control', 'private, no-store');
        }
    }
}
