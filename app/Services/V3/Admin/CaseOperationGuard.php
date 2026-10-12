<?php

namespace App\Services\V3\Admin;

use App\Models\V3\CaseOperationControl;
use App\Models\V3\ProcessingCase;

class CaseOperationGuard
{
    /**
     * Determine if external effects / claims / decisions are permitted for this case.
     * Denies when control mode is not 'normal' (e.g. hold_requested, held, resume_requested,
     * cancel_requested, cancelled) or when the case state itself is cancelled.
     */
    public function isPermitted(string $caseId): bool
    {
        $case = ProcessingCase::where('id', $caseId)->first();
        if (! $case || $case->state === 'cancelled') {
            return false;
        }

        $control = CaseOperationControl::where('case_id', $caseId)->first();
        if ($control && $control->mode !== 'normal') {
            return false;
        }

        return true;
    }

    /**
     * Assert that operations are permitted for this case.
     * Throws an exception on fence violation.
     */
    public function assertPermitted(string $caseId, ?string $conflictMessage = null, ?\Throwable $customException = null): void
    {
        if (! $this->isPermitted($caseId)) {
            if ($customException !== null) {
                throw $customException;
            }

            throw new \DomainException($conflictMessage ?? 'Operation conflict: case is held or cancelled.');
        }
    }
}
