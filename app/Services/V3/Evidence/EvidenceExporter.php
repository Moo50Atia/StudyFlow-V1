<?php

namespace App\Services\V3\Evidence;

use App\Models\User;
use App\Models\V3\ApprovedScene;
use App\Models\V3\EvidenceExport;
use App\Models\V3\EvidenceExportAudit;
use App\Models\V3\ProcessingCase;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EvidenceExporter
{
    public const MAX_SNAPSHOT_BYTES = 10485760; // 10 MiB

    public const EXPIRY_MINUTES = 15;

    public const SERIALIZER_VERSION = 'evidence-json-v1';

    public function __construct(
        protected EvidenceProjector $evidenceProjector,
        protected MetricProjector $metricProjector
    ) {}

    /**
     * Create or replay a teacher evidence export.
     *
     * @return array{status: int, export: EvidenceExport}
     *
     * @throws EvidenceConflictException
     * @throws EvidenceTooLargeException
     */
    public function exportTeacher(User $actor, ProcessingCase $case, array $payload): array
    {
        $scope = $payload['scope'];
        $idempotencyKey = strtolower($payload['idempotency_key']);
        $approvalRef = ! empty($payload['approval_ref']) ? strtolower($payload['approval_ref']) : null;

        $approvedScene = null;
        if ($scope === 'owner_approval') {
            if (! $approvalRef) {
                throw new \InvalidArgumentException('approval_ref is required for owner_approval scope.');
            }
            $approvedScene = ApprovedScene::where('id', $approvalRef)
                ->where('case_id', $case->id)
                ->first();
            if (! $approvedScene) {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Approved scene not found for case.');
            }
        }

        // Canonical payload for request SHA-256
        $canonical = [
            'approval_ref' => $scope === 'owner_approval' ? $approvalRef : null,
            'case_id' => strtolower($case->id),
            'idempotency_key' => $idempotencyKey,
            'schema_version' => 1,
            'scope' => $scope,
        ];
        ksort($canonical);
        $requestSha256 = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES));

        // Exact replay check
        $existing = EvidenceExport::where('actor_user_id', $actor->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            if ($existing->request_sha256 === $requestSha256) {
                return ['status' => 200, 'export' => $existing];
            }
            throw new EvidenceConflictException('Idempotency key reused with different request payload.');
        }

        try {
            return DB::transaction(function () use ($actor, $case, $scope, $idempotencyKey, $requestSha256, $approvedScene) {
                $existingInTx = EvidenceExport::where('actor_user_id', $actor->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingInTx) {
                    if ($existingInTx->request_sha256 === $requestSha256) {
                        return ['status' => 200, 'export' => $existingInTx];
                    }
                    throw new EvidenceConflictException('Idempotency key reused with different request payload.');
                }

                $asOf = now()->setTimezone('UTC');

                if ($scope === 'owner_approval') {
                    $graph = $this->evidenceProjector->projectApprovalGraph($case, $approvedScene, $asOf);
                } else {
                    $graph = $this->evidenceProjector->projectCaseGraph($case, $asOf);
                }

                $metric = $this->metricProjector->projectCaseMetrics($case, $asOf);

                $snapshotData = [
                    'schema_version' => 1,
                    'scope' => $scope,
                    'graph' => $graph,
                    'metric' => $metric,
                ];

                $jsonBytes = json_encode($snapshotData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $byteSize = strlen($jsonBytes);
                if ($byteSize > self::MAX_SNAPSHOT_BYTES) {
                    throw new EvidenceTooLargeException('Snapshot exceeds 10 MiB limit.');
                }

                $snapshotSha256 = hash('sha256', $jsonBytes);
                $exportId = (string) Str::uuid();
                $snapshotPath = "evidence-exports/{$actor->id}/{$exportId}/{$snapshotSha256}.json";

                Storage::disk('v3_private')->put($snapshotPath, $jsonBytes);

                $export = EvidenceExport::forceCreate([
                    'id' => $exportId,
                    'actor_user_id' => $actor->id,
                    'actor_role' => 'teacher',
                    'idempotency_key' => $idempotencyKey,
                    'request_sha256' => $requestSha256,
                    'scope' => $scope,
                    'case_id' => $case->id,
                    'approval_id' => $approvedScene?->id,
                    'period_start' => null,
                    'period_end' => null,
                    'serializer_version' => self::SERIALIZER_VERSION,
                    'as_of' => $asOf,
                    'snapshot_path' => $snapshotPath,
                    'snapshot_sha256' => $snapshotSha256,
                    'snapshot_bytes' => $byteSize,
                    'expires_at' => $asOf->copy()->addMinutes(self::EXPIRY_MINUTES),
                    'file_removed_at' => null,
                    'created_at' => $asOf,
                ]);

                EvidenceExportAudit::forceCreate([
                    'id' => (string) Str::uuid(),
                    'export_id' => $exportId,
                    'actor_user_id' => $actor->id,
                    'event' => 'generated',
                    'occurred_at' => $asOf,
                    'result_code' => 'ready',
                    'snapshot_sha256' => $snapshotSha256,
                ]);

                return ['status' => 201, 'export' => $export];
            }, 5);
        } catch (QueryException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'unique') || str_contains($msg, 'UNIQUE') || str_contains($msg, 'locked') || str_contains($msg, 'busy')) {
                $raceExisting = EvidenceExport::where('actor_user_id', $actor->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($raceExisting) {
                    if ($raceExisting->request_sha256 === $requestSha256) {
                        return ['status' => 200, 'export' => $raceExisting];
                    }
                    throw new EvidenceConflictException('Idempotency key reused with different request payload.');
                }
            }
            throw $e;
        }
    }

    /**
     * Create or replay an admin evidence export.
     *
     * @return array{status: int, export: EvidenceExport}
     *
     * @throws EvidenceConflictException
     * @throws EvidenceTooLargeException
     */
    public function exportAdmin(User $actor, array $payload): array
    {
        $idempotencyKey = strtolower($payload['idempotency_key']);
        $from = Carbon::parse(str_replace(' ', '+', $payload['from']))->setTimezone('UTC');
        $to = Carbon::parse(str_replace(' ', '+', $payload['to']))->setTimezone('UTC');

        $canonical = [
            'from' => $from->toIso8601String(),
            'idempotency_key' => $idempotencyKey,
            'schema_version' => 1,
            'to' => $to->toIso8601String(),
        ];
        ksort($canonical);
        $requestSha256 = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES));

        // Exact replay check
        $existing = EvidenceExport::where('actor_user_id', $actor->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            if ($existing->request_sha256 === $requestSha256) {
                return ['status' => 200, 'export' => $existing];
            }
            throw new EvidenceConflictException('Idempotency key reused with different request payload.');
        }

        try {
            return DB::transaction(function () use ($actor, $idempotencyKey, $requestSha256, $from, $to) {
                $existingInTx = EvidenceExport::where('actor_user_id', $actor->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingInTx) {
                    if ($existingInTx->request_sha256 === $requestSha256) {
                        return ['status' => 200, 'export' => $existingInTx];
                    }
                    throw new EvidenceConflictException('Idempotency key reused with different request payload.');
                }

                $asOf = now()->setTimezone('UTC');
                $report = $this->metricProjector->projectAdminMetrics($from, $to, $asOf);

                $snapshotData = [
                    'schema_version' => 1,
                    'scope' => 'admin_period',
                    'report' => $report,
                ];

                $jsonBytes = json_encode($snapshotData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $byteSize = strlen($jsonBytes);
                if ($byteSize > self::MAX_SNAPSHOT_BYTES) {
                    throw new EvidenceTooLargeException('Snapshot exceeds 10 MiB limit.');
                }

                $snapshotSha256 = hash('sha256', $jsonBytes);
                $exportId = (string) Str::uuid();
                $snapshotPath = "evidence-exports/{$actor->id}/{$exportId}/{$snapshotSha256}.json";

                Storage::disk('v3_private')->put($snapshotPath, $jsonBytes);

                $export = EvidenceExport::forceCreate([
                    'id' => $exportId,
                    'actor_user_id' => $actor->id,
                    'actor_role' => 'admin',
                    'idempotency_key' => $idempotencyKey,
                    'request_sha256' => $requestSha256,
                    'scope' => 'admin_period',
                    'case_id' => null,
                    'approval_id' => null,
                    'period_start' => $from,
                    'period_end' => $to,
                    'serializer_version' => self::SERIALIZER_VERSION,
                    'as_of' => $asOf,
                    'snapshot_path' => $snapshotPath,
                    'snapshot_sha256' => $snapshotSha256,
                    'snapshot_bytes' => $byteSize,
                    'expires_at' => $asOf->copy()->addMinutes(self::EXPIRY_MINUTES),
                    'file_removed_at' => null,
                    'created_at' => $asOf,
                ]);

                EvidenceExportAudit::forceCreate([
                    'id' => (string) Str::uuid(),
                    'export_id' => $exportId,
                    'actor_user_id' => $actor->id,
                    'event' => 'generated',
                    'occurred_at' => $asOf,
                    'result_code' => 'ready',
                    'snapshot_sha256' => $snapshotSha256,
                ]);

                return ['status' => 201, 'export' => $export];
            }, 5);
        } catch (QueryException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'unique') || str_contains($msg, 'UNIQUE') || str_contains($msg, 'locked') || str_contains($msg, 'busy')) {
                $raceExisting = EvidenceExport::where('actor_user_id', $actor->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($raceExisting) {
                    if ($raceExisting->request_sha256 === $requestSha256) {
                        return ['status' => 200, 'export' => $raceExisting];
                    }
                    throw new EvidenceConflictException('Idempotency key reused with different request payload.');
                }
            }
            throw $e;
        }
    }

    /**
     * Download teacher evidence export bytes.
     *
     * @return array{bytes: string, export: EvidenceExport}
     *
     * @throws EvidenceExpiredException
     * @throws EvidenceUnavailableException
     */
    public function downloadTeacher(User $actor, ProcessingCase $case, string $exportId): array
    {
        $export = EvidenceExport::where('id', $exportId)
            ->where('case_id', $case->id)
            ->where('actor_user_id', $actor->id)
            ->first();

        if (! $export) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Export not found.');
        }

        if (now()->gte($export->expires_at)) {
            EvidenceExportAudit::forceCreate([
                'id' => (string) Str::uuid(),
                'export_id' => $export->id,
                'actor_user_id' => $actor->id,
                'event' => 'download_attempt',
                'occurred_at' => now(),
                'result_code' => 'expired',
                'snapshot_sha256' => null,
            ]);
            throw new EvidenceExpiredException('Export snapshot has expired.');
        }

        if (! Storage::disk('v3_private')->exists($export->snapshot_path)) {
            EvidenceExportAudit::forceCreate([
                'id' => (string) Str::uuid(),
                'export_id' => $export->id,
                'actor_user_id' => $actor->id,
                'event' => 'download_attempt',
                'occurred_at' => now(),
                'result_code' => 'file_unavailable',
                'snapshot_sha256' => null,
            ]);
            throw new EvidenceUnavailableException('Export file missing.');
        }

        $bytes = Storage::disk('v3_private')->get($export->snapshot_path);
        if (hash('sha256', $bytes) !== $export->snapshot_sha256) {
            EvidenceExportAudit::forceCreate([
                'id' => (string) Str::uuid(),
                'export_id' => $export->id,
                'actor_user_id' => $actor->id,
                'event' => 'download_attempt',
                'occurred_at' => now(),
                'result_code' => 'file_unavailable',
                'snapshot_sha256' => null,
            ]);
            throw new EvidenceUnavailableException('Export file integrity check failed.');
        }

        EvidenceExportAudit::forceCreate([
            'id' => (string) Str::uuid(),
            'export_id' => $export->id,
            'actor_user_id' => $actor->id,
            'event' => 'download_attempt',
            'occurred_at' => now(),
            'result_code' => 'stream_started',
            'snapshot_sha256' => null,
        ]);

        return ['bytes' => $bytes, 'export' => $export];
    }

    /**
     * Download admin evidence export bytes.
     *
     * @return array{bytes: string, export: EvidenceExport}
     *
     * @throws EvidenceExpiredException
     * @throws EvidenceUnavailableException
     */
    public function downloadAdmin(User $actor, string $exportId): array
    {
        $export = EvidenceExport::where('id', $exportId)
            ->where('actor_role', 'admin')
            ->where('actor_user_id', $actor->id)
            ->first();

        if (! $export) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Export not found.');
        }

        if (now()->gte($export->expires_at)) {
            EvidenceExportAudit::forceCreate([
                'id' => (string) Str::uuid(),
                'export_id' => $export->id,
                'actor_user_id' => $actor->id,
                'event' => 'download_attempt',
                'occurred_at' => now(),
                'result_code' => 'expired',
                'snapshot_sha256' => null,
            ]);
            throw new EvidenceExpiredException('Export snapshot has expired.');
        }

        if (! Storage::disk('v3_private')->exists($export->snapshot_path)) {
            EvidenceExportAudit::forceCreate([
                'id' => (string) Str::uuid(),
                'export_id' => $export->id,
                'actor_user_id' => $actor->id,
                'event' => 'download_attempt',
                'occurred_at' => now(),
                'result_code' => 'file_unavailable',
                'snapshot_sha256' => null,
            ]);
            throw new EvidenceUnavailableException('Export file missing.');
        }

        $bytes = Storage::disk('v3_private')->get($export->snapshot_path);
        if (hash('sha256', $bytes) !== $export->snapshot_sha256) {
            EvidenceExportAudit::forceCreate([
                'id' => (string) Str::uuid(),
                'export_id' => $export->id,
                'actor_user_id' => $actor->id,
                'event' => 'download_attempt',
                'occurred_at' => now(),
                'result_code' => 'file_unavailable',
                'snapshot_sha256' => null,
            ]);
            throw new EvidenceUnavailableException('Export file integrity check failed.');
        }

        EvidenceExportAudit::forceCreate([
            'id' => (string) Str::uuid(),
            'export_id' => $export->id,
            'actor_user_id' => $actor->id,
            'event' => 'download_attempt',
            'occurred_at' => now(),
            'result_code' => 'stream_started',
            'snapshot_sha256' => null,
        ]);

        return ['bytes' => $bytes, 'export' => $export];
    }
}
