<?php

namespace App\Services\V3\Admin;

use App\Models\V3\AdminCommand;
use App\Models\V3\AdminOperationAudit;
use App\Models\V3\CaseOperationControl;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ReviewOutbox;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCheckAttempt;
use App\Models\V3\SceneCheckRun;
use App\Models\V3\SceneJevAttempt;
use App\Models\V3\SceneQualification;
use App\Models\V3\SourceEffect;
use App\Models\V3\TeacherReviewDecision;
use App\Models\V3\WorkflowLink;
use App\Services\V3\Workflow\BridgeClient;
use App\Services\V3\Workflow\ReconcileWorkflowStart;
use App\Services\V3\Workflow\ReviewSignalGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReconcileAdminTarget
{
    public function __construct(
        protected BridgeClient $bridgeClient,
        protected ReconcileWorkflowStart $reconcileWorkflowStart,
        protected ReviewSignalGateway $reviewSignalGateway
    ) {}

    public function reconcile(AdminCommand $command): string
    {
        if ($command->action !== 'retry' || $command->status === 'applied') {
            return $command->status;
        }

        $kind = $command->target_kind;
        $ref = $command->target_ref;
        $caseId = $command->case_id;

        $newStatus = match ($kind) {
            'workflow_start' => $this->reconcileWorkflowStart($caseId),
            'source_proof' => $this->reconcileSourceProof($caseId, $ref),
            'scene_check' => $this->reconcileSceneCheck($caseId, $ref),
            'scene_jev' => $this->reconcileSceneJev($caseId, $ref),
            'review_signal' => $this->reconcileReviewSignal($caseId, $ref),
            'review_correction' => $this->reconcileReviewCorrection($caseId, $ref),
            default => 'blocked',
        };

        return DB::transaction(function () use ($command, $newStatus) {
            $cmd = AdminCommand::where('id', $command->id)->lockForUpdate()->first();
            if (! $cmd) {
                return $newStatus;
            }

            $cmd->status = $newStatus;
            if ($newStatus === 'applied') {
                $cmd->applied_at = now();
            }
            $cmd->save();

            if ($newStatus === 'applied') {
                AdminOperationAudit::create([
                    'id' => (string) Str::uuid(),
                    'case_id' => $cmd->case_id,
                    'actor_user_id' => $cmd->actor_user_id,
                    'source_kind' => 'command',
                    'source_ref' => $cmd->id,
                    'event' => 'command_applied',
                    'action_code' => 'retry',
                    'reason_code' => 'technical_reconcile',
                    'old_control_mode' => 'normal',
                    'new_control_mode' => 'normal',
                    'old_control_version' => $cmd->expected_control_version,
                    'new_control_version' => $cmd->expected_control_version,
                    'occurred_at' => now(),
                ]);
            }

            return $newStatus;
        });
    }

    protected function reconcileWorkflowStart(string $caseId): string
    {
        $link = WorkflowLink::where('case_id', $caseId)->first();
        if (! $link) {
            return 'blocked';
        }

        $this->reconcileWorkflowStart->reconcileLink($link);
        $link->refresh();

        if ($link->start_state === 'started') {
            return 'applied';
        }

        if ($link->start_state === 'closed') {
            return 'blocked';
        }

        return 'uncertain';
    }

    protected function reconcileSourceProof(string $caseId, ?string $effectId): string
    {
        if (! $effectId) {
            return 'blocked';
        }

        $effect = SourceEffect::where('id', $effectId)
            ->where('case_id', $caseId)
            ->first();

        if (! $effect) {
            return 'blocked';
        }

        if ($effect->state === 'confirmed') {
            return 'applied';
        }

        if ($effect->state === 'blocked') {
            return 'blocked';
        }

        // Read verification only - inspect KnowledgeSourceBinding
        $binding = KnowledgeSourceBinding::where('case_id', $caseId)->first();
        if (! $binding) {
            return 'uncertain';
        }

        if ($effect->operation === 'notebook_create') {
            if ($binding->notebook_id && in_array($binding->state, ['notebook_ready', 'bound'], true)) {
                $effect->update([
                    'state' => 'confirmed',
                    'confirmed_at' => now(),
                    'provider_ref' => $binding->notebook_id,
                ]);
                return 'applied';
            }
            return 'uncertain';
        }

        if ($effect->operation === 'notebook_add_text') {
            if ($binding->source_id && $binding->transfer_status === 'confirmed') {
                $effect->update([
                    'state' => 'confirmed',
                    'confirmed_at' => now(),
                    'provider_ref' => $binding->source_id,
                ]);
                return 'applied';
            }
            return 'uncertain';
        }

        return 'uncertain';
    }

    protected function reconcileSceneCheck(string $caseId, ?string $attemptId): string
    {
        if (! $attemptId) {
            return 'blocked';
        }

        $attempt = SceneCheckAttempt::where('id', $attemptId)
            ->where('case_id', $caseId)
            ->first();

        if (! $attempt) {
            return 'blocked';
        }

        $run = SceneCheckRun::where('candidate_id', $attempt->candidate_id)->first();
        if ($run || $attempt->state === 'recorded') {
            if ($attempt->state !== 'recorded') {
                $attempt->update(['state' => 'recorded']);
            }
            return 'applied';
        }

        return 'uncertain';
    }

    protected function reconcileSceneJev(string $caseId, ?string $attemptId): string
    {
        if (! $attemptId) {
            return 'blocked';
        }

        $attempt = SceneJevAttempt::where('id', $attemptId)
            ->where('case_id', $caseId)
            ->first();

        if (! $attempt) {
            return 'blocked';
        }

        $qual = SceneQualification::where('candidate_id', $attempt->candidate_id)->first();
        if ($qual || $attempt->state === 'recorded') {
            if ($attempt->state !== 'recorded') {
                $attempt->update(['state' => 'recorded']);
            }
            return 'applied';
        }

        return 'uncertain';
    }

    protected function reconcileReviewSignal(string $caseId, ?string $outboxId): string
    {
        if (! $outboxId) {
            return 'blocked';
        }

        $outbox = ReviewOutbox::where('id', $outboxId)->first();
        if (! $outbox) {
            return 'blocked';
        }

        if ($outbox->state === 'delivered') {
            return 'applied';
        }

        if (! $outbox->review_decision_id) {
            return 'blocked';
        }

        $decision = TeacherReviewDecision::find($outbox->review_decision_id);
        if (! $decision) {
            return 'blocked';
        }

        // 1. Query decision on workflow
        $queryRes = $this->reviewSignalGateway->queryDecision($outbox->workflow_id, $decision->id);
        if (($queryRes['consumed'] ?? false) === true) {
            $outbox->update([
                'state' => 'delivered',
                'delivered_at' => now(),
            ]);
            return 'applied';
        }

        // 2. Resend the same decision ID
        $signalPayload = [
            'schema_version' => 1,
            'case_id' => $decision->case_id,
            'section_ref' => $decision->section_id,
            'review_ref' => $decision->review_item_id,
            'decision_ref' => $decision->id,
            'candidate_ref' => $decision->candidate_id,
            'candidate_version' => (int) $decision->candidate_version,
            'outcome' => $decision->decision,
        ];

        $sendRes = $this->reviewSignalGateway->sendDecisionSignal($outbox->workflow_id, $signalPayload);
        if (($sendRes['accepted'] ?? false) === true) {
            $outbox->update([
                'state' => 'delivered',
                'delivered_at' => now(),
            ]);
            return 'applied';
        }

        $outbox->update(['state' => 'uncertain']);
        return 'uncertain';
    }

    protected function reconcileReviewCorrection(string $caseId, ?string $outboxId): string
    {
        if (! $outboxId) {
            return 'blocked';
        }

        $outbox = ReviewOutbox::where('id', $outboxId)
            ->where('operation', 'start_correction')
            ->first();

        if (! $outbox) {
            return 'blocked';
        }

        if (in_array($outbox->state, ['delivered', 'running'], true)) {
            return 'applied';
        }

        $resp = $this->bridgeClient->describeWorkflow($outbox->workflow_id);
        if (is_array($resp) && ($resp['status'] ?? null) === 200) {
            $runId = $resp['data']['run_id'] ?? null;
            $outbox->update([
                'state' => 'running',
                'run_id' => $runId,
            ]);
            return 'applied';
        }

        return 'uncertain';
    }
}
