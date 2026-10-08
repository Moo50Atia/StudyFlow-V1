<?php

namespace App\Services\V3\Source;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SourceArtifactStore
{
    protected string $diskName = 'v3_private';

    /**
     * Store canonical text to disk atomically and return relative path.
     */
    public function putArtifact(string $caseId, string $canonicalText, string $sha256): string
    {
        $disk = Storage::disk($this->diskName);
        $dir = "extracted/{$caseId}";
        $path = "{$dir}/{$sha256}.txt";

        if ($disk->exists($path)) {
            $existing = $disk->get($path);
            if (hash('sha256', $existing) === $sha256) {
                return $path;
            }
        }

        // Write atomically
        $disk->makeDirectory($dir);
        $tempPath = "{$dir}/.tmp_" . bin2hex(random_bytes(8)) . ".txt";
        $disk->put($tempPath, $canonicalText);

        // Move to final path
        if ($disk->exists($path)) {
            $disk->delete($path);
        }
        $disk->move($tempPath, $path);

        return $path;
    }

    /**
     * Read canonical text from disk and verify SHA-256.
     *
     * @throws \RuntimeException if file missing or corrupted
     */
    public function getArtifact(string $artifactPath, string $expectedSha256): string
    {
        $disk = Storage::disk($this->diskName);
        if (! $disk->exists($artifactPath)) {
            throw new \RuntimeException("Artifact file missing: {$artifactPath}");
        }

        $content = $disk->get($artifactPath);
        $actualSha = hash('sha256', $content);
        if ($actualSha !== $expectedSha256) {
            throw new \RuntimeException("Artifact checksum mismatch for {$artifactPath}");
        }

        return $content;
    }

    /**
     * Purge orphaned extraction files older than 24 hours.
     */
    public function purgeOrphans(int $maxAgeSeconds = 86400): int
    {
        $disk = Storage::disk($this->diskName);
        $purged = 0;
        $now = time();

        $directories = $disk->directories('extracted');
        foreach ($directories as $caseDir) {
            $files = $disk->files($caseDir);
            foreach ($files as $file) {
                // If temporary file or unreferenced file older than maxAgeSeconds
                $lastModified = $disk->lastModified($file);
                if (($now - $lastModified) > $maxAgeSeconds) {
                    $isReferenced = DB::table('v3_extracted_sources')
                        ->where('artifact_path', $file)
                        ->exists();

                    if (! $isReferenced) {
                        $disk->delete($file);
                        $purged++;
                    }
                }
            }
        }

        return $purged;
    }
}
