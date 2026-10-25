<?php

namespace App\Services\V3\LocalWorker;

use App\Models\User;
use App\Models\V3\LocalArtifact;
use App\Models\V3\LocalAssignment;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalResult;
use App\Models\V3\LocalTask;
use App\Models\V3\ProcessingDevice;
use App\Models\V3\SubjectRun;
use App\Services\LearningAccess;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerProtocolException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocalWorkerFencingService
{
    public const PURPOSE_LIMITS = [
        'raw_turn' => 16777216,                // 16 MiB
        'authoring_raw_response' => 16777216,  // 16 MiB
        'prompt_export' => 67108864,           // 64 MiB
        'scene_package' => 4194304,            // 4 MiB
    ];

    public const TOTAL_TASK_BUDGET = 536870912; // 512 MiB

    public function __construct(
        protected LearningAccess $learningAccess
    ) {}

    /**
     * Verify that the device is active, unexpired, and not revoked.
     */
    public function verifyDeviceActive(ProcessingDevice $device): void
    {
        if (!$device->isActive()) {
            throw new LocalWorkerProtocolException(
                "Processing device '{$device->id}' is revoked, inactive, or expired",
                'unauthorized',
                401
            );
        }
    }

    /**
     * Verify that the device has explicit authority (scopes) over the job's tenant and teacher.
     */
    public function verifyDeviceGrant(ProcessingDevice $device, LocalJob $job): void
    {
        $this->verifyDeviceActive($device);

        $scopes = is_array($device->scopes) ? $device->scopes : [];
        if (empty($scopes)) {
            throw new LocalWorkerProtocolException(
                "Processing device '{$device->id}' has no granted scopes",
                'forbidden',
                403
            );
        }

        // Check tenant scope if job has tenant_id
        if ($job->tenant_id !== null) {
            $hasTenantAccess = in_array('tenant:all', $scopes, true)
                || in_array("tenant:{$job->tenant_id}", $scopes, true);
            if (!$hasTenantAccess) {
                throw new LocalWorkerProtocolException(
                    "Device is not authorized for tenant '{$job->tenant_id}'",
                    'forbidden',
                    403
                );
            }
        }

        // Check teacher scope
        $teacherScopes = array_filter($scopes, fn ($s) => str_starts_with($s, 'teacher:'));
        if (!empty($teacherScopes)) {
            $hasTeacherAccess = in_array('teacher:all', $teacherScopes, true)
                || in_array("teacher:{$job->teacher_id}", $teacherScopes, true);
            if (!$hasTeacherAccess) {
                throw new LocalWorkerProtocolException(
                    "Device is not authorized for teacher '{$job->teacher_id}'",
                    'forbidden',
                    403
                );
            }
        } else {
            // If no specific teacher: scopes are defined, device must have tenant:all or match tenant
            $hasTenantAccess = in_array('tenant:all', $scopes, true)
                || ($job->tenant_id !== null && in_array("tenant:{$job->tenant_id}", $scopes, true));
            if (!$hasTenantAccess) {
                throw new LocalWorkerProtocolException(
                    "Device has no matching tenant or teacher authority for job",
                    'forbidden',
                    403
                );
            }
        }

        // Revalidate current V3 enrollment and the run's authoritative owner.
        $teacher = User::find($job->teacher_id);
        if (!$teacher || !in_array($teacher->role, ['teacher', 'admin'], true)) {
            throw new LocalWorkerProtocolException(
                "Teacher account '{$job->teacher_id}' is invalid or not a teacher",
                'forbidden',
                403
            );
        }

        $enrollment = DB::table('v3_account_access')->where('user_id', $teacher->id)->first();
        if (!$enrollment || !(bool) $enrollment->is_active) {
            throw new LocalWorkerProtocolException(
                "Teacher account '{$job->teacher_id}' has been disabled",
                'forbidden',
                403
            );
        }

        $run = SubjectRun::query()->with('subject')->find($job->run_id);
        if (!$run || (int) $run->owner_user_id !== (int) $job->teacher_id) {
            throw new LocalWorkerProtocolException('Worker job no longer matches its authoritative run owner.', 'forbidden', 403);
        }
        if ($run->subject_id !== null) {
            if (!$this->learningAccess->canManage($teacher, (int) $run->subject_id)) {
                throw new LocalWorkerProtocolException('Run owner no longer has authority over the subject.', 'forbidden', 403);
            }
        } elseif (!$this->learningAccess->canManageProcessingDevices($teacher)) {
            throw new LocalWorkerProtocolException('Run has no subject authority binding.', 'forbidden', 403);
        }
    }

    /** Ensure the enrolled device still advertises a capability for this task kind. */
    public function verifyTaskCapability(ProcessingDevice $device, LocalTask $task): void
    {
        $required = match ($task->task_kind) {
            'author_prompts' => ['notebooklm_authoring_v1', 'notebooklm_v1', 'authoring_v1'],
            'return_scenes' => ['scene_return_v1', 'static_three_file_v1'],
            default => [],
        };
        $capabilities = is_array($device->capabilities) ? $device->capabilities : [];

        if ($required === [] || array_intersect($required, $capabilities) === []) {
            throw new LocalWorkerProtocolException(
                "Device is not currently authorized for task kind '{$task->task_kind}'",
                'unsupported_capability',
                403
            );
        }
    }

    /**
     * Check if a device has active capacity to take a new task.
     */
    public function verifyDeviceCapacity(ProcessingDevice $device, ?string $excludeClaimId = null): void
    {
        $this->verifyDeviceActive($device);

        $availableSlots = 1;
        if (is_array($device->capabilities) && isset($device->capabilities['available_slots'])) {
            $availableSlots = max(1, (int) $device->capabilities['available_slots']);
        }

        $query = LocalAssignment::where('device_id', $device->id)
            ->whereIn('status', ['active', 'reconciliation_only'])
            ->where('lease_expires_at', '>', now());

        if ($excludeClaimId !== null) {
            $query->where('claim_id', '!=', $excludeClaimId);
        }

        $activeCount = $query->count();
        if ($activeCount >= $availableSlots) {
            throw new LocalWorkerProtocolException(
                "Device capacity exhausted ({$activeCount}/{$availableSlots} slots active)",
                'capacity_exhausted',
                429
            );
        }
    }

    /**
     * Transactionally verify fencing headers, live lease, active epoch, and control state.
     */
    public function verifyAssignmentFencing(
        Request $request,
        LocalTask $task,
        ProcessingDevice $device,
        bool $allowReconciliation = false,
        bool $requireHeaders = false
    ): LocalAssignment {
        $job = LocalJob::find($task->job_id);
        if (!$job) {
            throw new LocalWorkerProtocolException("Job not found", 'not_found', 404);
        }

        $this->verifyDeviceGrant($device, $job);
        $this->verifyTaskCapability($device, $task);

        $assignmentId = $request->header('X-Assignment-ID');
        $assignmentEpoch = $request->header('X-Assignment-Epoch');

        if ($requireHeaders && (!$assignmentId || $assignmentEpoch === null)) {
            throw new LocalWorkerProtocolException(
                'X-Assignment-ID and X-Assignment-Epoch headers required',
                'fencing_headers_missing',
                400
            );
        }

        if ($assignmentId) {
            // Verify assignment identity
            $assignment = LocalAssignment::where('id', $assignmentId)
                ->where('task_id', $task->id)
                ->where('device_id', $device->id)
                ->first();

            if (!$assignment) {
                throw new LocalWorkerProtocolException(
                    'No assignment matches the provided identity for this device and task',
                    'assignment_not_found',
                    403
                );
            }

            // Verify epoch matches assignment
            if ($assignmentEpoch !== null && (int) $assignmentEpoch !== (int) $assignment->assignment_epoch) {
                throw new LocalWorkerProtocolException(
                    'Assignment epoch does not match active task epoch',
                    'stale_epoch',
                    409
                );
            }

            if ($assignmentEpoch !== null && $task->active_epoch > 0 && (int) $assignmentEpoch !== (int) $task->active_epoch) {
                throw new LocalWorkerProtocolException(
                    'Assignment epoch does not match active task epoch',
                    'stale_epoch',
                    409
                );
            }
        } else {
            // Fallback to active assignment for this device
            $assignment = LocalAssignment::where('task_id', $task->id)
                ->where('device_id', $device->id)
                ->whereIn('status', $allowReconciliation ? ['active', 'reconciliation_only'] : ['active'])
                ->latest()
                ->first();

            if (!$assignment) {
                throw new LocalWorkerProtocolException(
                    'No active assignment found for this device and task',
                    'no_active_assignment',
                    403
                );
            }
        }

        // A normal assignment may mutate only while the task is actively leased.
        // Held/reconciliation tasks remain readable through the explicit
        // reconciliation path, but must never receive a fresh provider permit.
        $blockedStatuses = [
            'paused',
            'paused_auth',
            'paused_quota',
            'cancelled',
            'completed',
            'reconciliation_required',
        ];
        if (in_array($task->status, $blockedStatuses, true) && !$allowReconciliation) {
            throw new LocalWorkerProtocolException(
                "Task is in state '{$task->status}'",
                'control_changed',
                409
            );
        }

        if (in_array($task->status, ['paused', 'cancelled'], true)) {
            throw new LocalWorkerProtocolException(
                "Task is in state '{$task->status}'",
                'control_changed',
                409
            );
        }

        // Verify assignment is the task's active assignment
        if ($task->active_assignment_id !== null && $task->active_assignment_id !== $assignment->id) {
            throw new LocalWorkerProtocolException(
                'Assignment is not the current active assignment for this task',
                'stale_assignment',
                409
            );
        }

        // Verify lease expiry
        if (now()->greaterThan($assignment->lease_expires_at)) {
            $assignment->status = 'expired';
            $assignment->save();

            throw new LocalWorkerProtocolException(
                'Lease has expired',
                'lease_lost',
                409
            );
        }

        // Verify assignment status
        $allowedStatuses = $allowReconciliation ? ['active', 'reconciliation_only'] : ['active'];
        if (!in_array($assignment->status, $allowedStatuses, true)) {
            throw new LocalWorkerProtocolException(
                "Assignment is in status '{$assignment->status}', expected active",
                'assignment_not_active',
                409
            );
        }

        return $assignment;
    }

    /**
     * Enforce purpose-specific and aggregate upload limits.
     */
    public function verifyUploadCeilings(string $purpose, int $expectedBytes, LocalTask $task): void
    {
        if (!array_key_exists($purpose, self::PURPOSE_LIMITS)) {
            throw new LocalWorkerProtocolException(
                "Unknown or unallowlisted upload purpose '{$purpose}'",
                'invalid_purpose',
                422
            );
        }

        $maxBytes = self::PURPOSE_LIMITS[$purpose];
        if ($expectedBytes > $maxBytes) {
            throw new LocalWorkerProtocolException(
                "Expected size ({$expectedBytes}b) exceeds maximum limit for purpose '{$purpose}' ({$maxBytes}b)",
                'bounds_exceeded',
                413
            );
        }

        // Input PDFs and instructions have a separate admission-time budget.
        // Only provider outputs and their reservations consume this ceiling.
        $currentTaskBytes = (int) LocalArtifact::where('task_id', $task->id)
            ->whereIn('purpose', array_keys(self::PURPOSE_LIMITS))
            ->sum('size_bytes');
        if (($currentTaskBytes + $expectedBytes) > self::TOTAL_TASK_BUDGET) {
            throw new LocalWorkerProtocolException(
                "Total task output budget exceeded. Current: {$currentTaskBytes}b, requested: {$expectedBytes}b, max: " . self::TOTAL_TASK_BUDGET . 'b',
                'bounds_exceeded',
                413
            );
        }
    }

    /**
     * Check idempotency: identical retry replays receipt, changed body conflicts.
     */
    public function verifyResultIdempotency(string $rawBody, ?LocalResult $existing): void
    {
        if ($existing) {
            $incomingDigest = hash('sha256', $rawBody);
            if ($existing->result_digest !== $incomingDigest) {
                throw new LocalWorkerProtocolException(
                    'Result ID already submitted with differing payload digest',
                    'idempotency_conflict',
                    409
                );
            }
        }
    }
}
