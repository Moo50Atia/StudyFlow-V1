<?php

namespace App\Services\V3\Authoring;

use Illuminate\Support\Facades\Storage;

class StoreSceneArtifacts
{
    /**
     * Store static three-file scene package artifacts and manifest.json to private storage.
     *
     * @param array<int, array{path: string, mime: string, content: string, bytes: int, sha256: string}> $validatedFiles
     * @return array{
     *     manifest_path: string,
     *     manifest_sha256: string,
     *     file_count: int,
     *     total_bytes: int,
     *     source_ref: string
     * }
     */
    public function store(
        string $caseId,
        string $sectionId,
        string $candidateId,
        string $promptVersionId,
        string $promptSha256,
        string $origin,
        string $provenanceRef,
        string $sourceManifestSha256,
        array $validatedFiles,
        int $totalBytes,
        string $profile = 'static_three_file_v1'
    ): array {
        $disk = Storage::disk('v3_private');
        $baseDir = "scene-artifacts/{$caseId}/{$sectionId}/{$candidateId}";

        $manifestFiles = [];
        foreach ($validatedFiles as $file) {
            $filePath = "{$baseDir}/{$file['path']}";
            $disk->put($filePath, $file['content']);

            $manifestFiles[] = [
                'path' => $file['path'],
                'mime' => $file['mime'],
                'bytes' => $file['bytes'],
                'sha256' => $file['sha256'],
            ];
        }

        $manifestDoc = [
            'schema_version' => 2,
            'profile' => $profile,
            'case_id' => $caseId,
            'section_id' => $sectionId,
            'prompt_version_id' => $promptVersionId,
            'prompt_sha256' => $promptSha256,
            'origin' => $origin,
            'provenance_ref' => $provenanceRef,
            'source_manifest_sha256' => $sourceManifestSha256,
            'files' => $manifestFiles,
            'entrypoint' => 'index.html',
            'created_at' => now()->toIso8601String(),
        ];

        $manifestJson = json_encode($manifestDoc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $manifestPath = "{$baseDir}/manifest.json";
        $disk->put($manifestPath, $manifestJson);

        return [
            'manifest_path' => $manifestPath,
            'manifest_sha256' => hash('sha256', $manifestJson),
            'file_count' => count($manifestFiles),
            'total_bytes' => $totalBytes,
            'source_ref' => $baseDir,
        ];
    }
}
