<?php

namespace App\Services\V3\Prompts;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PromptOrphanCleaner
{
    protected string $diskName = 'v3_private';

    /**
     * Purge orphaned prompt files older than 24 hours.
     * Never delete referenced or uncertain artifacts.
     */
    public function purgeOrphans(int $maxAgeSeconds = 86400): int
    {
        $disk = Storage::disk($this->diskName);
        $purged = 0;
        $now = time();

        $scanRoots = ['prompt-turns', 'prompt-versions', 'prompt-feedback'];

        // Get all uncertain turn answer paths and answer SHAs
        $uncertainTurns = DB::table('v3_prompt_turns')
            ->where('state', 'uncertain')
            ->get(['answer_path', 'answer_sha256']);

        $uncertainPaths = [];
        $uncertainShas = [];
        foreach ($uncertainTurns as $ut) {
            if ($ut->answer_path) {
                $uncertainPaths[$ut->answer_path] = true;
            }
            if ($ut->answer_sha256) {
                $uncertainShas[$ut->answer_sha256] = true;
            }
        }

        // Get all uncertain feedback effect response paths and SHAs
        $uncertainEffects = DB::table('v3_prompt_feedback_effects')
            ->where('state', 'uncertain')
            ->get(['response_path', 'response_sha256']);

        foreach ($uncertainEffects as $ue) {
            if ($ue->response_path) {
                $uncertainPaths[$ue->response_path] = true;
            }
            if ($ue->response_sha256) {
                $uncertainShas[$ue->response_sha256] = true;
            }
        }

        foreach ($scanRoots as $root) {
            if (! $disk->exists($root)) {
                continue;
            }

            $caseDirs = $disk->directories($root);
            foreach ($caseDirs as $caseDir) {
                $files = $disk->allFiles($caseDir);
                foreach ($files as $file) {
                    $lastModified = $disk->lastModified($file);
                    if (($now - $lastModified) <= $maxAgeSeconds) {
                        // Younger than retention threshold: keep
                        continue;
                    }

                    // Check if file itself is marked uncertain or matches uncertain SHA
                    $basename = pathinfo($file, PATHINFO_FILENAME);
                    if (isset($uncertainPaths[$file]) || isset($uncertainShas[$basename])) {
                        continue;
                    }

                    // Check if file is referenced
                    $isReferenced = DB::table('v3_scene_prompt_versions')->where('body_path', $file)->exists()
                        || DB::table('v3_prompt_turns')->where('answer_path', $file)->exists()
                        || DB::table('v3_prompt_feedback_effects')->where('response_path', $file)->exists();

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
