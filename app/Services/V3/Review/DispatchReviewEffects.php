<?php

namespace App\Services\V3\Review;

use App\Models\V3\ReviewOutbox;
use App\Models\V3\TeacherReviewDecision;
use App\Models\V3\PostapprovalCorrection;
use App\Models\V3\ReviewRoute;
use App\Models\V3\SceneRepair;
use App\Models\V3\TeacherPromptCorrection;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\WorkflowLink;
use App\Models\V3\CaseOperationControl;
use App\Models\V3\ProcessingCase;
use App\Services\V3\Workflow\ReviewSignalGateway;
use Illuminate\Support\Facades\DB;

class DispatchReviewEffects
{
    public function __construct(protected ReviewSignalGateway $gateway) {}

    public function dispatchPending(): int
    {
        $ids = ReviewOutbox::query()->where(function ($q) {
            $q->whereIn('state', ['pending', 'uncertain'])
              ->orWhere(function ($q) { $q->where('state', 'sending')->where('lease_until', '<', now()); });
        })->orderBy('created_at')->limit(100)->pluck('id');

        $processed = 0;
        foreach ($ids as $id) {
            // Linearize the outbox claim with 014's case/control fence. The common lock
            // order is case -> control -> effect; release all DB locks before bridge I/O.
            $item = DB::transaction(function () use ($id) {
                $identity = ReviewOutbox::whereKey($id)->first(['review_decision_id', 'postapproval_correction_id']);
                if (! $identity) return null;
                $caseId = $identity->review_decision_id
                    ? TeacherReviewDecision::whereKey($identity->review_decision_id)->value('case_id')
                    : PostapprovalCorrection::whereKey($identity->postapproval_correction_id)->value('case_id');
                if (! $caseId) return null;
                $case = ProcessingCase::whereKey($caseId)->lockForUpdate()->first();
                if (! $case) return null;
                $control = CaseOperationControl::where('case_id', $caseId)->lockForUpdate()->first();
                $row = ReviewOutbox::whereKey($id)->lockForUpdate()->first();
                if (! $row || ! in_array($row->state, ['pending', 'uncertain'], true)
                    && ! ($row->state === 'sending' && $row->lease_until?->isPast())) return null;

                $mode = $control?->mode ?? 'normal';
                if ($case->state === 'cancelled' || in_array($mode, ['cancel_requested', 'cancelled'], true)) {
                    // An unclaimed intent is provably unsent and must never escape a
                    // committed cancellation. Uncertain/sending intents may have
                    // reached Temporal, so retain them for read-only reconciliation.
                    if ($row->state === 'pending') {
                        $row->state = 'blocked';
                        $row->lease_until = null;
                        $row->error_code = 'operation_controlled';
                        $row->save();
                    }
                    return null;
                }
                if ($mode !== 'normal') {
                    // Hold/resume-request fences defer unclaimed work; resume lets the
                    // same stable effect ID be reconciled or sent later.
                    return null;
                }

                $row->state = 'sending';
                $row->lease_until = now()->addMinutes(2);
                $row->last_attempt_at = now();
                $row->attempt_count++;
                $row->save();
                return $row;
            });
            if (! $item) continue;

            $delivered = false;
            $errorCode = 'effect_unavailable';
            if ($item->operation === 'signal_decision' && $item->review_decision_id) {
                $decision = TeacherReviewDecision::find($item->review_decision_id);
                if ($decision) {
                    $payload = [
                        'schema_version' => 1,
                        'case_id' => $decision->case_id,
                        'section_ref' => $decision->section_id,
                        'review_ref' => $decision->review_item_id,
                        'decision_ref' => $decision->id,
                        'candidate_ref' => $decision->candidate_id,
                        'candidate_version' => (int) $decision->candidate_version,
                        'outcome' => $decision->decision,
                    ];
                    $reviewOwnerReady = true;
                    if (str_starts_with($item->workflow_id, 'v3-web-review-')) {
                        $started = $this->gateway->startWebReview($decision->review_item_id, [
                            'schema_version' => 1,
                            'case_id' => $decision->case_id,
                            'section_ref' => $decision->section_id,
                            'review_ref' => $decision->review_item_id,
                            'candidate_ref' => $decision->candidate_id,
                            'candidate_version' => (int) $decision->candidate_version,
                        ]);
                        $reviewOwnerReady = ($started['accepted'] ?? false) === true;
                        if (! $reviewOwnerReady) $errorCode = 'review_owner_unavailable';
                    }
                    if ($reviewOwnerReady) {
                        $proof = $this->gateway->queryDecision($item->workflow_id, $decision->id);
                        if (($proof['status'] ?? 503) < 400 && ($proof['consumed'] ?? false) === true) {
                            $delivered = true;
                        } else {
                            $sent = $this->gateway->sendDecisionSignal($item->workflow_id, $payload);
                            if (($sent['accepted'] ?? false) === true) {
                                $proof = $this->gateway->queryDecision($item->workflow_id, $decision->id);
                                $delivered = ($proof['status'] ?? 503) < 400 && ($proof['consumed'] ?? false) === true;
                            }
                        }
                    }

                    if ($delivered && $decision->decision === 'request_changes') {
                        // A first-pass teacher change request creates a ReviewRoute,
                        // but no PostapprovalCorrection row. Start the same durable
                        // correction workflow from that route only after the case
                        // workflow has proved it consumed the exact decision.
                        $route = ReviewRoute::where('review_decision_id', $decision->id)->first();
                        if (! $route || $route->postapproval_correction_id) {
                            $delivered = false;
                            $errorCode = 'review_route_missing';
                        } else {
                            $routeWorkflowId = "v3-review-correction-{$route->id}";
                            $started = $this->gateway->startCorrection($routeWorkflowId, [
                                'schema_version' => 1,
                                'case_id' => $decision->case_id,
                                'section_ref' => $decision->section_id,
                                'correction_ref' => $route->id,
                                'route_ref' => $route->id,
                                'feedback_kind' => $decision->feedback_kind,
                            ]);
                            $delivered = ($started['accepted'] ?? false) === true;
                            if (! $delivered) $errorCode = 'review_route_start_unavailable';
                        }
                    } elseif ($delivered && str_starts_with($item->workflow_id, 'v3-review-correction-')) {
                        // A successor review decision must be consumed by its
                        // correction workflow and by the still-running parent
                        // case workflow, which advances that section's review
                        // fence from request_changes to the successor candidate.
                        $reviewItem = TeacherReviewItem::find($decision->review_item_id);
                        $sceneRouteId = SceneRepair::where('to_candidate_id', $decision->candidate_id)
                            ->value('teacher_review_route_id');
                        $promptRouteId = $reviewItem
                            ? TeacherPromptCorrection::where('to_prompt_version_id', $reviewItem->prompt_version_id)
                                ->value('review_route_id')
                            : null;
                        $route = ReviewRoute::find($sceneRouteId ?: $promptRouteId);
                        if ($route && ! $route->postapproval_correction_id) {
                            $parentWorkflowId = WorkflowLink::where('case_id', $decision->case_id)->value('workflow_id');
                            if ($parentWorkflowId && $parentWorkflowId !== $item->workflow_id) {
                                $parentProof = $this->gateway->queryDecision($parentWorkflowId, $decision->id);
                                if (($parentProof['status'] ?? 503) >= 400 || ! ($parentProof['consumed'] ?? false)) {
                                    $parentSent = $this->gateway->sendDecisionSignal($parentWorkflowId, $payload);
                                    if (($parentSent['accepted'] ?? false) !== true) {
                                        $delivered = false;
                                        $errorCode = 'parent_decision_signal_unavailable';
                                    } else {
                                        $parentProof = $this->gateway->queryDecision($parentWorkflowId, $decision->id);
                                        $delivered = ($parentProof['status'] ?? 503) < 400
                                            && ($parentProof['consumed'] ?? false) === true;
                                        if (! $delivered) $errorCode = 'parent_decision_unconfirmed';
                                    }
                                }
                            }
                        }
                    }
                } else { $errorCode = 'decision_missing'; }
            } elseif ($item->operation === 'start_correction' && $item->postapproval_correction_id) {
                $correction = PostapprovalCorrection::find($item->postapproval_correction_id);
                $route = $correction ? ReviewRoute::where('postapproval_correction_id', $correction->id)->first() : null;
                if ($correction && $route && $item->workflow_id === "v3-review-correction-{$correction->id}") {
                    $result = $this->gateway->startCorrection($item->workflow_id, [
                        'schema_version' => 1,
                        'case_id' => $correction->case_id,
                        'section_ref' => $correction->section_id,
                        'correction_ref' => $correction->id,
                        'route_ref' => $route->id,
                        'feedback_kind' => $correction->feedback_kind,
                    ]);
                    $delivered = ($result['accepted'] ?? false) === true;
                } else { $errorCode = 'correction_context_missing'; }
            } else {
                $errorCode = 'unsupported_effect';
            }

            $fresh = ReviewOutbox::whereKey($id)->first();
            if ($fresh) {
                $fresh->state = $delivered ? 'delivered' : 'uncertain';
                $fresh->delivered_at = $delivered ? now() : null;
                $fresh->lease_until = null;
                $fresh->error_code = $delivered ? null : $errorCode;
                $fresh->save();
            }
            if ($delivered) $processed++;
        }
        return $processed;
    }
}
