<?php

namespace App\Services\V3\NotebookLM;

use App\Models\V3\NotebookFrameworkVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class FrameworkCatalog
{
    public const RESPONSE_FORMAT_TEXT = "RESPONSE FORMAT combined-envelope-v1\n"
        ."The uploaded educational PDF supplies facts. steps.txt supplies the First, Second and Third framework. Preserve that framework unchanged.\n"
        ."For the dividing request, return only its structural map.\n"
        ."For the execution acknowledgement, return only System Ready.\n"
        ."For Start with First lesson and each literal Continue, return all three output artifacts for exactly the current mapped work item, without reading ahead. X is its one-based depth-first work ordinal; a Mini Chapter without Lessons counts as one work item.\n"
        ."Use these exact standalone delimiters in this order:\n"
        ."=== FIRST ===\n"
        ."The complete First output, including Lecture and every Section with its eleven fields.\n"
        ."=== SECOND ===\n"
        ."The complete Second output, including Lecture and each LecX (SecY) placeholder and Prepared Perspective Map.\n"
        ."=== THIRD ===\n"
        ."The complete Third output, including Lecture and one quoted final prompt per LecX (SecY).\n"
        ."=== END ===\n"
        ."Finish with exactly: ✅ Lesson [X] Completed. Awaiting 'Continue' for the next lesson.\n"
        ."Do not include these delimiter lines inside an artifact. Return no other text outside the three artifacts and final completion marker.";

    public const RESPONSE_FORMAT_SHA256 = 'b7c49fb0ef3b62264c5b81d5673ace865e9d497ee1100f2e22675abbc614e105';

    /**
     * Get the active framework version referenced by the pointer.
     */
    public function getActiveFramework(): ?NotebookFrameworkVersion
    {
        $pointer = DB::table('v3_notebook_framework_pointer')->where('id', 1)->first();
        if (! $pointer || ! $pointer->version_id) {
            return null;
        }

        return NotebookFrameworkVersion::find($pointer->version_id);
    }

    /**
     * Import and approve a notebook framework file with verified sha256 and active admin actor.
     */
    public function importFramework(string $filePath, string $expectedSha256, int $adminUserId, string $profile = 'expanded-root-v1'): NotebookFrameworkVersion
    {
        // 1. Verify admin actor
        $user = DB::table('users')->where('id', $adminUserId)->first();
        $access = DB::table('v3_account_access')->where('user_id', $adminUserId)->first();

        if (! $user || $user->role !== 'admin' || ! $access || ! (bool) $access->is_active) {
            throw new InvalidArgumentException('Approval actor must be an active V3 admin.');
        }

        // 2. Verify file existence
        if (! file_exists($filePath) || ! is_readable($filePath)) {
            throw new InvalidArgumentException("Framework file at '{$filePath}' does not exist or is unreadable.");
        }

        $bytes = file_get_contents($filePath);
        if ($bytes === false) {
            throw new RuntimeException("Failed to read framework file at '{$filePath}'.");
        }

        // 3. Verify sha256
        $actualSha256 = hash('sha256', $bytes);
        if (strtolower($actualSha256) !== strtolower($expectedSha256)) {
            throw new InvalidArgumentException("Framework checksum mismatch. Expected {$expectedSha256}, got {$actualSha256}.");
        }

        // 4. Duplicate check: if identical sha256 exists, return it and ensure pointer is set
        $existing = NotebookFrameworkVersion::where('body_sha256', $actualSha256)->first();
        if ($existing) {
            DB::table('v3_notebook_framework_pointer')->updateOrInsert(
                ['id' => 1],
                ['version_id' => $existing->id, 'updated_at' => now()]
            );

            return $existing;
        }

        // 5. Partition slices by exact headings
        $firstIdx = strpos($bytes, 'FIRST —');
        $secondIdx = strpos($bytes, 'SECOND —');
        $thirdIdx = strpos($bytes, 'THIRD —');

        if ($firstIdx === false || $secondIdx === false || $thirdIdx === false || ! ($firstIdx < $secondIdx && $secondIdx < $thirdIdx)) {
            throw new InvalidArgumentException('Framework file does not contain valid FIRST —, SECOND —, and THIRD — heading sections.');
        }

        $firstSlice = substr($bytes, $firstIdx, $secondIdx - $firstIdx);
        $secondSlice = substr($bytes, $secondIdx, $thirdIdx - $secondIdx);
        $thirdSlice = substr($bytes, $thirdIdx);

        $firstSha = hash('sha256', $firstSlice);
        $secondSha = hash('sha256', $secondSlice);
        $thirdSha = hash('sha256', $thirdSlice);

        // 6. Write private storage artifact
        $storagePath = "frameworks/{$actualSha256}.txt";
        $disk = Storage::disk('v3_private');
        $disk->put($storagePath, $bytes);

        // 7. Atomic DB insertion and pointer update
        $versionId = (string) Str::uuid();

        try {
            return DB::transaction(function () use (
                $versionId,
                $profile,
                $storagePath,
                $actualSha256,
                $bytes,
                $firstSha,
                $secondSha,
                $thirdSha,
                $adminUserId
            ) {
                $version = NotebookFrameworkVersion::forceCreate([
                    'id' => $versionId,
                    'profile' => $profile,
                    'body_path' => $storagePath,
                    'body_sha256' => $actualSha256,
                    'body_bytes' => strlen($bytes),
                    'first_sha256' => $firstSha,
                    'second_sha256' => $secondSha,
                    'third_sha256' => $thirdSha,
                    'response_format_sha256' => self::RESPONSE_FORMAT_SHA256,
                    'approved_by_user_id' => $adminUserId,
                    'approved_at' => now(),
                    'created_at' => now(),
                ]);

                DB::table('v3_notebook_framework_pointer')->updateOrInsert(
                    ['id' => 1],
                    ['version_id' => $versionId, 'updated_at' => now()]
                );

                return $version;
            });
        } catch (\Throwable $e) {
            // Rollback stored file if insertion failed
            if ($disk->exists($storagePath)) {
                $disk->delete($storagePath);
            }
            throw $e;
        }
    }
}
