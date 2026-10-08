<?php

namespace App\Console\Commands\V3;

use App\Services\V3\Review\DispatchReviewEffects;
use Illuminate\Console\Command;

class DispatchReviewEffectsCommand extends Command
{
    protected $signature = 'v3:dispatch-review-effects';
    protected $description = 'Dispatch pending review outbox signals and correction workflows';

    public function handle(DispatchReviewEffects $dispatcher): int
    {
        $count = $dispatcher->dispatchPending();
        $this->info("Dispatched {$count} review effects.");
        return 0;
    }
}
