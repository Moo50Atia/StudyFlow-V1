<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Services\V3\Prompts\PromptConflictException;
use App\Services\V3\Prompts\PromptUnavailableException;
use App\Services\V3\Prompts\PromptValidationException;
use App\Services\V3\Review\ClaimTeacherPromptCorrection;
use App\Services\V3\Review\RecordTeacherPromptCorrection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TeacherPromptCorrectionController extends Controller
{
    public function __construct(
        protected ClaimTeacherPromptCorrection $claimService,
        protected RecordTeacherPromptCorrection $recordService
    ) {}

    public function claim(Request $request, string $case_id, string $route_ref): JsonResponse
    {
        if (! Str::isUuid($case_id) || ! Str::isUuid($route_ref)) {
            return response()->json([
                'error' => ['code' => 'resource_unavailable', 'message' => 'Invalid ID format.'],
            ], 404)->header('Cache-Control', 'private, no-store');
        }

        if ($request->input('schema_version') !== 1) {
            return response()->json([
                'error' => ['code' => 'contract_invalid', 'message' => 'Invalid schema_version.'],
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        try {
            $result = $this->claimService->claimFromTeacherRoute($case_id, $route_ref);
            if (isset($result['error'])) {
                return response()->json([
                    'error' => ['code' => 'repair_conflict', 'message' => $result['error']],
                ], $result['status_code'])->header('Cache-Control', 'private, no-store');
            }

            return response()->json(['data' => $result['data']], $result['status_code'])
                ->header('Cache-Control', 'private, no-store');
        } catch (PromptValidationException $e) {
            return response()->json([
                'error' => ['code' => 'contract_invalid', 'message' => $e->getMessage()],
            ], 422)->header('Cache-Control', 'private, no-store');
        } catch (PromptConflictException $e) {
            return response()->json([
                'error' => ['code' => 'repair_conflict', 'message' => $e->getMessage()],
            ], 409)->header('Cache-Control', 'private, no-store');
        } catch (PromptUnavailableException $e) {
            return response()->json([
                'error' => ['code' => 'resource_unavailable', 'message' => $e->getMessage()],
            ], 404)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            return response()->json([
                'error' => ['code' => 'correction_unavailable', 'message' => $e->getMessage()],
            ], 503)->header('Cache-Control', 'private, no-store');
        }
    }

    public function context(Request $request, string $case_id, string $effect_ref): JsonResponse
    {
        if (! Str::isUuid($case_id) || ! Str::isUuid($effect_ref)) {
            return response()->json([
                'error' => ['code' => 'resource_unavailable', 'message' => 'Invalid ID format.'],
            ], 404)->header('Cache-Control', 'private, no-store');
        }

        try {
            $context = $this->recordService->getContext($case_id, $effect_ref);
            return response()->json(['data' => $context], 200)
                ->header('Cache-Control', 'private, no-store');
        } catch (PromptValidationException $e) {
            return response()->json([
                'error' => ['code' => 'contract_invalid', 'message' => $e->getMessage()],
            ], 422)->header('Cache-Control', 'private, no-store');
        } catch (PromptUnavailableException $e) {
            return response()->json([
                'error' => ['code' => 'resource_unavailable', 'message' => $e->getMessage()],
            ], 404)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            return response()->json([
                'error' => ['code' => 'correction_unavailable', 'message' => $e->getMessage()],
            ], 503)->header('Cache-Control', 'private, no-store');
        }
    }

    public function result(Request $request, string $case_id, string $effect_ref): JsonResponse
    {
        if (! Str::isUuid($case_id) || ! Str::isUuid($effect_ref)) {
            return response()->json([
                'error' => ['code' => 'resource_unavailable', 'message' => 'Invalid ID format.'],
            ], 404)->header('Cache-Control', 'private, no-store');
        }

        try {
            $res = $this->recordService->recordResult($case_id, $effect_ref, $request->all());
            if (isset($res['error'])) {
                return response()->json([
                    'error' => $res['error'],
                ], $res['status_code'])->header('Cache-Control', 'private, no-store');
            }

            return response()->json(['data' => $res['data']], $res['status_code'])
                ->header('Cache-Control', 'private, no-store');
        } catch (PromptValidationException $e) {
            return response()->json([
                'error' => ['code' => 'contract_invalid', 'message' => $e->getMessage()],
            ], 422)->header('Cache-Control', 'private, no-store');
        } catch (PromptConflictException $e) {
            return response()->json([
                'error' => ['code' => 'repair_conflict', 'message' => $e->getMessage()],
            ], 409)->header('Cache-Control', 'private, no-store');
        } catch (PromptUnavailableException $e) {
            return response()->json([
                'error' => ['code' => 'resource_unavailable', 'message' => $e->getMessage()],
            ], 404)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            return response()->json([
                'error' => ['code' => 'correction_unavailable', 'message' => $e->getMessage()],
            ], 503)->header('Cache-Control', 'private, no-store');
        }
    }

    public function uncertain(Request $request, string $case_id, string $effect_ref): JsonResponse
    {
        if (! Str::isUuid($case_id) || ! Str::isUuid($effect_ref)) {
            return response()->json([
                'error' => ['code' => 'resource_unavailable', 'message' => 'Invalid ID format.'],
            ], 404)->header('Cache-Control', 'private, no-store');
        }

        try {
            $res = $this->recordService->recordUncertain($case_id, $effect_ref, $request->all());
            return response()->json(['data' => $res['data']], $res['status_code'])
                ->header('Cache-Control', 'private, no-store');
        } catch (PromptValidationException $e) {
            return response()->json([
                'error' => ['code' => 'contract_invalid', 'message' => $e->getMessage()],
            ], 422)->header('Cache-Control', 'private, no-store');
        } catch (PromptConflictException $e) {
            return response()->json([
                'error' => ['code' => 'repair_conflict', 'message' => $e->getMessage()],
            ], 409)->header('Cache-Control', 'private, no-store');
        } catch (PromptUnavailableException $e) {
            return response()->json([
                'error' => ['code' => 'resource_unavailable', 'message' => $e->getMessage()],
            ], 404)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            return response()->json([
                'error' => ['code' => 'correction_unavailable', 'message' => $e->getMessage()],
            ], 503)->header('Cache-Control', 'private, no-store');
        }
    }
}
