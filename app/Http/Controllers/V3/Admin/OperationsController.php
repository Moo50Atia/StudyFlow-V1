<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Services\V3\Admin\QueryOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class OperationsController extends Controller
{
    public function __construct(
        protected QueryOperations $queryOperations
    ) {}

    public function index(Request $request)
    {
        try {
            $result = $this->queryOperations->listCases($request->query());
        } catch (\InvalidArgumentException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'contract_invalid',
                        'message' => 'Invalid operation request.',
                    ],
                ], 422)->header('Cache-Control', 'private, no-store');
            }
            abort(422, 'Invalid operation request.');
        }

        if ($request->expectsJson()) {
            return response()->json($result, 200)
                ->header('Cache-Control', 'private, no-store');
        }

        $admin = $request->attributes->get('v3_actor') ?? $request->user();

        return \App\Support\InertiaPage::render('v3.admin.operations.index', [
            'admin' => $admin,
            'cases' => $result['data'],
            'meta' => $result['meta'],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $case)
    {
        $detail = $this->queryOperations->getCaseDetail($case);

        if (! $detail) {
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

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $detail,
            ], 200)->header('Cache-Control', 'private, no-store');
        }

        $admin = $request->attributes->get('v3_actor') ?? $request->user();

        return \App\Support\InertiaPage::render('v3.admin.operations.show', [
            'admin' => $admin,
            'summary' => $detail['summary'],
            'actionTargets' => $detail['action_targets'],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function health(Request $request, \App\Services\V3\Admin\ProbeOperationsHealth $probe): JsonResponse|Response
    {
        try {
            $data = $probe->probe();
            return response()->json([
                'data' => $data,
            ], 200)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            return response()->json([
                'error' => [
                    'code' => 'operation_unavailable',
                    'message' => 'Operation is temporarily unavailable.',
                ],
            ], 503)->header('Cache-Control', 'private, no-store');
        }
    }
}
