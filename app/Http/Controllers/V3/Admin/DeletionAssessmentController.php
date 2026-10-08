<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Models\V3\ProcessingCase;
use App\Services\V3\Admin\AssessDeletion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DeletionAssessmentController extends Controller
{
    public function __construct(
        protected AssessDeletion $assessDeletion
    ) {}

    public function store(Request $request, string $case): JsonResponse|RedirectResponse
    {
        if (! Str::isUuid($case) || strtolower($case) !== $case) {
            return $this->errorNotFound($request);
        }

        $caseModel = ProcessingCase::find($case);
        if (! $caseModel) {
            return $this->errorNotFound($request);
        }

        $actor = $request->attributes->get('v3_actor') ?? $request->user();

        try {
            $result = $this->assessDeletion->assess($actor, $case, $request->all());
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 403) {
                return $this->errorForbidden($request);
            }
            if ($e->getStatusCode() === 404) {
                return $this->errorNotFound($request);
            }
            if ($e->getStatusCode() === 409) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'error' => [
                            'code' => 'operation_conflict',
                            'message' => 'Operation changed. Reload and retry.',
                        ],
                    ], 409)->header('Cache-Control', 'no-store, private');
                }
                return back()->withErrors(['message' => 'Operation changed. Reload and retry.']);
            }
            throw $e;
        } catch (\InvalidArgumentException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'contract_invalid',
                        'message' => 'Invalid operation request.',
                    ],
                ], 422)->header('Cache-Control', 'no-store, private');
            }
            return back()->withErrors(['message' => 'Invalid operation request.']);
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'operation_unavailable',
                        'message' => 'Operation is temporarily unavailable.',
                    ],
                ], 503)->header('Cache-Control', 'no-store, private');
            }
            abort(503, 'Operation is temporarily unavailable.');
        }

        $statusCode = $result['replayed'] ? 200 : 201;

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'assessment_ref' => $result['assessment']->id,
                    'status' => $result['assessment']->status,
                    'safe_blocker' => $result['assessment']->safe_blocker,
                    'policy_version' => $result['assessment']->policy_version,
                ],
            ], $statusCode)->header('Cache-Control', 'no-store, private');
        }

        return redirect()->route('v3.admin.operations.show', $case, 303)
            ->header('Cache-Control', 'no-store, private');
    }

    protected function errorNotFound(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => [
                    'code' => 'resource_unavailable',
                    'message' => 'V3 resource is unavailable.',
                ],
            ], 404)->header('Cache-Control', 'no-store, private');
        }
        abort(404, 'V3 resource is unavailable.');
    }

    protected function errorForbidden(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => [
                    'code' => 'access_denied',
                    'message' => 'Access denied.',
                ],
            ], 403)->header('Cache-Control', 'no-store, private');
        }
        abort(403, 'Access denied.');
    }
}
