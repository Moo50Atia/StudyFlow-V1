<?php

namespace App\Services\V3\Authoring;

use App\Models\User;
use App\Models\V3\ManualSceneImport;
use App\Models\V3\PipelineCommand;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptSectionState;
use App\Models\V3\SceneCandidate;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SubjectRun;
use App\Models\V3\TeacherSection;
use App\Services\V3\WebPipeline\CommandConflictException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ImportManualScene
{
    public function __construct(
        protected StoreSceneArtifacts $artifactStore
    ) {}

    /**
     * Import a static three-file scene package manually submitted by a teacher.
     *
     * @param array<string, mixed> $payload
     * @return array{
     *     candidate_id: string,
     *     import_id: string,
     *     is_replay: bool
     * }
     * @throws HttpException
     * @throws CommandConflictException
     * @throws AuthoringValidationException
     */
    public function import(User $actor, SubjectRun $run, TeacherSection $section, array $payload): array
    {
        // 1. Authorization: Only the owner teacher may import scenes
        if ($run->owner_user_id !== $actor->id) {
            throw new HttpException(403, 'Unauthorized. Only the owner of this run can import scenes.');
        }

        // 2. Validate section belongs to run's item/case
        $item = $run->items()->where('id', $section->subject_run_item_id)->first();
        if (! $item && $section->case_id) {
            $item = $run->items()->where('case_id', $section->case_id)->first();
        }
        if (! $item) {
            throw new HttpException(404, 'Section does not belong to this subject run.');
        }

        $case = ProcessingCase::find($section->case_id);
        if (! $case) {
            throw new HttpException(404, 'Processing case not found for this section.');
        }

        $commandId = $payload['command_id'] ?? null;
        if (! $commandId || ! Str::isUuid($commandId)) {
            throw new HttpException(422, 'Invalid or missing command_id.');
        }

        $expectedVersion = (int) ($payload['expected_version'] ?? $run->state_version);
        $promptVersionId = $payload['prompt_version_id'] ?? null;
        if (! $promptVersionId || ! Str::isUuid($promptVersionId)) {
            throw new HttpException(422, 'Invalid or missing prompt_version_id.');
        }

        // 3. Fencing: Ensure prompt_version_id is the CURRENT active prompt version for this section
        $sectionState = PromptSectionState::where('section_id', $section->id)
            ->where('case_id', $case->id)
            ->first();

        if (! $sectionState || $sectionState->current_prompt_version_id !== $promptVersionId) {
            throw new HttpException(409, json_encode([
                'error' => [
                    'code' => 'prompt_conflict',
                    'message' => 'The prompt version is obsolete or does not match the section current prompt.',
                ],
            ]));
        }

        $promptVersion = ScenePromptVersion::where('id', $promptVersionId)
            ->where('section_id', $section->id)
            ->first();

        if (! $promptVersion) {
            throw new HttpException(404, 'Prompt version not found.');
        }

        // 4. Validate package according to V2 static three-file specification
        $rawPackage = $payload['package'] ?? null;
        if (! is_array($rawPackage)) {
            throw new HttpException(422, json_encode([
                'error' => [
                    'code' => 'scene_package_invalid',
                    'message' => 'Missing scene package.',
                ],
            ]));
        }

        try {
            $validatedPackage = StaticScenePackage::validate($rawPackage);
        } catch (AuthoringValidationException $e) {
            throw new HttpException(422, json_encode([
                'error' => [
                    'code' => 'scene_package_invalid',
                    'message' => $e->getMessage(),
                ],
            ]));
        }

        $packageSha256 = $validatedPackage['package_sha256'];

        // 5. Command idempotency check
        $existingCommand = PipelineCommand::where('id', $commandId)->first();
        if ($existingCommand) {
            if ($existingCommand->request_sha256 === $packageSha256) {
                $existingImport = ManualSceneImport::where('command_id', $commandId)->first();
                if ($existingImport && $existingImport->candidate_id) {
                    return [
                        'candidate_id' => $existingImport->candidate_id,
                        'import_id' => $existingImport->id,
                        'is_replay' => true,
                    ];
                }
            } else {
                throw new HttpException(409, json_encode([
                    'error' => [
                        'code' => 'command_conflict',
                        'message' => 'Conflicting command payload already submitted with this command_id.',
                    ],
                ]));
            }
        }

        // 6. Execute atomic creation in transaction
        return DB::transaction(function () use (
            $actor,
            $run,
            $case,
            $section,
            $promptVersion,
            $commandId,
            $packageSha256,
            $expectedVersion,
            $validatedPackage,
            $payload
        ) {
            // Register or lock PipelineCommand
            PipelineCommand::firstOrCreate(
                ['id' => $commandId],
                [
                    'run_id' => $run->id,
                    'actor_user_id' => $actor->id,
                    'action' => 'import_manual_scene',
                    'request_sha256' => $packageSha256,
                    'expected_version' => $expectedVersion,
                    'state' => 'applied',
                    'applied_at' => now(),
                ]
            );

            $importId = (string) Str::uuid();
            $candidateId = (string) Str::uuid();

            // Store files on disk and create manifest
            $stored = $this->artifactStore->store(
                caseId: $case->id,
                sectionId: $section->id,
                candidateId: $candidateId,
                promptVersionId: $promptVersion->id,
                promptSha256: $promptVersion->body_sha256,
                origin: 'manual_import',
                provenanceRef: $importId,
                sourceManifestSha256: $validatedPackage['source_manifest_sha256'],
                validatedFiles: $validatedPackage['files'],
                totalBytes: $validatedPackage['total_bytes']
            );

            // Record ManualSceneImport (null candidate_id initially to satisfy FK)
            $import = ManualSceneImport::create([
                'id' => $importId,
                'case_id' => $case->id,
                'section_id' => $section->id,
                'prompt_version_id' => $promptVersion->id,
                'actor_user_id' => $actor->id,
                'command_id' => $commandId,
                'package_sha256' => $packageSha256,
                'private_source_ref' => $stored['source_ref'],
                'user_reported_provider' => $payload['user_reported_provider'] ?? null,
                'user_reported_model' => $payload['user_reported_model'] ?? null,
                'candidate_id' => null,
            ]);

            // Record SceneCandidate with manual provenance
            $candidate = SceneCandidate::forceCreate([
                'id' => $candidateId,
                'request_id' => null,
                'attempt_id' => null,
                'case_id' => $case->id,
                'section_id' => $section->id,
                'prompt_version_id' => $promptVersion->id,
                'qualification_id' => null,
                'settings_version_id' => $case->settings_version_id,
                'manifest_path' => $stored['manifest_path'],
                'manifest_sha256' => $stored['manifest_sha256'],
                'entrypoint' => 'index.html',
                'file_count' => $stored['file_count'],
                'total_bytes' => $stored['total_bytes'],
                'origin' => 'manual_import',
                'package_profile' => 'static_three_file_v1',
                'manual_import_id' => $importId,
                'prompt_gate_id' => null,
                'collected_at' => now(),
            ]);

            // Link candidate_id back to import
            $import->update(['candidate_id' => $candidateId]);

            // Initialize Candidate state
            DB::table('v3_scene_candidate_states')->insert([
                'candidate_id' => $candidateId,
                'root_candidate_id' => $candidateId,
                'case_id' => $case->id,
                'section_id' => $section->id,
                'prompt_version_id' => $promptVersion->id,
                'version_no' => 1,
                'status' => 'awaiting_check',
                'state_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'candidate_id' => $candidateId,
                'import_id' => $importId,
                'is_replay' => false,
            ];
        });
    }
}
