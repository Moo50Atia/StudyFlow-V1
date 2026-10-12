<?php

namespace App\Console\Commands\V3;

use App\Services\V3\WebPipeline\DispatchRunOutbox;
use Illuminate\Console\Command;

class DispatchWebPipeline extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'v3:dispatch-web-pipeline
                            {--limit=50 : Maximum number of outbox items to process}
                            {--json : Output result in JSON format}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch pending pipeline outbox records to application bridge.';

    /**
     * Execute the console command.
     */
    public function handle(DispatchRunOutbox $dispatcher): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $dispatched = $dispatcher->dispatchPending($limit);

        if ($this->option('json')) {
            $this->line(json_encode([
                'outcome' => 'complete',
                'dispatched_count' => $dispatched,
            ]));
        } else {
            $this->info("Dispatched {$dispatched} outbox records to application bridge.");
        }

        return 0;
    }
}
