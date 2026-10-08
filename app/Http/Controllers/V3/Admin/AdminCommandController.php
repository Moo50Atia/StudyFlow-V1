<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Models\V3\ProcessingCase;
use App\Services\V3\Admin\SubmitAdminCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminCommandController extends Controller
{
    public function __construct(
        protected SubmitAdminCommand $submitAdminCommand
    ) {}

    public function store(Request $request, string $case): JsonResponse|RedirectResponse
    {
        if (! Str::isUuid($case) || strtolower($case) !== $case) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'resource_unavailable',
                        'message' => 'V3 resource is unavailable.',
                    ],
                ], 404)->header('Cache-Control', 'private, no-store');
            }
            abort(404, 'V3 resource is unavailable.');
        }

        $caseModel = ProcessingCase::find($case);
        if (! $caseModel) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'resource_unavailable',
                        'message' => 'V3 resource is unavailable.',
                    ],
                ], 404)->header('Cache-Control', 'private, no-store');
            }
            abort(404, 'V3 resource is unavailable.');
        }

        $actor = $request->attributes->get('v3_actor') ?? $request->user();

        try {
            $result = $this->submitAdminCommand->submit($case, $actor->id, $request->all());
        } catch (\InvalidArgumentException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'contract_invalid',
                        'message' => 'Invalid operation request.',
                    ],
                ], 422)->header('Cache-Control', 'private, no-store');
            }
            return back()->withErrors(['message' => 'Invalid operation request.']);
        } catch (\DomainException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'operation_conflict',
                        'message' => 'Operation changed. Reload and retry.',
                    ],
                ], 409)->header('Cache-Control', 'private, no-store');
            }
            return back()->withErrors(['message' => 'Operation changed. Reload and retry.']);
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'operation_unavailable',
                        'message' => 'Operation is temporarily unavailable.',
                    ],
                ], 503)->header('Cache-Control', 'private, no-store');
            }
            abort(503, 'Operation is temporarily unavailable.');
        }

        $statusCode = $result['replay'] ? 200 : 201;

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'command_ref' => $result['command_ref'],
                    'action' => $result['action'],
                    'status' => $result['status'],
                    'control_mode' => $result['control_mode'],
                    'control_version' => $result['control_version'],
                ],
            ], $statusCode)->header('Cache-Control', 'private, no-store');
        }

        return redirect()->route('v3.admin.operations.show', $case, 303)
            ->header('Cache-Control', 'private, no-store');
    }
}
