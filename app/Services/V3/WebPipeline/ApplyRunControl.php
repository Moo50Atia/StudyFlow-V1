<?php

namespace App\Services\V3\WebPipeline;

use App\Models\User;
use App\Models\V3\LocalAssignment;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalProviderEffect;
use App\Models\V3\LocalTask;
use App\Models\V3\PipelineCommand;
use App\Models\V3\SubjectRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ApplyRunControl
{
    public function __construct(
        protected RunEventStore $eventStore,
        protected RunOutbox $outbox,
        protected TerminalBridgeClient $bridgeClient
    ) {}

    /**
     * Apply or request a control command for a run.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function apply(User $actor, SubjectRun $run, array $input, bool $isAdmin = false): array
    {
        $commandId = strtolower(trim((string) ($input['command_id'] ?? '')));
        if (! Str::isUuid($commandId)) {
            throw new HttpException(422, 'command_id must be a valid UUID');
        }

        $action = strtolower(trim((string) ($input['action'] ?? '')));
        $allowedTeacherActions = ['pause', 'resume', 'cancel', 'retry'];
        $allowedAdminActions = ['pause', 'resume', 'cancel', 'retry'];

        if ($isAdmin) {
            if (! in_array($action, $allowedAdminActions, true)) {
                throw new HttpException(403, "Admin cannot execute action '{$action}'");
            }
        } else {
            if (! in_array($action, $allowedTeacherActions, true)) {
                throw new HttpException(422, "Invalid action '{$action}'");
            }
        }

        $expectedVersion = (int) ($input['expected_version'] ?? 0);
        // Canonical request payload for digest
        $canonical = [
            'action' => $action,
            'command_id' => $commandId,
            'expected_version' => $expectedVersion,
        ];
        ksort($canonical);
        $requestSha256 = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $workflowState = null;
        try {
            $status = $this->bridgeClient->getRunStatus($run->id)['status'] ?? null;
            if (is_string($status)) {
                $workflowState = $status;
            }
        } catch (\Throwable) {
            // Persisted state remains the safe fallback when the bridge is down.
        }

        return DB::transaction(function () use (
            $actor,
            $run,
            $commandId,
            $action,
            $expectedVersion,
            $requestSha256,
            $canonical,
            $workflowState
        ) {
            // Lock run
            $lockedRun = SubjectRun::where('id', $run->id)->lockForUpdate()->firstOrFail();

            // Replay check
            $existing = PipelineCommand::where('id', $commandId)->first();
            if ($existing) {
                if ($existing->request_sha256 === $requestSha256) {
                    return [
                        'status' => 'replayed',
                        'command_id' => $existing->id,
                        'action' => $existing->action,
                        'state' => $existing->state,
                        'state_version' => (int) $lockedRun->state_version,
                    ];
                }
                throw new CommandConflictException("Command ID {$commandId} already exists with different payload", 409, 'control_conflict');
            }

            // Version fence
            if ((int) $lockedRun->state_version !== $expectedVersion) {
                throw new StaleVersionException(
                    "Stale version: expected {$expectedVersion}, actual {$lockedRun->state_version}",
                    409,
                    'stale_version'
                );
            }

            $localJob = LocalJob::query()->where('run_id', $lockedRun->id)->lockForUpdate()->first();
            // Cannot resume cancelled run
            $effectiveState = ! $localJob && in_array($workflowState, ['queued', 'staging', 'running', 'paused', 'paused_auth', 'paused_quota', 'awaiting_generation', 'failed', 'held_persistence', 'held_uncertain'], true)
                ? $workflowState
                : $lockedRun->state;
            $validTransition = match ($action) {
                'pause' => $effectiveState === 'running',
                'resume' => $effectiveState === 'paused',
                'cancel' => in_array($effectiveState, ['staging', 'queued', 'running', 'paused', 'paused_auth', 'paused_quota', 'awaiting_generation'], true),
                'retry' => RunRetryEligibility::allows($lockedRun, $effectiveState),
                default => false,
            };
            if (! $validTransition) {
                throw new HttpException(409, "Run state '{$effectiveState}' does not accept '{$action}'");
            }

            $localImmediateControl = $localJob && (
                ($action === 'retry' && in_array($effectiveState, ['paused_auth', 'paused_quota'], true))
                || ($action === 'cancel' && $effectiveState === 'awaiting_generation')
            );

            $newVersion = (int) $lockedRun->state_version + 1;

            $retryManifestRef = null;
            if ($action === 'retry' && ! $localImmediateControl) {
                $retryManifestRef = PipelineCommand::where('run_id', $lockedRun->id)
                    ->where('action', 'submit')->latest('created_at')->value('private_payload_ref');
                if (! $retryManifestRef || ! Str::isUuid($retryManifestRef)) {
                    throw new HttpException(409, 'The original run input is unavailable for a safe retry.');
                }
            }

            $command = PipelineCommand::create([
                'id' => $commandId,
                'run_id' => $lockedRun->id,
                'actor_user_id' => $actor->id,
                'action' => $action,
                'private_payload_ref' => $retryManifestRef,
                'request_sha256' => $requestSha256,
                'expected_version' => $expectedVersion,
                'state' => $localImmediateControl ? 'applied' : 'requested',
                'accepted_at' => now(),
                'created_at' => now(),
            ]);

            $lockedRun->update([
                'state' => $action === 'retry' ? ($localImmediateControl ? 'queued' : 'staging')
                    : (($localImmediateControl && $action === 'cancel') ? 'cancelled' : $lockedRun->state),
                'finished_at' => $action === 'retry' ? null
                    : (($localImmediateControl && $action === 'cancel') ? now() : $lockedRun->finished_at),
                'state_version' => $newVersion,
                'last_updated_at' => now(),
            ]);

            if ($localImmediateControl && $action === 'retry') {
                $tasks = LocalTask::query()->where('job_id', $localJob->id)
                    ->whereIn('status', ['paused_auth', 'paused_quota'])->lockForUpdate()->get();
                if ($tasks->isEmpty()) {
                    throw new HttpException(409, 'No paused local worker task is available to retry.');
                }
                foreach ($tasks as $task) {
                    if ($task->effects()->whereIn('status', ['dispatching', 'unknown'])->exists()) {
                        throw new HttpException(409, 'A local provider effect still requires reconciliation.');
                    }
                    if ($task->active_assignment_id) {
                        $assignment = LocalAssignment::query()->whereKey($task->active_assignment_id)->lockForUpdate()->first();
                        if ($assignment && $assignment->status === 'active') {
                            $assignment->update(['status' => 'released']);
                        }
                    }
                    $task->update(['status' => 'queued', 'active_assignment_id' => null]);
                }
                $localJob->update(['status' => 'queued']);
            }

            if ($localImmediateControl && $action === 'cancel') {
                $tasks = LocalTask::query()->where('job_id', $localJob->id)
                    ->whereIn('status', ['awaiting_pull', 'queued', 'paused', 'paused_auth', 'paused_quota'])
                    ->lockForUpdate()->get();
                foreach ($tasks as $task) {
                    if ($task->effects()->whereIn('status', ['dispatching', 'unknown'])->exists()) {
                        throw new HttpException(409, 'A local provider effect still requires reconciliation.');
                    }
                    if ($task->active_assignment_id) {
                        $assignment = LocalAssignment::query()->whereKey($task->active_assignment_id)->lockForUpdate()->first();
                        if ($assignment && $assignment->status === 'active') {
                            $assignment->update(['status' => 'released']);
                        }
                    }
                    $task->update(['status' => 'cancelled', 'active_assignment_id' => null]);
                }
                $localJob->update(['status' => 'cancelled']);
            }

            // Record safe event
            $this->eventStore->recordEvent($lockedRun->id, [
                'operation_id' => $commandId,
                'kind' => "control_{$action}",
                'phase' => $lockedRun->generation_phase,
                'outcome' => 'requested',
                'safe_code' => 'ok',
                'state_version' => $newVersion,
                'occurred_at' => now(),
            ]);

            // Persist the control before delivery so a bridge outage cannot
            // turn an unapplied request into a claimed run-state transition.
            if (! $localImmediateControl) {
                $this->outbox->enqueue($command);
            }

            return [
                'status' => $localImmediateControl ? 'applied' : 'requested',
                'command_id' => $command->id,
                'action' => $command->action,
                'state' => $command->state,
                'state_version' => $newVersion,
            ];
        });
    }
}
