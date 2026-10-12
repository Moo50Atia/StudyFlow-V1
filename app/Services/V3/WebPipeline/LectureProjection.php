<?php

namespace App\Services\V3\WebPipeline;

use App\Models\Lecture;
use App\Models\User;

class LectureProjection
{
    /**
     * Project canonical lecture details for UI views, identifying pipeline links and historical provenance.
     */
    public function projectLecture(Lecture $lecture, ?User $user = null): array
    {
        $link = $lecture->pipelineLink;

        if (! $link) {
            return [
                'id' => $lecture->id,
                'title' => $lecture->title,
                'is_pipeline_linked' => false,
                'is_historical' => false,
                'badge_label' => null,
                'has_dynamic_view' => ! empty($lecture->dynamic_view_link),
                'dynamic_view_link' => $lecture->dynamic_view_link,
                'sections_count' => $lecture->sections->count(),
            ];
        }

        $run = $link->item?->run;
        $origin = $run?->origin ?? 'web_intake';
        $isHistorical = $origin === 'historical_import';

        return [
            'id' => $lecture->id,
            'title' => $lecture->title,
            'is_pipeline_linked' => true,
            'is_historical' => $isHistorical,
            'badge_label' => $isHistorical ? 'Imported Historical Content' : 'V3 Pipeline Managed',
            'has_dynamic_view' => false, // Never fabricate dynamic view link for pipeline-managed lectures
            'dynamic_view_link' => null,
            'sections_count' => $lecture->sections->count(),
            'owner_user_id' => $link->owner_user_id,
            'run_id' => $run?->id,
            'pdf_ordinal' => $link->item?->pdf_ordinal,
        ];
    }
}
