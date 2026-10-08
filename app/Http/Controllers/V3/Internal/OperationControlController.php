<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Models\V3\CaseOperationControl;
use App\Models\V3\ProcessingCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationControlController extends Controller
{
    public function show(Request $request, string $caseId): JsonResponse
    {
        try {
            $case = ProcessingCase::find($caseId);
            if (! $case) {
                return response()->json([
                    'error' => [
                        'code' => 'resource_unavailable',
                        'message' => 'V3 resource is unavailable.',
                    ],
                ], 404)->header('Cache-Control', 'private, no-store');
            }

            $control = CaseOperationControl::where('case_id', $caseId)->first();

            return response()->json([
                'data' => [
                    'mode' => $control ? $control->mode : 'normal',
                    'control_version' => $control ? (int) $control->control_version : 0,
                ],
            ], 200)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable) {
            return response()->json([
                'error' => [
                    'code' => 'operation_unavailable',
                    'message' => 'Operation is temporarily unavailable.',
                ],
            ], 503)->header('Cache-Control', 'private, no-store');
        }
    }
}
