<?php

namespace App\Jobs\V3;

use App\Models\V3\SourceActivation;
use App\Services\V3\Source\ActivateSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ActivateCaseSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(public string $activationId)
    {
        $this->onQueue('v3_workflow');
        $this->afterCommit = true;
    }

    public function handle(ActivateSource $activator): void
    {
        $activation = SourceActivation::find($this->activationId);
        if ($activation) {
            $activator->activate($activation);
        }
    }
}
