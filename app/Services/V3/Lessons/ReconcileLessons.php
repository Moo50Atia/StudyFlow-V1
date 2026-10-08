<?php

namespace App\Services\V3\Lessons;

use App\Models\V3\LessonCompletion;
use App\Models\V3\LessonQuery;
use App\Models\V3\MapActivation;
use App\Services\V3\Workflow\BridgeClient;
use Illuminate\Support\Facades\Log;

class ReconcileLessons
{
    public function __construct(
        protected BridgeClient $bridgeClient
    ) {}

    /**
     * Reconcile uncertain activations, stuck queries, and unacknowledged completion signals.
     */
    public function reconcile(): array
    {
        $reconciledActivations = 0;
        $reconciledCompletions = 0;
        $now = now();

        // 1. Reconcile uncertain map activations
        $uncertainActivations = MapActivation::where('state', 'uncertain')
            ->where(function ($q) use ($now) {
                $q->whereNull('next_check_at')
                  ->orWhere('next_check_at', '<=', $now);
            })
            ->get();

        foreach ($uncertainActivations as $act) {
            try {
                $binding = $act->binding;
                if (! $binding || $binding->state !== 'bound') {
                    continue;
                }

                $resp = $this->bridgeClient->activateMap(
                    $act->workflow_id,
                    [
                        'schema_version' => 1,
                        'case_id' => $act->case_id,
                        'binding_ref' => $act->binding_id,
                        'activation_id' => $act->id,
                    ]
                );

                if (($resp['status'] ?? 0) === 202) {
                    $act->update([
                        'state' => 'accepted',
                        'signal_accepted_at' => now(),
                    ]);
                    $reconciledActivations++;
                }
            } catch (\Throwable $e) {
                // Check if overdue
                if ($act->created_at && $act->created_at->diffInHours(now()) >= 24) {
                    if (! $act->last_alerted_at || $act->last_alerted_at->diffInHours(now()) >= 24) {
                        Log::warning("mapping_activation_overdue", ['case_id' => $act->case_id]);
                        $act->update([
                            'last_alert_code' => 'mapping_activation_overdue',
                            'last_alerted_at' => now(),
                        ]);
                    }
                }
            }
        }

        // 2. Reconcile unacknowledged completion signals
        $uncertainCompletions = LessonCompletion::where('signal_state', 'uncertain')
            ->get();

        foreach ($uncertainCompletions as $comp) {
            try {
                $workflowId = "v3-case-{$comp->case_id}";
                $resp = $this->bridgeClient->notifyLessonReady(
                    $workflowId,
                    [
                        'schema_version' => 1,
                        'case_id' => $comp->case_id,
                        'map_ref' => $comp->map_id,
                        'work_ordinal' => $comp->work_ordinal,
                        'cursor_version' => $comp->cursor_version,
                        'completion_ref' => $comp->completion_ref,
                    ]
                );

                if (($resp['status'] ?? 0) === 202) {
                    $comp->update([
                        'signal_state' => 'accepted',
                        'signal_accepted_at' => now(),
                    ]);
                    $reconciledCompletions++;
                }
            } catch (\Throwable $e) {
                // Log safe code if overdue
            }
        }

        return [
            'reconciled_activations' => $reconciledActivations,
            'reconciled_completions' => $reconciledCompletions,
        ];
    }
}
