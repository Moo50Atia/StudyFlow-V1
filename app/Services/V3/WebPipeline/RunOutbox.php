<?php

namespace App\Services\V3\WebPipeline;

use App\Models\V3\PipelineCommand;
use App\Models\V3\PipelineOutbox;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RunOutbox
{
    /**
     * Enqueue a command to the durable outbox.
     */
    public function enqueue(PipelineCommand|string $command, string $destination = 'application_bridge'): PipelineOutbox
    {
        $commandModel = is_string($command) ? PipelineCommand::findOrFail($command) : $command;

        return DB::transaction(function () use ($commandModel, $destination) {
            $existing = PipelineOutbox::where('command_id', $commandModel->id)->first();
            if ($existing) {
                return $existing;
            }

            return PipelineOutbox::create([
                'id' => (string) Str::uuid(),
                'command_id' => $commandModel->id,
                'destination' => $destination,
                'state' => 'pending',
                'attempt_count' => 0,
                'next_attempt_at' => now(),
            ]);
        });
    }

    /**
     * Claim pending or expired leased outbox messages for dispatch.
     *
     * @return Collection<int, PipelineOutbox>
     */
    public function claimPending(int $limit = 10, int $leaseSeconds = 60, ?string $destination = null): Collection
    {
        return DB::transaction(function () use ($limit, $leaseSeconds, $destination) {
            $query = PipelineOutbox::where(function ($q) {
                $q->where(function ($sub) {
                    $sub->where('state', 'pending')
                        ->where(function ($dateQ) {
                            $dateQ->whereNull('next_attempt_at')
                                ->orWhere('next_attempt_at', '<=', now());
                        });
                })->orWhere(function ($sub) {
                    $sub->where('state', 'leased')
                        ->whereNotNull('lease_expires_at')
                        ->where('lease_expires_at', '<=', now());
                });
            });

            if ($destination) {
                $query->where('destination', $destination);
            }

            $items = $query->orderBy('created_at', 'asc')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            $claimed = collect();
            foreach ($items as $item) {
                $leaseToken = (string) Str::uuid();
                $item->update([
                    'state' => 'leased',
                    'lease_token' => $leaseToken,
                    'lease_expires_at' => now()->addSeconds($leaseSeconds),
                    'attempt_count' => $item->attempt_count + 1,
                ]);
                $claimed->push($item->fresh(['command']));
            }

            return $claimed;
        });
    }

    /**
     * Mark an outbox item as delivered, verifying lease token.
     */
    public function markDelivered(PipelineOutbox|string $outbox, string $leaseToken, ?string $safeCode = 'ok'): bool
    {
        $outboxId = is_string($outbox) ? $outbox : $outbox->id;

        return DB::transaction(function () use ($outboxId, $leaseToken, $safeCode) {
            $record = PipelineOutbox::lockForUpdate()->find($outboxId);
            if (! $record || $record->lease_token !== $leaseToken) {
                return false;
            }

            $record->update([
                'state' => 'delivered',
                'lease_token' => null,
                'lease_expires_at' => null,
                'safe_code' => $safeCode,
            ]);

            return true;
        });
    }

    /**
     * Mark an outbox item as failed, releasing lease and scheduling retry or terminal failure.
     */
    public function markFailed(
        PipelineOutbox|string $outbox,
        string $leaseToken,
        string $safeCode,
        int $retryDelaySeconds = 10,
        int $maxAttempts = 5
    ): bool {
        $outboxId = is_string($outbox) ? $outbox : $outbox->id;

        return DB::transaction(function () use ($outboxId, $leaseToken, $safeCode, $retryDelaySeconds, $maxAttempts) {
            $record = PipelineOutbox::lockForUpdate()->find($outboxId);
            if (! $record || $record->lease_token !== $leaseToken) {
                return false;
            }

            $isTerminal = $record->attempt_count >= $maxAttempts;

            $record->update([
                'state' => $isTerminal ? 'failed' : 'pending',
                'lease_token' => null,
                'lease_expires_at' => null,
                'next_attempt_at' => $isTerminal ? null : now()->addSeconds($retryDelaySeconds),
                'safe_code' => $safeCode,
            ]);

            return true;
        });
    }

    /**
     * Reconcile lost acknowledgment by checking underlying command status.
     */
    public function reconcileLostAck(string $commandId): ?PipelineOutbox
    {
        return DB::transaction(function () use ($commandId) {
            $command = PipelineCommand::find($commandId);
            $outbox = PipelineOutbox::where('command_id', $commandId)->lockForUpdate()->first();

            if (! $command || ! $outbox) {
                return $outbox;
            }

            if ($command->state === 'applied' && $outbox->state !== 'delivered') {
                $outbox->update([
                    'state' => 'delivered',
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'safe_code' => $command->safe_code ?: 'ok',
                ]);
            }

            return $outbox->fresh();
        });
    }
}
