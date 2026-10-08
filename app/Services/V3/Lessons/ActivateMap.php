<?php

namespace App\Services\V3\Lessons;

use App\Jobs\V3\ActivateMapJob;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\MapActivation;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\WorkflowLink;
use App\Services\V3\Workflow\BridgeClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ActivateMap
{
    public function __construct(
        protected BridgeClient $bridgeClient
    ) {}

    public function discoverAndDispatch(): int
    {
        $dispatched = 0;

        $links = WorkflowLink::where('start_state', 'started')
            ->where('stage_code', 'sourcing')
            ->where('status_code', 'queued')
            ->whereHas('processingCase', function ($query) {
                $query->where('state', 'queued');
            })
            ->get();

        foreach ($links as $link) {
            try {
                $created = DB::transaction(function () use ($link) {
                    $lockedCase = ProcessingCase::where('id', $link->case_id)->lockForUpdate()->first();
                    if (!$lockedCase || $lockedCase->state !== 'queued') {
                        return false;
                    }

                    $lockedLink = WorkflowLink::where('case_id', $link->case_id)->lockForUpdate()->first();
                    if (!$lockedLink || $lockedLink->start_state !== 'started' || $lockedLink->stage_code !== 'sourcing') {
                        return false;
                    }

                    $hasTerminal = DB::table('v3_case_events')
                        ->where('case_id', $lockedCase->id)
                        ->whereIn('status_code', ['blocked', 'failed'])
                        ->exists();
                    if ($hasTerminal) {
                        return false;
                    }

                    $settings = SettingsVersion::find($lockedCase->settings_version_id);
                    if (!$settings || $settings->checksum !== $lockedLink->settings_checksum) {
                        return false;
                    }

                    $binding = KnowledgeSourceBinding::where('case_id', $lockedCase->id)
                        ->where('state', 'bound')
                        ->first();
                    if (!$binding) {
                        return false;
                    }

                    $existing = MapActivation::where('case_id', $lockedCase->id)->lockForUpdate()->first();
                    if ($existing) {
                        return false;
                    }

                    $activation = MapActivation::forceCreate([
                        'id' => (string) Str::uuid(),
                        'case_id' => $lockedCase->id,
                        'binding_id' => $binding->id,
                        'workflow_id' => $lockedLink->workflow_id,
                        'state' => 'pending',
                        'lease_until' => now()->addMinutes(2),
                    ]);

                    ActivateMapJob::dispatch($activation->id)->afterCommit();
                    return true;
                }, 5);
            } catch (\Illuminate\Database\QueryException $e) {
                $created = false;
            }

            if ($created) {
                $dispatched++;
            }
        }

        return $dispatched;
    }

    public function activate(MapActivation $activation): bool
    {
        $claimed = DB::transaction(function () use ($activation) {
            $locked = MapActivation::where('id', $activation->id)->lockForUpdate()->first();
            if (!$locked) {
                return false;
            }

            if (in_array($locked->state, ['accepted', 'blocked'], true)) {
                return false;
            }

            if ($locked->state === 'signaling' && $locked->lease_until !== null && $locked->lease_until > now()) {
                return false;
            }

            $locked->state = 'signaling';
            $locked->lease_until = now()->addMinutes(2);
            $locked->signal_attempted_at = now();
            $locked->signal_attempts = $locked->signal_attempts + 1;
            $locked->save();

            return true;
        });

        if (!$claimed) {
            return false;
        }

        $resp = $this->bridgeClient->activateMap($activation->workflow_id, [
            'schema_version' => 1,
            'case_id' => $activation->case_id,
            'binding_ref' => $activation->binding_id,
            'activation_id' => $activation->id,
        ]);

        return DB::transaction(function () use ($activation, $resp) {
            $locked = MapActivation::where('id', $activation->id)->lockForUpdate()->first();
            if (!$locked) {
                return false;
            }

            if ($resp['status'] === 202 && !empty($resp['data']['accepted'])) {
                $locked->state = 'accepted';
                $locked->signal_accepted_at = now();
                $locked->lease_until = null;
                $locked->error_code = null;
                $locked->save();
                return true;
            } elseif ($resp['status'] === 409) {
                $locked->state = 'blocked';
                $locked->error_code = $resp['error']['code'] ?? 'mapping_activation_conflict';
                $locked->lease_until = null;
                $locked->save();
                return false;
            } else {
                $locked->state = 'uncertain';
                $locked->lease_until = null;
                $locked->error_code = $resp['error']['code'] ?? 'workflow_unavailable';
                $locked->save();
                return false;
            }
        });
    }
}
