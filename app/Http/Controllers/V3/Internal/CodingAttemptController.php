<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Services\V3\Authoring\AuthoringConflictException;
use App\Services\V3\Authoring\AuthoringUnavailableException;
use App\Services\V3\Authoring\AuthoringValidationException;
use App\Services\V3\Authoring\ClaimCodingAttempt;
use App\Services\V3\Authoring\RecordAuthoringResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CodingAttemptController extends Controller
{
    public function __construct(
        protected ClaimCodingAttempt $claimAttempt,
        protected RecordAuthoringResult $recordResultService
    ) {}

    public function reserve(Request $request, string $caseId, string $requestRef): JsonResponse
    {
        try {
            $payload = $request->json()->all();
            $result = $this->claimAttempt->claim($caseId, $requestRef, $payload);

            return response()->json([
                'data' => $result['data'],
            ], $result['status_code'])
                ->header('Cache-Control', 'private, no-store');
        } catch (AuthoringValidationException $e) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'Invalid attempt reservation request.',
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        } catch (AuthoringConflictException $e) {
            return response()->json([
                'error' => [
                    'code' => 'authoring_conflict',
                    'message' => 'Attempt reservation conflict.',
                ],
            ], 409)->header('Cache-Control', 'private, no-store');
        } catch (AuthoringUnavailableException $e) {
            return response()->json([
                'error' => [
                    'code' => 'authoring_unavailable',
                    'message' => 'Authoring request unavailable.',
                ],
            ], 404)->header('Cache-Control', 'private, no-store');
        }
    }

    public function show(Request $request, string $caseId, string $attemptRef): JsonResponse
    {
        try {
            $result = $this->claimAttempt->getAttempt($caseId, $attemptRef);

            return response()->json($result, 200)
                ->header('Cache-Control', 'private, no-store');
        } catch (AuthoringUnavailableException $e) {
            return response()->json([
                'error' => [
                    'code' => 'authoring_unavailable',
                    'message' => 'Coding attempt not found.',
                ],
            ], 404)->header('Cache-Control', 'private, no-store');
        }
    }

    public function recordResult(Request $request, string $caseId, string $attemptRef): JsonResponse
    {
        $rawBytes = $request->getContent();
        if (strlen($rawBytes) > 4194304) {
            return response()->json([
                'error' => ['code' => 'contract_invalid', 'message' => 'Request payload exceeds 4MB limit.'],
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        try {
            $payload = $request->json()->all();
            $result = $this->recordResultService->record($caseId, $attemptRef, $payload, $rawBytes);

            return response()->json([
                'data' => $result['data'],
            ], $result['status_code'])
                ->header('Cache-Control', 'private, no-store');
        } catch (AuthoringValidationException $e) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => $e->getMessage(),
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        } catch (AuthoringConflictException $e) {
            return response()->json([
                'error' => [
                    'code' => 'authoring_conflict',
                    'message' => $e->getMessage(),
                ],
            ], 409)->header('Cache-Control', 'private, no-store');
        } catch (AuthoringUnavailableException $e) {
            $msg = $e->getMessage();
            $code = str_contains($msg, 'failed') || str_contains($msg, 'storage') ? 503 : 404;

            return response()->json([
                'error' => [
                    'code' => 'authoring_unavailable',
                    'message' => $msg,
                ],
            ], $code)->header('Cache-Control', 'private, no-store');
        }
    }
}
