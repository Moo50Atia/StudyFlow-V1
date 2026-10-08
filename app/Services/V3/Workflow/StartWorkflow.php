<?php

namespace App\Services\V3\Workflow;

use App\Models\V3\ProcessingCase;
use App\Models\V3\WorkflowLink;
use App\Services\V3\Access\V3AccessResolver;
use App\Services\V3\Settings\SettingsUnavailable;
use App\Services\V3\Settings\SettingsVersionRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StartWorkflow
{
    public function __construct(
        protected BridgeClient $bridgeClient,
        protected ?SettingsVersionRepository $settingsRepo = null,
        protected ?V3AccessResolver $accessResolver = null,
        protected ?ReconcileWorkflowStart $reconciler = null
    ) {
        $this->settingsRepo = $settingsRepo ?? app(SettingsVersionRepository::class);
        $this->accessResolver = $accessResolver ?? app(V3AccessResolver::class);
        $this->reconciler = $reconciler ?? app(ReconcileWorkflowStart::class);
    }

    public function start(string $caseId): array
    {
        /** @var ProcessingCase|null $case */
        $case = ProcessingCase::with('owner')->find($caseId);
        if (! $case) {
            return ['status' => 'not_found'];
        }

        /** @var WorkflowLink|null $link */
        $link = WorkflowLink::where('case_id', $caseId)->first();
        if (! $link) {
            return ['status' => 'no_link'];
        }

        // Check 014 admin operation control fence
        if (! app(\App\Services\V3\Admin\CaseOperationGuard::class)->isPermitted($caseId)) {
            return ['status' => 'deferred', 'reason' => 'operation_fenced'];
        }

        // 1. Recheck owner access immediately before remote start
        $owner = $case->owner;
        $accessResolution = $this->accessResolver->resolve($owner);
        if (! $accessResolution['allowed'] || $accessResolution['role'] !== 'teacher') {
            // Inactive/demoted owner keeps link pending and case awaiting_workflow
            $link->forceFill([
                'start_state' => 'pending',
                'lease_until' => null,
            ])->save();

            return ['status' => 'deferred', 'reason' => 'inactive_owner'];
        }

        // 2. Verify captured 005 settings row
        try {
            $captured = $this->settingsRepo->getCaptured($case->settings_version_id);
        } catch (SettingsUnavailable|\Throwable $e) {
            // Missing or corrupt captured row prevents start, leaves pending case/link and fixed alert
            $this->logAlertOnce($link, 'settings_unavailable');
            $link->forceFill([
                'start_state' => 'pending',
                'lease_until' => null,
                'last_alert_code' => 'settings_unavailable',
                'last_alerted_at' => now(),
            ])->save();

            return ['status' => 'deferred', 'reason' => 'settings_unavailable'];
        }

        $payload = is_array($captured->payload) ? $captured->payload : json_decode($captured->payload, true);
        $limits = $payload['limits'] ?? [];
        $technicalRetries = (int) ($limits['technical_retries'] ?? 3);
        $retryDelaySeconds = (int) ($limits['retry_delay_seconds'] ?? 5);

        // 3. Mark starting and persist verified checksum before remote start
        $link->forceFill([
            'settings_checksum' => $captured->checksum,
            'start_state' => 'starting',
            'start_attempted_at' => now(),
            'start_attempts' => $link->start_attempts + 1,
        ])->save();

        // 4. Call Python bridge outside DB transaction
        $startRequest = [
            'schema_version' => 1,
            'case_id' => $case->id,
            'settings_version_id' => $case->settings_version_id,
            'settings_checksum' => $captured->checksum,
            'workflow_id' => "v3-case-{$case->id}",
            'pipeline_version' => $case->pipeline_version ?? 'legacy_staged_v1',
            'retry_policy' => [
                'technical_retries' => $technicalRetries,
                'retry_delay_seconds' => $retryDelaySeconds,
            ],
        ];

        $resp = $this->bridgeClient->startWorkflow($startRequest);

        $link->refresh();
        // Do not overwrite if closed or already advanced
        if ($link->start_state === 'closed') {
            return ['status' => 'closed'];
        }

        if ($resp['status'] === 200) {
            $data = $resp['data'] ?? [];
            $runId = $data['run_id'] ?? null;
            $temporalStatus = $data['temporal_status'] ?? 'open';

            if ($temporalStatus === 'open') {
                $linkUpdates = [
                    'start_state' => 'started',
                    'started_at' => $link->started_at ?? now(),
                ];
                if ($link->run_id === null && $runId) {
                    $linkUpdates['run_id'] = $runId;
                }
                $link->forceFill($linkUpdates)->save();

                return ['status' => 'started', 'run_id' => $runId];
            } else {
                $link->forceFill(['start_state' => 'closed'])->save();
                if ($runId) {
                    $this->reconciler->reconcileClosedWorkflow($case, $link, $runId);
                }
                return ['status' => 'closed', 'run_id' => $runId];
            }
        } elseif ($resp['status'] === 409) {
            $this->logAlertOnce($link, 'workflow_identity_conflict');
            $link->forceFill([
                'start_state' => 'identity_conflict',
                'last_alert_code' => 'workflow_identity_conflict',
                'last_alerted_at' => now(),
            ])->save();

            return ['status' => 'conflict'];
        } else {
            // 503 / timeout / network failure leaves uncertain
            $link->forceFill([
                'start_state' => 'uncertain',
                'lease_until' => null,
            ])->save();

            return ['status' => 'uncertain'];
        }
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
