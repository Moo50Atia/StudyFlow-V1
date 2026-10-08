<?php

namespace App\Jobs\V3;

use App\Services\V3\Intake\IntakeCoordinator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ValidatePdfUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 270;

    public function __construct(
        public string $attemptId
    ) {
        $this->onQueue('v3_intake');
    }

    public function handle(IntakeCoordinator $coordinator): void
    {
        $coordinator->processAttempt($this->attemptId);
    }
}
