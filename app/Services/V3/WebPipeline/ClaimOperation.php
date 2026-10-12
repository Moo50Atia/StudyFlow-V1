<?php

namespace App\Services\V3\WebPipeline;

use App\Models\V3\PipelineOperation;
use App\Models\V3\SubjectRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ClaimOperation
{
    /**
     * Claim an operation lease for worker execution.
     *
     * @param array $payload
     * @return array{status: int, lease_token: string, context_ref: string}
     */
    public function claim(array $payload): array
    {
        $runId = $payload['run_id'] ?? null;
        $opId = $payload['operation_id'] ?? null;
        $reqSha = $payload['request_sha256'] ?? null;
        $expectedVersion = $payload['expected_version'] ?? null;

        if (! $runId || ! $opId || ! $reqSha) {
            abort(response()->json(['error' => ['code' => 'contract_invalid', 'message' => 'Missing required claim parameters']], 422));
        }

        $run = SubjectRun::find($runId);
        if (! $run) {
            abort(response()->json(['error' => ['code' => 'artifact_missing', 'message' => 'SubjectRun not found']], 404));
        }

        return DB::transaction(function () use ($payload, $run, $runId, $opId, $reqSha, $expectedVersion) {
            $existing = PipelineOperation::where('id', $opId)->lockForUpdate()->first();

            if ($existing) {
                if ($existing->request_sha256 === $reqSha) {
                    return [
                        'status' => 200,
                        'lease_token' => $existing->lease_token,
                        'context_ref' => $existing->context_ref,
                    ];
                }

                abort(response()->json([
                    'error' => [
                        'code' => 'operation_conflict',
                        'message' => "Conflicting request_sha256 for operation {$opId}",
                    ],
                ], 409));
            }

            if ($expectedVersion !== null && (int) $expectedVersion !== (int) $run->state_version) {
                abort(response()->json([
                    'error' => [
                        'code' => 'operation_conflict',
                        'message' => "Stale expected_version for operation {$opId}",
                    ],
                ], 409));
            }

            $leaseToken = (string) Str::uuid();
            $contextRef = (string) Str::uuid();

            PipelineOperation::create([
                'id' => $opId,
                'run_id' => $runId,
                'item_id' => $payload['item_id'] ?? null,
                'kind' => $payload['kind'] ?? 'unknown',
                'expected_version' => $expectedVersion ?? 1,
                'request_sha256' => $reqSha,
                'lease_token' => $leaseToken,
                'context_ref' => $contextRef,
                'lease_expires_at' => now()->addMinutes(10),
                'status' => 'pending',
            ]);

            // Cache is optional acceleration
            Cache::put("v3_web_claim:{$opId}", [
                'operation_id' => $opId,
                'run_id' => $runId,
                'item_id' => $payload['item_id'] ?? null,
                'kind' => $payload['kind'] ?? 'unknown',
                'expected_version' => $expectedVersion,
                'request_sha256' => $reqSha,
                'lease_token' => $leaseToken,
                'context_ref' => $contextRef,
            ], 600);

            return [
                'status' => 201,
                'lease_token' => $leaseToken,
                'context_ref' => $contextRef,
            ];
        });
    }
}
