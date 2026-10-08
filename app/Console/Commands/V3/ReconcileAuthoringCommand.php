<?php

namespace App\Console\Commands\V3;

use App\Services\V3\Authoring\ReconcileAuthoring;
use Illuminate\Console\Command;

class ReconcileAuthoringCommand extends Command
{
    protected $signature = 'v3:reconcile-authoring';
    protected $description = 'Reconcile abandoned authoring attempts and stale authoring requests';

    public function handle(ReconcileAuthoring $reconciler): int
    {
        $counts = $reconciler->reconcile();
        $this->info("Reconciliation complete. Uncertain attempts: {$counts['uncertain_attempts']}, Stale requests: {$counts['stale_requests']}.");

        return self::SUCCESS;
    }
}
