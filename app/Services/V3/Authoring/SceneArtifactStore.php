<?php

namespace App\Services\V3\Authoring;

use App\Models\V3\AuthoringRequest;
use App\Models\V3\CodingAttempt;
use Illuminate\Support\Facades\Storage;

class SceneArtifactStore
{
    public const ALLOWED_EXTENSIONS = ['html', 'js', 'css', 'svg', 'json'];

    /**
     * Validate package according to ScenePackageV1 rules.
     * Throws AuthoringValidationException if invalid.
     */
    public function validatePackage(array $package): void
    {
        $schemaVersion = $package['schema_version'] ?? null;
        if ($schemaVersion !== 1) {
            throw new AuthoringValidationException('Package schema_version must be 1.');
        }

        $files = $package['files'] ?? null;
        if (! is_array($files) || ! array_is_list($files)) {
            throw new AuthoringValidationException('Package files must be a list.');
        }

        $count = count($files);
        if ($count < 1 || $count > 32) {
            throw new AuthoringValidationException("Package files count must be between 1 and 32, got {$count}.");
        }

        $hasIndex = false;
        $totalBytes = 0;
        $seenPathsExact = [];
        $seenPathsFolded = [];

        foreach ($files as $file) {
            if (! is_array($file) || ! isset($file['path'], $file['content'])) {
                throw new AuthoringValidationException('Each file must have path and content.');
            }

            $path = $file['path'];
            $content = $file['content'];

            if (! is_string($path) || ! is_string($content)) {
                throw new AuthoringValidationException('Path and content must be strings.');
            }

            $pathBytes = strlen($path);
            if ($pathBytes < 1 || $pathBytes > 160) {
                throw new AuthoringValidationException('Path must be 1-160 bytes.');
            }

            // Path segment validation: 1-4 segments, no backslash, no colon, no empty, no dot, no ..
            if (str_contains($path, '\\') || str_contains($path, ':') || str_starts_with($path, '/') || str_starts_with($path, '.')) {
                throw new AuthoringValidationException("Invalid path characters or absolute path: {$path}");
            }

            $segments = explode('/', $path);
            if (count($segments) < 1 || count($segments) > 4) {
                throw new AuthoringValidationException("Path must have 1-4 segments: {$path}");
            }

            foreach ($segments as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..' || ! preg_match('/^[A-Za-z0-9._-]+$/', $segment)) {
                    throw new AuthoringValidationException("Invalid path segment: '{$segment}' in {$path}");
                }
            }

            // Allowed extension
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                throw new AuthoringValidationException("Extension '.{$ext}' is not allowed.");
            }

            // Uniqueness (case-sensitive and case-folded)
            if (in_array($path, $seenPathsExact, true)) {
                throw new AuthoringValidationException("Duplicate file path: {$path}");
            }
            $folded = strtolower($path);
            if (in_array($folded, $seenPathsFolded, true)) {
                throw new AuthoringValidationException("Case-folded duplicate file path: {$path}");
            }
            $seenPathsExact[] = $path;
            $seenPathsFolded[] = $folded;

            // Content UTF-8 and size
            if (! mb_check_encoding($content, 'UTF-8')) {
                throw new AuthoringValidationException("File content for '{$path}' is not valid UTF-8.");
            }

            $contentBytes = strlen($content);
            if ($contentBytes < 1 || $contentBytes > 262144) {
                throw new AuthoringValidationException("File '{$path}' size ({$contentBytes} bytes) must be between 1 and 262144 bytes.");
            }

            $totalBytes += $contentBytes;

