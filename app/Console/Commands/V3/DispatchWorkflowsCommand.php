<?php

namespace App\Console\Commands\V3;

use App\Services\V3\Workflow\DiscoverCases;
use App\Services\V3\Workflow\ReconcileWorkflowStart;
use Illuminate\Console\Command;

class DispatchWorkflowsCommand extends Command
{
    protected $signature = 'v3:dispatch-workflows';
    protected $description = 'Discover awaiting_workflow cases and dispatch Temporal workflows';

    public function handle(
        DiscoverCases $discoverer,
        ReconcileWorkflowStart $reconciler
    ): int {
        $discovered = $discoverer->discover();
        $reconciled = $reconciler->reconcile();

        $this->info("Dispatched {$discovered} cases, reconciled {$reconciled} workflows.");
        return self::SUCCESS;
    }
}
