<?php

namespace App\Services\V3\Workflow;

use App\Models\V3\CaseEvent;
use App\Models\V3\ProcessingCase;
use App\Models\V3\WorkflowLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReconcileWorkflowStart
{
    public function __construct(
        protected BridgeClient $bridgeClient
    ) {}

    public function reconcile(): int
    {
        $links = WorkflowLink::where(function ($query) {
            $query->where('start_state', 'started')
                ->where(function ($q) {
                    $q->whereNull('last_reconciled_at')
                        ->orWhere('last_reconciled_at', '<=', now()->subMinutes(5));
                });
        })->orWhere(function ($query) {
            $query->where('start_state', 'uncertain');
        })->orWhere(function ($query) {
            $query->where('start_state', 'starting')
                ->where('lease_until', '<', now());
        })->get();

        $count = 0;
        foreach ($links as $link) {
            $this->reconcileLink($link);
            $count++;
        }

        return $count;
    }

    public function reconcileLink(WorkflowLink $link): void
    {
        // Check 24-hour overdue alert
        if (! in_array($link->start_state, ['started', 'closed'], true)) {
            $createdAt = $link->created_at ?? now();
            if ($createdAt->diffInHours(now()) >= 24) {
                $this->logAlertOnce($link, 'workflow_start_overdue');
                $link->last_alert_code = 'workflow_start_overdue';
                $link->last_alerted_at = now();
                $link->save();
            }
        }

        $resp = $this->bridgeClient->describeWorkflow($link->workflow_id);

        if (! is_array($resp) || ($resp['status'] ?? null) !== 200) {
            // Bridge or Temporal unavailable: stay in current state (uncertain/started), do not infer not-found!
            $link->last_reconciled_at = now();
            $link->save();
            return;
        }

        $data = $resp['data'] ?? [];
        if (! empty($data['found'])) {
            // Verify memo
            $memoCaseId = $data['case_id'] ?? null;
            $memoSettingsId = $data['settings_version_id'] ?? null;
            $memoChecksum = $data['settings_checksum'] ?? null;

            if ($memoCaseId !== null && $memoCaseId !== $link->case_id) {
                $this->handleIdentityConflict($link);
                return;
            }
            if ($memoSettingsId !== null && (int) $memoSettingsId !== (int) $link->settings_version_id) {
                $this->handleIdentityConflict($link);
                return;
            }
            if ($memoChecksum !== null && $link->settings_checksum !== null && $memoChecksum !== $link->settings_checksum) {
                $this->handleIdentityConflict($link);
                return;
            }

            $runId = $data['run_id'] ?? null;
            $temporalStatus = $data['temporal_status'] ?? 'open';

            $linkUpdates = [
                'last_reconciled_at' => now(),
            ];
            if ($link->run_id === null && $runId) {
                $linkUpdates['run_id'] = $runId;
            }

            if ($temporalStatus === 'open') {
                $linkUpdates['start_state'] = 'started';
                if ($link->started_at === null) {
                    $linkUpdates['started_at'] = now();
                }
                $link->forceFill($linkUpdates)->save();
            } else {
                $linkUpdates['start_state'] = 'closed';
                $link->forceFill($linkUpdates)->save();
                if ($runId) {
                    $this->reconcileClosedWorkflow($link->processingCase, $link, $runId);
                }
            }
        } else {
            // Verified absent by Temporal
            $startAttemptedAt = $link->start_attempted_at ?? $link->created_at ?? now();
            if ($startAttemptedAt->diffInDays(now()) >= 30) {
                $this->logAlertOnce($link, 'workflow_history_missing');
                $link->forceFill([
                    'start_state' => 'history_missing',
                    'last_alert_code' => 'workflow_history_missing',
                    'last_alerted_at' => now(),
                    'last_reconciled_at' => now(),
                ])->save();
            } else {
                // Verified not-found within 30 days permits retry
                $link->forceFill([
                    'start_state' => 'pending',
                    'lease_until' => null,
                    'last_reconciled_at' => now(),
                ])->save();
            }
        }
    }

    public function reconcileClosedWorkflow(?ProcessingCase $case, WorkflowLink $link, string $runId): void
    {
        if (! $case) {
            return;
        }

        DB::transaction(function () use ($case, $link, $runId) {
            $lockedCase = ProcessingCase::where('id', $case->id)->lockForUpdate()->first();
            if (! $lockedCase || in_array($lockedCase->state, ['blocked', 'failed'], true)) {
                return;
            }

            $eventKey = "temporal_closed:{$runId}";
            $existing = CaseEvent::where('case_id', $lockedCase->id)->where('event_key', $eventKey)->first();
            if ($existing) {
                return;
            }

            $newSeq = $lockedCase->state_version + 1;
            $payloadDigest = hash('sha256', implode("\n", [
                '1',
                $eventKey,
                $lockedCase->id,
                $link->workflow_id,
                $runId,
                (string) $lockedCase->state_version,
                'workflow_failed',
                $link->stage_code,
                'failed',
                'temporal_closed',
            ]));

            CaseEvent::forceCreate([
                'id' => (string) Str::uuid(),
                'case_id' => $lockedCase->id,
                'event_key' => $eventKey,
                'payload_digest' => $payloadDigest,
                'sequence' => $newSeq,
                'workflow_id' => $link->workflow_id,
                'run_id' => $runId,
                'event_type' => 'workflow_failed',
                'stage_code' => $link->stage_code,
                'status_code' => 'failed',
                'error_code' => 'temporal_closed',
                'recorded_at' => now(),
            ]);

            $lockedCase->forceFill([
                'state' => 'failed',
                'state_version' => $newSeq,
            ])->save();

            $link->forceFill([
                'status_code' => 'failed',
                'error_code' => 'temporal_closed',
                'last_event_at' => now(),
            ])->save();
        });
    }

    protected function handleIdentityConflict(WorkflowLink $link): void
    {
        $this->logAlertOnce($link, 'workflow_identity_conflict');
        $link->forceFill([
            'start_state' => 'identity_conflict',
            'last_alert_code' => 'workflow_identity_conflict',
            'last_alerted_at' => now(),
            'last_reconciled_at' => now(),
        ])->save();
    }

    protected function logAlertOnce(WorkflowLink $link, string $alertCode): void
    {
        if ($link->last_alert_code === $alertCode && $link->last_alerted_at !== null) {
            if ($link->last_alerted_at->diffInHours(now()) < 24) {
                return;
            }
        }

        Log::warning($alertCode, [
            'code' => $alertCode,
            'case_id' => $link->case_id,
        ]);
    }
}
