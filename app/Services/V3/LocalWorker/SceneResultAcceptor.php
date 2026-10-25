<?php

namespace App\Services\V3\LocalWorker;

use App\Models\V3\LocalArtifact;
use App\Models\V3\LocalAssignment;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalResult;
use App\Models\V3\LocalSceneSubmission;
use App\Models\V3\LocalTask;
use App\Models\V3\PipelineCommand;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ProcessingDevice;
use App\Models\V3\PromptSectionState;
use App\Models\V3\SceneCandidate;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use App\Models\V3\TeacherSection;
use App\Services\V3\Authoring\StoreSceneArtifacts;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerProtocolException;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerV1Validator;
use App\Services\V3\WebPipeline\RunOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class SceneResultAcceptor
{
    public function __construct(
        protected StoreSceneArtifacts $artifactStore,
        protected RunOutbox $runOutbox,
        protected SealedInputManifestReader $manifestReader,
    ) {}

    public function acceptSceneResult(
        LocalTask $task,
        LocalAssignment $assignment,
        ProcessingDevice $device,
        array $payload,
        ?string $wireDigest = null
    ): array {
        LocalWorkerV1Validator::validateSceneResult($payload);

        if (!in_array($task->status, ['leased', 'running'], true)) {
            throw new LocalWorkerProtocolException("Task is in status '{$task->status}', cannot commit scene result", 'invalid_task_status', 409);
        }

        if ($payload['input_manifest_sha256'] !== $task->input_manifest_sha256) {
            throw new LocalWorkerProtocolException("input_manifest_sha256 mismatch", 'input_changed', 409);
        }

        $resultId = strtolower($payload['result_id']);

        return DB::transaction(function () use ($task, $assignment, $device, $payload, $resultId, $wireDigest) {
            $existing = LocalResult::where('task_id', $task->id)->where('id', $resultId)->first();
            if ($existing) {
                return $existing->receipt_payload;
            }

            $job = LocalJob::findOrFail($task->job_id);
            $run = SubjectRun::findOrFail($job->run_id);

            // Scene acceptance is possible only against the exact immutable return
            // manifest captured by this task. A missing/invalid manifest must never
            // turn the submitted batch into its own completion inventory.
            $returnManifestData = $this->manifestReader->sceneReturn($task, $job, $run);
            $requiredMap = [];
            foreach ($returnManifestData['required_scenes'] as $requiredScene) {
                $sceneId = $requiredScene['scene_id'];
                if (isset($requiredMap[$sceneId])) {
                    throw new LocalWorkerProtocolException('Scene return manifest contains a duplicate scene binding.', 'invalid_scene_binding', 422);
                }
                $requiredMap[$sceneId] = $requiredScene;
            }
            $allRequiredSceneIds = array_keys($requiredMap);
            $submittedSceneIds = array_column($payload['submissions'], 'scene_id');
            $expectedSceneIds = $allRequiredSceneIds;
            sort($submittedSceneIds);
            sort($expectedSceneIds);
            if ($submittedSceneIds !== $expectedSceneIds) {
                throw new LocalWorkerProtocolException('Pull must return the complete required scene inventory.', 'incomplete_result', 422);
            }
            $seenBatchSceneIds = [];

            $acceptedCandidateIds = [];

            foreach ($payload['submissions'] as $subIdx => $sub) {
                $provenance = $sub['provenance'];
                if ($provenance['device_id'] !== $device->id) {
                    throw new LocalWorkerProtocolException('Scene provenance device does not match the authenticated assignment', 'provenance_device_mismatch', 403);
                }
                if (!in_array($provenance['operator_id'], [(int) $job->teacher_id, (int) $device->platform_owner_id], true)) {
                    throw new LocalWorkerProtocolException('Scene operator must be the assigned teacher or the enrolled device owner', 'provenance_operator_mismatch', 403);
                }

                // Must be owned by this task and job (LW-R03)
                $packageArtifact = LocalArtifact::where('task_id', $task->id)
                    ->where('job_id', $task->job_id)
                    ->where(function ($q) use ($sub) {
                        $q->where('id', $sub['package_artifact_id'])->orWhere('logical_name', $sub['package_artifact_id']);
                    })->first();

                if (!$packageArtifact || $packageArtifact->is_quarantine) {
                    throw new LocalWorkerProtocolException("Package artifact '{$sub['package_artifact_id']}' missing or in quarantine for task", 'incomplete_result', 422);
                }

                $sceneId = $sub['scene_id'];
                if (!isset($requiredMap[$sceneId])) {
                    throw new LocalWorkerProtocolException("Scene ID '{$sceneId}' is not in required return inventory", 'invalid_scene_binding', 422);
                }
                if (isset($seenBatchSceneIds[$sceneId])) {
                    throw new LocalWorkerProtocolException("Scene '{$sceneId}' is submitted more than once in this batch", 'duplicate_scene_submission', 422);
                }
                $seenBatchSceneIds[$sceneId] = true;
                $expected = $requiredMap[$sceneId];
                if ($sub['prompt_version_id'] !== $expected['prompt_version_id']) {
                    throw new LocalWorkerProtocolException("prompt_version_id mismatch for scene '{$sceneId}'", 'stale_prompt_version', 422);
                }
                if ($sub['prompt_sha256'] !== $expected['prompt_sha256']) {
                    throw new LocalWorkerProtocolException("prompt_sha256 mismatch for scene '{$sceneId}'", 'prompt_mismatch', 422);
                }

                $packageContent = Storage::disk('v3_private')->get($packageArtifact->storage_path);
                if (hash('sha256', $packageContent) !== $sub['package_sha256'] || $packageArtifact->digest_sha256 !== $sub['package_sha256']) {
                    throw new LocalWorkerProtocolException("Package artifact hash mismatch for scene '{$sub['scene_id']}'", 'artifact_mismatch', 422);
                }

                $packageJson = json_decode($packageContent, true);
                LocalWorkerV1Validator::validateScenePackage($packageJson);

                $promptVersion = ScenePromptVersion::findOrFail($sub['prompt_version_id']);
                if ($promptVersion->status !== 'active' || $promptVersion->section_id !== $sceneId
                    || !hash_equals($promptVersion->body_sha256, $sub['prompt_sha256'])) {
                    throw new LocalWorkerProtocolException("Prompt version '{$promptVersion->id}' is obsolete or inactive", 'stale_prompt_version', 422);
                }

                $sec = TeacherSection::findOrFail($promptVersion->section_id);
                $promptState = PromptSectionState::where('section_id', $sec->id)->lockForUpdate()->first();
                if (!$promptState || $promptState->current_prompt_version_id !== $promptVersion->id) {
                    throw new LocalWorkerProtocolException(
                        "Prompt version '{$promptVersion->id}' is no longer the current version for section '{$sec->id}'.",
                        'stale_prompt_version',
                        409
                    );
                }
                $case = ProcessingCase::findOrFail($sec->case_id);

                // Case must belong to run
                $caseBelongsToRun = SubjectRunItem::where('run_id', $run->id)->where('case_id', $case->id)->exists();
                if (!$caseBelongsToRun) {
                    throw new LocalWorkerProtocolException("Case '{$case->id}' does not belong to run '{$run->id}'", 'foreign_case_forbidden', 403);
                }

                // Deduplicate identical submissions (LW-R23)
                $existingSub = LocalSceneSubmission::where('task_id', $task->id)
                    ->where('scene_id', $sub['scene_id'])
                    ->where('prompt_version_id', $sub['prompt_version_id'])
                    ->where('package_sha256', $sub['package_sha256'])
                    ->first();

                if ($existingSub) {
                    $acceptedCandidateIds[] = $existingSub->candidate_id;
                    continue;
                }

                $mimes = [
                    'index.html' => 'text/html; charset=UTF-8',
                    'styles.css' => 'text/css; charset=UTF-8',
                    'script.js' => 'application/javascript; charset=UTF-8',
                ];
                $decoratedFiles = array_map(function ($file) use ($mimes) {
                    $content = (string) $file['content'];
                    return [
                        'path' => $file['path'],
                        'content' => $content,
                        'mime' => $mimes[$file['path']] ?? 'text/plain',
                        'bytes' => strlen($content),
                        'sha256' => hash('sha256', $content),
                    ];
                }, $packageJson['files']);

                $candidateId = (string) Str::uuid();

                // Store scene artifacts
                $stored = $this->artifactStore->store(
                    caseId: $case->id,
                    sectionId: $sec->id,
                    candidateId: $candidateId,
                    promptVersionId: $promptVersion->id,
                    promptSha256: $promptVersion->body_sha256,
                    origin: 'manual_import',
                    provenanceRef: $sub['submission_id'],
                    sourceManifestSha256: $sub['package_sha256'],
                    validatedFiles: $decoratedFiles,
                    totalBytes: array_sum(array_column($decoratedFiles, 'bytes')),
                    profile: $packageJson['profile'] ?? 'static_three_file_v1'
                );

                // Record SceneCandidate with manual provenance
                $candidate = SceneCandidate::forceCreate([
                    'id' => $candidateId,
                    'request_id' => null,
                    'attempt_id' => null,
                    'case_id' => $case->id,
                    'section_id' => $sec->id,
                    'prompt_version_id' => $promptVersion->id,
                    'qualification_id' => null,
                    'settings_version_id' => $case->settings_version_id,
                    'manifest_path' => $stored['manifest_path'],
                    'manifest_sha256' => $stored['manifest_sha256'],
                    'entrypoint' => 'index.html',
                    'file_count' => $stored['file_count'],
                    'total_bytes' => $stored['total_bytes'],
                    'origin' => 'manual_import',
                    'package_profile' => $packageJson['profile'] ?? 'static_three_file_v1',
                    'manual_import_id' => null,
                    'prompt_gate_id' => null,
                    'collected_at' => now(),
                ]);

                // Initialize Candidate state
                DB::table('v3_scene_candidate_states')->insert([
                    'candidate_id' => $candidateId,
                    'root_candidate_id' => $candidateId,
                    'case_id' => $case->id,
                    'section_id' => $sec->id,
                    'prompt_version_id' => $promptVersion->id,
                    'version_no' => 1,
                    'status' => 'awaiting_check',
                    'state_version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                LocalSceneSubmission::create([
                    'id' => $sub['submission_id'],
                    'result_id' => $resultId,
                    'task_id' => $task->id,
                    'scene_id' => $sub['scene_id'],
                    'prompt_version_id' => $sub['prompt_version_id'],
                    'prompt_sha256' => $sub['prompt_sha256'],
                    'package_artifact_id' => $sub['package_artifact_id'],
                    'package_sha256' => $sub['package_sha256'],
                    'provenance_mode' => $sub['provenance']['mode'],
                    'provenance_payload' => $sub['provenance'],
                    'candidate_id' => $candidateId,
                ]);

                // Dispatch qualification command (LW-R24)
                $commandId = (string) Str::uuid();
                $cmdPayload = json_encode([
                    'candidate_id' => $candidateId,
                    'section_id' => $sec->id,
                    'case_id' => $case->id,
                    'prompt_version_id' => $promptVersion->id,
                    'task_id' => $task->id,
                ]);
                $cmd = PipelineCommand::create([
                    'id' => $commandId,
                    'run_id' => $run->id,
                    'actor_user_id' => $run->owner_user_id,
                    'action' => 'check_candidate',
                    'request_sha256' => hash('sha256', $cmdPayload),
                    'expected_version' => 1,
                    'private_payload_ref' => $candidateId,
                    'state' => 'pending',
                    'accepted_at' => now(),
                ]);
                $this->runOutbox->enqueue($cmd, 'qualification_runner');

                $acceptedCandidateIds[] = $candidateId;
            }

            // Check if all required scenes are accepted (LW-R23)
            $acceptedSceneIds = LocalSceneSubmission::where('task_id', $task->id)
                ->distinct()
                ->pluck('scene_id')
                ->all();

            $allCompleted = !empty($allRequiredSceneIds) && empty(array_diff($allRequiredSceneIds, $acceptedSceneIds));

            if ($allCompleted) {
                $task->status = 'completed';
                $task->save();

                $job->status = 'completed';
                $job->save();

                $run->state = 'completed';
                $run->save();

                $assignment->status = 'released';
                $assignment->save();
            }

            $receipt = [
                'schema_version' => 1,
                'result_id' => $resultId,
                'task_id' => $task->id,
                'job_id' => $job->id,
                'run_id' => $run->id,
                'status' => $allCompleted ? 'completed' : 'partial',
                'accepted_candidates' => $acceptedCandidateIds,
                'required_scenes_count' => count($allRequiredSceneIds),
                'accepted_scenes_count' => count($acceptedSceneIds),
                'committed_at' => now()->toIso8601String(),
            ];

            LocalResult::create([
                'id' => $resultId,
                'task_id' => $task->id,
                'job_id' => $task->job_id,
                'device_id' => $device->id,
                'assignment_id' => $assignment->id,
                'assignment_epoch' => $assignment->assignment_epoch,
                'result_kind' => 'return_scenes',
                'result_digest' => $wireDigest ?? hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                'manifest_sha256' => $task->input_manifest_sha256,
                'receipt_payload' => $receipt,
            ]);

            return $receipt;
        });
    }


}
