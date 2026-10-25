<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V3\ClosedCaseSummary;
use App\Models\V3\ProcessingCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CaseSummaryController extends Controller
{
    public function index(Request $request)
    {
        if ($request->has('page')) {
            $rawPage = (string) $request->query('page');
            if (! ctype_digit($rawPage) || (int) $rawPage < 1) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'error' => [
                            'code' => 'validation_failed',
                            'message' => 'The page must be a positive integer.',
                        ],
                    ], 422)->header('Cache-Control', 'private, no-store');
                }
                abort(422, 'The page must be a positive integer.');
            }
            $page = (int) $rawPage;
        } else {
            $page = 1;
        }

        $perPage = 20;
        $casesPaginator = ProcessingCase::with('workflowLink')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $summaries = collect($casesPaginator->items())->map(function (ProcessingCase $case) {
            return ClosedCaseSummary::fromCase($case, $case->workflowLink);
        })->values()->all();

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $summaries,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'has_more' => $casesPaginator->hasMorePages(),
                ],
            ], 200)->header('Cache-Control', 'private, no-store');
        }

        $admin = $request->attributes->get('v3_actor') ?? $request->user();

        return \App\Support\InertiaPage::render('v3.admin.cases.index', [
            'admin' => $admin,
            'cases' => $summaries,
            'paginator' => $casesPaginator,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $case)
    {
        if (! Str::isUuid($case)) {
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

        /** @var ProcessingCase|null $caseModel */
        $caseModel = ProcessingCase::with('workflowLink')->find($case);

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

        $summary = ClosedCaseSummary::fromCase($caseModel, $caseModel->workflowLink);

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $summary,
            ], 200)->header('Cache-Control', 'private, no-store');
        }

        $admin = $request->attributes->get('v3_actor') ?? $request->user();

        return \App\Support\InertiaPage::render('v3.admin.cases.summary', [
            'admin' => $admin,
            'case' => $summary,
        ])->header('Cache-Control', 'private, no-store');
    }
}