            if ($path === 'index.html') {
                $hasIndex = true;
                $trimmed = ltrim($content);
                $isHtml = str_starts_with(strtolower($trimmed), '<!doctype html') || str_starts_with(strtolower($trimmed), '<html');
                if (! $isHtml) {
                    throw new AuthoringValidationException("index.html must start with HTML doctype or <html tag.");
                }
            }
        }

        if (! $hasIndex) {
            throw new AuthoringValidationException("Package must contain index.html.");
        }

        if ($totalBytes > 1048576) {
            throw new AuthoringValidationException("Total decoded content bytes ({$totalBytes}) exceeds 1048576 bytes (1MB).");
        }
    }

    /**
     * Write package and manifest to staging and atomically rename to final candidate directory.
     *
     * @return array{manifest_path: string, manifest_sha256: string, file_count: int, total_bytes: int}
     */
    public function stageAndCommit(
        string $caseId,
        string $candidateRef,
        AuthoringRequest $request,
        CodingAttempt $attempt,
        array $package,
        ?string $returnedModel
    ): array {
        $disk = Storage::disk('v3_private');
        $root = config('filesystems.disks.v3_private.root');

        $stagingRelative = "scene-candidates/.staging/{$caseId}/{$attempt->id}";
        $finalRelative = "scene-candidates/{$caseId}/{$candidateRef}";

        // Write files to staging
        $manifestFiles = [];
        $totalBytes = 0;

        // Sort files by path
        $files = $package['files'];
        usort($files, fn ($a, $b) => strcmp($a['path'], $b['path']));

        foreach ($files as $f) {
            $path = $f['path'];
            $content = $f['content'];
            $bytes = strlen($content);
            $sha = hash('sha256', $content);

            $filePath = "{$stagingRelative}/{$path}";
            $disk->put($filePath, $content);

            // Chmod 0600 if on local filesystem
            if ($root) {
                $fullPath = "{$root}/{$filePath}";
                if (file_exists($fullPath)) {
                    @chmod($fullPath, 0600);
                }
            }

            $manifestFiles[] = [
                'path' => $path,
                'bytes' => $bytes,
                'sha256' => $sha,
            ];
            $totalBytes += $bytes;
        }

        $manifestData = [
            'schema_version' => 1,
            'candidate_ref' => $candidateRef,
            'request_ref' => $request->id,
            'case_id' => $caseId,
            'section_ref' => $request->section_id,
            'prompt_version_ref' => $request->prompt_version_id,
            'prompt_body_sha256' => $request->prompt_body_sha256,
            'qualification_ref' => $request->qualification_id,
            'settings_version_id' => $request->settings_version_id,
            'settings_checksum' => $request->settings_checksum,
            'template_id' => $request->template_id,
            'template_sha256' => $request->template_sha256,
            'attempt_ref' => $attempt->id,
            'provider_id' => $attempt->provider_id,
            'requested_model' => $attempt->requested_model,
            'returned_model' => $returnedModel,
            'entrypoint' => 'index.html',
            'files' => $manifestFiles,
            'created_at' => now()->toIso8601String(),
        ];

        $manifestJson = json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $manifestSha = hash('sha256', $manifestJson);

        $manifestStagingPath = "{$stagingRelative}/manifest.json";
        $disk->put($manifestStagingPath, $manifestJson);

        if ($root) {
            $fullManifest = "{$root}/{$manifestStagingPath}";
            if (file_exists($fullManifest)) {
                @chmod($fullManifest, 0600);
            }
        }

        // Atomic rename from staging to final directory
        if ($root) {
            $stagingFull = "{$root}/{$stagingRelative}";
            $finalFull = "{$root}/{$finalRelative}";

            if (file_exists($finalFull)) {
                throw new AuthoringConflictException("Final candidate directory already exists: {$finalRelative}");
            }

            @mkdir(dirname($finalFull), 0755, true);
            if (! @rename($stagingFull, $finalFull)) {
                // If rename fails, try copy/delete
                $this->copyDirectory($disk, $stagingRelative, $finalRelative);
                $disk->deleteDirectory($stagingRelative);
            }
        } else {
            // In-memory or virtual disk
            $this->copyDirectory($disk, $stagingRelative, $finalRelative);
            $disk->deleteDirectory($stagingRelative);
        }

        return [
            'manifest_path' => "{$finalRelative}/manifest.json",
            'manifest_sha256' => $manifestSha,
            'file_count' => count($manifestFiles),
            'total_bytes' => $totalBytes,
            'manifest_data' => $manifestData,
        ];
    }

    private function copyDirectory($disk, string $src, string $dst): void
    {
        $files = $disk->allFiles($src);
        foreach ($files as $file) {
            $subPath = substr($file, strlen($src) + 1);
            $target = "{$dst}/{$subPath}";
            $disk->put($target, $disk->get($file));
        }
    }
}
