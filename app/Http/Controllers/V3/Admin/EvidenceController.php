<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Services\V3\Evidence\EvidenceConflictException;
use App\Services\V3\Evidence\EvidenceExpiredException;
use App\Services\V3\Evidence\EvidenceExporter;
use App\Services\V3\Evidence\EvidenceTooLargeException;
use App\Services\V3\Evidence\EvidenceUnavailableException;
use App\Services\V3\Evidence\MetricProjector;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

class EvidenceController extends Controller
{
    public function __construct(
        protected MetricProjector $metricProjector,
        protected ?EvidenceExporter $exporter = null
    ) {
        $this->exporter = $exporter ?? app(EvidenceExporter::class);
    }

    public function metrics(Request $request)
    {
        $allQuery = $request->query();
        $allowedParams = ['from', 'to'];
        foreach (array_keys($allQuery) as $param) {
            if (! in_array($param, $allowedParams, true)) {
                return response()->json([
                    'error' => [
                        'code' => 'contract_invalid',
                        'message' => 'Unexpected parameter provided.',
                    ],
                ], 422)->header('Cache-Control', 'private, no-store');
            }
        }

        if (! $request->has('from') || ! $request->has('to')) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'Missing from or to parameter.',
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        $rawFrom = str_replace(' ', '+', (string) $request->query('from'));
        $rawTo = str_replace(' ', '+', (string) $request->query('to'));

        try {
            $from = Carbon::parse($rawFrom);
            $to = Carbon::parse($rawTo);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'Invalid timestamp format.',
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        if ($from->gte($to)) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'From timestamp must be before to timestamp.',
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        $diffSeconds = $from->diffInSeconds($to);
        if ($diffSeconds < 60 || $diffSeconds > (31 * 86400)) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'Period must be between 1 minute and 31 days.',
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        $report = $this->metricProjector->projectAdminMetrics($from, $to);

        if ($request->expectsJson() || $request->is('api/*') || $request->wantsJson()) {
            return response()->json([
                'data' => $report,
            ], 200)->header('Cache-Control', 'private, no-store');
        }

        return \App\Support\InertiaPage::render('v3.admin.evidence.index', [
            'report' => $report,
            'from' => $rawFrom,
            'to' => $rawTo,
        ], 200)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request)
    {
        $request->query->add([
            'from' => $request->query('from', now()->subDays(7)->toIso8601String()),
            'to' => $request->query('to', now()->toIso8601String()),
        ]);
        return $this->metrics($request);
    }

    public function export(Request $request): JsonResponse
    {
        $allowedKeys = ['schema_version', 'idempotency_key', 'from', 'to', '_token'];
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
        $rawFrom = $request->input('from');
        $rawTo = $request->input('to');

        if ($schemaVersion !== 1 || ! is_string($idempotencyKey) || ! Str::isUuid($idempotencyKey) || ! is_string($rawFrom) || ! is_string($rawTo)) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'Invalid export request payload.',
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        try {
            $from = Carbon::parse(str_replace(' ', '+', $rawFrom));
            $to = Carbon::parse(str_replace(' ', '+', $rawTo));
        } catch (\Throwable $e) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'Invalid timestamp format.',
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        if ($from->gte($to)) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'From timestamp must be before to timestamp.',
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        $diffSeconds = $from->diffInSeconds($to);
        if ($diffSeconds < 60 || $diffSeconds > (31 * 86400)) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'Period must be between 1 minute and 31 days.',
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        try {
            $result = $this->exporter->exportAdmin($request->user(), $request->all());
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
        }

        $export = $result['export'];
        $downloadUrl = route('v3.admin.evidence-exports.download', [
            'export' => $export->id,
        ]);

        return response()->json([
            'data' => [
                'export_ref' => (string) $export->id,
                'scope' => 'admin_period',
                'as_of' => $export->as_of->toIso8601String(),
                'expires_at' => $export->expires_at->toIso8601String(),
                'download_url' => $downloadUrl,
            ],
        ], $result['status'])->header('Cache-Control', 'private, no-store');
    }

    public function download(Request $request, string $export): Response|JsonResponse
    {
        if (! Str::isUuid($export)) {
            return $this->resourceUnavailable($request);
        }

        try {
            $result = $this->exporter->downloadAdmin($request->user(), $export);
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
