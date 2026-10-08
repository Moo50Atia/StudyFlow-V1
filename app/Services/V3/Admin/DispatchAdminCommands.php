<?php

namespace App\Services\V3\Admin;

use App\Models\V3\AdminCommand;
use App\Models\V3\AdminCommandDelivery;
use App\Services\V3\Workflow\BridgeClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Services\V3\Workflow\BridgeRequestSigner;


class DispatchAdminCommands
{
    public function __construct(
        protected BridgeClient $bridgeClient,
        protected ReconcileAdminTarget $reconcileAdminTarget
    ) {}

    public function dispatch(): int
    {
        $now = now();
        $deliveries = AdminCommandDelivery::whereIn('state', ['pending', 'uncertain', 'sending'])
            ->where(function ($q) use ($now) {
                $q->whereNull('lease_until')->orWhere('lease_until', '<', $now);
            })
            ->limit(50)
            ->get();

        $dispatched = 0;

        foreach ($deliveries as $delivery) {
            $claimed = DB::transaction(function () use ($delivery) {
                $d = AdminCommandDelivery::where('id', $delivery->id)
                    ->whereIn('state', ['pending', 'uncertain', 'sending'])
                    ->where(function ($q) {
                        $q->whereNull('lease_until')->orWhere('lease_until', '<', now());
                    })
                    ->lockForUpdate()
                    ->first();

                if (! $d) {
                    return null;
                }

                $d->lease_until = now()->addMinutes(2);
                $d->attempt_count = $d->attempt_count + 1;
                $d->last_attempt_at = now();
                $d->save();

                return $d;
            });

            if (! $claimed) {
                continue;
            }

            $command = AdminCommand::find($claimed->command_id);
            if (! $command) {
                continue;
            }

            $path = "/internal/v1/workflows/{$claimed->workflow_id}/admin-controls";
            $baseUrl = config('services.v3.bridge_url', 'http://127.0.0.1:8765');
            $secretKey = config('services.v3.bridge_hmac_key');

            if (empty($secretKey)) {
                $claimed->state = 'uncertain';
                $claimed->save();
                continue;
            }

            $body = [
                'schema_version' => 1,
                'case_id' => $claimed->case_id,
                'command_ref' => (string) $command->id,
                'control_version' => (int) $claimed->control_version,
                'action' => $command->action,
            ];

            $signed = BridgeRequestSigner::prepareBridgeRequest('POST', $path, $body, $secretKey);

            try {
                $resp = Http::withHeaders($signed['headers'])
                    ->timeout(5)
                    ->withBody($signed['body'], 'application/json')
                    ->post(rtrim($baseUrl, '/') . $path);

                if ($resp->status() === 202) {
                    $claimed->state = 'sending';
                    $claimed->save();
                    $dispatched++;
                } elseif ($resp->status() === 200) {
                    // Already applied
                    $claimed->state = 'acknowledged';
                    $claimed->acknowledged_at = now();
                    $claimed->save();
                    $dispatched++;
                } else {
                    $claimed->state = 'uncertain';
                    $claimed->save();
                }
            } catch (\Throwable $e) {
                $claimed->state = 'uncertain';
                $claimed->save();
            }
        }

        // Claim and reconcile retry commands with 2-minute lease
        $retryCommands = AdminCommand::where('action', 'retry')
            ->whereIn('status', ['accepted', 'uncertain'])
            ->where(function ($q) use ($now) {
                $q->whereNull('lease_until')->orWhere('lease_until', '<', $now);
            })
            ->limit(50)
            ->get();

        foreach ($retryCommands as $retryCmd) {
            $claimedRetry = DB::transaction(function () use ($retryCmd) {
                $c = AdminCommand::where('id', $retryCmd->id)
                    ->whereIn('status', ['accepted', 'uncertain'])
                    ->where(function ($q) {
                        $q->whereNull('lease_until')->orWhere('lease_until', '<', now());
                    })
                    ->lockForUpdate()
                    ->first();

                if (! $c) {
                    return null;
                }

                $c->lease_until = now()->addMinutes(2);
                $c->dispatch_attempts = $c->dispatch_attempts + 1;
                $c->last_attempt_at = now();
                $c->save();

                return $c;
            });

            if (! $claimedRetry) {
                continue;
            }

            $this->reconcileAdminTarget->reconcile($claimedRetry);
            $dispatched++;
        }

        return $dispatched;
    }
}
