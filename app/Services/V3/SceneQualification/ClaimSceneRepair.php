<?php

namespace App\Services\V3\SceneQualification;

use App\Models\V3\AuthoringRequest;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckRun;
use App\Models\V3\SceneQualification;
use App\Models\V3\SceneRepair;
use App\Models\V3\SettingsVersion;
use App\Services\V3\Authoring\ClaimAuthoringRequest;
use App\Services\V3\Authoring\SceneCandidateResolver;
use App\Services\V3\Prompts\PromptConflictException;
use App\Services\V3\Prompts\PromptUnavailableException;
use App\Services\V3\Prompts\PromptValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ClaimSceneRepair
{
    public function __construct(
        protected ClaimAuthoringRequest $authoringClaimService,
        protected SceneCandidateResolver $candidateResolver
    ) {}

    public function claim(string $caseId, array $data): array
    {
        $failedCandidateRef = $data['failed_candidate_ref'] ?? null;
        $expectedOrdinal = (int) ($data['expected_ordinal'] ?? 0);

        if (! $failedCandidateRef || $expectedOrdinal < 1) {
            throw new PromptValidationException("Missing or invalid failed_candidate_ref or expected_ordinal.");
        }

        return DB::transaction(function () use ($caseId, $failedCandidateRef, $expectedOrdinal) {
            /** @var ProcessingCase|null $case */
            $case = ProcessingCase::find($caseId);
            if (! $case) {
                throw new PromptUnavailableException("Case not found.");
            }

            if (! app(\App\Services\V3\Admin\CaseOperationGuard::class)->isPermitted($caseId)) {
                throw new PromptConflictException("Operation conflict: case is held or cancelled.");
            }

            /** @var SceneCandidate|null $candidate */
            $candidate = SceneCandidate::where('id', $failedCandidateRef)
                ->where('case_id', $caseId)
                ->first();
            if (! $candidate) {
                throw new PromptUnavailableException("Candidate not found.");
            }

            /** @var SceneCandidateState|null $candidateState */
            $candidateState = SceneCandidateState::where('candidate_id', $candidate->id)
                ->lockForUpdate()
                ->first();
            if (! $candidateState) {
                throw new PromptUnavailableException("Candidate state not found.");
            }

            // Check if already claimed for this candidate
            $existingRepair = SceneRepair::where('from_candidate_id', $candidate->id)
                ->lockForUpdate()
                ->first();
            if ($existingRepair) {
                if ($existingRepair->ordinal === $expectedOrdinal) {
                    return [
                        'status_code' => 200,
                        'data' => [
                            'repair_ref' => $existingRepair->id,
                            'authoring_request_ref' => $existingRepair->authoring_request_id,
                            'ordinal' => $existingRepair->ordinal,
                            'state' => $existingRepair->state,
                        ],
                    ];
                }
                throw new PromptConflictException("Conflicting repair already claimed for this candidate.");
            }

            if (in_array($candidateState->status, ['qualified', 'superseded', 'blocked', 'stale'], true)) {
                throw new PromptConflictException("Candidate in terminal state {$candidateState->status} cannot be repaired.");
            }

            /** @var SettingsVersion|null $settings */
            $settings = SettingsVersion::find($case->settings_version_id);
            if (! $settings) {
                throw new PromptUnavailableException("Settings not found.");
            }

            $repairLimit = (int) ($settings->payload['limits']['scene_repairs'] ?? 2);
            $maxOrdinal = SceneRepair::where('root_candidate_id', $candidateState->root_candidate_id)->max('ordinal') ?? 0;
            $nextOrdinal = $maxOrdinal + 1;

            if ($expectedOrdinal !== $nextOrdinal) {
                throw new PromptConflictException("Expected ordinal {$expectedOrdinal} does not match next ordinal {$nextOrdinal}.");
            }

            if ($nextOrdinal > $repairLimit) {
                $candidateState->update([
                    'status' => 'blocked',
                    'safe_error_code' => 'validation',
                    'state_version' => $candidateState->state_version + 1,
                ]);
                throw new PromptConflictException("Repair budget exhausted.");
            }

            // Derive criteria
            $checkRun = SceneCheckRun::where('candidate_id', $candidate->id)->first();
            $qual = SceneQualification::where('candidate_id', $candidate->id)->first();

            $failedGate = 'browser';
            $failedChecks = [];
            $checkReport = [];

            if ($checkRun && Storage::disk('v3_private')->exists($checkRun->report_path)) {
                $reportJson = Storage::disk('v3_private')->get($checkRun->report_path);
                $checkReport = json_decode($reportJson, true) ?? [];
            }

            if ($checkRun && $checkRun->verdict === 'fail') {
                $failedGate = 'browser';
                $checks = $checkReport['checks'] ?? [];
                $codes = [];
                foreach ($checks as $chk) {
                    if (($chk['status'] ?? '') === 'fail') {
                        foreach ($chk['detail_codes'] ?? [] as $dc) {
                            if ($dc !== 'not_run_due_to_prior_failure') {
                                $codes[] = $dc;
                            }
                        }
                    }
                }
                $failedChecks = array_values(array_unique($codes));
            } elseif ($qual && $qual->decision !== 'pass') {
                $failedGate = 'jev';
                $failedChecks = $qual->failed_checks ?? [];
            }

            $obs = $checkReport['observations'] ?? [];
            $controlRoles = [];
            foreach ($obs['controls'] ?? [] as $c) {
                if (! empty($c['role'])) {
                    $controlRoles[] = (string) $c['role'];
                }
            }

            $checkObservations = [
                'asset_failures' => $obs['asset_failures'] ?? [],
                'runtime_errors' => $obs['runtime_errors'] ?? [],
                'forbidden_request_count' => (int) ($obs['forbidden_request_count'] ?? 0),
                'control_roles' => array_values(array_unique($controlRoles)),
            ];

            $criteria = [
                'schema_version' => 1,
                'failed_candidate_ref' => $candidate->id,
                'failed_gate' => $failedGate,
                'failed_checks' => $failedChecks,
                'check_observations' => $checkObservations,
            ];

            $criteriaJson = json_encode($criteria, JSON_UNESCAPED_SLASHES);
            $criteriaSha = hash('sha256', $criteriaJson);
            $criteriaPath = "scene-repairs/{$caseId}/{$candidateState->root_candidate_id}/{$nextOrdinal}/{$criteriaSha}.json";
            Storage::disk('v3_private')->put($criteriaPath, $criteriaJson);

            $repair = SceneRepair::forceCreate([
                'id' => (string) Str::uuid(),
                'case_id' => $caseId,
                'section_id' => $candidate->section_id,
                'prompt_version_id' => $candidate->prompt_version_id,
                'root_candidate_id' => $candidateState->root_candidate_id,
                'from_candidate_id' => $candidate->id,
                'to_candidate_id' => null,
                'authoring_request_id' => null,
                'ordinal' => $nextOrdinal,
                'criteria_path' => $criteriaPath,
                'criteria_sha256' => $criteriaSha,
                'state' => 'claimed',
            ]);

            // Extend 011 request
            $authReq = $this->authoringClaimService->claimRepair($caseId, $repair->id);

            $repair->update([
                'authoring_request_id' => $authReq->id,
            ]);

            $candidateState->update([
                'status' => 'repairing',
                'state_version' => $candidateState->state_version + 1,
            ]);

            return [
                'status_code' => 201,
                'data' => [
                    'repair_ref' => $repair->id,
                    'authoring_request_ref' => $authReq->id,
                    'ordinal' => $repair->ordinal,
                    'state' => 'claimed',
                ],
            ];
        });
    }

    public function getContext(string $caseId, string $repairRef): array
    {
        /** @var SceneRepair|null $repair */
        $repair = SceneRepair::where('id', $repairRef)
            ->where('case_id', $caseId)
            ->first();
        if (! $repair) {
            throw new PromptUnavailableException("Repair not found.");
        }

        if (! Storage::disk('v3_private')->exists($repair->criteria_path)) {
            throw new PromptUnavailableException("Repair criteria missing.");
        }

        $criteriaBytes = Storage::disk('v3_private')->get($repair->criteria_path);
        $criteria = json_decode($criteriaBytes, true);

        // Rehash previous files
        $resolved = $this->candidateResolver->resolve($caseId, $repair->from_candidate_id);
        $previousFiles = $resolved['data']['files'] ?? $resolved['files'] ?? [];

        return [
            'repair_ref' => $repair->id,
            'authoring_request_ref' => $repair->authoring_request_id,
            'previous_candidate_ref' => $repair->from_candidate_id,
            'previous_files' => $previousFiles,
            'failed_checks' => $criteria['failed_checks'] ?? [],
            'check_observations' => $criteria['check_observations'] ?? [],
            'teacher_note' => $criteria['teacher_note'] ?? null,
            'criteria_sha256' => $repair->criteria_sha256,
        ];
    }
}
