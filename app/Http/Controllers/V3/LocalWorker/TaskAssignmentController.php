<?php

namespace App\Http\Controllers\V3\LocalWorker;

use App\Http\Controllers\Controller;
use App\Models\V3\LocalArtifact;
use App\Models\V3\LocalAssignment;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalProviderEffect;
use App\Models\V3\LocalTask;
use App\Models\V3\ProcessingDevice;
use App\Services\V3\LocalWorker\LocalWorkerFencingService;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerJsonDecoder;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerProtocolException;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerV1Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TaskAssignmentController extends Controller
{
    public function __construct(
        protected LocalWorkerFencingService $fencingService
    ) {}

    /**
     * Helper to verify task ownership and assignment.
     */
    private function resolveActiveAssignment(
        Request $request,
        string $taskId,
        bool $requireMatchingHeaders = true,
        bool $allowReconciliation = false,
        bool $allowPausedRelease = false
    ): array
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

        $assignment = LocalAssignment::where('task_id', $task->id)
            ->where('device_id', $device->id)
            ->whereIn('status', $allowReconciliation ? ['active', 'reconciliation_only'] : ['active'])
            ->first();

        if (!$assignment) {
            // Check past assignment if matching headers not required
            $pastAssignment = LocalAssignment::where('task_id', $task->id)
                ->where('device_id', $device->id)
                ->latest()
                ->first();

            if ($pastAssignment && !$requireMatchingHeaders) {
                return [$task, $pastAssignment, $device];
            }

            abort(response()->json(['error' => ['code' => 'not_found', 'message' => 'No active assignment for this task.']], 404));
        }

        if ($requireMatchingHeaders) {
            $headerAssignmentId = $request->header('X-Assignment-ID');
            $headerEpoch = $request->header('X-Assignment-Epoch');

            if (!$headerAssignmentId || $headerEpoch === null) {
                abort(response()->json([
                    'error' => [
                        'code' => 'fencing_headers_missing',
                        'message' => 'X-Assignment-ID and X-Assignment-Epoch headers are required.',
                    ],
                ], 400));
            }

            if ($headerAssignmentId && strtolower($headerAssignmentId) !== strtolower($assignment->id)) {
                abort(response()->json([
                    'error' => [
                        'code' => 'epoch_conflict',
                        'message' => 'Assignment ID does not match active lease.',
                    ],
                ], 409));
            }

            if ($headerEpoch !== null && (int) $headerEpoch !== (int) $assignment->assignment_epoch) {
                abort(response()->json([
                    'error' => [
                        'code' => 'epoch_conflict',
                        'message' => 'Assignment epoch does not match current lease epoch.',
                    ],
                ], 409));
            }

            if ($allowPausedRelease && $task->status === 'paused') {
                if ($task->active_assignment_id !== $assignment->id
                    || $assignment->status !== 'active'
                    || now()->greaterThan($assignment->lease_expires_at)) {
                    abort(response()->json([
                        'error' => ['code' => 'lease_lost', 'message' => 'Paused assignment is no longer current or live.'],
                    ], 409));
                }
            } else {
                try {
                    $assignment = $this->fencingService->verifyAssignmentFencing(
                        $request,
                        $task,
                        $device,
                        $allowReconciliation,
                        true
                    );
                } catch (LocalWorkerProtocolException $e) {
                    abort(response()->json([
                        'error' => ['code' => $e->getProtocolCode(), 'message' => $e->getMessage()],
                    ], $e->getStatusCode()));
                }
            }
        }

        return [$task, $assignment, $device];
    }

    /**
     * Read this device's assignment status.
     * GET /api/v3/local-worker/v1/tasks/{task}/assignment
     */
    public function getAssignment(Request $request, string $task): JsonResponse
    {
        // Reconciliation-only assignments are created explicitly by the platform
        // owner and must remain visible to the worker so it can submit evidence.
        [$localTask, $assignment] = $this->resolveActiveAssignment($request, $task, false, true);

        return response()->json([
            'schema_version' => 1,
            'task_id' => $localTask->id,
            'assignment_id' => $assignment->id,
            'assignment_epoch' => $assignment->assignment_epoch,
            'control_version' => $assignment->control_version,
            'task_status' => $localTask->status,
            'assignment_status' => $assignment->status,
            'lease_expires_at' => $assignment->lease_expires_at->toIso8601String(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Heartbeat to renew active lease and acknowledge control version.
     * POST /api/v3/local-worker/v1/tasks/{task}/heartbeats
     */
    public function progress(Request $request, string $task): JsonResponse
    {
        $payload = LocalWorkerJsonDecoder::decode($request->getContent(), 8192);
        $allowed = ['schema_version', 'sequence', 'phase', 'status', 'lessons_saved', 'scenes_generated',
            'scenes_built', 'current_pdf_ordinal', 'pdfs_completed', 'total_pdfs'];
        abort_unless(array_diff(array_keys($payload), $allowed) === [], 422);
        $data = validator($payload, [
            'schema_version' => 'required|integer|in:1', 'sequence' => 'required|integer|min:1',
            'phase' => ['required', 'string', 'regex:/^[a-z_]{1,48}$/'],
            'status' => 'required|in:running,paused,completed,cancelled,failed,partial,prompts_exported',
            'lessons_saved' => 'required|integer|min:0|max:1000000',
            'scenes_generated' => 'required|integer|min:0|max:1000000',
            'scenes_built' => 'required|integer|min:0|max:1000000',
            'current_pdf_ordinal' => 'present|nullable|integer|min:1|max:10000',
            'pdfs_completed' => 'required|integer|min:0|max:10000',
            'total_pdfs' => 'required|integer|min:0|max:10000',
        ])->validate();
        return DB::transaction(function () use ($request, $task, $data) {
            $device = $request->attributes->get('local_worker_device');
            abort_unless($device, 401);
            $this->fencingService->verifyDeviceActive($device);
            $localTask = LocalTask::whereKey($task)->lockForUpdate()->firstOrFail();
            $this->fencingService->verifyDeviceGrant($device, $localTask->job);
            $this->fencingService->verifyTaskCapability($device, $localTask);
            $assignment = LocalAssignment::whereKey($request->header('X-Assignment-ID'))
                ->where('task_id', $task)->where('device_id', $device->id)->lockForUpdate()->first();
            // Telemetry may describe a held/expired attempt, but can never
            // renew its lease, release a hold, or overwrite a newer epoch.
            abort_unless($assignment && $request->hasHeader('X-Assignment-Epoch')
                && (int) $request->header('X-Assignment-Epoch') === (int) $assignment->assignment_epoch
                && (int) $localTask->active_epoch === (int) $assignment->assignment_epoch
                && in_array($assignment->status, ['active', 'expired', 'reconciliation_only'], true)
                && $localTask->task_kind === 'author_prompts', 409);
            $key = 'local-worker-progress:'.$task;
            $previous = \Illuminate\Support\Facades\Cache::get($key);
            if (!$previous || $previous['assignment_epoch'] !== $assignment->assignment_epoch
                || $data['sequence'] > $previous['sequence']) {
                \Illuminate\Support\Facades\Cache::put($key, [...$data,
                    'assignment_epoch' => $assignment->assignment_epoch,
                    'received_at' => now()->toIso8601String()], now()->addDay());
            }
            return response()->json(['accepted' => true]);
        });
    }

    public function heartbeat(Request $request, string $task): JsonResponse
    {
        [$localTask, $assignment] = $this->resolveActiveAssignment($request, $task, true);

        $payload = LocalWorkerJsonDecoder::decode($request->getContent(), 262144);
        LocalWorkerV1Validator::validateHeartbeatRequest($payload);

        return DB::transaction(function () use ($localTask, $assignment, $payload) {
            $localTask = LocalTask::whereKey($localTask->id)->lockForUpdate()->firstOrFail();
            $assignment = LocalAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            if ($localTask->active_assignment_id !== $assignment->id || $assignment->status !== 'active') {
                return response()->json(['error' => ['code' => 'stale_assignment', 'message' => 'Assignment is no longer current.']], 409);
            }

            if ((int) $payload['observed_control_version'] !== (int) $localTask->control_version) {
                return response()->json([
                    'error' => [
                        'code' => 'control_changed',
                        'message' => 'Observed control version does not match the current task control version.',
                        'task_status' => $localTask->status,
                        'control_version' => $localTask->control_version,
                    ],
                ], 409);
            }

            // Check if lease already expired (LW-R11)
            if (now()->greaterThan($assignment->lease_expires_at)) {
                $assignment->status = 'expired';
                $assignment->save();

                $hasUnsettledEffects = LocalProviderEffect::where('task_id', $localTask->id)
                    ->whereIn('status', ['dispatching', 'unknown'])
                    ->exists();

                if ($hasUnsettledEffects) {
                    $localTask->status = 'reconciliation_required';
                } else {
                    $localTask->status = 'queued';
                    $localTask->active_assignment_id = null;
                }
                $localTask->save();

                return response()->json([
                    'error' => [
                        'code' => 'lease_lost',
                        'message' => 'Lease has expired.',
                    ],
                ], 409);
            }

            // Check if task is paused or cancelled
            if (in_array($localTask->status, ['paused', 'cancelled'], true)) {
                return response()->json([
                    'error' => [
                        'code' => 'control_changed',
                        'message' => "Task is in state '{$localTask->status}'.",
                        'task_status' => $localTask->status,
                        'control_version' => $localTask->control_version,
                    ],
                ], 409);
            }

            // Renew lease
            $assignment->lease_expires_at = now()->addSeconds(120);
            $assignment->heartbeat_at = now();
            $assignment->save();

            return response()->json([
                'schema_version' => 1,
                'task_id' => $localTask->id,
                'assignment_id' => $assignment->id,
                'assignment_epoch' => $assignment->assignment_epoch,
                'control_version' => $assignment->control_version,
                'task_status' => $localTask->status,
                'lease_expires_at' => $assignment->lease_expires_at->toIso8601String(),
                'server_time' => now()->toIso8601String(),
            ]);
        });
    }

    /**
     * Release active assignment cleanly.
     * POST /api/v3/local-worker/v1/tasks/{task}/release
     */
    public function release(Request $request, string $task): JsonResponse
    {
        [$localTask, $assignment] = $this->resolveActiveAssignment($request, $task, true, false, true);

        $manualFilesMissing = $request->input('reason') === 'manual_scenes_missing' && $localTask->task_kind === 'return_scenes';
        return DB::transaction(function () use ($localTask, $assignment, $manualFilesMissing) {
            $localTask = LocalTask::whereKey($localTask->id)->lockForUpdate()->firstOrFail();
            $assignment = LocalAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            if ($localTask->active_assignment_id !== $assignment->id || $assignment->status !== 'active') {
                return response()->json(['error' => ['code' => 'stale_assignment', 'message' => 'Assignment is no longer current.']], 409);
            }

            // Cannot cleanly release if there are pending/unknown effects
            $unsettled = LocalProviderEffect::where('task_id', $localTask->id)
                ->whereIn('status', ['reserved', 'dispatching', 'unknown'])
                ->count();

            if ($unsettled > 0) {
                $localTask->status = 'reconciliation_required';
                $localTask->save();

                return response()->json([
                    'error' => [
                        'code' => 'reconciliation_required',
                        'message' => "Cannot release task with {$unsettled} pending/unknown provider effects. Reconciliation required.",
                    ],
                ], 409);
            }

            $assignment->status = 'released';
            $assignment->save();

            // Releasing a worker must never undo a durable owner control.
            if (!in_array($localTask->status, ['paused', 'cancelled', 'paused_auth', 'paused_quota', 'reconciliation_required'], true)) {
                $localTask->status = $manualFilesMissing ? 'awaiting_pull' : 'queued';
            }
            $localTask->active_assignment_id = null;
            $localTask->save();

            if ($localTask->status === 'awaiting_pull') {
                $job = LocalJob::findOrFail($localTask->job_id);
                $job->update(['status' => 'awaiting_generation']);
                \App\Models\V3\SubjectRun::whereKey($job->run_id)->update(['state' => 'awaiting_generation']);
            }

            return response()->json([
                'schema_version' => 1,
                'task_id' => $localTask->id,
                'status' => 'released',
            ]);
        });
    }

    /**
     * Submit reconciliation evidence or read recovery status.
     * POST /api/v3/local-worker/v1/tasks/{task}/recoveries
     */
    public function recoveries(Request $request, string $task): JsonResponse
    {
        [$localTask, $assignment] = $this->resolveActiveAssignment($request, $task, true, true);

        $payload = LocalWorkerJsonDecoder::decode($request->getContent(), 262144);
        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== ['evidence_payload', 'schema_version'] || ($payload['schema_version'] ?? null) !== 1
            || !is_array($payload['evidence_payload'] ?? null) || $payload['evidence_payload'] === []) {
            throw new LocalWorkerProtocolException(
                'Recovery accepts only schema_version and non-empty evidence_payload; outcome decisions are owner-controlled.',
                'invalid_recovery_request',
                422
            );
        }

        return DB::transaction(function () use ($localTask, $assignment, $payload) {
            $lockedTask = LocalTask::query()->lockForUpdate()->findOrFail($localTask->id);
            $lockedAssignment = LocalAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if ($lockedTask->status !== 'reconciliation_required'
                || $lockedTask->active_assignment_id !== $lockedAssignment->id
                || $lockedAssignment->status !== 'reconciliation_only') {
                throw new LocalWorkerProtocolException(
                    'Only an owner-issued reconciliation assignment may submit evidence for a held task.',
                    'reconciliation_not_authorized',
                    409
                );
            }

            $evidenceJson = json_encode($payload['evidence_payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $evidenceArtifactId = (string) Str::uuid();
            $storagePath = "local-worker-staging/{$evidenceArtifactId}";
            Storage::disk('v3_private')->put($storagePath, $evidenceJson);

            LocalArtifact::create([
                'id' => $evidenceArtifactId,
                'job_id' => $lockedTask->job_id,
                'task_id' => $lockedTask->id,
                'purpose' => 'reconciliation_evidence',
                'digest_sha256' => hash('sha256', $evidenceJson),
                'size_bytes' => strlen($evidenceJson),
                'media_type' => 'application/json',
                'logical_name' => "reconciliation_evidence_{$evidenceArtifactId}.json",
                'storage_path' => $storagePath,
                'is_quarantine' => false,
                'is_committed' => true,
            ]);

            return response()->json([
                'schema_version' => 1,
                'task_id' => $lockedTask->id,
                'assignment_id' => $lockedAssignment->id,
                'task_status' => $lockedTask->status,
                'evidence_artifact_id' => $evidenceArtifactId,
                'message' => 'Evidence recorded for owner review; no effect or task state was changed.',
            ], 200);
        });
    }
}
