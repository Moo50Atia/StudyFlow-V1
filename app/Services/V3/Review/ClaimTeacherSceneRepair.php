<?php

namespace App\Services\V3\Review;

use App\Models\V3\ProcessingCase;
use App\Models\V3\ReviewRoute;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckRun;
use App\Models\V3\SceneRepair;
use App\Models\V3\SettingsVersion;
use App\Services\V3\Authoring\ClaimAuthoringRequest;
use App\Services\V3\Prompts\PromptConflictException;
use App\Services\V3\Prompts\PromptUnavailableException;
use App\Services\V3\Prompts\PromptValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ClaimTeacherSceneRepair
{
    public function __construct(
        protected ClaimAuthoringRequest $authoringClaimService
    ) {}

    public function claimFromTeacherRoute(string $caseId, string $routeRef): array
    {
        return DB::transaction(function () use ($caseId, $routeRef) {
            $route = ReviewRoute::where('id', $routeRef)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();

            if (! $route) {
                throw new PromptUnavailableException("Review route not found.");
            }

            if ($route->kind !== 'scene_repair') {
                throw new PromptValidationException("Route is not a scene repair route.");
            }

            // Check idempotent replay
            if ($route->state === 'claimed' && $route->target_ref) {
                $existingRepair = SceneRepair::find($route->target_ref);
                if ($existingRepair) {
                    return [
                        'status_code' => 200,
                        'data' => [
                            'repair_ref' => $existingRepair->id,
                            'authoring_request_ref' => $existingRepair->authoring_request_id,
                            'ordinal' => $existingRepair->ordinal,
                        ],
                    ];
                }
            }

            if ($route->state !== 'pending') {
                throw new PromptConflictException("Review route is in state {$route->state}.");
            }

            $decision = $route->reviewDecision;
            $postapproval = $route->postapprovalCorrection;

            if ($decision) {
                $candidateId = $decision->candidate_id;
                $notePath = $decision->note_path;
                $fromQualId = $decision->qualification_id;
            } elseif ($postapproval) {
                $candidateId = $postapproval->approval->candidate_id;
                $notePath = $postapproval->note_path;
                $fromQualId = $postapproval->approval->qualification_id;
            } else {
                throw new PromptUnavailableException("No review decision or correction linked to route.");
            }

            $candidate = SceneCandidate::where('id', $candidateId)->where('case_id', $caseId)->first();
            if (! $candidate) {
                throw new PromptUnavailableException("Candidate not found.");
            }

            $candState = SceneCandidateState::where('candidate_id', $candidateId)->lockForUpdate()->first();
            if (! $candState || $candState->status !== 'qualified') {
                throw new PromptConflictException("Candidate is not in qualified status.");
            }

            $case = ProcessingCase::find($caseId);
            $settings = SettingsVersion::find($case->settings_version_id);
            $repairLimit = (int) ($settings->payload['limits']['scene_repairs'] ?? 2);

            $maxOrdinal = SceneRepair::where('root_candidate_id', $candState->root_candidate_id)->max('ordinal') ?? 0;
            $nextOrdinal = $maxOrdinal + 1;

            if ($nextOrdinal > $repairLimit) {
                $route->update([
                    'state' => 'blocked',
                    'error_code' => 'validation',
                ]);
                $candState->update([
                    'status' => 'blocked',
                    'safe_error_code' => 'validation',
                    'state_version' => $candState->state_version + 1,
                ]);

                return [
                    'status_code' => 409,
                    'error' => 'Repair budget exhausted.',
                ];
            }

            $teacherNote = Storage::disk('v3_private')->get($notePath);

            // Bounded observations from 012 check run
            $checkRun = SceneCheckRun::where('candidate_id', $candidate->id)->first();
            $checkReport = [];
            if ($checkRun && Storage::disk('v3_private')->exists($checkRun->report_path)) {
                $checkReport = json_decode(Storage::disk('v3_private')->get($checkRun->report_path), true) ?? [];
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
                'control_roles' => array_values(array_unique($controlRoles)),
                'forbidden_request_count' => (int) ($obs['forbidden_request_count'] ?? 0),
                'runtime_errors' => $obs['runtime_errors'] ?? [],
            ];

            // RepairCriteriaV2
            $criteria = [
                'check_observations' => $checkObservations,
                'from_candidate_ref' => $candidate->id,
                'from_qualification_ref' => $fromQualId,
                'reason' => 'teacher_visual',
                'schema_version' => 2,
                'source_route_ref' => $route->id,
                'teacher_note' => $teacherNote,
            ];
            ksort($criteria);

            $criteriaJson = json_encode($criteria, JSON_UNESCAPED_SLASHES);
            $criteriaSha = hash('sha256', $criteriaJson);
            $criteriaPath = "scene-repairs/{$caseId}/{$candState->root_candidate_id}/{$nextOrdinal}/{$criteriaSha}.json";
            Storage::disk('v3_private')->put($criteriaPath, $criteriaJson);

            $repair = SceneRepair::forceCreate([
                'id' => (string) Str::uuid(),
                'case_id' => $caseId,
                'section_id' => $candidate->section_id,
                'prompt_version_id' => $candidate->prompt_version_id,
                'root_candidate_id' => $candState->root_candidate_id,
                'from_candidate_id' => $candidate->id,
                'to_candidate_id' => null,
                'authoring_request_id' => null,
                'teacher_review_route_id' => $route->id,
                'ordinal' => $nextOrdinal,
                'criteria_path' => $criteriaPath,
                'criteria_sha256' => $criteriaSha,
                'state' => 'claimed',
            ]);

            $authReq = $this->authoringClaimService->claimRepair($caseId, $repair->id);
            $repair->update(['authoring_request_id' => $authReq->id]);

            $candState->update([
                'status' => 'repairing',
                'state_version' => $candState->state_version + 1,
            ]);

            $route->update([
                'state' => 'claimed',
                'target_ref' => $repair->id,
            ]);

            return [
                'status_code' => 201,
                'data' => [
                    'repair_ref' => $repair->id,
                    'authoring_request_ref' => $authReq->id,
                    'ordinal' => $repair->ordinal,
                ],
            ];
        });
    }
}
