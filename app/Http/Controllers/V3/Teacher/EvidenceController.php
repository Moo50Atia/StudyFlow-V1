<?php

namespace App\Http\Controllers\V3\Teacher;

use App\Http\Controllers\Controller;
use App\Models\V3\ProcessingCase;
use App\Services\V3\Evidence\EvidenceConflictException;
use App\Services\V3\Evidence\EvidenceExpiredException;
use App\Services\V3\Evidence\EvidenceExporter;
use App\Services\V3\Evidence\EvidenceProjector;
use App\Services\V3\Evidence\EvidenceTooLargeException;
use App\Services\V3\Evidence\EvidenceUnavailableException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class EvidenceController extends Controller
{
    public function __construct(
        protected EvidenceProjector $projector = new EvidenceProjector,
        protected ?EvidenceExporter $exporter = null
    ) {
        $this->exporter = $exporter ?? app(EvidenceExporter::class);
    }

    /**
     * Display the evidence timeline for an owned case.
     */
    public function show(Request $request, string $case): JsonResponse|View|Response
    {
        $caseModel = $this->resolveOwnedCase($request, $case);
        if ($caseModel instanceof JsonResponse) {
            return $caseModel;
        }

        $page = 1;
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
        }

        $timeline = $this->projector->projectCaseTimeline($caseModel, $page, 20);

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'case_ref' => $timeline['case_ref'],
                    'as_of' => $timeline['as_of'],
                    'integrity' => $timeline['integrity'],
                    'current_approval_refs' => $timeline['current_approval_refs'],
                    'historical_approval_refs' => $timeline['historical_approval_refs'],
                    'events' => $timeline['events'],
                    'gaps' => $timeline['gaps'],
                ],
                'meta' => $timeline['meta'],
            ])->header('Cache-Control', 'private, no-store');
        }

        return view('v3.teacher.evidence.show', [
            'case' => $caseModel,
            'evidence' => $timeline,
        ]);
    }

    /**
     * Display the approval-specific graph for an owned case.
     */
    public function approval(Request $request, string $case, string $approval): JsonResponse|View|Response
    {
        $caseModel = $this->resolveOwnedCase($request, $case);
        if ($caseModel instanceof JsonResponse) {
            return $caseModel;
        }

        if (! Str::isUuid($approval)) {
            return $this->resourceUnavailable($request);
        }

        $graph = $this->projector->projectApprovalGraph($caseModel, $approval);
        if ($graph === null) {
            return $this->resourceUnavailable($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $graph,
            ])->header('Cache-Control', 'private, no-store');
        }

        return view('v3.teacher.evidence.approval', [
            'case' => $caseModel,
            'graph' => $graph,
        ]);
    }

    /**
     * Create or replay an evidence export for an owned case.
     */
    public function export(Request $request, string $case): JsonResponse|RedirectResponse
    {
        $caseModel = $this->resolveOwnedCase($request, $case);
        if ($caseModel instanceof JsonResponse) {
            return $caseModel;
        }

        $allowedKeys = ['schema_version', 'idempotency_key', 'scope', 'approval_ref', '_token'];
        foreach (array_keys($request->all()) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                return response()->json([
                    'error' => [
                        'code' => 'contract_invalid',
                        'message' => 'Unexpected parameter provided.',
                    ],
                ], 422)->header('Cache-Control', 'private, no-store');
            }
        }

        $schemaVersion = $request->input('schema_version');
        $idempotencyKey = $request->input('idempotency_key');
        $scope = $request->input('scope');
        $approvalRef = $request->input('approval_ref');

        if ($schemaVersion !== 1 || ! is_string($idempotencyKey) || ! Str::isUuid($idempotencyKey) || ! in_array($scope, ['owner_case', 'owner_approval'], true)) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'Invalid export request payload.',
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        if ($scope === 'owner_approval' && (! is_string($approvalRef) || ! Str::isUuid($approvalRef))) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'approval_ref is required for owner_approval scope.',
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        try {
            $result = $this->exporter->exportTeacher($request->user(), $caseModel, $request->all());
        } catch (EvidenceConflictException $e) {
            return response()->json([
                'error' => [
                    'code' => 'evidence_conflict',
                    'message' => 'Idempotency conflict.',
                ],
            ], 409)->header('Cache-Control', 'private, no-store');
        } catch (EvidenceTooLargeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'evidence_too_large',
                    'message' => 'Snapshot exceeds 10 MiB limit.',
                ],
            ], 413)->header('Cache-Control', 'private, no-store');
        } catch (ModelNotFoundException $e) {
            return $this->resourceUnavailable($request);
        }

        $export = $result['export'];
        $downloadUrl = route('v3.teacher.evidence-exports.download', [
            'case' => $caseModel->id,
            'export' => $export->id,
        ]);

        if (! $request->expectsJson() && ! $request->is('api/*') && ! $request->wantsJson()) {
            return redirect()->away($downloadUrl, 303)->header('Cache-Control', 'private, no-store');
        }

        return response()->json([
            'data' => [
                'export_ref' => (string) $export->id,
                'scope' => (string) $export->scope,
                'as_of' => $export->as_of->toIso8601String(),
                'expires_at' => $export->expires_at->toIso8601String(),
                'download_url' => $downloadUrl,
            ],
        ], $result['status'])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Download the snapshot JSON for an owned export.
     */
    public function download(Request $request, string $case, string $export): Response|JsonResponse
    {
        $caseModel = $this->resolveOwnedCase($request, $case);
        if ($caseModel instanceof JsonResponse) {
            return $caseModel;
        }

        if (! Str::isUuid($export)) {
            return $this->resourceUnavailable($request);
        }

        try {
            $result = $this->exporter->downloadTeacher($request->user(), $caseModel, $export);
        } catch (ModelNotFoundException $e) {
            return $this->resourceUnavailable($request);
        } catch (EvidenceExpiredException $e) {
            return response()->json([
                'error' => [
                    'code' => 'evidence_expired',
                    'message' => 'Export snapshot has expired.',
                ],
            ], 410)->header('Cache-Control', 'private, no-store');
        } catch (EvidenceUnavailableException $e) {
            return response()->json([
                'error' => [
                    'code' => 'evidence_unavailable',
                    'message' => 'Evidence unavailable.',
                ],
            ], 503)->header('Cache-Control', 'private, no-store');
        }

        return response($result['bytes'], 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="evidence.json"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Resolve and authorize the owned ProcessingCase.
     */
    protected function resolveOwnedCase(Request $request, string $case): ProcessingCase|JsonResponse
    {
        if (! Str::isUuid($case)) {
            return $this->resourceUnavailable($request);
        }

        /** @var ProcessingCase|null $caseModel */
        $caseModel = ProcessingCase::find($case);

        if (! $caseModel || ! Gate::forUser($request->user())->allows('view', $caseModel)) {
            return $this->resourceUnavailable($request);
        }

        return $caseModel;
    }

    /**
     * Return standardized 404 response.
     */
    protected function resourceUnavailable(Request $request): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'resource_unavailable',
                'message' => 'V3 resource is unavailable.',
            ],
        ], 404)->header('Cache-Control', 'private, no-store');
    }
}
