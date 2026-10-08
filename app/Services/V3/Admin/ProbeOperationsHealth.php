<?php

namespace App\Services\V3\Admin;

use App\Services\V3\Workflow\BridgeClient;
use Illuminate\Support\Facades\DB;

class ProbeOperationsHealth
{
    public function __construct(
        protected BridgeClient $bridgeClient
    ) {}

    public function probe(): array
    {
        // 1. Probe Temporal bridge with bounded deadline
        $bridgeProbe = $this->bridgeClient->probeHealth();
        $isReachable = ($bridgeProbe['status'] === 200 && ($bridgeProbe['data']['reachable'] ?? false) === true);
        $temporalBridgeStatus = $isReachable ? 'reachable' : 'unavailable';

        // 2. Count backlogs safely
        $workflowStartBacklog = DB::table('v3_workflow_links')
            ->whereIn('start_state', ['pending', 'uncertain'])
            ->count();

        $workflowStartBacklog += DB::table('v3_processing_cases')
            ->where('state', 'awaiting_workflow')
            ->whereNotIn('id', function ($query) {
                $query->select('case_id')->from('v3_workflow_links');
            })
            ->count();

        $reviewDeliveryBacklog = DB::table('v3_review_outbox')
            ->whereIn('state', ['pending', 'sending', 'uncertain'])
            ->count();

        return [
            'checked_at' => now()->toIso8601String(),
            'temporal_bridge' => $temporalBridgeStatus,
            'workflow_start_backlog' => $workflowStartBacklog,
            'review_delivery_backlog' => $reviewDeliveryBacklog,
            'provider_health' => 'unknown',
        ];
    }
}
