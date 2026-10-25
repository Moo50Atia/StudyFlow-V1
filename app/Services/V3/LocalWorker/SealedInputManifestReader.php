<?php

namespace App\Services\V3\LocalWorker;

use App\Models\V3\LocalArtifact;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalTask;
use App\Models\V3\SubjectRun;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerJsonDecoder;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerProtocolException;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerV1Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Reads sealed task manifests and validates their identities and fingerprints. */
final class SealedInputManifestReader
{
    public function authoring(LocalTask $task): array
    {
        // Load sealed input manifest to verify evidence and items (LW-R09)
        $manifestArtifact = LocalArtifact::find($task->input_manifest_id);
        if (!$manifestArtifact) {
            $manifestArtifact = LocalArtifact::where('task_id', $task->id)
                ->where('purpose', 'input_manifest')
                ->first();
        }

        if (!$manifestArtifact || !$manifestArtifact->is_committed
            || !Storage::disk('v3_private')->exists($manifestArtifact->storage_path)) {
            throw new LocalWorkerProtocolException('Sealed input manifest is missing or unavailable.', 'incomplete_result', 422);
        }

        try {
            $manifestBytes = Storage::disk('v3_private')->get($manifestArtifact->storage_path);
            if (!hash_equals($task->input_manifest_sha256, hash('sha256', $manifestBytes))) {
                throw new LocalWorkerProtocolException('Sealed input manifest digest mismatch.', 'incomplete_result', 422);
            }
            $inputManifestData = LocalWorkerJsonDecoder::decode($manifestBytes, LocalWorkerJsonDecoder::MANIFEST_MAX_SIZE);
            LocalWorkerV1Validator::validateInputManifest($inputManifestData);
        } catch (LocalWorkerProtocolException $e) {
            throw new LocalWorkerProtocolException('Sealed input manifest is invalid: ' . $e->getMessage(), 'incomplete_result', 422);
        }
        return $inputManifestData;
    }

    /** Load and validate the immutable return-scene inventory bound to this task. */
    public function sceneReturn(LocalTask $task, LocalJob $job, SubjectRun $run): array
    {
        $artifact = LocalArtifact::query()
            ->whereKey($task->input_manifest_id)
            ->where('job_id', $job->id)
            ->where('task_id', $task->id)
            ->where('purpose', 'input_manifest')
            ->where('is_quarantine', false)
            ->where('is_committed', true)
            ->lockForUpdate()
            ->first();
        if (!$artifact || !$task->input_manifest_sha256 || !Storage::disk('v3_private')->exists($artifact->storage_path)) {
            throw new LocalWorkerProtocolException('Sealed scene-return manifest is missing or unavailable.', 'incomplete_result', 422);
        }

        $bytes = Storage::disk('v3_private')->get($artifact->storage_path);
        $digest = hash('sha256', $bytes);
        if (strlen($bytes) !== (int) $artifact->size_bytes
            || !hash_equals($task->input_manifest_sha256, $digest)
            || !hash_equals($artifact->digest_sha256, $digest)) {
            throw new LocalWorkerProtocolException('Sealed scene-return manifest fingerprint is invalid.', 'artifact_mismatch', 422);
        }

        $manifest = LocalWorkerJsonDecoder::decode($bytes, LocalWorkerJsonDecoder::MANIFEST_MAX_SIZE);
        if (!is_array($manifest)) {
            throw new LocalWorkerProtocolException('Sealed scene-return manifest must be a JSON object.', 'invalid_manifest', 422);
        }
        LocalWorkerV1Validator::validateInputManifest($manifest);
        if ($manifest['task_kind'] !== 'return_scenes'
            || $manifest['task_id'] !== $task->id
            || $manifest['job_id'] !== $job->id
            || $manifest['run_id'] !== $run->id
            || $manifest['execution_mode'] !== 'local_worker_v1'
            || $manifest['output_scope'] !== 'prompts_and_scenes'
            || !is_array($manifest['required_scenes'] ?? null)
            || $manifest['required_scenes'] === []) {
            throw new LocalWorkerProtocolException('Scene-return manifest identity or required scene inventory is invalid.', 'invalid_manifest', 422);
        }

        foreach ($manifest['required_scenes'] as $index => $scene) {
            if (!is_array($scene)
                || !is_string($scene['scene_id'] ?? null)
                || !Str::isUuid($scene['scene_id'])
                || !is_string($scene['prompt_version_id'] ?? null)
                || !Str::isUuid($scene['prompt_version_id'])
                || !is_string($scene['prompt_sha256'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $scene['prompt_sha256'])) {
                throw new LocalWorkerProtocolException("Scene-return manifest binding {$index} is invalid.", 'invalid_scene_binding', 422);
            }
        }

        return $manifest;
    }
}
