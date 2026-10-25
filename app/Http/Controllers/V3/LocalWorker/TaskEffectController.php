<?php

namespace App\Http\Controllers\V3\LocalWorker;

use App\Http\Controllers\Controller;
use App\Models\V3\LocalAssignment;
use App\Models\V3\LocalArtifact;
use App\Models\V3\LocalEffectAttempt;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalProviderEffect;
use App\Models\V3\LocalTask;
use App\Models\V3\ProcessingDevice;
use App\Models\V3\SubjectRun;
use App\Services\V3\LocalWorker\LocalWorkerFencingService;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerJsonDecoder;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerProtocolException;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerV1Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class TaskEffectController extends Controller
{
    public function __construct(
        protected LocalWorkerFencingService $fencingService
    ) {}

    private function lockAndVerifyFence(
        Request $request,
        LocalTask $task,
        LocalAssignment $assignment,
        ProcessingDevice $device,
        bool $allowReconciliation = false
    ): array {
        $lockedTask = LocalTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
        $lockedAssignment = LocalAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
        $verifiedAssignment = $this->fencingService->verifyAssignmentFencing(
            $request,
            $lockedTask,
            $device,
            $allowReconciliation,
            true
        );

        return [$lockedTask, $verifiedAssignment ?? $lockedAssignment];
    }

    private function verifyActiveAssignment(Request $request, string $taskId, bool $allowReconciliation = false): array
    {
        /** @var ProcessingDevice $device */
        $device = $request->attributes->get('local_worker_device');
        if (!$device) {
            abort(response()->json(['error' => ['code' => 'unauthorized', 'message' => 'Device authentication required.']], 401));
        }

        $this->fencingService->verifyDeviceActive($device);

        $task = LocalTask::find($taskId);
        if (!$task) {
            abort(response()->json(['error' => ['code' => 'not_found', 'message' => 'Task not found.']], 404));
        }

        $job = LocalJob::find($task->job_id);
        if (!$job) {
            abort(response()->json(['error' => ['code' => 'not_found', 'message' => 'Job not found.']], 404));
        }

        try {
            $this->fencingService->verifyDeviceGrant($device, $job);
        } catch (LocalWorkerProtocolException) {
            abort(response()->json(['error' => ['code' => 'not_found', 'message' => 'Task not found.']], 404));
        }

        try {
            $assignment = $this->fencingService->verifyAssignmentFencing($request, $task, $device, $allowReconciliation, true);
        } catch (LocalWorkerProtocolException $e) {
            abort(response()->json(['error' => ['code' => $e->getProtocolCode(), 'message' => $e->getMessage()]], $e->getStatusCode()));
        }

        return [$task, $assignment, $device];
    }

    /**
     * Reserve/replay logical provider-effect intent.
     * POST /api/v3/local-worker/v1/tasks/{task}/effects/reserve
     */
    public function reserve(Request $request, string $task): JsonResponse
    {
        [$localTask, $assignment, $device] = $this->verifyActiveAssignment($request, $task);

        $payload = LocalWorkerJsonDecoder::decode($request->getContent(), 262144);
        LocalWorkerV1Validator::validateEffectReserveRequest($payload);

        $effectId = strtolower($payload['effect_id']);
        $logicalKey = $payload['logical_effect_key'];
        $opKind = $payload['operation_kind'];
        $digest = strtolower($payload['request_digest']);

        return DB::transaction(function () use ($request, $localTask, $assignment, $device, $effectId, $logicalKey, $opKind, $digest, $payload) {
            [$localTask] = $this->lockAndVerifyFence($request, $localTask, $assignment, $device);
            // Check if logical key already exists for this task
            $existing = LocalProviderEffect::where('task_id', $localTask->id)
                ->where('logical_effect_key', $logicalKey)
                ->first();

            if ($existing) {
                // If request digest differs, conflict
                if ($existing->request_digest !== $digest) {
                    return response()->json([
                        'error' => [
                            'code' => 'idempotency_conflict',
                            'message' => 'Logical effect already reserved with differing request digest.',
                        ],
                    ], 409);
                }

                $terminalRecovery = null;
                if ($existing->operation_kind === 'terminal_pipeline_export' && $existing->status === 'reserved' && $existing->outcome_ref) {
                    $evidence = LocalArtifact::whereKey($existing->outcome_ref)->where('task_id', $localTask->id)
                        ->where('purpose', 'reconciliation_evidence')->where('is_committed', true)->firstOrFail();
                    $bytes = Storage::disk('v3_private')->get($evidence->storage_path);
                    if (hash('sha256', $bytes) !== $evidence->digest_sha256 || strlen($bytes) !== $evidence->size_bytes) {
                        throw new LocalWorkerProtocolException('Terminal recovery evidence changed.', 'artifact_mismatch', 422);
                    }
                    $terminalRecovery = LocalWorkerJsonDecoder::decode($bytes, 262144);
                }
                return response()->json([
                    'schema_version' => 1,
                    'effect_id' => $existing->id,
                    'logical_effect_key' => $existing->logical_effect_key,
                    'operation_kind' => $existing->operation_kind,
                    'status' => $existing->status,
                    'current_attempt_id' => $existing->current_attempt_id,
                    'attempt_count' => $existing->attempt_count,
                    'terminal_recovery' => $terminalRecovery,
                ], 200);
            }

            // Create new logical effect
            $effect = LocalProviderEffect::create([
                'id' => $effectId,
                'task_id' => $localTask->id,
                'job_id' => $localTask->job_id,
                'logical_effect_key' => $logicalKey,
                'operation_kind' => $opKind,
                'pdf_ordinal' => $payload['pdf_ordinal'] ?? null,
                'lesson_ordinal' => $payload['lesson_ordinal'] ?? null,
                'scene_ordinal' => $payload['scene_ordinal'] ?? null,
                'request_digest' => $digest,
                'status' => 'reserved',
                'attempt_count' => 0,
            ]);

            return response()->json([
                'schema_version' => 1,
                'effect_id' => $effect->id,
                'logical_effect_key' => $effect->logical_effect_key,
                'operation_kind' => $effect->operation_kind,
                'status' => 'reserved',
                'current_attempt_id' => null,
                'attempt_count' => 0,
            ], 201);
        });
    }

    /**
     * Commit pre-send dispatch fence and issue dispatch permit.
     * POST /api/v3/local-worker/v1/tasks/{task}/effects/{effect}/dispatch
     */
    public function dispatchPermit(Request $request, string $task, string $effect): JsonResponse
    {
        [$localTask, $assignment, $device] = $this->verifyActiveAssignment($request, $task);

        $payload = LocalWorkerJsonDecoder::decode($request->getContent(), 262144);
        LocalWorkerV1Validator::validateEffectDispatchRequest($payload);

        $workerProcessId = $payload['worker_process_id'];

        return DB::transaction(function () use ($request, $localTask, $assignment, $device, $effect, $workerProcessId) {
            [$localTask, $assignment] = $this->lockAndVerifyFence($request, $localTask, $assignment, $device);
            $providerEffect = LocalProviderEffect::where('id', strtolower($effect))
                ->where('task_id', $localTask->id)
                ->lockForUpdate()
                ->first();

            if (!$providerEffect) {
                return response()->json(['error' => ['code' => 'not_found', 'message' => 'Effect not found.']], 404);
            }

            // If already settled, cannot re-dispatch without recovery
            if (in_array($providerEffect->status, ['succeeded', 'failed_no_effect'], true)) {
                return response()->json([
                    'error' => [
                        'code' => 'effect_already_settled',
                        'message' => "Effect has already settled in status '{$providerEffect->status}'.",
                    ],
                ], 409);
            }

            // If current attempt is dispatching and valid, return existing permit
            if ($providerEffect->current_attempt_id) {
                $currentAttempt = LocalEffectAttempt::find($providerEffect->current_attempt_id);
                if ($currentAttempt) {
                    if ($currentAttempt->status === 'issued' && now()->lessThan($currentAttempt->permit_expires_at)) {
                        // Process ID binding check (LW-R06)
                        if ($currentAttempt->worker_process_id !== null && $currentAttempt->worker_process_id !== $workerProcessId) {
                            return response()->json([
                                'error' => [
                                    'code' => 'reconciliation_required',
                                    'message' => 'Cannot rebind active permit to a different worker process without reconciliation.',
                                ],
                            ], 409);
                        }

                        return response()->json([
                            'schema_version' => 1,
                            'permit_id' => $currentAttempt->permit_id,
                            'effect_id' => $providerEffect->id,
                            'attempt_id' => $currentAttempt->id, // LW-R16
                            'attempt_number' => $currentAttempt->attempt_number,
                            'device_id' => $device->id,
                            'assignment_id' => $assignment->id,
                            'assignment_epoch' => $assignment->assignment_epoch,
                            'worker_process_id' => $workerProcessId,
                            'issued_at' => $currentAttempt->permit_issued_at->toIso8601String(),
                            'send_not_after' => $currentAttempt->permit_expires_at->toIso8601String(),
                        ], 200);
                    }

                    // LW-R06: If previous attempt expired or status is unknown, require reconciliation
                    if (in_array($currentAttempt->status, ['issued', 'unknown'], true) || $providerEffect->status === 'unknown') {
                        return response()->json([
                            'error' => [
                                'code' => 'reconciliation_required',
                                'message' => 'Previous attempt expired or settled as unknown; explicit reconciliation required.',
                            ],
                        ], 409);
                    }
                }
            }

            // Monotonically increase attempt number
            $nextAttemptNum = $providerEffect->attempt_count + 1;
            $permitId = (string) Str::uuid();
            $attemptId = (string) Str::uuid();

            // Permit expires in min(10s, lease_expires_at)
            $permitExpiresAt = now()->addSeconds(10);
            if ($permitExpiresAt->greaterThan($assignment->lease_expires_at)) {
                $permitExpiresAt = $assignment->lease_expires_at;
            }

            $attempt = LocalEffectAttempt::create([
                'id' => $attemptId,
                'effect_id' => $providerEffect->id,
                'attempt_number' => $nextAttemptNum,
                'device_id' => $device->id,
                'assignment_id' => $assignment->id,
                'assignment_epoch' => $assignment->assignment_epoch,
                'worker_process_id' => $workerProcessId,
                'permit_id' => $permitId,
                'permit_issued_at' => now(),
                'permit_expires_at' => $permitExpiresAt,
                'status' => 'issued',
            ]);

            $providerEffect->status = 'dispatching';
            $providerEffect->current_attempt_id = $attemptId;
            $providerEffect->attempt_count = $nextAttemptNum;
            $providerEffect->save();

            return response()->json([
                'schema_version' => 1,
                'permit_id' => $permitId,
                'effect_id' => $providerEffect->id,
                'attempt_id' => $attemptId, // LW-R16
                'attempt_number' => $nextAttemptNum,
                'device_id' => $device->id,
                'assignment_id' => $assignment->id,
                'assignment_epoch' => $assignment->assignment_epoch,
                'worker_process_id' => $workerProcessId,
                'issued_at' => $attempt->permit_issued_at->toIso8601String(),
                'send_not_after' => $permitExpiresAt->toIso8601String(),
            ], 200);
        });
    }

    /**
     * Record actual outcome evidence of an effect.
     * POST /api/v3/local-worker/v1/tasks/{task}/effects/{effect}/outcome
     */
    public function outcome(Request $request, string $task, string $effect): JsonResponse
    {
        [$localTask, $assignment, $device] = $this->verifyActiveAssignment($request, $task, true);

        $payload = LocalWorkerJsonDecoder::decode($request->getContent(), 262144);
        LocalWorkerV1Validator::validateEffectOutcomeRequest($payload);

        return DB::transaction(function () use ($request, $localTask, $assignment, $device, $effect, $payload) {
            [$localTask] = $this->lockAndVerifyFence($request, $localTask, $assignment, $device, true);
            $providerEffect = LocalProviderEffect::where('id', strtolower($effect))
                ->where('task_id', $localTask->id)
                ->lockForUpdate()
                ->first();

            if (!$providerEffect) {
                return response()->json(['error' => ['code' => 'not_found', 'message' => 'Effect not found.']], 404);
            }

            $status = $payload['status'];
            $outcomeRef = $payload['outcome_ref'];

            $settledStatuses = ['succeeded', 'failed_no_effect', 'unknown', 'authentication_required', 'rate_limited'];
            if (in_array($providerEffect->status, $settledStatuses, true)) {
                if ($providerEffect->status === $status && $providerEffect->outcome_ref === $outcomeRef) {
                    return response()->json([
                        'schema_version' => 1,
                        'effect_id' => $providerEffect->id,
                        'status' => $providerEffect->status,
                        'outcome_ref' => $providerEffect->outcome_ref,
                        'attempt_count' => $providerEffect->attempt_count,
                    ], 200);
                }

                return response()->json([
                    'error' => [
                        'code' => 'idempotency_conflict',
                        'message' => 'A settled provider effect outcome is immutable.',
                    ],
                ], 409);
            }

            $providerEffect->status = $status;
            $providerEffect->outcome_ref = $outcomeRef;
            $providerEffect->save();

            if ($providerEffect->current_attempt_id) {
                $attempt = LocalEffectAttempt::find($providerEffect->current_attempt_id);
                if ($attempt) {
                    $attempt->status = $status;
                    $attempt->outcome_ref = $outcomeRef;
                    $attempt->save();
                }
            }

            // Durable holds on task, job, and run (LW-R34)
            if ($status === 'authentication_required' || $outcomeRef === 'auth_required') {
                $localTask->status = 'paused_auth';
                $localTask->save();
                $job = LocalJob::find($localTask->job_id);
                if ($job) {
                    $job->status = 'paused_auth';
                    $job->save();
                    $run = SubjectRun::find($job->run_id);
                    if ($run) {
                        $run->state = 'paused_auth';
                        $run->save();
                    }
                }
            } elseif ($status === 'rate_limited' || $outcomeRef === 'rate_limited') {
                $localTask->status = 'paused_quota';
                $localTask->save();
                $job = LocalJob::find($localTask->job_id);
                if ($job) {
                    $job->status = 'paused_quota';
                    $job->save();
                    $run = SubjectRun::find($job->run_id);
                    if ($run) {
                        $run->state = 'paused_quota';
                        $run->save();
                    }
                }
            } elseif ($status === 'unknown') {
                $localTask->status = 'reconciliation_required';
                $localTask->save();
                $job = LocalJob::find($localTask->job_id);
                if ($job) {
                    $job->status = 'held_reconciliation';
                    $job->save();
                    $run = SubjectRun::find($job->run_id);
                    if ($run) {
                        $run->state = 'held_reconciliation';
                        $run->save();
                    }
                }
            }

            return response()->json([
                'schema_version' => 1,
                'effect_id' => $providerEffect->id,
                'status' => $providerEffect->status,
                'outcome_ref' => $providerEffect->outcome_ref,
                'attempt_count' => $providerEffect->attempt_count,
            ], 200);
        });
    }

    /**
     * Read retained effect receipt and dispatch history.
     * GET /api/v3/local-worker/v1/tasks/{task}/effects/{effect}
     */
    public function getEffect(Request $request, string $task, string $effect): JsonResponse
    {
        [$localTask] = $this->verifyActiveAssignment($request, $task, true);

        $providerEffect = LocalProviderEffect::where('id', strtolower($effect))
            ->where('task_id', $localTask->id)
            ->first();

        if (!$providerEffect) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Effect not found.']], 404);
        }

        $attempts = LocalEffectAttempt::where('effect_id', $providerEffect->id)
            ->orderBy('attempt_number', 'asc')
            ->get();

        return response()->json([
            'schema_version' => 1,
            'effect_id' => $providerEffect->id,
            'task_id' => $providerEffect->task_id,
            'logical_effect_key' => $providerEffect->logical_effect_key,
            'operation_kind' => $providerEffect->operation_kind,
            'status' => $providerEffect->status,
            'current_attempt_id' => $providerEffect->current_attempt_id,
            'attempt_count' => $providerEffect->attempt_count,
            'outcome_ref' => $providerEffect->outcome_ref,
            'attempts' => $attempts->map(fn ($a) => [
                'attempt_number' => $a->attempt_number,
                'permit_id' => $a->permit_id,
                'status' => $a->status,
                'issued_at' => $a->permit_issued_at->toIso8601String(),
                'expires_at' => $a->permit_expires_at->toIso8601String(),
            ]),
        ]);
    }
}
