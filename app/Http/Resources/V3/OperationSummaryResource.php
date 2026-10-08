<?php

namespace App\Http\Resources\V3;

use App\Data\V3\OperationSummaryData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OperationSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request = null): array
    {
        $dto = $this->resource instanceof OperationSummaryData
            ? $this->resource
            : OperationSummaryData::fromArray((array) $this->resource);

        return $dto->toArray();
    }
}
