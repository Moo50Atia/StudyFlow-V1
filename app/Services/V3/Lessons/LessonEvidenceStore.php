<?php

namespace App\Services\V3\Lessons;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LessonEvidenceStore
{
    protected string $diskName = 'v3_private';
    public const MAX_ANSWER_BYTES = 4404019; // 4.2 MiB

    /**
     * Store raw answer to private disk atomically and return relative path.
     */
    public function putAnswer(string $caseId, string $queryId, string $rawAnswer, string $sha256): string
    {
        if (strlen($rawAnswer) > self::MAX_ANSWER_BYTES) {
            throw new \InvalidArgumentException("Answer exceeds maximum byte cap: " . strlen($rawAnswer) . " > " . self::MAX_ANSWER_BYTES);
        }

        $disk = Storage::disk($this->diskName);
        $dir = "lesson-answers/{$caseId}/{$queryId}";
        $path = "{$dir}/{$sha256}.txt";

        if ($disk->exists($path)) {
            $existing = $disk->get($path);
            if (hash('sha256', $existing) === $sha256) {
                return $path;
            }
        }

        // Concurrent duplicate acceptances can reach this content-addressed
        // path together. Treat a losing mkdir as success only when the full
        // directory now exists; propagate all other storage failures.
        if (! $disk->directoryExists($dir)) {
            try {
                $disk->makeDirectory($dir);
            } catch (\Throwable $e) {
                if (! $disk->directoryExists($dir)) {
                    throw $e;
                }
            }
        }
        $tempPath = "{$dir}/.tmp_" . bin2hex(random_bytes(8)) . ".txt";
        $disk->put($tempPath, $rawAnswer);

        if ($disk->exists($path)) {
            $disk->delete($path);
        }
        $disk->move($tempPath, $path);

        return $path;
    }

    /**
     * Read raw answer from private disk and verify SHA-256.
     *
     * @throws \RuntimeException if file missing or corrupted
     */
    public function getAnswer(string $answerPath, string $expectedSha256): string
    {
        $disk = Storage::disk($this->diskName);
        if (! $disk->exists($answerPath)) {
            throw new \RuntimeException("Answer file missing: {$answerPath}");
        }

        $content = $disk->get($answerPath);
        $actualSha = hash('sha256', $content);
        if ($actualSha !== $expectedSha256) {
            throw new \RuntimeException("Answer checksum mismatch for {$answerPath}");
        }

        return $content;
    }

    /**
     * Purge orphaned answer files older than 24 hours.
     */
    public function purgeOrphans(int $maxAgeSeconds = 86400): int
    {
        $disk = Storage::disk($this->diskName);
        $purged = 0;
        $now = time();

        $caseDirectories = $disk->directories('lesson-answers');
        foreach ($caseDirectories as $caseDir) {
            $queryDirectories = $disk->directories($caseDir);
            foreach ($queryDirectories as $queryDir) {
                $files = $disk->files($queryDir);
                foreach ($files as $file) {
                    $lastModified = $disk->lastModified($file);
                    if (($now - $lastModified) > $maxAgeSeconds) {
                        $isReferenced = DB::table('v3_lesson_queries')
                            ->where('answer_path', $file)
                            ->exists();

                        if (! $isReferenced) {
                            $disk->delete($file);
                            $purged++;
                        }
                    }
                }
            }
        }

        return $purged;
    }
}
