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
            $records = PipelineOutbox::where('destination', 'application_bridge')
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

        $outbox->attempt_count += 1;

        try {
            if (in_array($command->action, ['submit', 'generate_scene', 'import_terminal_run'], true)) {
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
                        'submit' => 'web_subject_v1',
                        'generate_scene' => 'web_authoring_v1',
                        'import_terminal_run' => 'web_import_v1',
                    },
                ];
                if (in_array($command->action, ['generate_scene', 'import_terminal_run'], true)) {
                    $payload['subject_id'] = $command->id;
                }
                $resp = $this->bridgeClient->submitAdmission($payload);
            } else {
                $payload = [
                    'command_id' => $command->id,
                    'run_id' => $run->id,
                    'action' => $command->action,
                    'expected_version' => (int) $command->expected_version + 1,
                    'command_digest' => $command->request_sha256,
                ];
                $resp = $this->bridgeClient->sendCommand($payload);
                if (in_array($command->action, ['pause', 'resume', 'cancel'], true)) {
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
                if (in_array($command->action, ['pause', 'resume', 'cancel'], true)) {
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
}
