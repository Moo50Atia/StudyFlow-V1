<?php

namespace App\Services\V3\Authoring;

use App\Models\V3\SceneCandidate;
use App\Services\V3\Prompts\QualifiedPromptResolver;
use App\Services\V3\Prompts\PromptUnavailableException;
use Illuminate\Support\Facades\Storage;

class SceneCandidateResolver
{
    public function __construct(
        protected QualifiedPromptResolver $promptResolver
    ) {}

    /**
     * Resolve a scene candidate for internal worker, rehashing all files and manifest.
     *
     * @param string $caseId
     * @param string $candidateRef
     * @return array
     */
    public function resolve(string $caseId, string $candidateRef): array
    {
        $candidate = SceneCandidate::where('id', $candidateRef)
            ->where('case_id', $caseId)
            ->first();

        if (! $candidate) {
            throw new AuthoringUnavailableException('Candidate not found.');
        }

        // Recheck 010 prompt current pass
        try {
            $this->promptResolver->resolve($caseId, $candidate->prompt_version_id);
        } catch (PromptUnavailableException $e) {
            throw new AuthoringUnavailableException('Prompt version is no longer current pass.');
        }

        $disk = Storage::disk('v3_private');

        // Check manifest
        if (! $disk->exists($candidate->manifest_path)) {
            throw new AuthoringUnavailableException('Candidate manifest missing.');
        }

        $manifestBytes = $disk->get($candidate->manifest_path);
        if (hash('sha256', $manifestBytes) !== $candidate->manifest_sha256) {
            throw new AuthoringUnavailableException('Candidate manifest digest mismatch.');
        }

        $manifestData = json_decode($manifestBytes, true);
        if (! is_array($manifestData) || ! isset($manifestData['files'])) {
            throw new AuthoringUnavailableException('Invalid candidate manifest.');
        }

        $filesList = [];
        $baseDir = "scene-candidates/{$caseId}/{$candidateRef}";

        foreach ($manifestData['files'] as $f) {
            $path = $f['path'];
            $expectedSha = $f['sha256'];
            $expectedBytes = $f['bytes'];

            $filePath = "{$baseDir}/{$path}";
            if (! $disk->exists($filePath)) {
                throw new AuthoringUnavailableException("Candidate file missing: {$path}");
            }

            $content = $disk->get($filePath);
            if (hash('sha256', $content) !== $expectedSha) {
                throw new AuthoringUnavailableException("Candidate file digest mismatch: {$path}");
            }

            if (strlen($content) !== $expectedBytes) {
                throw new AuthoringUnavailableException("Candidate file byte size mismatch: {$path}");
            }

            $filesList[] = [
                'path' => $path,
                'content' => $content,
            ];
        }

        return [
            'data' => [
                'candidate_ref' => $candidate->id,
                'request_ref' => $candidate->request_id,
                'section_ref' => $candidate->section_id,
                'prompt_version_ref' => $candidate->prompt_version_id,
                'qualification_ref' => $candidate->qualification_id,
                'settings_version_id' => $candidate->settings_version_id,
                'attempt_ref' => $candidate->attempt_id,
                'manifest' => $manifestData,
                'manifest_sha256' => $candidate->manifest_sha256,
                // Preserve the exact stored bytes: JSON re-encoding in Python
                // may produce a different digest than the candidate ledger.
                'manifest_raw_base64' => base64_encode($manifestBytes),
                'files' => $filesList,
            ],
        ];
    }
}
