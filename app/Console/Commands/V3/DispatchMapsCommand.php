<?php

namespace App\Console\Commands\V3;

use App\Services\V3\Lessons\ActivateMap;
use Illuminate\Console\Command;

class DispatchMapsCommand extends Command
{
    protected $signature = 'v3:dispatch-maps';
    protected $description = 'Discover eligible cases and activate 009 lesson mapping';

    public function handle(
        ActivateMap $activator
    ): int {
        $dispatched = $activator->discoverAndDispatch();

        $this->info("Dispatched {$dispatched} map activations.");
        return self::SUCCESS;
    }
}
