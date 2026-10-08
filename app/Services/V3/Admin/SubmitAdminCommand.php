<?php

namespace App\Services\V3\Admin;

use App\Models\V3\AdminCommand;
use App\Models\V3\AdminCommandDelivery;
use App\Models\V3\AdminOperationAudit;
use App\Models\V3\CaseEvent;
use App\Models\V3\CaseOperationControl;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\WorkflowLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubmitAdminCommand
{
    public function __construct(
        protected QueryOperations $queryOperations
    ) {}

    public function canonicalizePayload(array $input): array
    {
        $allowedKeys = [
            'schema_version',
            'idempotency_key',
            'expected_case_version',
            'expected_control_version',
            'action',
            'reason_code',
            'target_kind',
            'target_ref',
        ];

        foreach (array_keys($input) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                throw new \InvalidArgumentException('Invalid operation request.');
            }
        }

        if (($input['schema_version'] ?? null) !== 1) {
            throw new \InvalidArgumentException('Invalid operation request.');
        }

        $idempotencyKey = $input['idempotency_key'] ?? '';
        if (! is_string($idempotencyKey) || ! Str::isUuid($idempotencyKey)) {
            throw new \InvalidArgumentException('Invalid operation request.');
        }

        $expectedCaseVersion = $input['expected_case_version'] ?? null;
        if (! is_int($expectedCaseVersion) || $expectedCaseVersion < 1) {
            throw new \InvalidArgumentException('Invalid operation request.');
        }

        $expectedControlVersion = $input['expected_control_version'] ?? null;
        if (! is_int($expectedControlVersion) || $expectedControlVersion < 0) {
            throw new \InvalidArgumentException('Invalid operation request.');
        }

        $action = $input['action'] ?? '';
        if (! in_array($action, AdminCommand::ALLOWED_ACTIONS, true)) {
            throw new \InvalidArgumentException('Invalid operation request.');
        }

        $reasonCode = $input['reason_code'] ?? '';
        if (! in_array($reasonCode, AdminCommand::ALLOWED_REASONS, true)) {
            throw new \InvalidArgumentException('Invalid operation request.');
        }

        if ($action === 'hold' && $reasonCode !== 'operator_investigation') {
            throw new \InvalidArgumentException('Invalid operation request.');
        }

        if ($action === 'resume' && $reasonCode !== 'service_recovered') {
            throw new \InvalidArgumentException('Invalid operation request.');
        }

        if ($action === 'cancel' && ! in_array($reasonCode, ['operator_investigation', 'abandoned_review'], true)) {
            throw new \InvalidArgumentException('Invalid operation request.');
        }

        if ($action === 'retry' && $reasonCode !== 'technical_reconcile') {
            throw new \InvalidArgumentException('Invalid operation request.');
        }

        $targetKind = $input['target_kind'] ?? null;
        $targetRef = $input['target_ref'] ?? null;

        if ($action === 'retry') {
            if (! in_array($targetKind, AdminCommand::ALLOWED_TARGET_KINDS, true)) {
                throw new \InvalidArgumentException('Invalid operation request.');
            }
            if (! is_string($targetRef) || ! Str::isUuid($targetRef)) {
                throw new \InvalidArgumentException('Invalid operation request.');
            }
        } else {
            if ($targetKind !== null || $targetRef !== null) {
                throw new \InvalidArgumentException('Invalid operation request.');
            }
        }

        return [
            'schema_version' => 1,
            'idempotency_key' => strtolower($idempotencyKey),
            'expected_case_version' => $expectedCaseVersion,
            'expected_control_version' => $expectedControlVersion,
            'action' => $action,
            'reason_code' => $reasonCode,
            'target_kind' => $targetKind,
            'target_ref' => $targetRef ? strtolower($targetRef) : null,
        ];
    }

    public function submit(string $caseId, int $actorUserId, array $input): array
    {
        $canonical = $this->canonicalizePayload($input);
        $requestSha256 = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES));

        // 1. Check existing command by (case_id, idempotency_key)
        $existing = AdminCommand::where('case_id', $caseId)
            ->where('idempotency_key', $canonical['idempotency_key'])
            ->first();

        if ($existing) {
            if ($existing->request_sha256 !== $requestSha256) {
                throw new \DomainException('Operation changed. Reload and retry.');
            }

            $currentControl = CaseOperationControl::find($caseId);

            return [
                'replay' => true,
                'command_ref' => (string) $existing->id,
                'action' => $existing->action,
                'status' => $existing->status,
                'control_mode' => $currentControl?->mode ?? 'normal',
                'control_version' => $currentControl?->control_version ?? 0,
            ];
        }

        // 2. Lock case and control in transaction
        try {
            return DB::transaction(function () use ($caseId, $actorUserId, $canonical, $requestSha256) {
                $case = ProcessingCase::where('id', $caseId)->lockForUpdate()->first();
                if (! $case) {
                    throw new \RuntimeException('Case not found');
                }

                $control = CaseOperationControl::where('case_id', $caseId)->lockForUpdate()->first();
                if (! $control) {
                    $control = CaseOperationControl::create([
                        'case_id' => $caseId,
                        'mode' => 'normal',
                        'control_version' => 0,
                    ]);
                }

                $action = $canonical['action'];
                $reasonCode = $canonical['reason_code'];
                $targetKind = $canonical['target_kind'];
                $targetRef = $canonical['target_ref'];
                $expectedCaseVer = $canonical['expected_case_version'];
                $expectedControlVer = $canonical['expected_control_version'];

                // Version check
                if ((int) $case->state_version !== $expectedCaseVer || (int) $control->control_version !== $expectedControlVer) {
                    throw new \DomainException('Operation changed. Reload and retry.');
                }

                $link = WorkflowLink::where('case_id', $caseId)->first();
                $terminalStates = ['complete', 'approved', 'blocked', 'failed', 'cancelled'];

                $oldMode = $control->mode;
                $oldControlVer = (int) $control->control_version;
                $newMode = $oldMode;
                $controlVersionAfter = null;
                $commandStatus = 'accepted';
                $appliedAt = null;

                if ($action === 'hold') {
                    if (in_array($case->state, $terminalStates, true) || $oldMode !== 'normal') {
                        throw new \DomainException('Operation changed. Reload and retry.');
                    }

                    $controlVersionAfter = $oldControlVer + 1;
                    $hasRemoteWorkflow = ($link && in_array($link->start_state, ['started', 'uncertain'], true));

                    if (! $hasRemoteWorkflow) {
                        $newMode = 'held';
                        $commandStatus = 'applied';
                        $appliedAt = now();
                    } else {
                        $newMode = 'hold_requested';
                        $commandStatus = 'accepted';
                    }
                } elseif ($action === 'resume') {
                    if (in_array($case->state, $terminalStates, true) || $oldMode !== 'held') {
                        throw new \DomainException('Operation changed. Reload and retry.');
                    }

                    $controlVersionAfter = $oldControlVer + 1;
                    $hasRemoteWorkflow = ($link && in_array($link->start_state, ['started', 'uncertain'], true));

                    if (! $hasRemoteWorkflow) {
                        $newMode = 'normal';
                        $commandStatus = 'applied';
                        $appliedAt = now();
                    } else {
                        $newMode = 'resume_requested';
                        $commandStatus = 'accepted';
                    }
                } elseif ($action === 'cancel') {
                    if (in_array($case->state, $terminalStates, true) || in_array($oldMode, ['cancel_requested', 'cancelled'], true)) {
                        throw new \DomainException('Operation changed. Reload and retry.');
                    }

                    $controlVersionAfter = $oldControlVer + 1;

                    // A pending correction outbox row has not crossed the dispatch
                    // claim boundary. Block it in the same case transaction so the
                    // review dispatcher (which locks case -> control -> outbox) can
                    // never start it after cancellation commits. Sending/uncertain
                    // rows may already have reached Temporal and still need proof.
                    $caseCorrectionIds = DB::table('v3_postapproval_corrections')
                        ->select('id')
                        ->where('case_id', $caseId);
                    $unsentCorrections = DB::table('v3_review_outbox')
                        ->where('operation', 'start_correction')
                        ->whereIn('postapproval_correction_id', $caseCorrectionIds)
                        ->where('state', 'pending')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                    foreach ($unsentCorrections as $unsent) {
                        DB::table('v3_review_outbox')->where('id', $unsent->id)->update([
                            'state' => 'blocked',
                            'lease_until' => null,
                            'error_code' => 'operation_controlled',
                            'updated_at' => now(),
                        ]);
                    }

                    // Check if any workflow is started or start-uncertain
                    $hasCaseWorkflow = ($link && in_array($link->start_state, ['started', 'uncertain'], true));
                    $openCorrections = DB::table('v3_review_outbox')
                        ->where('operation', 'start_correction')
                        ->whereIn('state', ['sending', 'uncertain'])
                        ->where(function ($q) use ($caseId) {
                            $q->whereIn('postapproval_correction_id', function ($sub) use ($caseId) {
                                $sub->select('id')->from('v3_postapproval_corrections')->where('case_id', $caseId);
                            });
                        })
                        ->get();

                    if (! $hasCaseWorkflow && $openCorrections->isEmpty()) {
                        // Direct local cancellation
                        $newMode = 'cancelled';
                        $commandStatus = 'applied';
                        $appliedAt = now();

                        $newSequence = (int) $case->state_version + 1;
                        $case->state = 'cancelled';
                        $case->state_version = $newSequence;
                        $case->save();

                        CaseEvent::forceCreate([
                            'id' => (string) Str::uuid(),
                            'case_id' => $caseId,
                            'event_key' => "control:cancel:local",
                            'payload_digest' => hash('sha256', "control:cancel:local"),
                            'sequence' => $newSequence,
                            'workflow_id' => $link ? $link->workflow_id : "v3-case-{$caseId}",
                            'run_id' => ($link && $link->run_id) ? $link->run_id : "v3-run-{$caseId}",
                            'event_type' => 'stage_completed',
                            'stage_code' => 'complete',
                            'status_code' => 'cancelled',
                            'error_code' => null,
                            'recorded_at' => now(),
                        ]);

                        TeacherReviewItem::where('case_id', $caseId)
                            ->whereIn('status', ['pending', 'dormant'])
                            ->update(['status' => 'stale']);

                        ReviewSectionState::where('case_id', $caseId)
                            ->update([
                                'current_review_item_id' => null,
                                'current_approval_id' => null,
                                'state' => 'blocked',
                                'error_code' => null,
                            ]);

                        if ($link) {
                            $link->update([
                                'approved_scene_count' => 0,
                                'status_code' => 'cancelled',
                            ]);
                        }
                    } else {
                        $newMode = 'cancel_requested';
                        $commandStatus = 'accepted';
                    }
                } elseif ($action === 'retry') {
                    if ($oldMode !== 'normal' || in_array($case->state, $terminalStates, true)) {
                        throw new \DomainException('Operation changed. Reload and retry.');
                    }

                    $eligibleTargets = $this->queryOperations->resolveActionTargets($case, $link, $control);
                    $isTargetEligible = collect($eligibleTargets)->contains(function ($t) use ($targetKind, $targetRef) {
                        return $t['kind'] === $targetKind && $t['ref'] === $targetRef;
                    });

                    if (! $isTargetEligible) {
                        throw new \DomainException('Operation changed. Reload and retry.');
                    }

                    $commandStatus = 'accepted';
                }

            $commandId = (string) Str::uuid();

            $command = AdminCommand::create([
                'id' => $commandId,
                'case_id' => $caseId,
                'actor_user_id' => $actorUserId,
                'idempotency_key' => $canonical['idempotency_key'],
                'request_sha256' => $requestSha256,
                'action' => $action,
                'reason_code' => $reasonCode,
                'target_kind' => $targetKind,
                'target_ref' => $targetRef,
                'expected_case_version' => $expectedCaseVer,
                'expected_control_version' => $expectedControlVer,
                'control_version_after' => $controlVersionAfter,
                'status' => $commandStatus,
                'applied_at' => $appliedAt,
            ]);

            // Create command deliveries if remote workflow required
            if ($controlVersionAfter !== null && $commandStatus === 'accepted') {
                if ($link && in_array($link->start_state, ['started', 'uncertain'], true)) {
                    AdminCommandDelivery::create([
                        'id' => (string) Str::uuid(),
                        'command_id' => $commandId,
                        'case_id' => $caseId,
                        'workflow_id' => $link->workflow_id,
                        'state' => 'pending',
                        'control_version' => $controlVersionAfter,
                    ]);
                }

                // Any open correction workflows for this case
                $correctionOutbox = DB::table('v3_review_outbox')
                    ->where('operation', 'start_correction')
                    ->whereIn('state', ['pending', 'sending', 'uncertain'])
                    ->where(function ($q) use ($caseId) {
                        $q->whereIn('postapproval_correction_id', function ($sub) use ($caseId) {
                            $sub->select('id')->from('v3_postapproval_corrections')->where('case_id', $caseId);
                        });
                    })
                    ->get();

                foreach ($correctionOutbox as $outboxRow) {
                    AdminCommandDelivery::create([
                        'id' => (string) Str::uuid(),
                        'command_id' => $commandId,
                        'case_id' => $caseId,
                        'workflow_id' => $outboxRow->workflow_id,
                        'state' => 'pending',
                        'control_version' => $controlVersionAfter,
                    ]);
                }
            }

            // Update CaseOperationControl
            if ($controlVersionAfter !== null) {
                $control->update([
                    'mode' => $newMode,
                    'control_version' => $controlVersionAfter,
                    'last_command_id' => $commandId,
                    'requested_at' => now(),
                    'applied_at' => $appliedAt,
                ]);
            }

            // Record mutation audit
            AdminOperationAudit::create([
                'id' => (string) Str::uuid(),
                'case_id' => $caseId,
                'actor_user_id' => $actorUserId,
                'source_kind' => 'command',
                'source_ref' => $commandId,
                'event' => ($commandStatus === 'applied' ? 'command_applied' : 'command_requested'),
                'action_code' => $action,
                'reason_code' => $reasonCode,
                'old_control_mode' => $oldMode,
                'new_control_mode' => $newMode,
                'old_control_version' => $oldControlVer,
                'new_control_version' => $controlVersionAfter ?? $oldControlVer,
                'occurred_at' => now(),
            ]);

            return [
                'replay' => false,
                'command_ref' => $commandId,
                'action' => $action,
                'status' => $commandStatus,
                'control_mode' => $newMode,
                'control_version' => $controlVersionAfter ?? $oldControlVer,
            ];
        });
        } catch (\DomainException $e) {
            $this->recordDenialAudit($caseId, $actorUserId, $canonical['action'], 'operation_conflict');
            throw $e;
        }
    }

    protected function recordDenialAudit(string $caseId, int $actorUserId, string $action, string $safeDenialCode): void
    {
        try {
            AdminOperationAudit::create([
                'id' => (string) Str::uuid(),
                'case_id' => $caseId,
                'actor_user_id' => $actorUserId,
                'source_kind' => null,
                'source_ref' => null,
                'event' => 'operation_denied',
                'action_code' => $action,
                'safe_denial_code' => $safeDenialCode,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable) {
            // Denial audit failure preserves safe denial and emits fixed emergency code without leaking data
            logger()->emergency('V3_ADMIN_AUDIT_DENIAL_FAILED', ['code' => 'safe_denial_failed']);
        }
    }
}
