<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Services\V3\Prompts\PromptConflictException;
use App\Services\V3\Prompts\PromptUnavailableException;
use App\Services\V3\Prompts\PromptValidationException;
use App\Services\V3\Review\ClaimTeacherSceneRepair;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TeacherSceneRepairController extends Controller
{
    public function __construct(
        protected ClaimTeacherSceneRepair $claimService
    ) {}

    public function claim(Request $request, string $case_id): JsonResponse
    {
        if (! Str::isUuid($case_id)) {
            return response()->json([
                'error' => ['code' => 'resource_unavailable', 'message' => 'Invalid case ID format.'],
            ], 404)->header('Cache-Control', 'private, no-store');
        }

        $schemaVersion = $request->input('schema_version');
        $routeRef = $request->input('route_ref');

        if ($schemaVersion !== 1 || ! is_string($routeRef) || ! Str::isUuid($routeRef)) {
            return response()->json([
                'error' => ['code' => 'contract_invalid', 'message' => 'Invalid request fields.'],
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        try {
            $result = $this->claimService->claimFromTeacherRoute($case_id, $routeRef);
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
                'error' => ['code' => 'repair_unavailable', 'message' => $e->getMessage()],
            ], 503)->header('Cache-Control', 'private, no-store');
        }
    }
}
