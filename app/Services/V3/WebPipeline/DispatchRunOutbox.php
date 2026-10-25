<?php

namespace App\Services\V3\WebPipeline;

use App\Models\V3\PipelineCommand;
use App\Models\V3\PipelineOutbox;
use App\Models\V3\SubjectRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DispatchRunOutbox
{
    public function __construct(
        protected TerminalBridgeClient $bridgeClient,
        protected RunEventStore $eventStore
    ) {}

    /**
     * Dispatch all eligible pending outbox entries to the application bridge.
     *
     * @param int $limit
     * @return int Count of acknowledged deliveries
     */
    public function dispatchPending(int $limit = 50): int
    {
        $acknowledgedCount = 0;
        $leaseToken = (string) Str::uuid();
        $leaseExpiresAt = now()->addSeconds(60);

        // Atomically claim eligible records with a lease
        $items = DB::transaction(function () use ($limit, $leaseToken, $leaseExpiresAt) {
            $records = PipelineOutbox::whereIn('destination', ['application_bridge', 'qualification_runner'])
                ->where(function ($q) {
                    $q->where(function ($sub) {
                        $sub->where('state', 'pending')
                            ->where(function ($dateQ) {
                                $dateQ->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                            });
                    })->orWhere(function ($sub) {
                        $sub->where('state', 'leased')
                            ->whereNotNull('lease_expires_at')
                            ->where('lease_expires_at', '<=', now());
                    });
                })
                ->orderBy('created_at', 'asc')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            foreach ($records as $item) {
                $item->state = 'leased';
                $item->lease_token = $leaseToken;
                $item->lease_expires_at = $leaseExpiresAt;
                $item->save();
            }

            return $records;
        });

        foreach ($items as $outbox) {
            $dispatched = $this->dispatchItem($outbox, $leaseToken);
            if ($dispatched) {
                $acknowledgedCount++;
            }
        }

        return $acknowledgedCount;
    }

    /**
     * Dispatch a single outbox record.
     */
    public function dispatchItem(PipelineOutbox $outbox, ?string $leaseToken = null): bool
    {
        if ($leaseToken !== null && $outbox->lease_token !== $leaseToken) {
            return false;
        }

        $command = PipelineCommand::find($outbox->command_id);
        if (! $command) {
            $outbox->state = 'failed';
            $outbox->lease_token = null;
            $outbox->lease_expires_at = null;
            $outbox->safe_code = 'receipt_invalid';
            $outbox->save();
            return false;
        }

        $run = SubjectRun::find($command->run_id);
        if (! $run) {
            $outbox->state = 'failed';
            $outbox->lease_token = null;
            $outbox->lease_expires_at = null;
            $outbox->safe_code = 'artifact_missing';
            $outbox->save();
            return false;
        }

        if ($outbox->destination === 'qualification_runner') {
            return $this->dispatchQualification($outbox, $command, $run);
        }

        // Feature 021: Local worker runs are exclusively processed by outbound local worker
        if ($run->execution_mode === 'local_worker_v1' || \App\Models\V3\LocalJob::where('run_id', $run->id)->exists()) {
            if (in_array($command->action, ['pause', 'resume', 'cancel', 'retry'], true)) {
                $this->applyLocalWorkerControl($command, $run);
            }
            $outbox->state = 'acknowledged';
            $outbox->acknowledged_at = now();
            $outbox->lease_token = null;
            $outbox->lease_expires_at = null;
            $outbox->safe_code = 'handled_by_local_worker';
            $outbox->save();
            return true;
        }

        $outbox->attempt_count += 1;

        try {
            if (in_array($command->action, ['submit', 'retry', 'generate_scene', 'import_terminal_run'], true)) {
                $manifestRef = $command->private_payload_ref;
                if (! $manifestRef || ! Str::isUuid($manifestRef)) {
                    $outbox->state = 'failed';
                    $outbox->lease_token = null;
                    $outbox->lease_expires_at = null;
                    $outbox->safe_code = 'artifact_missing';
                    $outbox->save();
                    return false;
                }

                $payload = [
                    'submission_id' => $command->action === 'submit' ? $run->submission_key : $command->id,
                    'submission_digest' => $command->request_sha256,
                    'run_id' => $run->id,
                    'manifest_ref' => $manifestRef,
                    'workflow_kind' => match ($command->action) {
                        'submit', 'retry' => 'web_subject_v1',
                        'generate_scene' => 'web_authoring_v1',
                        'import_terminal_run' => 'web_import_v1',
                    },
                    'retry' => $command->action === 'retry',
                ];
                if (in_array($command->action, ['generate_scene', 'import_terminal_run'], true)) {
                    $payload['subject_id'] = $command->id;
                }
                $resp = $this->bridgeClient->submitAdmission($payload);
                if ($command->action === 'retry') {
                    if (($resp['outcome'] ?? null) !== 'accepted') {
                        throw new \RuntimeException('Retry admission was not accepted by the workflow supervisor');
                    }
                    $this->applyControlReceipt($command, $run);
                }
            } else {
                $payload = [
                    'command_id' => $command->id,
                    'run_id' => $run->id,
                    'action' => $command->action,
                    'expected_version' => (int) $command->expected_version + 1,
                    'command_digest' => $command->request_sha256,
                ];
                $resp = $this->bridgeClient->sendCommand($payload);
                if (in_array($command->action, ['pause', 'resume', 'cancel', 'retry'], true)) {
                    if (($resp['outcome'] ?? null) !== 'applied' || ($resp['applied'] ?? false) !== true) {
                        throw new \RuntimeException('Control has not been applied by the workflow');
                    }
                    $this->applyControlReceipt($command, $run);
                }
            }

            // Successfully acknowledged
            $outbox->state = 'acknowledged';
            $outbox->lease_token = null;
            $outbox->lease_expires_at = null;
            $outbox->safe_code = null;
            $outbox->save();
            return true;
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 409) {
                if (in_array($command->action, ['pause', 'resume', 'cancel', 'retry'], true)) {
                    $command->update(['state' => 'rejected', 'safe_code' => 'control_conflict']);
                }
                $outbox->state = 'failed';
                $outbox->lease_token = null;
                $outbox->lease_expires_at = null;
                $outbox->safe_code = 'operation_conflict';
                $outbox->save();
                return false;
            }

            // Transient failure: backoff retry and release lease
            $delay = (int) pow(2, min($outbox->attempt_count, 6));
            $outbox->state = 'pending';
            $outbox->lease_token = null;
            $outbox->lease_expires_at = null;
            $outbox->next_attempt_at = now()->addSeconds($delay);
            $outbox->save();
            return false;
        } catch (\Throwable $e) {
            $delay = (int) pow(2, min($outbox->attempt_count, 6));
            $outbox->state = 'pending';
            $outbox->lease_token = null;
            $outbox->lease_expires_at = null;
            $outbox->next_attempt_at = now()->addSeconds($delay);
            $outbox->save();
            return false;
        }
    }

    /** Deliver qualification through the bridge; ack only means Temporal accepted it. */
    protected function dispatchQualification(PipelineOutbox $outbox, PipelineCommand $command, SubjectRun $run): bool
    {
        $candidateId = $command->private_payload_ref;
        $candidate = $candidateId ? \App\Models\V3\SceneCandidate::find($candidateId) : null;
        if (
            $command->action !== 'check_candidate'
            || ! $candidate
            || $candidate->case_id === null
            || ! Str::isUuid((string) $candidate->id)
            || ! Str::isUuid((string) $candidate->case_id)
        ) {
            $outbox->update([
                'state' => 'failed', 'lease_token' => null, 'lease_expires_at' => null,
                'safe_code' => 'receipt_invalid',
            ]);
            $command->update(['state' => 'rejected', 'safe_code' => 'receipt_invalid']);
            return false;
        }

        $outbox->attempt_count++;
        try {
            $response = $this->bridgeClient->qualifySceneCandidate([
                'command_id' => $command->id,
                'run_id' => $run->id,
                'case_id' => $candidate->case_id,
                'candidate_ref' => $candidate->id,
                'command_digest' => $command->request_sha256,
            ]);
            if (($response['outcome'] ?? null) !== 'accepted') {
                throw new \RuntimeException('Qualification workflow was not accepted');
            }
            DB::transaction(function () use ($outbox, $command) {
                $outbox->update([
                    'state' => 'acknowledged', 'lease_token' => null,
                    'lease_expires_at' => null, 'safe_code' => null,
                ]);
                $command->update([
                    'state' => 'applied',
                    'safe_code' => 'ok',
                    'result_ref' => 'scene-qualification-' . $command->id,
                    'applied_at' => now(),
                ]);
            });
            return true;
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 409) {
                $outbox->update([
                    'state' => 'failed', 'lease_token' => null, 'lease_expires_at' => null,
                    'safe_code' => 'operation_conflict',
                ]);
                $command->update(['state' => 'rejected', 'safe_code' => 'operation_conflict']);
                return false;
            }
            return $this->retryQualification($outbox);
        } catch (\Throwable $e) {
            return $this->retryQualification($outbox);
        }
    }

    protected function retryQualification(PipelineOutbox $outbox): bool
    {
        $delay = (int) pow(2, min($outbox->attempt_count, 6));
        $outbox->update([
            'state' => 'pending', 'lease_token' => null, 'lease_expires_at' => null,
            'next_attempt_at' => now()->addSeconds($delay), 'safe_code' => 'storage_unavailable',
        ]);
        return false;
    }

    /** Reflect a confirmed Temporal control update in Laravel's run projection. */
    protected function applyControlReceipt(PipelineCommand $command, SubjectRun $run): void
    {
        DB::transaction(function () use ($command, $run) {
            $lockedCommand = PipelineCommand::whereKey($command->id)->lockForUpdate()->firstOrFail();
            if ($lockedCommand->state === 'applied') {
                return;
            }
            if ($lockedCommand->state !== 'requested') {
                throw new \RuntimeException('Control command is not awaiting application');
            }

            $lockedRun = SubjectRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $nextState = match ($lockedCommand->action) {
                'pause' => 'paused',
                'resume' => 'running',
                'cancel' => 'cancelled',
                'retry' => 'staging',
                default => throw new \RuntimeException('Unsupported run control'),
            };
            $nextVersion = (int) $lockedRun->state_version + 1;
            $lockedRun->update([
                'state' => $nextState,
                'state_version' => $nextVersion,
                'last_updated_at' => now(),
            ]);
            $lockedCommand->update([
                'state' => 'applied',
                'safe_code' => 'ok',
                'applied_at' => now(),
            ]);
            $this->eventStore->recordEvent($lockedRun->id, [
                'operation_id' => $lockedCommand->id,
                'kind' => "control_{$lockedCommand->action}",
                'phase' => $lockedRun->generation_phase,
                'outcome' => 'applied',
                'safe_code' => 'ok',
                'state_version' => $nextVersion,
                'occurred_at' => now(),
            ]);
        });
    }

    /** Transactionally apply run control commands to LocalJob, LocalTasks, and LocalAssignments. */
    protected function applyLocalWorkerControl(PipelineCommand $command, SubjectRun $run): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($command, $run) {
            $lockedCommand = PipelineCommand::whereKey($command->id)->lockForUpdate()->firstOrFail();
            if ($lockedCommand->state === 'applied') {
                return;
            }

            $job = \App\Models\V3\LocalJob::where('run_id', $run->id)->lockForUpdate()->first();
            $tasks = $job ? \App\Models\V3\LocalTask::where('job_id', $job->id)->lockForUpdate()->get() : collect();

            match ($lockedCommand->action) {
                'pause' => (function () use ($job, $tasks) {
                    if ($job) {
                        $job->status = 'paused';
                        $job->save();
                    }
                    foreach ($tasks as $task) {
                        if (in_array($task->status, ['queued', 'leased', 'running'], true)) {
                            $task->status = 'paused';
                            $task->control_version += 1;
                            $task->save();
                        }
                    }
                })(),
                'resume' => (function () use ($job, $tasks) {
                    if ($job) {
                        $job->status = 'running';
                        $job->save();
                    }
                    foreach ($tasks as $task) {
                        if ($task->status === 'paused') {
                            $task->status = $task->active_assignment_id ? 'leased' : 'queued';
                            $task->control_version += 1;
                            $task->save();
                        }
                    }
                })(),
                'cancel' => (function () use ($job, $tasks) {
                    if ($job) {
                        $job->status = 'cancelled';
                        $job->save();
                    }
                    foreach ($tasks as $task) {
                        if (!in_array($task->status, ['completed', 'cancelled'], true)) {
                            $task->status = 'cancelled';
                            $task->control_version += 1;
                            $task->save();
                        }
                        if ($task->active_assignment_id) {
                            \App\Models\V3\LocalAssignment::where('id', $task->active_assignment_id)->update(['status' => 'cancelled']);
                        }
                    }
                })(),
                'retry' => (function () use ($job, $tasks) {
                    if ($job) {
                        $job->status = 'running';
                        $job->save();
                    }
                    foreach ($tasks as $task) {
                        if (in_array($task->status, ['failed', 'cancelled'], true)) {
                            $task->status = 'queued';
                            $task->active_assignment_id = null;
                            $task->control_version += 1;
                            $task->save();
                        }
                    }
                })(),
                default => null,
            };

            $this->applyControlReceipt($command, $run);
        });
    }
}
