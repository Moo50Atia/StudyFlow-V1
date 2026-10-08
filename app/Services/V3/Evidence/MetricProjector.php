<?php

namespace App\Services\V3\Evidence;

use App\Models\V3\ApprovedScene;
use App\Models\V3\CaseEvent;
use App\Models\V3\CodingAttempt;
use App\Models\V3\PostapprovalCorrection;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptJevAttempt;
use App\Models\V3\PromptTurn;
use App\Models\V3\PromptWorkItem;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\SceneCheckAttempt;
use App\Models\V3\SceneJevAttempt;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SceneRepair;
use App\Models\V3\TeacherReviewDecision;
use App\Models\V3\TeacherReviewItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MetricProjector
{
    public const OUTCOME_STATUSES = [
        'queued',
        'running',
        'awaiting_review',
        'approved',
        'blocked',
        'failed',
        'cancelled',
        'unknown',
    ];

    /**
     * Project aggregate operational metrics for an admin period [from, to).
     */
    public function projectAdminMetrics(Carbon $from, Carbon $to, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ? $asOf->copy()->setTimezone('UTC') : now()->setTimezone('UTC');
        $fromUtc = $from->copy()->setTimezone('UTC');
        $toUtc = $to->copy()->setTimezone('UTC');

        // Period filter on case creation [from, to)
        $cases = ProcessingCase::where('created_at', '>=', $fromUtc)
            ->where('created_at', '<', $toUtc)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $caseCount = $cases->count();
        $caseIds = $cases->pluck('id')->toArray();

        // 1. Outcomes
        $outcomes = array_fill_keys(self::OUTCOME_STATUSES, 0);
        foreach ($cases as $case) {
            $status = $this->determineCaseStatus($case);
            if (isset($outcomes[$status])) {
                $outcomes[$status]++;
            } else {
                $outcomes['unknown']++;
            }
        }

        // 2. Approvals
        $currentApprovedCount = 0;
        $historicalApprovalCount = 0;
        if (! empty($caseIds)) {
            $historicalApprovalCount = ApprovedScene::whereIn('case_id', $caseIds)->count();

            // current approvals = v3_review_section_states.current_approval_id pointing to valid row while case not cancelled
            $activeCaseIds = $cases->filter(fn ($c) => $c->state !== 'cancelled')->pluck('id')->toArray();
            if (! empty($activeCaseIds)) {
                $validApprovalIds = ApprovedScene::whereIn('case_id', $activeCaseIds)->pluck('id')->toArray();
                $currentApprovedCount = ReviewSectionState::whereIn('case_id', $activeCaseIds)
                    ->whereNotNull('current_approval_id')
                    ->whereIn('current_approval_id', $validApprovalIds)
                    ->count();
            }
        }

        // 3. Operational counts
        $technicalRetries = 0;
        $promptRevisions = 0;
        $sceneRepairs = 0;
        $teacherChanges = 0;
        $gaps = 0;

        if (! empty($caseIds)) {
            // Technical retries: attempts after ordinal/attempt_no 1
            $technicalRetries += CodingAttempt::whereIn('case_id', $caseIds)->where('ordinal', '>', 1)->count();
            $technicalRetries += SceneCheckAttempt::whereIn('case_id', $caseIds)->where('attempt_no', '>', 1)->count();
            $technicalRetries += SceneJevAttempt::whereIn('case_id', $caseIds)->where('attempt_no', '>', 1)->count();

            $promptVersionIds = ScenePromptVersion::whereIn('case_id', $caseIds)->pluck('id')->toArray();
            if (! empty($promptVersionIds)) {
                $technicalRetries += PromptJevAttempt::whereIn('prompt_version_id', $promptVersionIds)->where('attempt_no', '>', 1)->count();
            }

            // Prompt revisions: successor versions per Section (predecessor_id is not null)
            $promptRevisions = ScenePromptVersion::whereIn('case_id', $caseIds)->whereNotNull('predecessor_id')->count();

            // Scene repairs: committed repairs
            $sceneRepairs = SceneRepair::whereIn('case_id', $caseIds)->count();

            // Teacher changes: request_changes decisions + post-approval corrections
            $teacherChanges += TeacherReviewDecision::whereIn('case_id', $caseIds)->where('decision', 'request_changes')->count();
            $teacherChanges += PostapprovalCorrection::whereIn('case_id', $caseIds)->count();
        }

        // 4. Durations
        $caseElapsed = $this->calculateCaseElapsed($cases, $asOf, $gaps);
        $stageResidence = $this->calculateStageResidence($cases, $asOf, $gaps);
        $operationObserved = $this->calculateOperationObserved($caseIds, $gaps);
        $reviewWait = $this->calculateReviewWait($caseIds, $asOf, $gaps);

        // 5. Usage & Cost
        $usage = [
            'known_input_tokens' => 0,
            'known_output_tokens' => 0,
            'unknown_operation_count' => 0,
        ];
        $knownSubtotals = [];
        $unknownComponentCount = 0;

        if (! empty($caseIds)) {
            $codingAttempts = CodingAttempt::whereIn('case_id', $caseIds)->get();
            foreach ($codingAttempts as $att) {
                if ($att->input_tokens !== null) {
                    $usage['known_input_tokens'] += (int) $att->input_tokens;
                }
                if ($att->output_tokens !== null) {
                    $usage['known_output_tokens'] += (int) $att->output_tokens;
                }
                if ($att->cost_minor_units !== null && ! empty($att->currency)) {
                    $curr = (string) $att->currency;
                    $knownSubtotals[$curr] = ($knownSubtotals[$curr] ?? 0) + (int) $att->cost_minor_units;
                } else {
                    $unknownComponentCount++;
                }
            }

            // In pilot, NotebookLM (008/009) and human review labor have no monetary charge feed
            // Sourcing/extraction operations and review items contribute to unknown components
            $reviewItemCount = TeacherReviewItem::whereIn('case_id', $caseIds)->count();
            $unknownComponentCount += $reviewItemCount;

            // NotebookLM operations count towards unknown operations & unknown components
            $sourceActivations = DB::table('v3_source_activations')->whereIn('case_id', $caseIds)->count();
            $extractedSources = DB::table('v3_extracted_sources')->whereIn('case_id', $caseIds)->count();
            $notebookOps = $sourceActivations + $extractedSources;
            $usage['unknown_operation_count'] += $notebookOps;
            $unknownComponentCount += $notebookOps;

            // JEV attempts in pilot have no monetary charge feed
            $sceneJevCount = SceneJevAttempt::whereIn('case_id', $caseIds)->count();
            $usage['unknown_operation_count'] += $sceneJevCount;
            $unknownComponentCount += $sceneJevCount;
        }

        // Build subtotals list sorted by currency
        ksort($knownSubtotals);
        $subtotalsList = [];
        foreach ($knownSubtotals as $curr => $amt) {
            $subtotalsList[] = [
                'currency' => $curr,
                'amount_minor' => $amt,
            ];
        }

        // Cost status and per-scene division
        $costStatus = 'unknown';
        $fullAmountMinor = null;
        $costCurrency = null;
        $perSceneMinor = null;
        $perSceneReason = null;

        if ($caseCount === 0 || $currentApprovedCount === 0) {
            $perSceneReason = 'no_current_approvals';
        } elseif ($unknownComponentCount > 0) {
            $costStatus = 'unknown';
            $perSceneReason = 'unknown_components';
        } elseif (count($subtotalsList) > 1) {
            $costStatus = 'actual';
            $perSceneReason = 'currency_mismatch';
        } elseif (count($subtotalsList) === 1) {
            $costStatus = 'actual';
            $costCurrency = $subtotalsList[0]['currency'];
            $fullAmountMinor = $subtotalsList[0]['amount_minor'];
            $quotient = round($fullAmountMinor / $currentApprovedCount, 6);
            $perSceneMinor = number_format($quotient, 6, '.', '');
            $perSceneReason = null;
        } else {
            $costStatus = 'actual';
            $fullAmountMinor = 0;
            $perSceneMinor = '0.000000';
            $perSceneReason = null;
        }

        return [
            'schema_version' => 1,
            'from' => $fromUtc->toIso8601String(),
            'to' => $toUtc->toIso8601String(),
            'as_of' => $asOf->toIso8601String(),
            'case_count' => $caseCount,
            'outcomes' => $outcomes,
            'current_approved_scene_count' => $currentApprovedCount,
            'historical_approval_count' => $historicalApprovalCount,
            'technical_retry_count' => $technicalRetries,
            'prompt_revision_count' => $promptRevisions,
            'scene_repair_count' => $sceneRepairs,
            'teacher_change_count' => $teacherChanges,
            'case_elapsed_ms' => $caseElapsed,
            'stage_residence_ms' => $stageResidence,
            'operation_observed_ms' => $operationObserved,
            'review_wait_ms' => $reviewWait,
            'usage' => $usage,
            'cost' => [
                'status' => $costStatus,
                'known_subtotals' => $subtotalsList,
                'unknown_component_count' => $unknownComponentCount,
                'full_amount_minor' => $fullAmountMinor,
                'currency' => $costCurrency,
                'per_current_approved_scene_minor' => $perSceneMinor,
                'per_scene_reason' => $perSceneReason,
            ],
            'gap_count' => $gaps,
        ];
    }

    /**
     * Project operational metrics for a single case.
     */
    public function projectCaseMetrics(ProcessingCase $case, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ? $asOf->copy()->setTimezone('UTC') : now()->setTimezone('UTC');
        $adminMetrics = $this->projectAdminMetrics(
            $case->created_at->copy()->subSecond(),
            $case->created_at->copy()->addSecond(),
            $asOf
        );

        return [
            'case_ref' => $case->id,
            'as_of' => $asOf->toIso8601String(),
            'outcome' => $this->determineCaseStatus($case),
            'current_approved_scene_count' => $adminMetrics['current_approved_scene_count'],
            'historical_approval_count' => $adminMetrics['historical_approval_count'],
            'technical_retry_count' => $adminMetrics['technical_retry_count'],
            'prompt_revision_count' => $adminMetrics['prompt_revision_count'],
            'scene_repair_count' => $adminMetrics['scene_repair_count'],
            'teacher_change_count' => $adminMetrics['teacher_change_count'],
            'case_elapsed_ms' => $adminMetrics['case_elapsed_ms'],
            'stage_residence_ms' => $adminMetrics['stage_residence_ms'],
            'operation_observed_ms' => $adminMetrics['operation_observed_ms'],
            'review_wait_ms' => $adminMetrics['review_wait_ms'],
            'usage' => $adminMetrics['usage'],
            'cost' => $adminMetrics['cost'],
            'gap_count' => $adminMetrics['gap_count'],
        ];
    }

    protected function determineCaseStatus(ProcessingCase $case): string
    {
        $latestEvent = CaseEvent::where('case_id', $case->id)
            ->orderBy('sequence', 'desc')
            ->orderBy('recorded_at', 'desc')
            ->first();

        if ($latestEvent && in_array($latestEvent->status_code, self::OUTCOME_STATUSES, true)) {
            return $latestEvent->status_code;
        }

        $stateMap = [
            'awaiting_workflow' => 'queued',
            'running' => 'running',
            'awaiting_review' => 'awaiting_review',
            'approved' => 'approved',
            'complete' => 'approved',
            'blocked' => 'blocked',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
        ];

        return $stateMap[$case->state] ?? 'unknown';
    }

    protected function calculateCaseElapsed(Collection $cases, Carbon $asOf, int &$gaps): array
    {
        $sum = 0;
        $knownCount = 0;
        $unknownCount = 0;
        $ongoingCount = 0;

        foreach ($cases as $case) {
            $created = $case->created_at ? Carbon::parse($case->created_at)->setTimezone('UTC') : null;
            if (! $created) {
                $unknownCount++;
                $gaps++;

                continue;
            }

            // Find terminal event
            $terminalEvent = CaseEvent::where('case_id', $case->id)
                ->where(function ($q) {
                    $q->whereIn('stage_code', ['terminal', 'complete'])
                        ->orWhereIn('status_code', ['approved', 'blocked', 'failed', 'cancelled'])
                        ->orWhereIn('event_type', ['scene_approved', 'workflow_failed', 'case_cancelled', 'workflow_completed']);
                })
                ->orderBy('sequence', 'asc')
                ->orderBy('recorded_at', 'asc')
                ->first();

            if ($terminalEvent && $terminalEvent->recorded_at) {
                $recorded = Carbon::parse($terminalEvent->recorded_at)->setTimezone('UTC');
                if ($recorded->gte($created)) {
                    $diffMs = (int) $created->diffInMilliseconds($recorded);
                    $sum += $diffMs;
                    $knownCount++;
                } else {
                    $unknownCount++;
                    $gaps++;
                }
            } else {
                // If terminal proof is absent
                if (in_array($case->state, ['approved', 'blocked', 'failed', 'cancelled'], true)) {
                    $unknownCount++;
                    $gaps++;
                } else {
                    $ongoingCount++;
                }
            }
        }

        return [
            'sum' => $knownCount > 0 ? $sum : null,
            'known_count' => $knownCount,
            'unknown_count' => $unknownCount,
            'ongoing_count' => $ongoingCount,
            'definition' => 'Elapsed time from case creation to terminal event or as_of',
        ];
    }

    protected function calculateStageResidence(Collection $cases, Carbon $asOf, int &$gaps): array
    {
        $stageData = [];

        foreach ($cases as $case) {
            $events = CaseEvent::where('case_id', $case->id)
                ->orderBy('sequence', 'asc')
                ->orderBy('recorded_at', 'asc')
                ->get();

            $caseCreated = $case->created_at ? Carbon::parse($case->created_at)->setTimezone('UTC') : null;
            if (! $caseCreated) {
                $gaps++;

                continue;
            }

            if ($events->isEmpty()) {
                $diff = $asOf->gte($caseCreated) ? (int) $caseCreated->diffInMilliseconds($asOf) : -1;
                $this->addStageInterval($stageData, 'intake', $diff, $gaps, true);

                continue;
            }

            // Initial interval from case creation to first event
            $firstEvent = $events->first();
            $firstRecorded = Carbon::parse($firstEvent->recorded_at)->setTimezone('UTC');
            $initDiff = $firstRecorded->gte($caseCreated) ? (int) $caseCreated->diffInMilliseconds($firstRecorded) : -1;
            $this->addStageInterval($stageData, 'intake', $initDiff, $gaps, false);

            // Intervals between events
            for ($i = 0; $i < $events->count(); $i++) {
                $current = $events[$i];
                $currentStage = $current->stage_code ?: 'unknown';
                $start = Carbon::parse($current->recorded_at)->setTimezone('UTC');

                if ($i + 1 < $events->count()) {
                    $next = $events[$i + 1];
                    $end = Carbon::parse($next->recorded_at)->setTimezone('UTC');
                    $diff = $end->gte($start) ? (int) $start->diffInMilliseconds($end) : -1;
                    $this->addStageInterval($stageData, $currentStage, $diff, $gaps, false);
                } else {
                    // Last event to as_of
                    $diff = $asOf->gte($start) ? (int) $start->diffInMilliseconds($asOf) : -1;
                    $isOngoing = ! in_array($case->state, ['complete', 'approved', 'blocked', 'failed', 'cancelled'], true);
                    $this->addStageInterval($stageData, $currentStage, $diff, $gaps, $isOngoing);
                }
            }
        }

        $result = [];
        foreach ($stageData as $stage => $data) {
            $result[$stage] = [
                'sum' => $data['known_count'] > 0 ? $data['sum'] : null,
                'known_count' => $data['known_count'],
                'unknown_count' => $data['unknown_count'],
                'ongoing_count' => $data['ongoing_count'],
                'definition' => "Residence time in {$stage} stage between event boundaries",
            ];
        }

        return $result;
    }

    protected function addStageInterval(array &$stageData, string $stage, int $diffMs, int &$gaps, bool $isOngoing): void
    {
        if (! isset($stageData[$stage])) {
            $stageData[$stage] = [
                'sum' => 0,
                'known_count' => 0,
                'unknown_count' => 0,
                'ongoing_count' => 0,
            ];
        }

        if ($diffMs < 0) {
            $stageData[$stage]['unknown_count']++;
            $gaps++;
        } elseif ($isOngoing) {
            $stageData[$stage]['ongoing_count']++;
            $stageData[$stage]['sum'] += $diffMs;
        } else {
            $stageData[$stage]['known_count']++;
            $stageData[$stage]['sum'] += $diffMs;
        }
    }

    protected function calculateOperationObserved(array $caseIds, int &$gaps): array
    {
        $sum = 0;
        $knownCount = 0;
        $unknownCount = 0;
        $ongoingCount = 0;

        if (empty($caseIds)) {
            return [
                'sum' => null,
                'known_count' => 0,
                'unknown_count' => 0,
                'ongoing_count' => 0,
                'definition' => 'Observed duration from operation sent/started to finished',
            ];
        }

        // 1. Coding attempts
        $codingAttempts = CodingAttempt::whereIn('case_id', $caseIds)->get();
        foreach ($codingAttempts as $att) {
            $start = $att->send_reserved_at ?: $att->created_at;
            $finish = $att->completed_at;
            $this->measureOperationInterval($start, $finish, $sum, $knownCount, $unknownCount, $ongoingCount, $gaps);
        }

        // 2. Scene JEV attempts
        $sceneJevs = SceneJevAttempt::whereIn('case_id', $caseIds)->get();
        foreach ($sceneJevs as $jev) {
            $this->measureOperationInterval($jev->sent_at, $jev->finished_at, $sum, $knownCount, $unknownCount, $ongoingCount, $gaps);
        }

        // 3. Scene Check attempts
        $checkAttempts = SceneCheckAttempt::whereIn('case_id', $caseIds)->get();
        foreach ($checkAttempts as $chk) {
            $this->measureOperationInterval($chk->started_at, $chk->finished_at, $sum, $knownCount, $unknownCount, $ongoingCount, $gaps);
        }

        // 4. Prompt JEV attempts
        $promptVersionIds = ScenePromptVersion::whereIn('case_id', $caseIds)->pluck('id')->toArray();
        if (! empty($promptVersionIds)) {
            $promptJevs = PromptJevAttempt::whereIn('prompt_version_id', $promptVersionIds)->get();
            foreach ($promptJevs as $pjev) {
                $this->measureOperationInterval($pjev->sent_at, $pjev->finished_at, $sum, $knownCount, $unknownCount, $ongoingCount, $gaps);
            }
        }

        // 5. Prompt turns
        $workItems = PromptWorkItem::whereIn('case_id', $caseIds)->pluck('id')->toArray();
        if (! empty($workItems)) {
            $turns = PromptTurn::whereIn('work_item_id', $workItems)->get();
            foreach ($turns as $turn) {
                $this->measureOperationInterval($turn->sent_at, $turn->recorded_at, $sum, $knownCount, $unknownCount, $ongoingCount, $gaps);
            }
        }

        return [
            'sum' => $knownCount > 0 ? $sum : null,
            'known_count' => $knownCount,
            'unknown_count' => $unknownCount,
            'ongoing_count' => $ongoingCount,
            'definition' => 'Observed duration from operation sent/started to finished',
        ];
    }

    protected function measureOperationInterval($start, $finish, int &$sum, int &$knownCount, int &$unknownCount, int &$ongoingCount, int &$gaps): void
    {
        if ($start && $finish) {
            $startDt = Carbon::parse($start)->setTimezone('UTC');
            $finishDt = Carbon::parse($finish)->setTimezone('UTC');
            if ($finishDt->gte($startDt)) {
                $diffMs = (int) $startDt->diffInMilliseconds($finishDt);
                $sum += $diffMs;
                $knownCount++;
            } else {
                $unknownCount++;
                $gaps++;
            }
        } elseif ($start && ! $finish) {
            $ongoingCount++;
        }
    }

    protected function calculateReviewWait(array $caseIds, Carbon $asOf, int &$gaps): array
    {
        $sum = 0;
        $knownCount = 0;
        $unknownCount = 0;
        $ongoingCount = 0;

        if (! empty($caseIds)) {
            $reviewItems = TeacherReviewItem::whereIn('case_id', $caseIds)->get();
            foreach ($reviewItems as $item) {
                if ($item->ready_at && $item->decided_at) {
                    $ready = Carbon::parse($item->ready_at)->setTimezone('UTC');
                    $decided = Carbon::parse($item->decided_at)->setTimezone('UTC');
                    if ($decided->gte($ready)) {
                        $diff = (int) $ready->diffInMilliseconds($decided);
                        $sum += $diff;
                        $knownCount++;
                    } else {
                        $unknownCount++;
                        $gaps++;
                    }
                } elseif ($item->ready_at && ! $item->decided_at) {
                    $ongoingCount++;
                } else {
                    $unknownCount++;
                }
            }
        }

        return [
            'sum' => $knownCount > 0 ? $sum : null,
            'known_count' => $knownCount,
            'unknown_count' => $unknownCount,
            'ongoing_count' => $ongoingCount,
            'definition' => 'Review wait time from review item ready to decided or as_of',
        ];
    }
}
