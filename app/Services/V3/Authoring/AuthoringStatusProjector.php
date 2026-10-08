<?php

namespace App\Services\V3\Authoring;

use App\Models\V3\AuthoringRequest;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptSectionState;
use App\Models\V3\SceneCandidate;
use App\Models\V3\TeacherSection;

class AuthoringStatusProjector
{
    /**
     * Project safe scene authoring status for teacher.
     *
     * @param ProcessingCase $case
     * @return array
     */
    public function project(ProcessingCase $case): array
    {
        // 1. Get all teacher sections ordered by 009 ordinal
        $teacherSections = TeacherSection::with('lessonNode')
            ->where('case_id', $case->id)
            ->get();

        // 2. Get prompt section states for current passing sections
        $passingStates = PromptSectionState::where('case_id', $case->id)
            ->where('status', 'qualified')
            ->get()
            ->keyBy('section_id');

        // 3. Get existing authoring requests and candidates for this case
        $requests = AuthoringRequest::where('case_id', $case->id)
            ->get()
            ->keyBy('section_id');

        $candidates = SceneCandidate::where('case_id', $case->id)
            ->get()
            ->keyBy('section_id');

        $sectionsList = [];
        foreach ($teacherSections as $ts) {
            $promptState = $passingStates->get($ts->id);
            if (! $promptState) {
                // Not a current qualified passing prompt
                continue;
            }

            $req = $requests->get($ts->id);
            $candidate = $candidates->get($ts->id);

            $sectionsList[] = [
                'section_ref' => (string) $ts->id,
                'prompt_version_ref' => (string) $promptState->current_prompt_version_id,
                'state' => $req ? $req->state : 'pending',
                'candidate_ref' => $candidate ? (string) $candidate->id : null,
                'work_ordinal' => (int) ($ts->lessonNode?->work_ordinal ?? 1),
                'ordinal_in_work' => (int) $ts->ordinal_in_work,
            ];
        }

        usort($sectionsList, function ($a, $b) {
            return ($a['work_ordinal'] <=> $b['work_ordinal'])
                ?: ($a['ordinal_in_work'] <=> $b['ordinal_in_work']);
        });

        // Strip ordering helpers
        $cleanSections = array_map(function ($item) {
            return [
                'section_ref' => $item['section_ref'],
                'prompt_version_ref' => $item['prompt_version_ref'],
                'state' => $item['state'],
                'candidate_ref' => $item['candidate_ref'],
            ];
        }, $sectionsList);

        return [
            'case_id' => $case->id,
            'sections' => array_values($cleanSections),
            'updated_at' => $case->updated_at->toISOString(),
        ];
    }
}
