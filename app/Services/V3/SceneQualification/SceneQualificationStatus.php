<?php

namespace App\Services\V3\SceneQualification;

use App\Models\V3\ProcessingCase;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\TeacherSection;

class SceneQualificationStatus
{
    public function project(ProcessingCase $case): array
    {
        $teacherSections = TeacherSection::where('case_id', $case->id)
            ->orderBy('ordinal_in_work', 'asc')
            ->get();

        $candidateStates = SceneCandidateState::where('case_id', $case->id)
            ->get()
            ->keyBy('section_id');

        $sectionsList = [];
        foreach ($teacherSections as $ts) {
            $cState = $candidateStates->get($ts->id);
            if (! $cState) {
                continue;
            }

            $status = $cState->status;
            $safeErrorCode = null;
            if (in_array($status, ['check_failed', 'blocked', 'stale'], true)) {
                $safeErrorCode = 'validation';
            } elseif ($status === 'unavailable') {
                $safeErrorCode = $cState->safe_error_code ?? 'internal';
            }

            $sectionsList[] = [
                'section_ref' => (string) $ts->id,
                'candidate_ref' => (string) $cState->candidate_id,
                'candidate_version' => (int) $cState->version_no,
                'status' => $status,
                'error_code' => $safeErrorCode,
            ];
        }

        return [
            'case_id' => $case->id,
            'sections' => $sectionsList,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
