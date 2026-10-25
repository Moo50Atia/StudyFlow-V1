<?php

namespace App\Http\Controllers\V3\LocalWorker;

use App\Http\Controllers\Controller;
use App\Models\V3\LocalArtifact;
use App\Models\V3\LocalAssignment;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalResult;
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
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TaskClaimController extends Controller
{
    public function __construct(
        protected LocalWorkerFencingService $fencingService
    ) {}

    /**
     * Atomically claim an eligible stage task.
     * POST /api/v3/local-worker/v1/claims
     */
    public function claim(Request $request): JsonResponse|Response
    {
        /** @var ProcessingDevice $device */
        $device = $request->attributes->get('local_worker_device');

        $this->fencingService->verifyDeviceActive($device);

        $payload = LocalWorkerJsonDecoder::decode($request->getContent(), 262144);
        LocalWorkerV1Validator::validateClaimRequest($payload);

        $claimId = strtolower($payload['claim_id']);
        $clientCapabilities = is_array($payload['capabilities']) ? $payload['capabilities'] : [];
        $requestedTaskId = $payload['task_id'] ?? null;

        return DB::transaction(function () use ($device, $claimId, $clientCapabilities, $requestedTaskId) {
            $device = ProcessingDevice::whereKey($device->id)->lockForUpdate()->firstOrFail();
            $this->fencingService->verifyDeviceActive($device);
            // 1. Check idempotency: did this device already make this claim?
            $existingAssignment = LocalAssignment::where('claim_id', $claimId)
                ->where('device_id', $device->id)
                ->first();

            if ($existingAssignment) {
                $task = LocalTask::findOrFail($existingAssignment->task_id);
                return response()->json($this->buildClaimResponse($task, $existingAssignment));
            }

            // Conflict: claim_id used by another device
            if (LocalAssignment::where('claim_id', $claimId)->exists()) {
                return response()->json([
                    'error' => [
                        'code' => 'idempotency_conflict',
                        'message' => 'Claim ID already used for another assignment.',
                    ],
                ], 409);
            }

            // Enforce capacity check (LW-R26)
            $this->fencingService->verifyDeviceCapacity($device, $claimId);

            // 2. Query eligible queued tasks
            $query = LocalTask::where('status', 'queued')
                ->orderBy('created_at')->orderBy('id')
                ->lockForUpdate();

            if ($requestedTaskId) {
                $query->where('id', $requestedTaskId);
            }

            $tasks = $query->get();
            $eligibleTask = null;
            $deviceCaps = is_array($device->capabilities) ? $device->capabilities : [];

            foreach ($tasks as $task) {
                if ($task->task_kind === 'author_prompts' && LocalProviderEffect::where('task_id', $task->id)
                    ->where('operation_kind', 'terminal_pipeline_export')->exists()) {
                    $previous = LocalAssignment::where('task_id', $task->id)->orderByDesc('assignment_epoch')->first();
                    if (!$previous || $previous->device_id !== $device->id) {
                        continue;
                    }
                }
                // Manual scene files live on the device that authored this run.
                // Another device cannot satisfy a Pull by claiming its local files.
                if ($task->task_kind === 'return_scenes') {
                    $author = LocalResult::where('job_id', $task->job_id)
                        ->where('result_kind', 'author_prompts')->first();
                    if (!$author || $author->device_id !== $device->id) {
                        continue;
                    }
                }
                $requiredCaps = match ($task->task_kind) {
                    'author_prompts' => ['notebooklm_authoring_v1', 'notebooklm_v1', 'authoring_v1'],
                    'return_scenes' => ['scene_return_v1', 'static_three_file_v1'],
                    default => [],
                };

                // Check that device actually has at least one matching capability
                $deviceHasCap = false;
                foreach ($requiredCaps as $rc) {
                    if (in_array($rc, $deviceCaps, true)) {
                        $deviceHasCap = true;
                        break;
                    }
                }
                if (!$deviceHasCap) {
                    continue;
                }

                // Check that client requested capabilities intersect
                $clientRequestedCap = false;
                foreach ($requiredCaps as $rc) {
                    if (in_array($rc, $clientCapabilities, true)) {
                        $clientRequestedCap = true;
                        break;
                    }
                }
                if (!$clientRequestedCap) {
                    continue;
                }

                // Verify teacher/tenant grant (LW-R01)
                $job = LocalJob::find($task->job_id);
                if (!$job) {
                    continue;
                }

                try {
                    $this->fencingService->verifyDeviceGrant($device, $job);
                } catch (LocalWorkerProtocolException) {
                    continue;
                }

                $eligibleTask = $task;
                break;
            }

            if (!$eligibleTask) {
                return response('', 204);
            }

            // 3. Atomically assign task
            $maxEpoch = (int) LocalAssignment::where('task_id', $eligibleTask->id)->max('assignment_epoch');
            $nextEpoch = $maxEpoch + 1;
            $assignmentId = (string) Str::uuid();

            $assignment = LocalAssignment::create([
                'id' => $assignmentId,
                'task_id' => $eligibleTask->id,
                'device_id' => $device->id,
                'claim_id' => $claimId,
                'assignment_epoch' => $nextEpoch,
                'lease_expires_at' => now()->addSeconds(120),
                'heartbeat_at' => now(),
                'status' => 'active',
                'control_version' => $eligibleTask->control_version,
            ]);

            $eligibleTask->status = 'leased';
            $eligibleTask->active_assignment_id = $assignmentId;
            $eligibleTask->active_epoch = $nextEpoch;
            $eligibleTask->save();

            // Truthful state projection on Job and SubjectRun (LW-R34)
            $job = LocalJob::find($eligibleTask->job_id);
            if ($job) {
                $job->status = 'leased';
                $job->save();

                $run = SubjectRun::find($job->run_id);
                if ($run && in_array($run->state, ['queued', 'pending'], true)) {
                    $run->state = 'running';
                    $run->save();
                }
            }

            return response()->json($this->buildClaimResponse($eligibleTask, $assignment));
        });
    }

    /**
     * Recover a lost claim acknowledgement.
     * GET /api/v3/local-worker/v1/claims/{claim_id}
     */
    public function getClaim(Request $request, string $claimId): JsonResponse
    {
        /** @var ProcessingDevice $device */
        $device = $request->attributes->get('local_worker_device');
        $this->fencingService->verifyDeviceActive($device);

        $assignment = LocalAssignment::where('claim_id', strtolower($claimId))
            ->where('device_id', $device->id)
            ->first();

        if (!$assignment) {
            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Claim not found for this device.',
                ],
            ], 404);
        }

        $task = LocalTask::find($assignment->task_id);
        if (!$task) {
            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Task not found.',
                ],
            ], 404);
        }

        $job = LocalJob::find($task->job_id);
        if (!$job) {
            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Job not found.',
                ],
            ], 404);
        }

        try {
            $this->fencingService->verifyDeviceGrant($device, $job);
        } catch (LocalWorkerProtocolException) {
            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Claim not found for this device.',
                ],
            ], 404);
        }

        return response()->json($this->buildClaimResponse($task, $assignment));
    }

    private function buildClaimResponse(LocalTask $task, LocalAssignment $assignment): array
    {
        $job = LocalJob::findOrFail($task->job_id);

        $allowedArtifactIds = [];
        $resourceBudget = [
            'max_turn_bytes' => 16777216,
            'max_prompt_export_bytes' => 67108864,
            'max_total_task_bytes' => 536870912,
        ];

        if ($task->input_manifest_id) {
            $manifestArtifact = LocalArtifact::find($task->input_manifest_id);
            if ($manifestArtifact && Storage::disk('v3_private')->exists($manifestArtifact->storage_path)) {
                $manifestJson = json_decode(Storage::disk('v3_private')->get($manifestArtifact->storage_path), true);
                if (is_array($manifestJson)) {
                    if (isset($manifestJson['artifacts']) && is_array($manifestJson['artifacts'])) {
                        foreach ($manifestJson['artifacts'] as $art) {
                            if (isset($art['artifact_id'])) {
                                $allowedArtifactIds[] = $art['artifact_id'];
                            }
                        }
                    }
                    if (isset($manifestJson['items']) && is_array($manifestJson['items'])) {
                        foreach ($manifestJson['items'] as $item) {
                            if (isset($item['original_artifact_id'])) {
                                $allowedArtifactIds[] = $item['original_artifact_id'];
                            }
                            if (isset($item['prepared_artifact_id'])) {
                                $allowedArtifactIds[] = $item['prepared_artifact_id'];
                            }
                        }
                    }
                    if (isset($manifestJson['instruction_artifacts']) && is_array($manifestJson['instruction_artifacts'])) {
                        foreach ($manifestJson['instruction_artifacts'] as $instr) {
                            if (isset($instr['artifact_id'])) {
                                $allowedArtifactIds[] = $instr['artifact_id'];
                            }
                        }
                    }
                    if (isset($manifestJson['resource_budget']) && is_array($manifestJson['resource_budget'])) {
                        $resourceBudget = array_merge($resourceBudget, $manifestJson['resource_budget']);
                    }
                }
            }
        }

        return [
            'schema_version' => 1,
            'task_id' => $task->id,
            'job_id' => $task->job_id,
            'run_id' => $job->run_id,
            'task_kind' => $task->task_kind,
            'task_status' => $task->status,
            'assignment_status' => $assignment->status,
            'device_id' => $assignment->device_id,
            'platform_owner_id' => $assignment->device->platform_owner_id,
            'assignment_id' => $assignment->id,
            'assignment_epoch' => $assignment->assignment_epoch,
            'control_version' => $assignment->control_version,
            'lease_expires_at' => $assignment->lease_expires_at->toIso8601String(),
            'server_time' => now()->toIso8601String(),
            'input_manifest_sha256' => $task->input_manifest_sha256,
            'allowed_artifact_ids' => array_values(array_unique($allowedArtifactIds)),
            'resource_budget' => $resourceBudget,
        ];
    }
}
