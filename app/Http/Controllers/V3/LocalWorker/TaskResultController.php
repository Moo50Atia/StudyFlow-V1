<?php

namespace App\Http\Controllers\V3\LocalWorker;

use App\Http\Controllers\Controller;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalResult;
use App\Models\V3\LocalTask;
use App\Models\V3\LocalAssignment;
use App\Models\V3\ProcessingDevice;
use App\Services\V3\LocalWorker\LocalWorkerFencingService;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerJsonDecoder;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerProtocolException;
use App\Services\V3\LocalWorker\ResultAcceptanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskResultController extends Controller
{
    public function __construct(
        protected ResultAcceptanceService $acceptanceService,
        protected LocalWorkerFencingService $fencingService
    ) {}

    public function submitResult(Request $request, string $taskId): JsonResponse
    {
        $raw = $request->getContent();
        try {
            $payload = LocalWorkerJsonDecoder::decode($raw, LocalWorkerJsonDecoder::MANIFEST_MAX_SIZE);
        } catch (LocalWorkerProtocolException $e) {
            return response()->json([
                'error' => [
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                ]
            ], $e->statusCode);
        }

        /** @var ProcessingDevice $device */
        $device = $request->attributes->get('local_worker_device');
        if (!$device) {
            return response()->json([
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'Valid device authentication required',
                ]
            ], 401);
        }

        try {
            return DB::transaction(function () use ($request, $taskId, $device, $payload, $raw): JsonResponse {
                $lockedDevice = ProcessingDevice::query()->lockForUpdate()->find($device->id);
                if (!$lockedDevice) {
                    throw new LocalWorkerProtocolException('Device is no longer available.', 'unauthorized', 401);
                }
                $this->fencingService->verifyDeviceActive($lockedDevice);

                $task = LocalTask::query()->lockForUpdate()->find($taskId);
                if (!$task) {
                    return response()->json([
                        'error' => ['code' => 'task_not_found', 'message' => "Task '{$taskId}' not found"],
                    ], 404);
                }
                $job = LocalJob::query()->lockForUpdate()->find($task->job_id);
                if (!$job) {
                    return response()->json(['error' => ['code' => 'not_found', 'message' => 'Job not found']], 404);
                }
                $this->fencingService->verifyDeviceGrant($lockedDevice, $job);

                $resultId = strtolower($payload['result_id'] ?? '');
                $existing = LocalResult::query()->where('task_id', $task->id)->where('id', $resultId)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->device_id !== $lockedDevice->id) {
                        return response()->json(['error' => ['code' => 'forbidden', 'message' => 'Result belongs to another device']], 403);
                    }
                    if (strtolower((string) $request->header('X-Assignment-ID')) !== strtolower($existing->assignment_id)
                        || (string) $request->header('X-Assignment-Epoch') !== (string) $existing->assignment_epoch) {
                        return response()->json(['error' => ['code' => 'assignment_mismatch', 'message' => 'Assignment identity mismatch on replay']], 409);
                    }
                    $this->fencingService->verifyResultIdempotency($raw, $existing);
                    return response()->json($existing->receipt_payload, 200);
                }

                $assignment = $this->fencingService->verifyAssignmentFencing($request, $task, $lockedDevice, false, true);
                $assignment = LocalAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
                // Re-read every fence after taking both row locks. Controls and lease changes
                // serialize on the task row, so a concurrent pause/revoke cannot race acceptance.
                $assignment = $this->fencingService->verifyAssignmentFencing($request, $task, $lockedDevice, false, true);
                $this->verifyEnvelopeBindings($payload, $task, $job, $assignment);

                $wireDigest = hash('sha256', $raw);
                $receipt = $task->task_kind === 'return_scenes'
                    ? $this->acceptanceService->acceptSceneResult($task, $assignment, $lockedDevice, $payload, $wireDigest)
                    : $this->acceptanceService->acceptResult($task, $assignment, $lockedDevice, $payload, $wireDigest);

                return response()->json($receipt, 200);
            });
        } catch (LocalWorkerProtocolException $e) {
            return response()->json([
                'error' => [
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                ]
            ], $e->statusCode);
        }
    }

    private function verifyEnvelopeBindings(array $payload, LocalTask $task, LocalJob $job, LocalAssignment $assignment): void
    {
        $expected = [
            'task_id' => $task->id,
            'job_id' => $job->id,
            'run_id' => $job->run_id,
            'assignment_id' => $assignment->id,
            'assignment_epoch' => (int) $assignment->assignment_epoch,
        ];
        foreach ($expected as $field => $value) {
            if (($payload[$field] ?? null) !== $value) {
                throw new LocalWorkerProtocolException("Result {$field} does not match the active server binding.", 'mismatched_identity', 409);
            }
        }
        if (array_key_exists('control_version', $payload)
            && $payload['control_version'] !== (int) $task->control_version) {
            throw new LocalWorkerProtocolException('Result control_version does not match the current task control version.', 'control_changed', 409);
        }
    }

    public function getResult(Request $request, string $resultId): JsonResponse
    {
        /** @var ProcessingDevice $device */
        $device = $request->attributes->get('local_worker_device');
        if (!$device) {
            return response()->json([
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'Valid device authentication required',
                ]
            ], 401);
        }

        $result = LocalResult::find($resultId);
        if (!$result || $result->device_id !== $device->id) {
            return response()->json([
                'error' => [
                    'code' => 'result_not_found',
                    'message' => "Result '{$resultId}' not found",
                ]
            ], 404);
        }

        $job = LocalJob::find($result->job_id);
        if (!$job) {
            return response()->json([
                'error' => [
                    'code' => 'result_not_found',
                    'message' => "Result '{$resultId}' not found",
                ]
            ], 404);
        }

        try {
            $this->fencingService->verifyDeviceGrant($device, $job);
        } catch (LocalWorkerProtocolException) {
            return response()->json([
                'error' => [
                    'code' => 'result_not_found',
                    'message' => "Result '{$resultId}' not found",
                ]
            ], 404);
        }

        return response()->json($result->receipt_payload, 200);
    }
}
