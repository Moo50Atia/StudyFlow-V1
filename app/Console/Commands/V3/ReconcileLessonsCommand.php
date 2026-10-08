<?php

namespace App\Console\Commands\V3;

use App\Services\V3\Lessons\ReconcileLessons;
use Illuminate\Console\Command;

class ReconcileLessonsCommand extends Command
{
    protected $signature = 'v3:reconcile-lessons';
    protected $description = 'Reconcile uncertain lesson map activations and completions';

    public function handle(
        ReconcileLessons $reconciler
    ): int {
        $result = $reconciler->reconcile();

        $this->info("Reconciled {$result['reconciled_activations']} map activations, {$result['reconciled_completions']} completions.");
        return self::SUCCESS;
    }
}
