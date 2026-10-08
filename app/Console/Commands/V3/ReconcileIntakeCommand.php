<?php

namespace App\Console\Commands\V3;

use App\Services\V3\Intake\ReconcileIntake;
use Illuminate\Console\Command;

class ReconcileIntakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'v3:reconcile-intake';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile pending V3 PDF intake attempts, leases, promotions, and cleanup';

    /**
     * Execute the console command.
     */
    public function handle(ReconcileIntake $reconciler): int
    {
        $stats = $reconciler->reconcile();

        $this->info(sprintf(
            'Reconciled intake: %d cleanup, %d promoting, %d expired, %d due dispatched.',
            $stats['cleanup_reconciled'],
            $stats['promoting_reconciled'],
            $stats['expired_blocked'],
            $stats['due_dispatched']
        ));

        return Command::SUCCESS;
    }
}
