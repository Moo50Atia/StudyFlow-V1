<?php

namespace App\Http\Resources\V3;

use App\Data\V3\OperationSummaryData;
use App\Models\V3\CaseOperationControl;
use App\Models\V3\ProcessingCase;
use App\Models\V3\WorkflowLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class OperationSummaryV1 extends JsonResource
{
    /**
     * Build exact scalar array for a processing case according to 014 contract.
     */
    public static function fromCase(
        ProcessingCase $case,
        ?WorkflowLink $link = null,
        ?CaseOperationControl $control = null
    ): array {
        $stageCode = $link?->stage_code ?? 'intake';
        $statusCode = $link?->status_code ?? ($case->state === 'awaiting_workflow' ? 'queued' : $case->state);
        $errorCode = $link?->error_code ?? null;

        $controlMode = $control?->mode ?? 'normal';
        if (! in_array($controlMode, CaseOperationControl::ALLOWED_MODES, true)) {
            $controlMode = 'normal';
        }

        $approvedCount = ($case->state === 'cancelled' || $controlMode === 'cancelled')
            ? 0
            : (int) ($link?->approved_scene_count ?? 0);

        $caseDto = new OperationSummaryData(
            caseId: (string) $case->id,
            stageCode: (string) $stageCode,
            statusCode: (string) $statusCode,
            errorCode: $errorCode,
            createdAt: $case->created_at->toISOString(),
            updatedAt: $case->updated_at->toISOString(),
            attemptCount: (int) ($link?->attempt_count ?? DB::table('v3_coding_attempts')->where('case_id', $case->id)->count()),
            sceneCount: (int) ($link?->scene_count ?? DB::table('v3_scene_candidates')->where('case_id', $case->id)->count()),
            approvedSceneCount: $approvedCount
        );

        $now = now()->timestamp;
        $ageSeconds = max(0, (int) ($now - $case->created_at->timestamp));

        $lastActivity = $link?->last_event_at ?? $case->updated_at ?? $case->created_at;
        $idleSeconds = max(0, (int) ($now - $lastActivity->timestamp));

        $dormantCount = static::countDormantReviews((string) $case->id);
        $uncertainCount = static::countUncertainEffects((string) $case->id);

        return [
            'case' => $caseDto->toArray(),
            'age_seconds' => $ageSeconds,
            'idle_seconds' => $idleSeconds,
            'control_mode' => $controlMode,
            'dormant_review_count' => $dormantCount,
            'uncertain_effect_count' => $uncertainCount,
        ];
    }

    public static function countDormantReviews(string $caseId): int
    {
        return DB::table('v3_teacher_review_items as items')
            ->join('v3_review_section_states as states', 'items.id', '=', 'states.current_review_item_id')
            ->where('items.case_id', $caseId)
            ->where('items.status', 'dormant')
            ->count();
    }

    public static function countUncertainEffects(string $caseId): int
    {
        $count = 0;

        $count += DB::table('v3_source_effects')
            ->where('case_id', $caseId)
            ->where('state', 'uncertain')
            ->count();

        $count += DB::table('v3_prompt_turns')
            ->where('case_id', $caseId)
            ->where('state', 'uncertain')
            ->count();

        $count += DB::table('v3_prompt_feedback_effects')
            ->where('case_id', $caseId)
            ->where('state', 'uncertain')
            ->count();

        $count += DB::table('v3_coding_attempts')
            ->where('case_id', $caseId)
            ->where('state', 'uncertain')
            ->count();

        $count += DB::table('v3_scene_check_attempts')
            ->where('case_id', $caseId)
            ->where('state', 'uncertain')
            ->count();

        $count += DB::table('v3_scene_jev_attempts')
            ->where('case_id', $caseId)
            ->where('state', 'uncertain')
            ->count();

        $count += DB::table('v3_teacher_prompt_corrections')
            ->where('case_id', $caseId)
            ->where('state', 'uncertain')
            ->count();

        $count += DB::table('v3_review_outbox')
            ->where('state', 'uncertain')
            ->where(function ($query) use ($caseId) {
                $query->whereIn('review_decision_id', function ($sub) use ($caseId) {
                    $sub->select('id')->from('v3_teacher_review_decisions')->where('case_id', $caseId);
                })->orWhereIn('postapproval_correction_id', function ($sub) use ($caseId) {
                    $sub->select('id')->from('v3_postapproval_corrections')->where('case_id', $caseId);
                });
            })
            ->count();

        return $count;
    }

    public function toArray(Request $request = null): array
    {
        if (is_array($this->resource)) {
            return $this->resource;
        }

        if ($this->resource instanceof ProcessingCase) {
            $link = $this->resource->workflowLink ?? WorkflowLink::find($this->resource->id);
            $control = CaseOperationControl::find($this->resource->id);
            return self::fromCase($this->resource, $link, $control);
        }

        return (array) $this->resource;
    }
}
