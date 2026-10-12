<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Models\V3\SubjectRun;
use App\Services\V3\WebPipeline\AdminRunSummary;
use App\Services\V3\WebPipeline\ApplyRunControl;
use App\Services\V3\WebPipeline\CommandConflictException;
use App\Services\V3\WebPipeline\StaleVersionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SubjectRunSummaryController extends Controller
{
    /**
     * List all runs with safe operational metadata only.
     */
    public function index(Request $request, AdminRunSummary $summary): JsonResponse|\Illuminate\View\View
    {
        $limit = max(1, min(100, (int) $request->query('limit', 25)));
        $runs = $summary->listRuns($limit);

        if (! $request->expectsJson()) {
            return view('v3.admin.subject-runs.index', [
                'runs' => $runs,
            ]);
        }

        return response()->json([
            'data' => $runs,
        ])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Show safe operational summary for a single run.
     */
    public function show(Request $request, SubjectRun $run, AdminRunSummary $summary): JsonResponse
    {
        $data = $summary->getSummary($run);

        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    /**
     * Submit an admin control command (pause/resume/cancel only).
     */
    public function command(
        Request $request,
        SubjectRun $run,
        ApplyRunControl $control
    ): JsonResponse {
        $user = $request->user();

        try {
            $result = $control->apply($user, $run, $request->all(), true);
            $statusCode = ($result['status'] ?? '') === 'replayed' ? 200 : 202;

            return response()->json($result, $statusCode)->header('Cache-Control', 'private, no-store');
        } catch (CommandConflictException $e) {
            return response()->json([
                'error' => [
                    'code' => $e->getSafeCode(),
                    'message' => $e->getMessage(),
                ],
            ], 409)->header('Cache-Control', 'private, no-store');
        } catch (StaleVersionException $e) {
            return response()->json([
                'error' => [
                    'code' => $e->getSafeCode(),
                    'message' => $e->getMessage(),
                ],
            ], 409)->header('Cache-Control', 'private, no-store');
        } catch (HttpException $e) {
            return response()->json([
                'error' => [
                    'code' => 'forbidden_action',
                    'message' => $e->getMessage(),
                ],
            ], $e->getStatusCode())->header('Cache-Control', 'private, no-store');
        }
    }
}
