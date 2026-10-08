<?php

namespace App\Services\V3\Review;

use App\Models\V3\CaseEvent;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\TeacherReviewDecision;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\TeacherSection;
use App\Models\V3\WorkflowLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProjectReviewEvent
{
    public function projectReady(string $caseId, string $reviewRef): void
    {
        $item = TeacherReviewItem::where('id', $reviewRef)->where('case_id', $caseId)->first();
        if (! $item) {
            return;
        }

        $eventKey = "review:{$reviewRef}:ready";
        $this->recordEvent(
            caseId: $caseId,
            eventKey: $eventKey,
            eventType: 'stage_started',
            stageCode: 'teacher_review',
            statusCode: 'awaiting_review'
        );
    }

    public function projectDecision(string $caseId, string $decisionRef): void
    {
        $decision = TeacherReviewDecision::where('id', $decisionRef)->where('case_id', $caseId)->first();
        if (! $decision) {
            return;
        }

        if ($decision->decision === 'request_changes') {
            $eventKey = "review:{$decisionRef}:changes";
            $this->recordEvent(
                caseId: $caseId,
                eventKey: $eventKey,
                eventType: 'stage_started',
                stageCode: 'teacher_review',
                statusCode: 'running'
            );
            return;
        }

        // Approve decision
        $eventKey = "review:{$decisionRef}:approved";

        // Determine if all expected current sections are approved
        $cursorComplete = DB::table('v3_lesson_cursors')
            ->where('case_id', $caseId)
            ->where('phase', 'complete')
            ->exists();

        $totalSections = TeacherSection::where('case_id', $caseId)->count();
        $approvedSectionsCount = ReviewSectionState::where('case_id', $caseId)
            ->where('state', 'approved')
            ->whereNotNull('current_approval_id')
            ->count();

        $hasNonApprovedSection = ReviewSectionState::where('case_id', $caseId)
            ->where(function ($q) {
                $q->where('state', '!=', 'approved')
                  ->orWhereNull('current_approval_id');
            })
            ->exists();

        // Also check if any section doesn't have a state row yet
        $hasMissingState = TeacherSection::where('case_id', $caseId)
            ->whereNotIn('id', function ($query) use ($caseId) {
                $query->select('section_id')->from('v3_review_section_states')->where('case_id', $caseId);
            })
            ->exists();

        $isAllApproved = ($cursorComplete && $totalSections > 0 && ! $hasNonApprovedSection && ! $hasMissingState && $approvedSectionsCount === $totalSections);

        $stageCode = $isAllApproved ? 'complete' : 'teacher_review';
        $statusCode = $isAllApproved ? 'approved' : 'awaiting_review';
        $eventType = $isAllApproved ? 'stage_completed' : 'stage_started';

        $this->recordEvent(
            caseId: $caseId,
            eventKey: $eventKey,
            eventType: $eventType,
            stageCode: $stageCode,
            statusCode: $statusCode,
            approvedSceneCount: $approvedSectionsCount
        );
    }

    public function projectCorrectionReopened(string $caseId, string $correctionRef): void
    {
        $eventKey = "review:{$correctionRef}:reopened";
        $approvedSectionsCount = ReviewSectionState::where('case_id', $caseId)
            ->where('state', 'approved')
            ->whereNotNull('current_approval_id')
            ->count();

        $this->recordEvent(
            caseId: $caseId,
            eventKey: $eventKey,
            eventType: 'stage_started',
            stageCode: 'teacher_review',
            statusCode: 'running',
            approvedSceneCount: $approvedSectionsCount
        );
    }

    public function projectAllBlocked(string $caseId): void
    {
        $eventKey = "review:all_blocked";
        $this->recordEvent(
            caseId: $caseId,
            eventKey: $eventKey,
            eventType: 'workflow_failed',
            stageCode: 'teacher_review',
            statusCode: 'blocked',
            errorCode: 'validation'
        );
    }

    protected function recordEvent(
        string $caseId,
        string $eventKey,
        string $eventType,
        string $stageCode,
        string $statusCode,
        ?string $errorCode = null,
        ?int $approvedSceneCount = null
    ): void {
        DB::transaction(function () use ($caseId, $eventKey, $eventType, $stageCode, $statusCode, $errorCode, $approvedSceneCount) {
            $case = ProcessingCase::where('id', $caseId)->lockForUpdate()->first();
            if (! $case) {
                return;
            }

            $link = WorkflowLink::where('case_id', $caseId)->lockForUpdate()->first();

            $existingEvent = CaseEvent::where('case_id', $caseId)
                ->where('event_key', $eventKey)
                ->first();

            if ($existingEvent) {
                return;
            }

            $newSequence = (int) $case->state_version + 1;
            $workflowId = $link ? $link->workflow_id : "v3-case-{$caseId}";
            $runId = ($link && $link->run_id) ? $link->run_id : "v3-run-{$caseId}";

            CaseEvent::forceCreate([
                'id' => (string) Str::uuid(),
                'case_id' => $caseId,
                'event_key' => $eventKey,
                'payload_digest' => hash('sha256', $eventKey),
                'sequence' => $newSequence,
                'workflow_id' => $workflowId,
                'run_id' => $runId,
                'event_type' => $eventType,
                'stage_code' => $stageCode,
                'status_code' => $statusCode,
                'error_code' => $errorCode,
                'recorded_at' => now(),
            ]);

            $case->forceFill([
                'state' => $statusCode,
                'state_version' => $newSequence,
            ])->save();

            if ($link) {
                $linkUpdates = [
                    'stage_code' => $stageCode,
                    'status_code' => $statusCode,
                    'error_code' => $errorCode,
                    'last_event_at' => now(),
                ];
                if ($approvedSceneCount !== null) {
                    $linkUpdates['approved_scene_count'] = $approvedSceneCount;
                }
                $link->forceFill($linkUpdates)->save();
            }
        });
    }
}
