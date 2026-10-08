<?php

namespace App\Console\Commands\V3;

use App\Services\V3\Review\EnsureReviewReady;
use Illuminate\Console\Command;

class ReconcileSceneReviewsCommand extends Command
{
    protected $signature = 'v3:reconcile-scene-reviews';
    protected $description = 'Reconcile 012 passing tips missed by a workflow callback';

    public function handle(EnsureReviewReady $ensureReviewReady): int
    {
        $count = $ensureReviewReady->reconcileAllPassing();
        $this->info("Reconciliation complete. Created items: {$count}.");
        return self::SUCCESS;
    }
}
