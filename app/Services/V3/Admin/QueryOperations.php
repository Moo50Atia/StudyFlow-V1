<?php

namespace App\Services\V3\Admin;

use App\Data\V3\OperationSummaryData;
use App\Http\Resources\V3\OperationSummaryV1;
use App\Models\V3\CaseOperationControl;
use App\Models\V3\ProcessingCase;
use App\Models\V3\WorkflowLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QueryOperations
{
    public const ALLOWED_QUERY_KEYS = [
        'stage_code',
        'status_code',
        'error_code',
        'older_than_minutes',
        'page',
    ];

    /**
     * Validate query parameters strictly according to FR-001 / contract.
     * Throws InvalidArgumentException with safe contract message if invalid.
     */
    public function validateQueryParameters(array $query): void
    {
        foreach (array_keys($query) as $key) {
            if (! in_array($key, self::ALLOWED_QUERY_KEYS, true)) {
                throw new \InvalidArgumentException('Invalid operation request.');
            }
        }

        if (isset($query['stage_code'])) {
            $stage = $query['stage_code'];
            if ($stage === 'unknown' || ! in_array($stage, OperationSummaryData::ALLOWED_STAGES, true)) {
                throw new \InvalidArgumentException('Invalid operation request.');
            }
        }

        if (isset($query['status_code'])) {
            $status = $query['status_code'];
            if ($status === 'unknown' || ! in_array($status, OperationSummaryData::ALLOWED_STATUSES, true)) {
                throw new \InvalidArgumentException('Invalid operation request.');
            }
        }

        if (isset($query['error_code'])) {
            $error = $query['error_code'];
            if ($error === 'unknown' || ! in_array($error, OperationSummaryData::ALLOWED_ERRORS, true)) {
                throw new \InvalidArgumentException('Invalid operation request.');
            }
        }

        if (isset($query['older_than_minutes'])) {
            $older = $query['older_than_minutes'];
            if (! is_numeric($older) || (int) $older != $older || (int) $older < 0 || (int) $older > 10080) {
                throw new \InvalidArgumentException('Invalid operation request.');
            }
        }

        if (isset($query['page'])) {
            $page = $query['page'];
            if (! is_numeric($page) || (int) $page != $page || (int) $page < 1) {
                throw new \InvalidArgumentException('Invalid operation request.');
            }
        }
    }

    /**
     * List cases with 014 closed projection, filtering and pagination.
     */
    public function listCases(array $query, int $perPage = 20): array
    {
        $this->validateQueryParameters($query);

        $page = isset($query['page']) ? (int) $query['page'] : 1;

        $qb = ProcessingCase::query()
            ->leftJoin('v3_workflow_links', 'v3_processing_cases.id', '=', 'v3_workflow_links.case_id')
            ->select('v3_processing_cases.*');

        if (isset($query['stage_code'])) {
            $stage = $query['stage_code'];
            $qb->where(function ($q) use ($stage) {
                if ($stage === 'intake') {
                    $q->where('v3_workflow_links.stage_code', 'intake')
                      ->orWhereNull('v3_workflow_links.stage_code');
                } else {
                    $q->where('v3_workflow_links.stage_code', $stage);
                }
            });
        }

        if (isset($query['status_code'])) {
            $status = $query['status_code'];
            $qb->where(function ($q) use ($status) {
                if ($status === 'queued') {
                    $q->where('v3_workflow_links.status_code', 'queued')
                      ->orWhere(function ($sub) {
                          $sub->whereNull('v3_workflow_links.status_code')
                              ->where('v3_processing_cases.state', 'awaiting_workflow');
                      });
                } else {
                    $q->where('v3_workflow_links.status_code', $status)
                      ->orWhere(function ($sub) use ($status) {
                          $sub->whereNull('v3_workflow_links.status_code')
                              ->where('v3_processing_cases.state', $status);
                      });
                }
            });
        }

        if (isset($query['error_code'])) {
            $qb->where('v3_workflow_links.error_code', $query['error_code']);
        }

        if (isset($query['older_than_minutes'])) {
            $minutes = (int) $query['older_than_minutes'];
            $cutoff = now()->subMinutes($minutes);
            $qb->where('v3_processing_cases.created_at', '<=', $cutoff);
        }

        $qb->orderByDesc('v3_processing_cases.created_at')
           ->orderByDesc('v3_processing_cases.id');

        $offset = ($page - 1) * $perPage;
        $itemsPlusOne = $qb->skip($offset)->take($perPage + 1)->get();

        $hasMore = $itemsPlusOne->count() > $perPage;
        $pageItems = $itemsPlusOne->take($perPage);

        $caseIds = $pageItems->pluck('id')->all();
        $links = WorkflowLink::whereIn('case_id', $caseIds)->get()->keyBy('case_id');
        $controls = CaseOperationControl::whereIn('case_id', $caseIds)->get()->keyBy('case_id');

        $summaries = $pageItems->map(function (ProcessingCase $case) use ($links, $controls) {
            $link = $links->get($case->id);
            $control = $controls->get($case->id);
            return OperationSummaryV1::fromCase($case, $link, $control);
        })->values()->all();

        return [
            'data' => $summaries,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'has_more' => $hasMore,
            ],
        ];
    }

    /**
     * Get single case detail and recoverable action targets.
     */
    public function getCaseDetail(string $caseId): ?array
    {
        if (! Str::isUuid($caseId) || strtolower($caseId) !== $caseId) {
            return null;
        }

        $case = ProcessingCase::find($caseId);
        if (! $case) {
            return null;
        }

        $link = WorkflowLink::find($caseId);
        $control = CaseOperationControl::find($caseId);

        $summary = OperationSummaryV1::fromCase($case, $link, $control);
        $actionTargets = $this->resolveActionTargets($case, $link, $control);

        return [
            'summary' => $summary,
            'action_targets' => $actionTargets,
        ];
    }

    /**
     * Resolve eligible recoverable targets according to contract:
     * workflow_start|source_proof|scene_check|scene_jev|review_signal|review_correction
     */
    public function resolveActionTargets(ProcessingCase $case, ?WorkflowLink $link, ?CaseOperationControl $control): array
    {
        $mode = $control?->mode ?? 'normal';
        if ($mode !== 'normal') {
            return [];
        }

        $terminalStates = ['complete', 'approved', 'blocked', 'failed', 'cancelled'];
        if (in_array($case->state, $terminalStates, true)) {
            return [];
        }

        $targets = [];

        // 1. workflow_start
        if ($link && in_array($link->start_state, ['pending', 'uncertain'], true)) {
            $targets[] = [
                'kind' => 'workflow_start',
                'ref' => (string) $case->id,
                'state' => $link->start_state,
            ];
        }

        // 2. source_proof
        $uncertainSource = DB::table('v3_source_effects')
            ->where('case_id', $case->id)
            ->where('state', 'uncertain')
            ->first();
        if ($uncertainSource) {
            $targets[] = [
                'kind' => 'source_proof',
                'ref' => (string) $uncertainSource->id,
                'state' => 'uncertain',
            ];
        }

        // 3. scene_check
        $uncertainCheck = DB::table('v3_scene_check_attempts')
            ->where('case_id', $case->id)
            ->where('state', 'uncertain')
            ->first();
        if ($uncertainCheck) {
            $targets[] = [
                'kind' => 'scene_check',
                'ref' => (string) $uncertainCheck->id,
                'state' => 'uncertain',
            ];
        }

        // 4. scene_jev
        $uncertainJev = DB::table('v3_scene_jev_attempts')
            ->where('case_id', $case->id)
            ->where('state', 'uncertain')
            ->first();
        if ($uncertainJev) {
            $targets[] = [
                'kind' => 'scene_jev',
                'ref' => (string) $uncertainJev->id,
                'state' => 'uncertain',
            ];
        }

        // 5. review_signal & review_correction from v3_review_outbox
        $outboxRows = DB::table('v3_review_outbox')
            ->whereIn('state', ['pending', 'uncertain'])
            ->where(function ($q) use ($case) {
                $q->whereIn('review_decision_id', function ($sub) use ($case) {
                    $sub->select('id')->from('v3_teacher_review_decisions')->where('case_id', $case->id);
                })->orWhereIn('postapproval_correction_id', function ($sub) use ($case) {
                    $sub->select('id')->from('v3_postapproval_corrections')->where('case_id', $case->id);
                });
            })
            ->get();

        foreach ($outboxRows as $row) {
            if ($row->operation === 'signal_decision') {
                $targets[] = [
                    'kind' => 'review_signal',
                    'ref' => (string) $row->id,
                    'state' => (string) $row->state,
                ];
            } elseif ($row->operation === 'start_correction') {
                $targets[] = [
                    'kind' => 'review_correction',
                    'ref' => (string) $row->id,
                    'state' => (string) $row->state,
                ];
            }
        }

        return $targets;
    }
}
