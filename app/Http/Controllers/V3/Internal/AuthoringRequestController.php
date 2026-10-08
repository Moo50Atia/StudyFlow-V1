<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Services\V3\Authoring\AuthoringConflictException;
use App\Services\V3\Authoring\AuthoringTemplateUnavailableException;
use App\Services\V3\Authoring\AuthoringUnavailableException;
use App\Services\V3\Authoring\AuthoringValidationException;
use App\Services\V3\Authoring\ClaimAuthoringRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuthoringRequestController extends Controller
{
    public function eligiblePrompts(string $caseId, ClaimAuthoringRequest $service): JsonResponse
    {
        try {
            $data = $service->listEligiblePrompts($caseId);

            return response()->json([
                'data' => $data,
            ], 200)->header('Cache-Control', 'private, no-store');
        } catch (AuthoringUnavailableException $e) {
            return response()->json([
                'error' => ['code' => 'authoring_unavailable', 'message' => 'Authoring unavailable.'],
            ], 404)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            return response()->json([
                'error' => ['code' => 'authoring_unavailable', 'message' => 'Authoring unavailable.'],
            ], 503)->header('Cache-Control', 'private, no-store');
        }
    }

    public function store(Request $request, string $caseId, ClaimAuthoringRequest $service): JsonResponse
    {
        try {
            $body = $request->all();

            // Strict unknown-key rejection
            $allowedKeys = ['schema_version', 'prompt_version_ref'];
            foreach (array_keys($body) as $key) {
                if (! in_array($key, $allowedKeys, true)) {
                    return response()->json([
                        'error' => ['code' => 'contract_invalid', 'message' => 'Invalid request payload.'],
                    ], 422)->header('Cache-Control', 'private, no-store');
                }
            }

            if (! isset($body['schema_version']) || (int) $body['schema_version'] !== 1) {
                return response()->json([
                    'error' => ['code' => 'contract_invalid', 'message' => 'Invalid schema version.'],
                ], 422)->header('Cache-Control', 'private, no-store');
            }

            if (empty($body['prompt_version_ref']) || ! is_string($body['prompt_version_ref']) || ! Str::isUuid($body['prompt_version_ref'])) {
                return response()->json([
                    'error' => ['code' => 'contract_invalid', 'message' => 'Invalid prompt version ref.'],
                ], 422)->header('Cache-Control', 'private, no-store');
            }

            $result = $service->claimInitial($caseId, $body['prompt_version_ref']);
            $authReq = $result['request'];
            $status = $result['is_replay'] ? 200 : 201;

            return response()->json([
                'data' => [
                    'request_ref' => $authReq->id,
                    'section_ref' => $authReq->section_id,
                    'state' => $authReq->state,
                    'settings_version_id' => $authReq->settings_version_id,
                ],
            ], $status)->header('Cache-Control', 'private, no-store');
        } catch (AuthoringUnavailableException $e) {
            return response()->json([
                'error' => ['code' => 'authoring_unavailable', 'message' => 'Authoring unavailable.'],
            ], 404)->header('Cache-Control', 'private, no-store');
        } catch (AuthoringTemplateUnavailableException $e) {
            return response()->json([
                'error' => ['code' => 'authoring_unavailable', 'message' => 'Authoring unavailable.'],
            ], 503)->header('Cache-Control', 'private, no-store');
        } catch (AuthoringConflictException $e) {
            return response()->json([
                'error' => ['code' => 'authoring_conflict', 'message' => 'Authoring conflict.'],
            ], 409)->header('Cache-Control', 'private, no-store');
        } catch (AuthoringValidationException $e) {
            return response()->json([
                'error' => ['code' => 'contract_invalid', 'message' => 'Invalid request.'],
            ], 422)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            return response()->json([
                'error' => ['code' => 'authoring_unavailable', 'message' => 'Authoring unavailable.'],
            ], 503)->header('Cache-Control', 'private, no-store');
        }
    }

    public function context(string $caseId, string $requestRef, ClaimAuthoringRequest $service): JsonResponse
    {
        try {
            $data = $service->getContext($caseId, $requestRef);

            return response()->json([
                'data' => $data,
            ], 200)->header('Cache-Control', 'private, no-store');
        } catch (AuthoringUnavailableException $e) {
            return response()->json([
                'error' => ['code' => 'authoring_unavailable', 'message' => 'Authoring unavailable.'],
            ], 404)->header('Cache-Control', 'private, no-store');
        } catch (AuthoringTemplateUnavailableException $e) {
            return response()->json([
                'error' => ['code' => 'authoring_unavailable', 'message' => 'Authoring unavailable.'],
            ], 503)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            return response()->json([
                'error' => ['code' => 'authoring_unavailable', 'message' => 'Authoring unavailable.'],
            ], 503)->header('Cache-Control', 'private, no-store');
        }
    }
}
