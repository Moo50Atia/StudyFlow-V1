<?php

namespace App\Services\V3\Source;

use App\Models\V3\SourceActivation;
use Illuminate\Support\Facades\Log;

class ReconcileSource
{
    public function __construct(
        protected ActivateSource $activator,
        protected SourceArtifactStore $artifactStore,
    ) {}

    public function reconcile(): int
    {
        $reconciled = 0;

        $activations = SourceActivation::where('state', 'uncertain')
            ->orWhere(function ($query) {
                $query->whereIn('state', ['pending', 'signaling'])
                    ->where(function ($q) {
                        $q->whereNull('lease_until')
                            ->orWhere('lease_until', '<', now());
                    });
            })
            ->whereHas('processingCase', function ($query) {
                $query->where('state', 'queued');
            })
            ->get();

        foreach ($activations as $activation) {
            if ($activation->signal_attempted_at && $activation->signal_attempted_at <= now()->subHours(24)) {
                if ($activation->last_alert_code !== 'source_activation_overdue' || !$activation->last_alerted_at || $activation->last_alerted_at <= now()->subHours(24)) {
                    Log::warning('source_activation_overdue', [
                        'code' => 'source_activation_overdue',
                        'case_id' => $activation->case_id,
                        'workflow_id' => $activation->workflow_id,
                    ]);
                    $activation->update([
                        'last_alert_code' => 'source_activation_overdue',
                        'last_alerted_at' => now(),
                    ]);
                }
            }

            $success = $this->activator->activate($activation);
            if ($success) {
                $reconciled++;
            }
        }

        $this->artifactStore->purgeOrphans();

        return $reconciled;
    }
}
