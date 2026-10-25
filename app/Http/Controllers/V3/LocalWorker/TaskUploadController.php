<?php

namespace App\Http\Controllers\V3\LocalWorker;

use App\Http\Controllers\Controller;
use App\Models\V3\LocalArtifact;
use App\Models\V3\LocalAssignment;
use App\Models\V3\LocalJob;
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

class TaskUploadController extends Controller
{
    public function __construct(
        protected LocalWorkerFencingService $fencingService
    ) {}

    private function lockAndVerifyFence(Request $request, LocalTask $task, LocalAssignment $assignment, ProcessingDevice $device): array
    {
        $lockedTask = LocalTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
        $lockedAssignment = LocalAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
        $verified = $this->fencingService->verifyAssignmentFencing($request, $lockedTask, $device, false, true);

        return [$lockedTask, $verified ?? $lockedAssignment];
    }

    private function verifyDeviceAccess(Request $request, string $taskId): array
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
            $assignment = $this->fencingService->verifyAssignmentFencing($request, $task, $device, false, true);
        } catch (LocalWorkerProtocolException $e) {
            abort(response()->json([
                'error' => ['code' => $e->getProtocolCode(), 'message' => $e->getMessage()],
            ], $e->getStatusCode()));
        }

        return [$task, $assignment, $device];
    }

    /**
     * Create or replay a bounded staging slot for an artifact.
     * POST /api/v3/local-worker/v1/tasks/{task}/uploads
     */
    public function createSlot(Request $request, string $task): JsonResponse
    {
        [$localTask, $assignment, $device] = $this->verifyDeviceAccess($request, $task);

        $payload = LocalWorkerJsonDecoder::decode($request->getContent(), 262144);
        LocalWorkerV1Validator::validateUploadSlotRequest($payload);

        $uploadId = strtolower($payload['upload_id']);
        $artifactId = strtolower($payload['artifact_id']);
        $purpose = $payload['purpose'];
        $mediaType = $payload['media_type'];
        $expectedBytes = (int) $payload['expected_bytes'];
        $expectedSha256 = strtolower($payload['expected_sha256']);

        return DB::transaction(function () use ($request, $localTask, $assignment, $device, $uploadId, $artifactId, $purpose, $mediaType, $expectedBytes, $expectedSha256) {
            [$localTask] = $this->lockAndVerifyFence($request, $localTask, $assignment, $device);
            // Scope existing artifact lookup to this task (LW-R27)
            $existing = LocalArtifact::where('task_id', $localTask->id)->where('id', $uploadId)->first();
            if ($existing) {
                // If identical, return existing slot receipt
                if ($existing->digest_sha256 === $expectedSha256
                    && (int) $existing->size_bytes === $expectedBytes
                    && $existing->logical_name === $artifactId
                    && $existing->purpose === $purpose
                    && $existing->media_type === $mediaType) {
                    return response()->json([
                        'schema_version' => 1,
                        'upload_id' => $existing->id,
                        'artifact_id' => $existing->logical_name,
                        'status' => $existing->is_quarantine ? 'slot_reserved' : 'uploaded',
                        'expected_bytes' => $existing->size_bytes,
                        'expected_sha256' => $existing->digest_sha256,
                    ], 200);
                }

                return response()->json([
                    'error' => [
                        'code' => 'idempotency_conflict',
                        'message' => 'Upload ID already exists with differing parameters.',
                    ],
                ], 409);
            }

            // Idempotent reservations allocate no new bytes, so replay them
            // before applying the incremental output budget under the task lock.
            $this->fencingService->verifyUploadCeilings($purpose, $expectedBytes, $localTask);

            $storagePath = "local-worker-staging/{$uploadId}";

            LocalArtifact::create([
                'id' => $uploadId,
                'job_id' => $localTask->job_id,
                'task_id' => $localTask->id,
                'purpose' => $purpose,
                'digest_sha256' => $expectedSha256,
                'size_bytes' => $expectedBytes,
                'media_type' => $mediaType,
                'logical_name' => $artifactId,
                'storage_path' => $storagePath,
                'is_quarantine' => true,
                'is_committed' => false,
            ]);

            return response()->json([
                'schema_version' => 1,
                'upload_id' => $uploadId,
                'artifact_id' => $artifactId,
                'status' => 'slot_reserved',
                'expected_bytes' => $expectedBytes,
                'expected_sha256' => $expectedSha256,
            ], 201);
        });
    }

    /**
     * Stream raw bytes into the upload slot and verify SHA-256 + length.
     * PUT /api/v3/local-worker/v1/tasks/{task}/uploads/{upload}
     */
    public function uploadBytes(Request $request, string $task, string $upload): JsonResponse
    {
        [$localTask, $assignment, $device] = $this->verifyDeviceAccess($request, $task);

        // Stream body to storage file
        $content = $request->getContent();
        $actualBytes = strlen($content);
        $actualSha256 = hash('sha256', $content);

        return DB::transaction(function () use ($request, $localTask, $assignment, $device, $upload, $content, $actualBytes, $actualSha256) {
            [$localTask] = $this->lockAndVerifyFence($request, $localTask, $assignment, $device);
            $artifact = LocalArtifact::where('id', strtolower($upload))
                ->where('task_id', $localTask->id)
                ->lockForUpdate()
                ->first();

            if (!$artifact) {
                return response()->json(['error' => ['code' => 'not_found', 'message' => 'Upload slot not found.']], 404);
            }
            if ($artifact->is_committed) {
                return response()->json(['error' => ['code' => 'immutable_artifact_conflict', 'message' => 'Committed artifact cannot be overwritten.']], 409);
            }
            if ($actualBytes !== (int) $artifact->size_bytes || $actualSha256 !== $artifact->digest_sha256) {
                return response()->json([
                    'error' => [
                        'code' => 'artifact_mismatch',
                        'message' => "Uploaded data does not match expected size or digest. Expected {$artifact->size_bytes}b ({$artifact->digest_sha256}), got {$actualBytes}b ({$actualSha256}).",
                    ],
                ], 422);
            }

            Storage::disk('v3_private')->put($artifact->storage_path, $content);
            $artifact->is_quarantine = false;
            $artifact->save();

            return response()->json([
                'schema_version' => 1,
                'upload_id' => $artifact->id,
                'artifact_id' => $artifact->logical_name,
                'status' => 'uploaded',
                'verified_bytes' => $actualBytes,
                'verified_sha256' => $actualSha256,
            ], 200);
        });
    }

    /**
     * Read the verified status of an upload staging slot.
     * GET /api/v3/local-worker/v1/tasks/{task}/uploads/{upload}
     */
    public function getUpload(Request $request, string $task, string $upload): JsonResponse
    {
        [$localTask] = $this->verifyDeviceAccess($request, $task);

        $artifact = LocalArtifact::where('id', strtolower($upload))
            ->where('task_id', $localTask->id)
            ->first();

        if (!$artifact) {
            abort(response()->json(['error' => ['code' => 'not_found', 'message' => 'Upload slot not found.']], 404));
        }

        return response()->json([
            'schema_version' => 1,
            'upload_id' => $artifact->id,
            'artifact_id' => $artifact->logical_name,
            'status' => $artifact->is_quarantine ? 'slot_reserved' : 'uploaded',
            'verified_bytes' => $artifact->is_quarantine ? null : $artifact->size_bytes,
            'verified_sha256' => $artifact->is_quarantine ? null : $artifact->digest_sha256,
        ]);
    }
}
