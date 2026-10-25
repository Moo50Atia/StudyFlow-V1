<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Models\V3\LocalAssignment;
use App\Models\V3\LocalArtifact;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalProviderEffect;
use App\Models\V3\LocalTask;
use App\Models\V3\ProcessingDevice;
use App\Models\V3\PipelineCommand;
use App\Models\V3\SubjectRun;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerJsonDecoder;
use App\Services\LearningAccess;
use App\Services\V3\LocalWorker\LocalWorkerFencingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class LocalWorkerReconciliationController extends Controller
{
    public function __construct(
        private readonly LearningAccess $learningAccess,
        private readonly LocalWorkerFencingService $fencingService,
    ) {}

    public function show(Request $request, string $taskId)
    {
        $this->authorizeOwner($request);
        $task = LocalTask::query()->findOrFail($taskId);
        $effects = LocalProviderEffect::query()->where('task_id', $task->id)->orderBy('created_at')->get([
            'id', 'logical_effect_key', 'operation_kind', 'status', 'outcome_ref', 'attempt_count', 'created_at', 'updated_at',
        ]);
        $evidence = LocalArtifact::query()
            ->where('task_id', $task->id)
            ->where('purpose', 'reconciliation_evidence')
            ->where('logical_name', 'like', 'reconciliation_evidence_%')
            ->where('is_quarantine', false)
            ->where('is_committed', true)
            ->orderBy('created_at')
            ->get(['id', 'digest_sha256', 'size_bytes', 'created_at']);

        $assignment = $task->activeAssignment;
        $recovery = null;
        foreach ($evidence->reverse() as $artifact) {
            try {
                $stored = LocalArtifact::findOrFail($artifact->id);
                $bytes = Storage::disk('v3_private')->get($stored->storage_path);
                if (strlen($bytes) !== $stored->size_bytes || hash('sha256', $bytes) !== $stored->digest_sha256) continue;
                $body = LocalWorkerJsonDecoder::decode($bytes, 262144);
                $effect = $effects->first(fn ($row) => $row->operation_kind === 'terminal_pipeline_export'
                    && LocalProviderEffect::find($row->id)?->request_digest === ($body['request_digest'] ?? null));
                if (!$effect || ($body['kind'] ?? null) !== 'local_terminal_run_evidence'
                    || ($body['task_id'] ?? null) !== $task->id) continue;
                $recovery = [
                    'effect_id' => $effect->id, 'evidence_artifact_id' => $artifact->id,
                    'terminal_status' => $body['terminal_status'] ?? 'unknown',
                    'can_continue' => $task->status === 'reconciliation_required'
                        && $assignment?->status === 'reconciliation_only' && $assignment->lease_expires_at->isFuture()
                        && in_array($body['terminal_status'] ?? null, ['prompts_exported', 'running', 'paused'], true),
                ];
                break;
            } catch (\Throwable) {
                continue; // Corrupt evidence never enables a recovery action.
            }
        }
        $latest = LocalAssignment::where('task_id', $task->id)->orderByDesc('assignment_epoch')->first();
        $data = [
            'task_id' => $task->id, 'run_id' => $task->job->run_id,
            'job_id' => $task->job_id, 'status' => $task->status,
            'assignment_status' => $assignment?->status,
            'can_check' => in_array($task->status, ['reconciliation_required', 'paused_auth', 'paused_quota'], true)
                && !$task->active_assignment_id && $latest?->status === 'expired',
            'effects' => $effects, 'evidence_artifacts' => $evidence, 'recovery' => $recovery,
        ];
        if (!$request->expectsJson()) {
            return \Inertia\Inertia::render('Admin/RunRecovery', ['initial' => $data]);
        }
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    public function begin(Request $request, string $taskId): JsonResponse
    {
        $this->authorizeOwner($request);

        return DB::transaction(function () use ($taskId): JsonResponse {
            $task = LocalTask::query()->lockForUpdate()->findOrFail($taskId);
            if (!in_array($task->status, ['reconciliation_required', 'paused_auth', 'paused_quota'], true) || $task->active_assignment_id !== null) {
                return response()->json(['error' => ['code' => 'task_not_reconcilable']], 409);
            }

            $latest = LocalAssignment::query()->where('task_id', $task->id)
                ->orderByDesc('assignment_epoch')->lockForUpdate()->first();
            if (!$latest || $latest->status !== 'expired') {
                return response()->json(['error' => ['code' => 'expired_assignment_required']], 409);
            }

            $device = ProcessingDevice::query()->lockForUpdate()->find($latest->device_id);
            if (!$device || !$device->isActive()) {
                return response()->json(['error' => ['code' => 'device_unavailable']], 409);
            }

            $job = LocalJob::query()->findOrFail($task->job_id);
            $this->fencingService->verifyDeviceGrant($device, $job);
            $this->fencingService->verifyDeviceCapacity($device);

            $assignment = LocalAssignment::create([
                'id' => (string) Str::uuid(),
                'task_id' => $task->id,
                'device_id' => $device->id,
                'claim_id' => (string) Str::uuid(),
                'assignment_epoch' => ((int) LocalAssignment::query()->where('task_id', $task->id)->max('assignment_epoch')) + 1,
                'lease_expires_at' => now()->addSeconds(120),
                'heartbeat_at' => now(),
                'status' => 'reconciliation_only',
                'control_version' => $task->control_version,
            ]);

            $task->active_assignment_id = $assignment->id;
            $task->active_epoch = $assignment->assignment_epoch;
            $task->status = 'reconciliation_required';
            $task->save();

            return response()->json([
                'task_id' => $task->id,
                'assignment_id' => $assignment->id,
                'assignment_epoch' => $assignment->assignment_epoch,
                'device_id' => $device->id,
                'lease_expires_at' => $assignment->lease_expires_at->toIso8601String(),
                'status' => $assignment->status,
            ], 201);
        });
    }

    public function resolveNoEffect(Request $request, string $taskId): JsonResponse
    {
        $this->authorizeOwner($request);
        $data = $request->validate([
            'schema_version' => ['required', 'integer', 'in:1'],
            'effect_id' => ['required', 'uuid'],
            'evidence_artifact_id' => ['required', 'uuid'],
            'acknowledge_no_external_effect' => ['required', 'accepted'],
        ]);

        return DB::transaction(function () use ($taskId, $data): JsonResponse {
            $task = LocalTask::query()->lockForUpdate()->findOrFail($taskId);
            if ($task->status !== 'reconciliation_required' || !$task->active_assignment_id) {
                return response()->json(['error' => ['code' => 'task_not_reconcilable']], 409);
            }
            $assignment = LocalAssignment::query()->lockForUpdate()->findOrFail($task->active_assignment_id);
            if ($assignment->status !== 'reconciliation_only' || $assignment->lease_expires_at->isPast()) {
                return response()->json(['error' => ['code' => 'reconciliation_assignment_required']], 409);
            }
            $effect = LocalProviderEffect::query()->where('task_id', $task->id)->lockForUpdate()->findOrFail($data['effect_id']);
            if (!in_array($effect->status, ['dispatching', 'unknown'], true)) {
                return response()->json(['error' => ['code' => 'effect_not_unsettled']], 409);
            }
            $evidence = LocalArtifact::query()->where('task_id', $task->id)
                ->whereKey($data['evidence_artifact_id'])
                ->where('purpose', 'reconciliation_evidence')
                ->where('logical_name', 'like', 'reconciliation_evidence_%')
                ->where('is_quarantine', false)
                ->where('is_committed', true)
                ->lockForUpdate()
                ->first();
            if (!$evidence) {
                return response()->json(['error' => ['code' => 'reconciliation_evidence_required']], 422);
            }
            $disk = Storage::disk('v3_private');
            if (!$disk->exists($evidence->storage_path)) {
                return response()->json(['error' => ['code' => 'reconciliation_evidence_unavailable']], 422);
            }
            $evidenceBytes = $disk->get($evidence->storage_path);
            if (strlen($evidenceBytes) !== (int) $evidence->size_bytes
                || !hash_equals($evidence->digest_sha256, hash('sha256', $evidenceBytes))) {
                return response()->json(['error' => ['code' => 'reconciliation_evidence_corrupt']], 422);
            }

            $effect->status = 'failed_no_effect';
            $effect->outcome_ref = $evidence->id;
            $effect->save();

            DB::table('v3_local_effect_attempts')
                ->where('effect_id', $effect->id)
                ->whereIn('status', ['dispatching', 'unknown', 'may_have_been_sent'])
                ->update(['status' => 'failed_no_effect', 'outcome_ref' => $evidence->id, 'updated_at' => now()]);

            $remaining = LocalProviderEffect::query()->where('task_id', $task->id)
                ->whereIn('status', ['dispatching', 'unknown'])->exists();
            $requeuedEffects = [];
            if (!$remaining) {
                // Keep each prior no-effect receipt on its attempt row, then
                // open a fresh attempt under the same logical effect identity.
                // This preserves idempotency keys and makes the authorized
                // retry executable by the existing worker journal.
                $requeuedEffects = LocalProviderEffect::query()->where('task_id', $task->id)
                    ->where('status', 'failed_no_effect')->lockForUpdate()->pluck('id')->all();
                LocalProviderEffect::query()->whereIn('id', $requeuedEffects)->update([
                    'status' => 'reserved',
                    'current_attempt_id' => null,
                    'updated_at' => now(),
                ]);
                $assignment->status = 'released';
                $assignment->save();
                $task->active_assignment_id = null;
                $task->status = 'queued';
                $job = LocalJob::query()->lockForUpdate()->findOrFail($task->job_id);
                $job->status = 'queued';
                $job->save();
            }
            $task->save();

            return response()->json([
                'task_id' => $task->id,
                'effect_id' => $effect->id,
                'effect_status' => $remaining ? $effect->status : 'reserved',
                'task_status' => $task->status,
                'evidence_artifact_id' => $evidence->id,
                'requeued_effect_ids' => $requeuedEffects,
            ]);
        });
    }

    public function resolveTerminal(Request $request, string $taskId): JsonResponse
    {
        $this->authorizeOwner($request);
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'effect_id' => ['required', 'uuid'],
            'evidence_artifact_id' => ['required', 'uuid'],
            'acknowledge_existing_terminal_run' => ['required', 'accepted'],
        ]);

        return DB::transaction(function () use ($taskId, $data, $request): JsonResponse {
            $task = LocalTask::whereKey($taskId)->lockForUpdate()->firstOrFail();
            $digest = hash('sha256', $taskId . ':' . $data['effect_id'] . ':' . $data['evidence_artifact_id']);
            $existing = PipelineCommand::find($data['request_id']);
            if ($existing) {
                abort_unless($existing->action === 'resolve_terminal' && $existing->request_sha256 === $digest, 409);
                return response()->json(['task_id' => $taskId, 'status' => 'authorized_existing_terminal']);
            }
            abort_unless($task->status === 'reconciliation_required' && $task->active_assignment_id, 409);
            $assignment = LocalAssignment::whereKey($task->active_assignment_id)->lockForUpdate()->firstOrFail();
            abort_unless($assignment->status === 'reconciliation_only' && $assignment->lease_expires_at->isFuture(), 409);
            $job = LocalJob::findOrFail($task->job_id);
            $this->fencingService->verifyDeviceGrant($assignment->device, $job);
            $this->fencingService->verifyTaskCapability($assignment->device, $task);
            $effect = LocalProviderEffect::where('task_id', $taskId)->whereKey($data['effect_id'])->lockForUpdate()->firstOrFail();
            abort_unless($effect->operation_kind === 'terminal_pipeline_export'
                && in_array($effect->status, ['dispatching', 'unknown', 'authentication_required', 'rate_limited'], true), 409);
            $artifact = LocalArtifact::whereKey($data['evidence_artifact_id'])->where('task_id', $taskId)
                ->where('job_id', $job->id)->where('purpose', 'reconciliation_evidence')
                ->where('is_committed', true)->where('is_quarantine', false)->firstOrFail();
            $bytes = Storage::disk('v3_private')->get($artifact->storage_path);
            abort_unless(strlen($bytes) === $artifact->size_bytes && hash('sha256', $bytes) === $artifact->digest_sha256, 422);
            $evidence = LocalWorkerJsonDecoder::decode($bytes, 262144);
            $terminalId = Uuid::uuid5($job->id, 'terminal-subject-v1')->toString();
            abort_unless(($evidence['kind'] ?? null) === 'local_terminal_run_evidence'
                && ($evidence['task_id'] ?? null) === $taskId && ($evidence['job_id'] ?? null) === $job->id
                && ($evidence['run_id'] ?? null) === $job->run_id
                && ($evidence['terminal_run_id'] ?? null) === $terminalId
                && ($evidence['input_manifest_sha256'] ?? null) === $task->input_manifest_sha256
                && ($evidence['request_digest'] ?? null) === $effect->request_digest
                && in_array($evidence['terminal_status'] ?? null, ['prompts_exported', 'running', 'paused'], true), 422);
            abort_unless(($evidence['terminal_status'] === 'prompts_exported'
                    && preg_match('/^[a-f0-9]{64}$/', $evidence['export_sha256'] ?? ''))
                || ($evidence['terminal_status'] !== 'prompts_exported'
                    && is_int($evidence['terminal_state_version'] ?? null) && $evidence['terminal_state_version'] > 0), 422);
            abort_if(LocalProviderEffect::where('task_id', $taskId)->where('id', '!=', $effect->id)
                ->whereIn('status', ['dispatching', 'unknown'])->exists(), 409);

            // Preserve the fact that execution occurred. The next permit may
            // only attach to this terminal run, never create another one.
            $effect->attempts()->whereIn('status', ['issued', 'dispatching', 'unknown'])
                ->update(['status' => 'reconciled_existing', 'outcome_ref' => $artifact->id]);
            $effect->update(['status' => 'reserved', 'current_attempt_id' => null, 'outcome_ref' => $artifact->id]);
            $assignment->update(['status' => 'released']);
            $task->update(['status' => 'queued', 'active_assignment_id' => null]);
            $job->update(['status' => 'queued']);
            SubjectRun::whereKey($job->run_id)->update(['state' => 'queued']);
            PipelineCommand::create([
                'id' => $data['request_id'], 'run_id' => $job->run_id, 'actor_user_id' => $request->user()->id,
                'action' => 'resolve_terminal', 'request_sha256' => $digest, 'state' => 'applied',
                'expected_version' => $task->control_version, 'accepted_at' => now(),
            ]);
            return response()->json(['task_id' => $taskId, 'status' => 'authorized_existing_terminal']);
        });
    }

    private function authorizeOwner(Request $request): void
    {
        abort_unless($this->learningAccess->isOwner($request->user()), 403, 'Only the platform owner can manage reconciliation.');
    }
}
