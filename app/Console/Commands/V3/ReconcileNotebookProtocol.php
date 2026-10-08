<?php

namespace App\Console\Commands\V3;

use App\Services\V3\NotebookLM\ReconcileDirectProtocol;
use Illuminate\Console\Command;

class ReconcileNotebookProtocol extends Command
{
    protected $signature = 'v3:reconcile-notebook-protocol';

    protected $description = 'Reconcile uncertain direct NotebookLM protocol queries, completions, and signals';

    public function handle(
        ReconcileDirectProtocol $reconciler
    ): int {
        $result = $reconciler->reconcile();

        $this->info("Reconciled {$result['reconciled_completions']} completions, {$result['reconciled_bundles']} bundles. Fenced cases: {$result['fenced_cases']}.");

        return self::SUCCESS;
    }
}
