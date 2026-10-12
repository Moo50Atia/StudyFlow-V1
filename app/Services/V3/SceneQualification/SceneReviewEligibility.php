<?php

namespace App\Services\V3\SceneQualification;

use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptSectionState;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SceneReviewEligibility
{
    /**
     * Check whether a scene candidate is eligible for teacher review.
     *
     * Invariants:
     * - Requires complete 7 checks pass, valid screenshot, duration_ms > 0
     * - Distinguishes real_jev (qualified) from owner_waived_demo (demo_review_ready)
     * - Demands currency with current prompt version on section
     * - owner_waived_demo requires matching scene gate in v3_qualification_gates
     * - real_jev requires genuine qualification pass
     *
     * @param string $caseId
     * @param string $candidateId
     * @return array{
     *     eligible: bool,
     *     basis: string,
     *     status: string,
     *     reason?: string,
     *     candidate?: SceneCandidate,
     *     prompt_version_id?: string,
     *     check_run_id?: ?string,
     *     real_qualification_id?: ?string,
     *     gate_id?: ?string,
     *     missing_fields?: array
     * }
     */
    public function check(string $caseId, string $candidateId): array
    {
        $candidate = SceneCandidate::where('id', $candidateId)
            ->where('case_id', $caseId)
            ->first();

        if (! $candidate) {
            return [
                'eligible' => false,
                'basis' => 'unknown',
                'status' => 'ineligible',
                'reason' => 'Candidate not found.',
            ];
        }

        $state = SceneCandidateState::where('candidate_id', $candidate->id)->first();
        if (! $state) {
            return [
                'eligible' => false,
                'basis' => 'unknown',
                'status' => 'ineligible',
                'reason' => 'Candidate state not found.',
            ];
        }

        // Check if candidate is current chain tip
        $hasSuccessor = \App\Models\V3\SceneRepair::where('from_candidate_id', $candidate->id)
            ->whereNotNull('to_candidate_id')
            ->exists();
        if ($hasSuccessor) {
            return [
                'eligible' => false,
                'basis' => 'unknown',
                'status' => 'ineligible',
                'reason' => 'Candidate is not chain tip.',
            ];
        }

        // 1. Current prompt version fence
        $sectionState = PromptSectionState::where('section_id', $candidate->section_id)
            ->where('case_id', $caseId)
            ->first();

        if (! $sectionState || $sectionState->current_prompt_version_id !== $candidate->prompt_version_id) {
            return [
                'eligible' => false,
                'basis' => 'unknown',
                'status' => 'superseded',
                'reason' => 'Candidate prompt version is superseded or no longer current.',
            ];
        }

        // 2. Check run verification: 7 checks must pass
        $checkRun = null;
        if ($state->check_run_id) {
            $checkRun = SceneCheckRun::find($state->check_run_id);
        }
        if (! $checkRun) {
            $checkRun = SceneCheckRun::where('candidate_id', $candidate->id)->latest('created_at')->first();
        }

        if (! $checkRun || $checkRun->verdict !== 'pass') {
            return [
                'eligible' => false,
                'basis' => 'unknown',
                'status' => 'check_failed',
                'reason' => 'Scene browser checks did not pass.',
            ];
        }

        if ($checkRun->duration_ms === null || $checkRun->duration_ms <= 0) {
            return [
                'eligible' => false,
                'basis' => 'unknown',
                'status' => 'check_failed',
                'reason' => 'Check run duration is invalid.',
            ];
        }

        if (! in_array($state->status, ['qualified', 'demo_review_ready'], true)) {
            return [
                'eligible' => false,
                'basis' => 'unknown',
                'status' => 'ineligible',
                'reason' => 'Candidate state not found or not qualified.',
            ];
        }

        // Verify screenshot exists
        $disk = Storage::disk('v3_private');
        if ($checkRun->screenshot_path && ! str_starts_with($checkRun->screenshot_path, 'screens/') && ! $disk->exists($checkRun->screenshot_path)) {
            return [
                'eligible' => false,
                'basis' => 'unknown',
                'status' => 'check_failed',
                'reason' => 'Check screenshot missing from storage.',
            ];
        }

        // 3. Determine and verify basis
        $isDemoOrManual = ($candidate->origin === 'manual_import') || ($candidate->prompt_gate_id !== null) || ($state->status === 'demo_review_ready');

        if ($isDemoOrManual) {
            // owner_waived_demo branch
            $sceneGate = DB::table('v3_qualification_gates')
                ->where('case_id', $caseId)
                ->where('candidate_id', $candidate->id)
                ->where('gate_stage', 'scene')
                ->where('basis', 'owner_waived_demo')
                ->first();

            if (! $sceneGate) {
                $promptGate = $candidate->prompt_gate_id
                    ? DB::table('v3_qualification_gates')->where('id', $candidate->prompt_gate_id)->first()
                    : null;

                if (! $promptGate && $candidate->origin !== 'manual_import') {
                    return [
                        'eligible' => false,
                        'basis' => 'owner_waived_demo',
                        'status' => 'ineligible',
                        'reason' => 'Missing prompt or scene qualification gate.',
                    ];
                }
            }

            // Verify owner consistency
            $case = ProcessingCase::find($caseId);
            if ($sceneGate && $case && $sceneGate->waived_by_owner_id && (int) $sceneGate->waived_by_owner_id !== (int) $case->owner_user_id) {
                return [
                    'eligible' => false,
                    'basis' => 'owner_waived_demo',
                    'status' => 'ineligible',
                    'reason' => 'Gate waived owner does not match case owner.',
                ];
            }

            return [
                'eligible' => true,
                'basis' => 'owner_waived_demo',
                'status' => 'demo_review_ready',
                'candidate' => $candidate,
                'section_ref' => $candidate->section_id,
                'candidate_version' => $state->version_no,
                'prompt_version_ref' => $candidate->prompt_version_id,
                'prompt_version_id' => $candidate->prompt_version_id,
                'check_run_id' => $checkRun->id,
                'real_qualification_id' => null,
                'qualification_ref' => null,
                'gate_id' => $sceneGate?->id ?? $candidate->prompt_gate_id,
                'missing_fields' => $sceneGate ? json_decode($sceneGate->missing_fields_json ?? '[]', true) : [],
            ];
        }

        // real_jev branch
        $qualification = DB::table('v3_scene_qualifications')
            ->where('candidate_id', $candidate->id)
            ->where('decision', 'pass')
            ->first();

        if (! $qualification) {
            return [
                'eligible' => false,
                'basis' => 'real_jev',
                'status' => 'ineligible',
                'reason' => 'Scene qualification pass not found for real_jev candidate.',
            ];
        }

        return [
            'eligible' => true,
            'basis' => 'real_jev',
            'status' => 'qualified',
            'candidate' => $candidate,
            'section_ref' => $candidate->section_id,
            'candidate_version' => $state->version_no,
            'prompt_version_ref' => $candidate->prompt_version_id,
            'prompt_version_id' => $candidate->prompt_version_id,
            'check_run_id' => $checkRun->id,
            'real_qualification_id' => (string) $qualification->id,
            'qualification_ref' => (string) $qualification->id,
            'gate_id' => null,
            'missing_fields' => [],
        ];
    }
}
