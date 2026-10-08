<?php

namespace App\Console\Commands\V3;

use App\Services\V3\Source\ActivateSource;
use App\Services\V3\Source\ReconcileSource;
use Illuminate\Console\Command;

class DispatchSourcesCommand extends Command
{
    protected $signature = 'v3:dispatch-sources';
    protected $description = 'Discover eligible cases and activate 008 source processing';

    public function handle(
        ActivateSource $activator,
        ReconcileSource $reconciler
    ): int {
        $dispatched = $activator->discoverAndDispatch();
        $reconciled = $reconciler->reconcile();

        $this->info("Dispatched {$dispatched} source activations, reconciled {$reconciled}.");
        return self::SUCCESS;
    }
}
