<?php

namespace App\Console\Commands\V3;

use App\Models\V3\LocalAssignment;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalProviderEffect;
use App\Models\V3\LocalTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireLocalWorkerLeases extends Command
{
    protected $signature = 'v3:expire-local-worker-leases';

    protected $description = 'Expire abandoned local-worker leases and hold tasks with ambiguous provider effects';

    public function handle(): int
    {
        $expiredCount = 0;
        $heldCount = 0;

        LocalAssignment::query()
            ->whereIn('status', ['active', 'reconciliation_only'])
            ->where('lease_expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($assignments) use (&$expiredCount, &$heldCount): void {
                foreach ($assignments as $candidate) {
                    $result = DB::transaction(function () use ($candidate): ?string {
                        $assignment = LocalAssignment::query()->lockForUpdate()->find($candidate->id);
                        if (!$assignment || !in_array($assignment->status, ['active', 'reconciliation_only'], true) || $assignment->lease_expires_at->isFuture()) {
                            return null;
                        }

                        $task = LocalTask::query()->lockForUpdate()->find($assignment->task_id);
                        if (!$task || $task->active_assignment_id !== $assignment->id
                            || !in_array($task->status, ['leased', 'running'], true)) {
                            $assignment->status = 'expired';
                            $assignment->save();
                            if ($task && $task->active_assignment_id === $assignment->id) {
                                // Retain the hold/control, but release the dead
                                // assignment so owner reconciliation can begin.
                                $task->active_assignment_id = null;
                                $task->save();
                            }
                            return null;
                        }

                        $hasAmbiguousEffect = LocalProviderEffect::query()
                            ->where('task_id', $task->id)
                            ->whereIn('status', ['dispatching', 'unknown'])
                            ->exists();

                        $assignment->status = 'expired';
                        $assignment->save();
                        $task->active_assignment_id = null;
                        $task->status = $hasAmbiguousEffect ? 'reconciliation_required' : 'queued';
                        $task->save();

                        $job = LocalJob::query()->lockForUpdate()->find($task->job_id);
                        if ($job && $hasAmbiguousEffect) {
                            $job->status = 'held_reconciliation';
                            $job->save();
                        } elseif ($job && $job->status === 'running') {
                            $job->status = 'queued';
                            $job->save();
                        }

                        return $hasAmbiguousEffect ? 'held' : 'queued';
                    });

                    if ($result !== null) {
                        $expiredCount++;
                        if ($result === 'held') {
                            $heldCount++;
                        }
                    }
                }
            }, 'id');

        $this->info("Expired {$expiredCount} local-worker lease(s); held {$heldCount} task(s) for reconciliation.");
        return self::SUCCESS;
    }
}
