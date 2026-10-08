<?php

namespace App\Http\Resources\V3;

use App\Data\V3\OperationSummaryData;
use App\Models\V3\ProcessingCase;
use App\Models\V3\WorkflowLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClosedCaseSummary extends JsonResource
{
    /**
     * Build exact scalar array for a processing case and optional workflow link.
     */
    public static function fromCase(ProcessingCase $case, ?WorkflowLink $link = null): array
    {
        $stageCode = $link?->stage_code ?? 'intake';
        $statusCode = $link?->status_code ?? ($case->state === 'awaiting_workflow' ? 'queued' : $case->state);
        $errorCode = $link?->error_code ?? null;

        $dto = new OperationSummaryData(
            caseId: (string) $case->id,
            stageCode: (string) $stageCode,
            statusCode: (string) $statusCode,
            errorCode: $errorCode,
            createdAt: $case->created_at->toISOString(),
            updatedAt: $case->updated_at->toISOString(),
            attemptCount: \App\Models\V3\CodingAttempt::where('case_id', $case->id)->count(),
            sceneCount: \App\Models\V3\SceneCandidate::where('case_id', $case->id)->count(),
            approvedSceneCount: 0
        );

        return $dto->toArray();
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request = null): array
    {
        if ($this->resource instanceof OperationSummaryData) {
            return $this->resource->toArray();
        }

        if (is_array($this->resource)) {
            return OperationSummaryData::fromArray($this->resource)->toArray();
        }

        if ($this->resource instanceof ProcessingCase) {
            return self::fromCase($this->resource, $this->resource->workflowLink ?? null);
        }

        return (array) $this->resource;
    }
}
