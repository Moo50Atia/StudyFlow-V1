<?php

namespace App\Services\V3\WebPipeline;

use App\Models\TeacherPermission;
use App\Models\User;
use Illuminate\Support\Str;

class TerminalArchiveReader
{
    /**
     * Verify historical terminal archive and plan immutable import snapshot.
     */
    public function verifyAndPlanImport(
        string $terminalRoot,
        string $runId,
        int $subjectId,
        int $teacherId,
        int $actorAdminId,
        string $importId,
        bool $isDryRun = false
    ): array {
        // 1. Authorize actor as active admin
        $actor = User::find($actorAdminId);
        $actorActive = $actor && $this->isUserActiveV3($actor->id);
        if (! $actor || ! $actorActive || $actor->role !== 'admin') {
            return [
                'is_valid' => false,
                'safe_code' => 'authentication_required',
                'is_dry_run' => $isDryRun,
                'pdf_count' => 0,
                'capture' => null,
                'errors' => ['Actor must be a fresh, active administrator.'],
            ];
        }

        // 2. Authorize teacher as active teacher with subject permission
        $teacher = User::find($teacherId);
        $teacherActive = $teacher && $this->isUserActiveV3($teacher->id);
        if (! $teacher || ! $teacherActive || $teacher->role !== 'teacher') {
            return [
                'is_valid' => false,
                'safe_code' => 'account_mismatch',
                'is_dry_run' => $isDryRun,
                'pdf_count' => 0,
                'capture' => null,
                'errors' => ['Target user must be an active teacher.'],
            ];
        }

        $hasPermission = TeacherPermission::where('teacher_id', $teacherId)
            ->where('subject_id', $subjectId)
            ->exists();

        if (! $hasPermission) {
            return [
                'is_valid' => false,
                'safe_code' => 'account_mismatch',
                'is_dry_run' => $isDryRun,
                'pdf_count' => 0,
                'capture' => null,
                'errors' => ['Teacher lacks permission for the target subject.'],
            ];
        }

        // 3. Verify terminal root and run directory containment
        $resolvedRoot = realpath($terminalRoot);
        if (! $resolvedRoot || ! is_dir($resolvedRoot) || is_link($resolvedRoot)) {
            return [
                'is_valid' => false,
                'safe_code' => 'artifact_missing',
                'is_dry_run' => $isDryRun,
                'pdf_count' => 0,
                'capture' => null,
                'errors' => ['Terminal root does not exist or is a symlink.'],
            ];
        }

        $runDir = $resolvedRoot . '/runs/' . $runId;
        if (! is_dir($runDir) || is_link($runDir)) {
            return [
                'is_valid' => false,
                'safe_code' => 'artifact_missing',
                'is_dry_run' => $isDryRun,
                'pdf_count' => 0,
                'capture' => null,
                'errors' => ["Run directory {$runId} does not exist or is a symlink."],
            ];
        }

        // Check for symlinks in runDir
        if ($this->hasSymlinkTraversals($runDir)) {
            return [
                'is_valid' => false,
                'safe_code' => 'artifact_corrupt',
                'is_dry_run' => $isDryRun,
                'pdf_count' => 0,
                'capture' => null,
                'errors' => ['Symlink detected inside run directory hierarchy.'],
            ];
        }

        // 4. Verify subject-config.json
        $configFile = $runDir . '/subject-config.json';
        if (! file_exists($configFile) || is_link($configFile)) {
            return [
                'is_valid' => false,
                'safe_code' => 'receipt_invalid',
                'is_dry_run' => $isDryRun,
                'pdf_count' => 0,
                'capture' => null,
                'errors' => ['subject-config.json missing or invalid.'],
            ];
        }

        $rawConfig = file_get_contents($configFile);
        $subjectConfig = json_decode($rawConfig, true);
        $configRunId = $subjectConfig['run_id'] ?? $subjectConfig['subject_run_id'] ?? null;
        if (! is_array($subjectConfig) || $configRunId !== $runId) {
            return [
                'is_valid' => false,
                'safe_code' => 'receipt_invalid',
                'is_dry_run' => $isDryRun,
                'pdf_count' => 0,
                'capture' => null,
                'errors' => ['subject-config.json contains malformed JSON or mismatched run_id.'],
            ];
        }

        $pdfFiles = $subjectConfig['pdf_files'] ?? [];
        if (empty($pdfFiles) && ! empty($subjectConfig['inputs']) && is_array($subjectConfig['inputs'])) {
            foreach ($subjectConfig['inputs'] as $inp) {
                if (($inp['kind'] ?? '') === 'pdf') {
                    $pdfFiles[] = [
                        'ordinal' => $inp['ordinal'] ?? count($pdfFiles) + 1,
                        'filename' => basename($inp['original_path'] ?? "pdf_{$inp['ordinal']}.pdf"),
                        'sha256' => $inp['sha256'] ?? '',
                        'bytes' => $inp['bytes'] ?? 0,
                    ];
                }
            }
        }

        if (! is_array($pdfFiles) || empty($pdfFiles)) {
            return [
                'is_valid' => false,
                'safe_code' => 'receipt_invalid',
                'is_dry_run' => $isDryRun,
                'pdf_count' => 0,
                'capture' => null,
                'errors' => ['subject-config.json has no pdf_files listed.'],
            ];
        }

        // 5. Inspect per-PDF archives
        $pdfArchives = [];
        $filesToCopy = [[
            'source' => $configFile,
            'rel_path' => 'subject-config.json',
            'sha256' => hash('sha256', $rawConfig),
            'bytes' => strlen($rawConfig),
        ]];
        $partialReason = null;

        foreach ($pdfFiles as $pdfEntry) {
            $ordinal = $pdfEntry['ordinal'] ?? null;
            if (! $ordinal) {
                continue;
            }

            // Look for archive_pdf_{ordinal}.json, archive_{ordinal}.json, or hierarchical paths
            $archiveFile = null;
            $candidates = [
                $runDir . "/archive_pdf_{$ordinal}.json",
                $runDir . "/archive_{$ordinal}.json",
                $runDir . "/pdfs/pdf-" . sprintf("%04d", $ordinal) . "/private/archive.json",
                $runDir . "/pdfs/pdf-{$ordinal}/private/archive.json",
            ];
            foreach ($candidates as $cand) {
                if (file_exists($cand) && ! is_link($cand)) {
                    $archiveFile = $cand;
                    break;
                }
            }

            if (! $archiveFile) {
                $partialReason = "PDF ordinal {$ordinal} missing archive JSON";
                continue;
            }

            $rawArchive = file_get_contents($archiveFile);
            $parsedArchive = json_decode($rawArchive, true);
            if (! is_array($parsedArchive)) {
                $partialReason = "PDF ordinal {$ordinal} archive JSON is corrupt";
                continue;
            }

            // Support both work_count/scene_count and lesson_count/prompt_count
            $lessonCount = $parsedArchive['work_count'] ?? ($parsedArchive['lesson_count'] ?? 0);
            $promptCount = $parsedArchive['scene_count'] ?? ($parsedArchive['prompt_count'] ?? 0);
            $parsedArchive['lesson_count'] = (int) $lessonCount;
            $parsedArchive['prompt_count'] = (int) $promptCount;

            $pdfArchives[$ordinal] = $parsedArchive;
            $filesToCopy[] = [
                'source' => $archiveFile,
                'rel_path' => "archive_pdf_{$ordinal}.json",
                'sha256' => hash('sha256', $rawArchive),
                'bytes' => strlen($rawArchive),
            ];
        }

        if (empty($pdfArchives)) {
            return [
                'is_valid' => false,
                'safe_code' => 'artifact_missing',
                'is_dry_run' => $isDryRun,
                'pdf_count' => 0,
                'capture' => null,
                'errors' => ['No valid PDF archives found in run directory.'],
            ];
        }

        // 6. Inspect scene-prompts.json (supporting both top-level prompts and lessons[].scenes[])
        $scenePrompts = [];
        $exportedLessons = [];
        $promptCandidates = [
            $runDir . '/private/scene-prompt-exports/scene-prompts.json',
            $runDir . '/scene-prompt-exports/scene-prompts.json',
        ];
        foreach ($promptCandidates as $cand) {
            if (file_exists($cand) && ! is_link($cand)) {
                $rawPrompts = file_get_contents($cand);
                $parsedPrompts = json_decode($rawPrompts, true);
                if (is_array($parsedPrompts)) {
                    if (! empty($parsedPrompts['lessons']) && is_array($parsedPrompts['lessons'])) {
                        $exportedLessons = $parsedPrompts['lessons'];
                        foreach ($parsedPrompts['lessons'] as $lessonEntry) {
                            $pOrd = (int) ($lessonEntry['pdf_ordinal'] ?? 1);
                            $lOrd = (int) ($lessonEntry['lesson_ordinal'] ?? 1);
                            $lTitle = $lessonEntry['lesson_title'] ?? "Lesson {$lOrd}";
                            foreach (($lessonEntry['scenes'] ?? []) as $sc) {
                                $scenePrompts[] = array_merge($sc, [
                                    'pdf_ordinal' => $pOrd,
                                    'lesson_ordinal' => $lOrd,
                                    'lesson_title' => $lTitle,
                                ]);
                            }
                        }
                    } elseif (! empty($parsedPrompts['prompts']) && is_array($parsedPrompts['prompts'])) {
                        $scenePrompts = $parsedPrompts['prompts'];
                    }

                    $filesToCopy[] = [
                        'source' => $cand,
                        'rel_path' => 'scene-prompts.json',
                        'sha256' => hash('sha256', $rawPrompts),
                        'bytes' => strlen($rawPrompts),
                    ];
                }
                break;
            }
        }

        // 7. Inspect lesson handoffs (checking both per-PDF and root directories)
        $handoffs = [];
        $handoffDirs = [
            $runDir . '/private/lesson_handoffs',
            $runDir . '/lesson_handoffs',
        ];

        foreach (array_keys($pdfArchives) as $pOrd) {
            $handoffDirs[] = $runDir . '/pdfs/pdf-' . sprintf('%04d', $pOrd) . '/lesson_handoffs';
            $handoffDirs[] = $runDir . '/pdfs/pdf-' . $pOrd . '/lesson_handoffs';
        }

        $seenHandoffSources = [];
        foreach ($handoffDirs as $hDir) {
            if (is_dir($hDir) && ! is_link($hDir)) {
                $files = scandir($hDir);
                foreach ($files as $hf) {
                    $hfPath = $hDir . '/' . $hf;
                    if (str_ends_with($hf, '.json') && ! is_link($hfPath) && ! isset($seenHandoffSources[$hfPath])) {
                        $seenHandoffSources[$hfPath] = true;
                        $content = file_get_contents($hfPath);
                        $parsedHandoff = json_decode($content, true);
                        if (is_array($parsedHandoff)) {
                            $handoffs[] = $parsedHandoff;
                            $filesToCopy[] = [
                                'source' => $hfPath,
                                'rel_path' => 'handoffs/' . substr($hfPath, strlen($runDir) + 1),
                                'sha256' => hash('sha256', $content),
                                'bytes' => strlen($content),
                            ];
                        }
                    }
                }
            }
        }

        // 8. Compute immutable digest
        $canonicalMaterial = [
            'run_id' => $runId,
            'subject_id' => $subjectId,
            'teacher_id' => $teacherId,
            'import_id' => $importId,
            'pdf_count' => count($pdfArchives),
            'files_count' => count($filesToCopy),
            'subject_config_sha256' => hash('sha256', $rawConfig),
            'files' => array_map(static fn (array $file): array => [
                'rel_path' => $file['rel_path'],
                'sha256' => $file['sha256'],
                'bytes' => $file['bytes'],
            ], $filesToCopy),
        ];
        $digest = hash('sha256', json_encode($canonicalMaterial));

        $capture = new ImportCapture(
            runId: $runId,
            subjectId: $subjectId,
            teacherId: $teacherId,
            actorAdminId: $actorAdminId,
            importId: $importId,
            subjectConfig: $subjectConfig,
            pdfArchives: $pdfArchives,
            scenePrompts: $scenePrompts,
            handoffs: $handoffs,
            filesToCopy: $filesToCopy,
            digest: $digest,
            isDryRun: $isDryRun,
            partialReason: $partialReason,
            exportedLessons: $exportedLessons
        );

        return [
            'is_valid' => true,
            'safe_code' => 'ok',
            'is_dry_run' => $isDryRun,
            'pdf_count' => count($pdfArchives),
            'capture' => $capture,
            'errors' => [],
        ];
    }

    /**
     * Recursively verify no symlinks exist in directory.
     */
    protected function hasSymlinkTraversals(string $dir): bool
    {
        if (is_link($dir)) {
            return true;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $fullPath = $dir . '/' . $item;
            if (is_link($fullPath)) {
                return true;
            }
            if (is_dir($fullPath) && $this->hasSymlinkTraversals($fullPath)) {
                return true;
            }
        }

        return false;
    }

    protected function isUserActiveV3(int $userId): bool
    {
        $access = \Illuminate\Support\Facades\DB::table('v3_account_access')->where('user_id', $userId)->first();
        if ($access) {
            return (bool) $access->is_active;
        }

        return true;
    }
}
