<?php

namespace App\Services\V3\WebPipeline;

use App\Models\V3\PipelineEvent;
use App\Models\V3\PipelineOperation;
use App\Models\V3\SubjectRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CommitOperation
{
    /**
     * Commit operation result from internal worker.
     *
     * @param array $payload
     * @return array{status: int, outcome: string, state_version: int}
     */
    public function commit(array $payload): array
    {
        $opId = $payload['operation_id'] ?? null;
        $leaseToken = $payload['lease_token'] ?? null;
        $resultSha = $payload['result_sha256'] ?? null;
        $outcome = $payload['outcome'] ?? 'completed';
        $safeCode = $payload['safe_code'] ?? 'ok';

        if (! $opId || ! $leaseToken || ! $resultSha) {
            abort(response()->json(['error' => ['code' => 'contract_invalid', 'message' => 'Missing commit parameters']], 422));
        }

        return DB::transaction(function () use ($payload, $opId, $leaseToken, $resultSha, $outcome, $safeCode) {
            $operation = PipelineOperation::where('id', $opId)->lockForUpdate()->first();

            // Check lease token against durable record (or cache fallback)
            if (! $operation) {
                $claimKey = "v3_web_claim:{$opId}";
                $cachedClaim = Cache::get($claimKey);
                if (! $cachedClaim || $cachedClaim['lease_token'] !== $leaseToken) {
                    abort(response()->json(['error' => ['code' => 'operation_conflict', 'message' => 'Stale or invalid lease token']], 409));
                }
            } else {
                if ($operation->status === 'completed') {
                    // Check duplicate result
                    if ($operation->result_sha256 === $resultSha) {
                        return [
                            'status' => 200,
                            'outcome' => $operation->outcome,
                            'state_version' => $operation->state_version ?? 1,
                        ];
                    }

                    abort(response()->json(['error' => ['code' => 'operation_conflict', 'message' => 'Divergent result digest']], 409));
                }

                if ($operation->lease_token !== $leaseToken) {
                    abort(response()->json(['error' => ['code' => 'operation_conflict', 'message' => 'Stale or invalid lease token']], 409));
                }
            }

            $runId = $operation ? $operation->run_id : $cachedClaim['run_id'];
            $run = SubjectRun::where('id', $runId)->lockForUpdate()->first();
            if (! $run) {
                abort(response()->json(['error' => ['code' => 'artifact_missing', 'message' => 'Run not found']], 404));
            }

            $expectedVersion = $payload['expected_version'] ?? null;
            if ($expectedVersion !== null) {
                if ($operation && $operation->expected_version !== null && (int) $expectedVersion !== (int) $operation->expected_version) {
                    abort(response()->json(['error' => ['code' => 'operation_conflict', 'message' => 'Conflicting expected_version']], 409));
                }
                if ((int) $expectedVersion !== (int) $run->state_version) {
                    abort(response()->json(['error' => ['code' => 'operation_conflict', 'message' => 'Stale state_version']], 409));
                }
            }

            $run->state_version += 1;
            $run->last_event_sequence += 1;
            $run->save();

            // Record safe event
            PipelineEvent::create([
                'id' => (string) Str::uuid(),
                'run_id' => $run->id,
                'sequence' => $run->last_event_sequence,
                'operation_id' => Str::isUuid($opId) ? $opId : null,
                'kind' => $operation ? $operation->kind : ($cachedClaim['kind'] ?? 'operation'),
                'phase' => 'execution',
                'outcome' => $outcome,
                'safe_code' => $safeCode,
                'attempt' => 1,
                'state_version' => $run->state_version,
                'counts_json' => '{}',
                'occurred_at' => now(),
                'historical' => false,
            ]);

            if ($operation) {
                $operation->update([
                    'status' => 'completed',
                    'outcome' => $outcome,
                    'safe_code' => $safeCode,
                    'result_ref' => $payload['result_ref'] ?? null,
                    'result_sha256' => $resultSha,
                    'state_version' => $run->state_version,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                ]);
            }

            // Cache acceleration
            $resultKey = "v3_web_result:{$opId}";
            Cache::put($resultKey, [
                'result_sha256' => $resultSha,
                'outcome' => $outcome,
                'safe_code' => $safeCode,
                'state_version' => $run->state_version,
            ], 3600);

            return [
                'status' => 201,
                'outcome' => $outcome,
                'state_version' => $run->state_version,
            ];
        });
    }
}
