<?php

namespace App\Jobs\V3;

use App\Services\V3\Workflow\StartWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class StartCaseWorkflowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(
        public string $caseId
    ) {
        $this->onConnection('v3_workflow');
        $this->onQueue('v3_workflow');
    }

    public function handle(StartWorkflow $starter): void
    {
        $starter->start($this->caseId);
    }
}
