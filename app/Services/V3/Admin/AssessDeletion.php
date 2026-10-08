<?php

namespace App\Services\V3\Admin;

use App\Http\Resources\V3\OperationSummaryV1;
use App\Models\User;
use App\Models\V3\AdminOperationAudit;
use App\Models\V3\CaseOperationControl;
use App\Models\V3\DeletionAssessment;
use App\Models\V3\ProcessingCase;
use App\Models\V3\WorkflowLink;
use App\Services\V3\Access\V3AccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AssessDeletion
{
    public function __construct(
        protected V3AccessResolver $accessResolver
    ) {}

    /**
     * Assess case deletion eligibility and record immutable assessment snapshot.
     *
     * @param User $actor
     * @param string $caseId
     * @param array $input
     * @return array{replayed: bool, assessment: DeletionAssessment}
     */
    public function assess(User $actor, string $caseId, array $input): array
    {
        // 1. Check active admin access
        $accessResult = $this->accessResolver->resolve($actor);
        if (! $accessResult['allowed'] || $accessResult['role'] !== 'admin') {
            throw new HttpException(403, 'Access denied.');
        }

        // 2. Validate case existence
        if (! Str::isUuid($caseId) || strtolower($caseId) !== $caseId) {
            throw new HttpException(404, 'Resource not found.');
        }

        $case = ProcessingCase::find($caseId);
        if (! $case) {
            throw new HttpException(404, 'Resource not found.');
        }

        // 3. Validate request schema
        $this->validateInput($input);

        $idempotencyKey = $input['idempotency_key'];
        $expectedCaseVersion = (int) $input['expected_case_version'];
        $expectedControlVersion = (int) $input['expected_control_version'];

        $canonicalPayload = [
            'expected_case_version' => $expectedCaseVersion,
            'expected_control_version' => $expectedControlVersion,
            'idempotency_key' => $idempotencyKey,
            'reason_code' => $input['reason_code'],
            'schema_version' => (int) $input['schema_version'],
        ];
        ksort($canonicalPayload);
        $requestSha256 = hash('sha256', json_encode($canonicalPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // 4. Exact replay check before lock
        $existing = DeletionAssessment::where('case_id', $case->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            if ($existing->request_sha256 === $requestSha256) {
                return ['replayed' => true, 'assessment' => $existing];
            }
            throw new HttpException(409, 'Operation changed. Reload and retry.');
        }

        // 5. Transaction with case and control locks
        return DB::transaction(function () use (
            $actor,
            $case,
            $idempotencyKey,
            $requestSha256,
            $expectedCaseVersion,
            $expectedControlVersion
        ) {
            // Lock case
            $lockedCase = ProcessingCase::where('id', $case->id)->lockForUpdate()->first();
            if (! $lockedCase) {
                throw new HttpException(404, 'Resource not found.');
            }

            // Lock or create control
            $control = CaseOperationControl::where('case_id', $case->id)->lockForUpdate()->first();
            $currentControlVersion = $control ? (int) $control->control_version : 0;
            $currentControlMode = $control ? $control->mode : 'normal';

            // Version checks
            if ((int) $lockedCase->state_version !== $expectedCaseVersion) {
                $this->recordSafeDenial($actor, $lockedCase->id, 'operation_conflict', $currentControlMode, $currentControlVersion);
                throw new HttpException(409, 'Operation changed. Reload and retry.');
            }

            if ($currentControlVersion !== $expectedControlVersion) {
                $this->recordSafeDenial($actor, $lockedCase->id, 'operation_conflict', $currentControlMode, $currentControlVersion);
                throw new HttpException(409, 'Operation changed. Reload and retry.');
            }

            // Determine status and blocker in exact order
            $safeBlocker = $this->evaluateBlockers($lockedCase, $control);
            $status = $safeBlocker === null ? 'deferred_to_015' : 'ineligible';

            $assessmentId = (string) Str::uuid();
            $assessment = DeletionAssessment::create([
                'id' => $assessmentId,
                'case_id' => $lockedCase->id,
                'actor_user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'request_sha256' => $requestSha256,
                'reason_code' => DeletionAssessment::REASON_CODE,
                'policy_version' => DeletionAssessment::POLICY_VERSION,
                'status' => $status,
                'safe_blocker' => $safeBlocker,
                'case_state_version' => (int) $lockedCase->state_version,
                'control_version' => $currentControlVersion,
                'assessed_at' => now(),
            ]);

            AdminOperationAudit::create([
                'id' => (string) Str::uuid(),
                'case_id' => $lockedCase->id,
                'actor_user_id' => $actor->id,
                'source_kind' => 'deletion_assessment',
                'source_ref' => $assessmentId,
                'event' => 'assessment_recorded',
                'action_code' => 'assess_deletion',
                'reason_code' => DeletionAssessment::REASON_CODE,
                'safe_denial_code' => null,
                'old_control_mode' => $currentControlMode,
                'new_control_mode' => $currentControlMode,
                'old_control_version' => $currentControlVersion,
                'new_control_version' => $currentControlVersion,
                'occurred_at' => now(),
            ]);

            return ['replayed' => false, 'assessment' => $assessment];
        });
    }

    protected function evaluateBlockers(ProcessingCase $case, ?CaseOperationControl $control): ?string
    {
        // 1. case_active
        $terminalStates = ['complete', 'approved', 'blocked', 'failed', 'cancelled'];
        if (! in_array($case->state, $terminalStates, true)) {
            return 'case_active';
        }
        if ($control && in_array($control->mode, ['hold_requested', 'held', 'resume_requested', 'cancel_requested'], true)) {
            return 'case_active';
        }

        // 2. workflow_open
        $link = WorkflowLink::where('case_id', $case->id)->first();
        if ($link) {
            if (in_array($link->status_code, ['queued', 'running', 'uncertain'], true) ||
                $link->start_state !== 'closed') {
                return 'workflow_open';
            }
        }

        $openDeliveries = DB::table('v3_admin_command_deliveries')
            ->where('case_id', $case->id)
            ->whereIn('status', ['pending', 'dispatching'])
            ->exists();
        if ($openDeliveries) {
            return 'workflow_open';
        }

        if (Schema::hasTable('v3_postapproval_corrections')) {
            $openCorrections = DB::table('v3_postapproval_corrections')
                ->where('case_id', $case->id)
                ->whereNotNull('workflow_id')
                ->whereIn('status', ['pending', 'running', 'uncertain'])
                ->exists();
            if ($openCorrections) {
                return 'workflow_open';
            }
        }

        // 3. review_pending
        $pendingReviews = DB::table('v3_teacher_review_items')
            ->where('case_id', $case->id)
            ->whereIn('status', ['pending', 'dormant'])
            ->exists();
        if ($pendingReviews) {
            return 'review_pending';
        }

        $sectionActiveReviews = DB::table('v3_review_section_states')
            ->where('case_id', $case->id)
            ->whereNotNull('current_review_item_id')
            ->exists();
        if ($sectionActiveReviews) {
            return 'review_pending';
        }

        // 4. effect_uncertain
        if (OperationSummaryV1::countUncertainEffects((string) $case->id) > 0) {
            return 'effect_uncertain';
        }

        $uncertainCommands = DB::table('v3_admin_commands')
            ->where('case_id', $case->id)
            ->where('status', 'uncertain')
            ->exists();
        if ($uncertainCommands) {
            return 'effect_uncertain';
        }

        $uncertainDeliveries = DB::table('v3_admin_command_deliveries')
            ->where('case_id', $case->id)
            ->where('status', 'uncertain')
            ->exists();
        if ($uncertainDeliveries) {
            return 'effect_uncertain';
        }

        return null;
    }

    protected function validateInput(array $input): void
    {
        $allowedKeys = [
            'schema_version',
            'idempotency_key',
            'expected_case_version',
            'expected_control_version',
            'reason_code',
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

        $reasonCode = $input['reason_code'] ?? '';
        if ($reasonCode !== DeletionAssessment::REASON_CODE) {
            throw new \InvalidArgumentException('Invalid operation request.');
        }
    }

    protected function recordSafeDenial(User $actor, string $caseId, string $denialCode, string $mode, int $version): void
    {
        try {
            AdminOperationAudit::create([
                'id' => (string) Str::uuid(),
                'case_id' => $caseId,
                'actor_user_id' => $actor->id,
                'source_kind' => null,
                'source_ref' => null,
                'event' => 'operation_denied',
                'action_code' => 'assess_deletion',
                'reason_code' => DeletionAssessment::REASON_CODE,
                'safe_denial_code' => $denialCode,
                'old_control_mode' => $mode,
                'new_control_mode' => $mode,
                'old_control_version' => $version,
                'new_control_version' => $version,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::emergency('V3_ADMIN_AUDIT_OUTAGE', ['denial_code' => $denialCode]);
        }
    }
}
