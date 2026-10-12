<?php

namespace App\Services\V3\WebPipeline;

use App\Models\Lecture;
use App\Models\Section;
use App\Models\V3\LecturePipelineLink;
use App\Models\V3\NotebookInstance;
use App\Models\V3\SectionPipelineLink;
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use App\Models\V3\TerminalImport;
use App\Models\V3\TerminalImportItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDOException;

class MaterializeTerminalImport
{
    public function __construct(
        protected RunEventStore $eventStore,
        protected ProjectLessonBundle $bundleProjector
    ) {}

    /**
     * Materialize a verified historical terminal archive import into application database.
     *
     * @return array{terminal_import: ?TerminalImport, subject_run: ?SubjectRun, status: string, is_dry_run: bool}
     */
    public function materialize(ImportCapture $capture): array
    {
        if ($capture->isDryRun) {
            return [
                'terminal_import' => null,
                'subject_run' => null,
                'status' => 'dry_run_validated',
                'is_dry_run' => true,
            ];
        }

        // Deduplication & Conflict check
        $existingImport = TerminalImport::find($capture->importId);
        if ($existingImport) {
            if ($existingImport->archive_sha256 === $capture->digest) {
                return [
                    'terminal_import' => $existingImport,
                    'subject_run' => SubjectRun::find($capture->runId),
                    'status' => 'duplicate',
                    'is_dry_run' => false,
                ];
            }

            throw new CommandConflictException(
                "Import {$capture->importId} already exists with different digest",
                409,
                'import_conflict'
            );
        }

        $this->retainVerifiedArchiveEvidence($capture);

        $attempts = 0;
        $maxAttempts = 4;
        $backoffs = [50000, 100000, 200000];

        while ($attempts < $maxAttempts) {
            try {
                return DB::transaction(function () use ($capture) {
                    // 1. Resolve settings and framework versions
                    $settingsVersionId = DB::table('v3_settings_versions')->max('id');
                    if (! $settingsVersionId) {
                        $settingsVersionId = DB::table('v3_settings_versions')->insertGetId([
                            'version' => 1,
                            'schema_version' => 1,
                            'payload' => json_encode(['import' => true]),
                            'checksum' => hash('sha256', 'import'),
                            'published_by_user_id' => $capture->actorAdminId,
                            'published_at' => now(),
                        ]);
                    }

                    $frameworkId = DB::table('v3_notebook_framework_versions')->value('id');
                    if (! $frameworkId) {
                        throw new CommandConflictException(
                            'An approved framework version is required for the destination.',
                            409,
                            'framework_unavailable'
                        );
                    }

                    // 2. Create SubjectRun
                    $totalLessons = 0;
                    $totalPrompts = count($capture->scenePrompts);
                    foreach ($capture->pdfArchives as $arch) {
                        $totalLessons += (int) ($arch['lesson_count'] ?? 0);
                    }

                    $run = SubjectRun::whereKey($capture->runId)->lockForUpdate()->first();
                    if ($run) {
                        // Queued imports need a lightweight run row so the
                        // outbox can retain its foreign key. Reuse that row
                        // after confirming its immutable import lineage.
                        $expectedCaptureHash = hash('sha256', json_encode($capture->subjectConfig));
                        if ($run->origin !== 'historical_import'
                            || (int) $run->owner_user_id !== $capture->teacherId
                            || (int) $run->subject_id !== $capture->subjectId
                            || $run->protocol !== 'notebooklm_per_pdf_web_v1'
                            || $run->request_sha256 !== $capture->digest
                            || $run->capture_sha256 !== $expectedCaptureHash
                            || ! in_array($run->state, ['queued', 'running'], true)) {
                            throw new CommandConflictException(
                                "Destination run {$capture->runId} conflicts with the captured import.",
                                409,
                                'import_conflict'
                            );
                        }
                        $run->update([
                            'state' => 'completed',
                            'state_version' => $run->state_version + 1,
                            'generation_phase' => 'prompts_exported',
                            'total_pdfs' => count($capture->pdfArchives),
                            'total_lessons' => $totalLessons,
                            'total_scenes' => $totalPrompts,
                            'finished_at' => now(),
                        ]);
                    } else {
                        $run = SubjectRun::create([
                            'id' => $capture->runId,
                            'origin' => 'historical_import',
                            'owner_user_id' => $capture->teacherId,
                            'subject_id' => $capture->subjectId,
                            'protocol' => 'notebooklm_per_pdf_web_v1',
                            'settings_version_id' => $settingsVersionId,
                            'framework_version_id' => $frameworkId,
                            'mode' => 'export_manual',
                            'qualification_mode' => 'owner_waived_demo',
                            'captured_settings_json' => $capture->subjectConfig,
                            'capture_sha256' => hash('sha256', json_encode($capture->subjectConfig)),
                            'submission_key' => (string) Str::uuid(),
                            'request_sha256' => $capture->digest,
                            'state' => 'completed',
                            'state_version' => 1,
                            'generation_phase' => 'prompts_exported',
                            'total_pdfs' => count($capture->pdfArchives),
                            'total_lessons' => $totalLessons,
                            'total_scenes' => $totalPrompts,
                            'finished_at' => now(),
                        ]);
                    }

                    // 3. Create NotebookInstance
                    NotebookInstance::create([
                        'id' => (string) Str::uuid(),
                        'run_id' => $capture->runId,
                        'owner_user_id' => $capture->teacherId,
                        'adapter_kind' => 'historical_import',
                        'provider_notebook_id' => 'imported-' . substr($capture->runId, 0, 8),
                        'state' => 'archived',
                    ]);

                    // 4. Create TerminalImport
                    $import = TerminalImport::create([
                        'id' => $capture->importId,
                        'actor_admin_id' => $capture->actorAdminId,
                        'owner_user_id' => $capture->teacherId,
                        'subject_id' => $capture->subjectId,
                        'source_run_id' => $capture->runId,
                        'archive_sha256' => $capture->digest,
                        'destination_run_id' => $capture->runId,
                        'request_sha256' => $capture->digest,
                        'state' => $capture->isPartial() ? 'partial' : 'completed',
                        'committed_item_count' => count($capture->pdfArchives),
                    ]);

                    // 5. Materialize per-PDF items and canonical lesson/section/prompt graph
                    $lessonsByPdf = [];
                    if (! empty($capture->exportedLessons)) {
                        foreach ($capture->exportedLessons as $lEntry) {
                            $pOrd = (int) ($lEntry['pdf_ordinal'] ?? 1);
                            $lessonsByPdf[$pOrd][] = $lEntry;
                        }
                    } else {
                        // Fallback grouping from scenePrompts
                        $grouped = [];
                        foreach ($capture->scenePrompts as $sp) {
                            $pOrd = (int) ($sp['pdf_ordinal'] ?? 1);
                            $lOrd = (int) ($sp['lesson_ordinal'] ?? 1);
                            $grouped[$pOrd][$lOrd]['pdf_ordinal'] = $pOrd;
                            $grouped[$pOrd][$lOrd]['lesson_ordinal'] = $lOrd;
                            $grouped[$pOrd][$lOrd]['lesson_title'] = $sp['lesson_title'] ?? "Lesson {$lOrd}";
                            $grouped[$pOrd][$lOrd]['scenes'][] = $sp;
                        }
                        foreach ($grouped as $pOrd => $lList) {
                            $lessonsByPdf[$pOrd] = array_values($lList);
                        }
                    }

                    // Index handoffs by pdf_ordinal and lesson_ordinal
                    $handoffsByKey = [];
                    foreach ($capture->handoffs as $hf) {
                        $pOrd = (int) ($hf['pdf_ordinal'] ?? ($hf['pdf']['ordinal'] ?? 1));
                        $lOrd = (int) ($hf['lesson_ordinal'] ?? 1);
                        $handoffsByKey["{$pOrd}:{$lOrd}"] = $hf;
                        // Real terminal archives may retain per-PDF handoffs
                        // without a top-level scene-prompts export.
                        if (empty($lessonsByPdf[$pOrd])) {
                            $lessonsByPdf[$pOrd] = [];
                        }
                        if (! collect($lessonsByPdf[$pOrd])->contains(fn ($l) => (int) ($l['lesson_ordinal'] ?? 0) === $lOrd)) {
                            $lessonsByPdf[$pOrd][] = [
                                'pdf_ordinal' => $pOrd,
                                'lesson_ordinal' => $lOrd,
                                'lesson_title' => $hf['mapped_title'] ?? "Lesson {$lOrd}",
                                'scenes' => array_map(fn ($scene) => [
                                    'scene_ordinal' => $scene['scene_ordinal'] ?? 1,
                                    'scene_title' => $scene['scene_title'] ?? ($hf['mapped_title'] ?? "Scene {$lOrd}"),
                                    'scene_type' => $scene['scene_type'] ?? 'Interactive Visualization',
                                    'prompt' => $scene['generated_prompt'] ?? ($scene['prompt'] ?? ''),
                                ], $hf['scenes'] ?? []),
                            ];
                        }
                    }

                    $totalCommittedLessons = 0;
                    $totalCommittedPrompts = 0;

                    foreach ($capture->pdfArchives as $ordinal => $pdfData) {
                        $ordinalInt = (int) $ordinal;
                        $filename = $pdfData['filename'] ?? ($pdfData['original_filename'] ?? "PDF_{$ordinalInt}.pdf");
                        $title = pathinfo($filename, PATHINFO_FILENAME);

                        // 5a. Create Lecture
                        $lecture = Lecture::create([
                            'subject_id' => $capture->subjectId,
                            'title' => ucwords(str_replace(['_', '-'], ' ', $title)),
                            'description' => "Historical imported content for {$filename}",
                        ]);

                        $pdfLessons = $lessonsByPdf[$ordinalInt] ?? [];
                        $pdfLessonCount = count($pdfLessons) > 0 ? count($pdfLessons) : (int) ($pdfData['lesson_count'] ?? 0);
                        $pdfPromptCount = 0;
                        foreach ($pdfLessons as $pl) {
                            $pdfPromptCount += count($pl['scenes'] ?? []);
                        }
                        if ($pdfPromptCount === 0) {
                            $pdfPromptCount = (int) ($pdfData['prompt_count'] ?? 0);
                        }

                        // 5b. Create SubjectRunItem
                        $runItem = SubjectRunItem::create([
                            'id' => (string) Str::uuid(),
                            'run_id' => $capture->runId,
                            'owner_user_id' => $capture->teacherId,
                            'pdf_ordinal' => $ordinalInt,
                            'display_title' => $filename,
                            'original_filename' => $filename,
                            'input_sha256' => $pdfData['pdf_sha256'] ?? ($pdfData['input_sha256'] ?? hash('sha256', "pdf_{$ordinalInt}")),
                            'lecture_id' => $lecture->id,
                            'state' => 'completed',
                            'saved_lesson_count' => $pdfLessonCount,
                            'prompt_count' => $pdfPromptCount,
                            'state_version' => 1,
                        ]);

                        // 5c. Create LecturePipelineLink
                        LecturePipelineLink::create([
                            'lecture_id' => $lecture->id,
                            'item_id' => $runItem->id,
                            'owner_user_id' => $capture->teacherId,
                            'protocol' => 'notebooklm_per_pdf_web_v1',
                        ]);

                        // 5d. Create TerminalImportItem
                        TerminalImportItem::create([
                            'import_id' => $capture->importId,
                            'pdf_ordinal' => $ordinalInt,
                            'request_sha256' => hash('sha256', "item_{$ordinalInt}_{$capture->importId}"),
                            'destination_item_id' => $runItem->id,
                            'committed_at' => now(),
                        ]);

                        // 5e. Create ProcessingCase
                        $caseId = (string) Str::uuid();
                        DB::table('v3_processing_cases')->insert([
                            'id' => $caseId,
                            'owner_user_id' => $capture->teacherId,
                            'settings_version_id' => $settingsVersionId,
                            'idempotency_key' => (string) Str::uuid(),
                            'action' => 'process',
                            'subject_run_item_id' => $runItem->id,
                            'state' => 'completed',
                            'state_version' => 1,
                            'source_origin' => 'terminal_import',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $runItem->update(['case_id' => $caseId]);

                        // 5f. Create LessonMap
                        $mapId = (string) Str::uuid();
                        DB::table('v3_lesson_maps')->insert([
                            'id' => $mapId,
                            'case_id' => $caseId,
                            'state' => 'completed',
                            'work_count' => $pdfLessonCount,
                            'page_count' => 0,
                            'source_origin' => 'terminal_import',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        // 5g. Materialize each Lesson and its Sections and Prompts
                        foreach ($pdfLessons as $lessonIdx => $lessonData) {
                            $lOrd = (int) ($lessonData['lesson_ordinal'] ?? ($lessonIdx + 1));
                            $lTitle = $lessonData['lesson_title'] ?? "Lesson {$lOrd}";

                            // Create LessonNode
                            $nodeId = (string) Str::uuid();
                            DB::table('v3_lesson_nodes')->insert([
                                'id' => $nodeId,
                                'map_id' => $mapId,
                                'kind' => 'lesson',
                                'position_path' => "/1/{$lOrd}",
                                'ordinal_in_parent' => $lOrd,
                                'work_ordinal' => $lOrd,
                                'title' => $lTitle,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            $totalCommittedLessons++;

                            // Match handoff
                            $handoff = $handoffsByKey["{$ordinalInt}:{$lOrd}"] ?? null;
                            $rawFirst = $handoff['raw_first'] ?? '';
                            $parsedSections = $this->bundleProjector->parseRawFirst($rawFirst);
                            if (empty($parsedSections)) {
                                $parsedSections = [
                                    [
                                        'ordinal' => 1,
                                        'title' => $lTitle,
                                        'fields' => $this->bundleProjector->projectFirstFields([])['fields'],
                                        'missing_fields' => $this->bundleProjector->projectFirstFields([])['missing_fields'],
                                        'context_digest' => hash('sha256', ''),
                                    ],
                                ];
                            }

                            $sectionIdsByOrdinal = [];

                            foreach ($parsedSections as $secData) {
                                $secOrd = (int) ($secData['ordinal'] ?? 1);
                                $secTitle = $secData['title'] ?? "Section {$secOrd}";

                                // Create TeacherSection
                                $teacherSecId = (string) Str::uuid();
                                $secFields = $secData['fields'] ?? [];
                                $missingFields = $secData['missing_fields'] ?? [];

                                DB::table('v3_teacher_sections')->insert([
                                    'id' => $teacherSecId,
                                    'case_id' => $caseId,
                                    'map_id' => $mapId,
                                    'work_node_id' => $nodeId,
                                    'ordinal_in_work' => $secOrd,
                                    'origin' => 'terminal_import',
                                    'projection_status' => empty($missingFields) ? 'complete' : 'partial',
                                    'missing_fields_json' => ! empty($missingFields) ? json_encode($missingFields) : null,
                                    'subject_run_item_id' => $runItem->id,
                                    'section_identity' => $secFields['section_identity'] ?? null,
                                    'core_understanding' => $secFields['core_understanding'] ?? null,
                                    'visual_first_explanation' => $secFields['visual_first_explanation'] ?? null,
                                    'visual_scene_script' => $secFields['visual_scene_script'] ?? null,
                                    'dynamic_view_blueprint' => $secFields['dynamic_view_blueprint'] ?? null,
                                    'visual_learning_outcome' => $secFields['visual_learning_outcome'] ?? null,
                                    'concept_check' => $secFields['concept_check'] ?? null,
                                    'conceptual_summary' => $secFields['conceptual_summary'] ?? null,
                                    'colloquial_egyptian_explanation' => $secFields['colloquial_egyptian_explanation'] ?? null,
                                    'laws_if_any' => $secFields['laws_if_any'] ?? null,
                                    'real_life_example' => $secFields['real_life_example'] ?? null,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);

                                $sectionIdsByOrdinal[$secOrd] = $teacherSecId;

                                // Create legacy Section and SectionPipelineLink
                                app(LessonProjection::class)->project(\App\Models\V3\TeacherSection::findOrFail($teacherSecId), $lecture->id, $capture->teacherId, $runItem->id);
                                DB::table('v3_lesson_publications')->updateOrInsert(
                                    ['work_node_id' => $nodeId],
                                    ['lecture_id' => $lecture->id, 'updated_at' => now(), 'created_at' => now()]
                                );
                            }

                            // 5h. Create ScenePromptVersions for scenes in this Lesson
                            $scenesInLesson = $lessonData['scenes'] ?? [];
                            $assignedSections = [];
                            foreach ($scenesInLesson as $scIdx => $sc) {
                                $scOrd = (int) ($sc['scene_ordinal'] ?? ($scIdx + 1));
                                $pText = $sc['prompt'] ?? ($sc['prompt_text'] ?? '');
                                $pSha = hash('sha256', $pText);
                                if ($pText === '' || (isset($sc['prompt_sha256']) && $sc['prompt_sha256'] !== $pSha)) {
                                    throw new CommandConflictException('Archived scene prompt is missing or has changed.', 409, 'artifact_corrupt');
                                }
                                $sectionId = $sectionIdsByOrdinal[$scOrd] ?? null;
                                if (! $sectionId && $scOrd > 0) {
                                    // Some historical exports contain more scene prompts
                                    // than First sections. Retain the extra scene with an
                                    // explicitly missing First projection, never by
                                    // rewriting another section's prompt version.
                                    $sectionId = (string) Str::uuid();
                                    $sectionIdsByOrdinal[$scOrd] = $sectionId;
                                    DB::table('v3_teacher_sections')->insert([
                                        'id' => $sectionId,
                                        'case_id' => $caseId,
                                        'map_id' => $mapId,
                                        'work_node_id' => $nodeId,
                                        'ordinal_in_work' => $scOrd,
                                        'origin' => 'terminal_import',
                                        'projection_status' => 'partial',
                                        'missing_fields_json' => json_encode(ProjectLessonBundle::SCHEMA_COLUMNS),
                                        'subject_run_item_id' => $runItem->id,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);
                                    $legacySec = Section::create([
                                        'lecture_id' => $lecture->id,
                                        'title' => $sc['scene_title'] ?? ($sc['section_title'] ?? "Scene {$scOrd}"),
                                    ]);
                                    SectionPipelineLink::create([
                                        'section_id' => $legacySec->id,
                                        'teacher_section_id' => $sectionId,
                                        'item_id' => $runItem->id,
                                        'owner_user_id' => $capture->teacherId,
                                    ]);
                                }
                                if (! $sectionId || isset($assignedSections[$sectionId])) {
                                    throw new CommandConflictException('Archived scene cannot be matched to exactly one First section.', 409, 'artifact_corrupt');
                                }
                                $assignedSections[$sectionId] = true;
                                $scTitle = $sc['scene_title'] ?? ($sc['section_title'] ?? "Scene {$scOrd}");
                                $scType = $sc['scene_type'] ?? 'Interactive Visualization';

                                $promptVerId = (string) Str::uuid();
                                $bodyPath = "historical-imports/{$capture->importId}/prompts/pdf-{$ordinalInt}/lesson-{$lOrd}/scene-{$scOrd}_{$pSha}.txt";
                                Storage::disk('v3_private')->put($bodyPath, $pText);
                                if (hash('sha256', Storage::disk('v3_private')->get($bodyPath)) !== $pSha) {
                                    throw new CommandConflictException('Archived prompt could not be retained.', 409, 'artifact_corrupt');
                                }
                                DB::table('v3_scene_prompt_versions')->insert([
                                    'id' => $promptVerId,
                                    'case_id' => $caseId,
                                    'section_id' => $sectionId,
                                    'version_no' => 1,
                                    'origin' => 'historical_import',
                                    'prompt_text' => $pText,
                                    'body_path' => $bodyPath,
                                    'body_sha256' => $pSha,
                                    'body_bytes' => strlen($pText),
                                    'scene_title' => $scTitle,
                                    'scene_type' => $scType,
                                    'status' => 'awaiting_manual',
                                    'created_at' => now(),
                                ]);
                                DB::table('v3_prompt_section_states')->insert([
                                    'section_id' => $sectionId,
                                    'case_id' => $caseId,
                                    'current_prompt_version_id' => $promptVerId,
                                    'status' => 'awaiting_manual',
                                    'revision_count' => 0,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                                $totalCommittedPrompts++;
                            }
                        }
                    }

                    // Update SubjectRun totals from actually committed counts
                    $run->update([
                        'total_lessons' => $totalCommittedLessons,
                        'total_scenes' => $totalCommittedPrompts,
                    ]);

                    // 6. Record safe events
                    $this->eventStore->recordEvent($capture->runId, [
                        'kind' => 'import_started',
                        'phase' => 'import',
                        'outcome' => 'completed',
                        'safe_code' => 'ok',
                        'historical' => true,
                    ]);

                    foreach ($capture->pdfArchives as $ordinal => $pdfData) {
                        $this->eventStore->recordEvent($capture->runId, [
                            'kind' => 'import_item_committed',
                            'phase' => 'import',
                            'outcome' => 'completed',
                            'safe_code' => 'ok',
                            'historical' => true,
                            'counts' => [
                                'pdf_ordinal' => (int) $ordinal,
                            ],
                        ]);
                    }

                    $this->eventStore->recordEvent($capture->runId, [
                        'kind' => 'import_finished',
                        'phase' => 'import',
                        'outcome' => 'completed',
                        'safe_code' => 'ok',
                        'historical' => true,
                        'counts' => [
                            'pdfs_accepted' => count($capture->pdfArchives),
                            'prompts_saved' => $totalPrompts,
                        ],
                    ]);

                    return [
                        'terminal_import' => $import,
                        'subject_run' => $run,
                        'status' => 'completed',
                        'is_dry_run' => false,
                    ];
                });
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'database is locked') || str_contains($e->getMessage(), 'busy')) {
                    $attempts++;
                    if ($attempts >= $maxAttempts) {
                        throw new ConcurrentUpdateException("Database locked during materialization", 409, 'concurrent_update', $e);
                    }
                    usleep($backoffs[$attempts - 1] ?? 100000);
                    continue;
                }
                throw $e;
            }
        }

        throw new ConcurrentUpdateException("Concurrency conflict during materialization", 409, 'concurrent_update');
    }

    /** Retain the exact verified source bytes before publishing an import row. */
    private function retainVerifiedArchiveEvidence(ImportCapture $capture): void
    {
        $disk = Storage::disk('v3_private');
        $prefix = "historical-imports/{$capture->importId}/evidence";
        $retained = [];
        $seen = [];
        foreach ($capture->filesToCopy as $entry) {
            $relative = $entry['rel_path'] ?? null;
            $source = $entry['source'] ?? null;
            if (! is_string($relative) || ! preg_match('~^(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+$~D', $relative)
                || isset($seen[$relative]) || ! is_string($source)
                || ! is_file($source) || is_link($source) || realpath($source) !== $source) {
                throw new CommandConflictException('Archive evidence path is invalid.', 409, 'artifact_corrupt');
            }
            $seen[$relative] = true;
            $bytes = file_get_contents($source);
            if (! is_string($bytes) || strlen($bytes) !== (int) ($entry['bytes'] ?? -1)
                || ! hash_equals((string) ($entry['sha256'] ?? ''), hash('sha256', $bytes))) {
                throw new CommandConflictException('Archive evidence changed after verification.', 409, 'artifact_corrupt');
            }
            $path = "{$prefix}/{$relative}";
            if (! $disk->put($path, $bytes)
                || ! hash_equals($entry['sha256'], hash_file('sha256', $disk->path($path)))) {
                throw new CommandConflictException('Archive evidence could not be retained.', 409, 'storage_unavailable');
            }
            $retained[] = [
                'path' => $relative,
                'sha256' => $entry['sha256'],
                'bytes' => (int) $entry['bytes'],
            ];
        }
        $manifest = json_encode([
            'schema_version' => 1,
            'source_run_id' => $capture->runId,
            'archive_sha256' => $capture->digest,
            'files' => $retained,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (! $disk->put("{$prefix}/manifest.json", $manifest)) {
            throw new CommandConflictException('Archive evidence manifest could not be retained.', 409, 'storage_unavailable');
        }
    }
}
