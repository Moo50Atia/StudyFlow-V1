<?php

namespace App\Services\V3\LocalWorker;

use App\Models\V3\LocalArtifact;
use App\Models\V3\LocalAssignment;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalProviderEffect;
use App\Models\V3\LocalResult;
use App\Models\V3\LocalTask;
use App\Models\V3\ProcessingCase;
use App\Models\V3\ProcessingDevice;
use App\Models\V3\PromptSectionState;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use App\Models\V3\TeacherSection;
use App\Models\V3\NotebookFrameworkVersion;
use App\Models\V3\Document;
use App\Services\V3\Prompts\PromptContextReader;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerProtocolException;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerJsonDecoder;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerV1Validator;
use App\Services\V3\WebPipeline\LessonProjection;
use App\Services\V3\WebPipeline\ProjectLessonBundle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class AuthoringResultAcceptor
{
    public function __construct(
        protected ProjectLessonBundle $projector,
        protected TerminalScenePromptParser $terminalPromptParser,
        protected SealedInputManifestReader $manifestReader,
    ) {}

    public function acceptAuthoringResult(
        LocalTask $task,
        LocalAssignment $assignment,
        ProcessingDevice $device,
        array $payload,
        ?string $wireDigest = null
    ): array {
        LocalWorkerV1Validator::validateAuthoringResult($payload);

        if (!in_array($task->status, ['leased', 'running'], true)) {
            throw new LocalWorkerProtocolException("Task is in status '{$task->status}', cannot commit result", 'invalid_task_status', 409);
        }

        if ($payload['task_id'] !== $task->id || $payload['job_id'] !== $task->job_id) {
            throw new LocalWorkerProtocolException("Task or Job ID mismatch in result envelope", 'mismatched_identity', 409);
        }

        if ($payload['input_manifest_sha256'] !== $task->input_manifest_sha256) {
            throw new LocalWorkerProtocolException("input_manifest_sha256 does not match task manifest digest", 'input_changed', 409);
        }

        $resultId = strtolower($payload['result_id']);

        return DB::transaction(function () use ($task, $assignment, $device, $payload, $resultId, $wireDigest) {
            // Check idempotency
            $existing = LocalResult::where('task_id', $task->id)->first();
            if ($existing) {
                if ($existing->id === $resultId) {
                    return $existing->receipt_payload;
                }
                throw new LocalWorkerProtocolException("Result already committed for this task with differing result ID", 'idempotency_conflict', 409);
            }

            // Verify all artifact references are committed, bound to this task,
            // and still match the retained bytes before using any as evidence.
            $referencedArtifacts = [];
            if (is_array($payload['artifact_references'] ?? null)) {
                foreach ($payload['artifact_references'] as $ref) {
                    $artId = $ref['artifact_id'] ?? null;
                    $artSha = $ref['digest_sha256'] ?? null;

                    $artifact = LocalArtifact::where(function ($q) use ($artId) {
                        $q->where('id', $artId)->orWhere('logical_name', $artId);
                    })->where('task_id', $task->id)->where('job_id', $task->job_id)->first();
                    if (!$artifact || $artifact->is_quarantine || !$artifact->is_committed
                        || !Storage::disk('v3_private')->exists($artifact->storage_path)) {
                        throw new LocalWorkerProtocolException("Referenced artifact '{$artId}' missing or still in quarantine", 'incomplete_result', 422);
                    }
                    $bytes = Storage::disk('v3_private')->get($artifact->storage_path);
                    if (strlen($bytes) !== (int) $artifact->size_bytes
                        || !hash_equals($artifact->digest_sha256, hash('sha256', $bytes))) {
                        throw new LocalWorkerProtocolException("Retained bytes for artifact '{$artId}' are corrupt", 'artifact_mismatch', 422);
                    }
                    if (!$artSha || !hash_equals($artifact->digest_sha256, $artSha)) {
                        throw new LocalWorkerProtocolException("Digest mismatch for artifact '{$artId}'", 'artifact_mismatch', 422);
                    }
                    $referencedArtifacts[$artifact->id] = $artifact;
                }
            }

            $inputManifestData = $this->manifestReader->authoring($task);
            $archivesByItem = [];

            if ($inputManifestData) {
                // Verify protocol version
                $expectedProtocol = $inputManifestData['protocol_version'] ?? 'notebooklm_per_pdf_web_v1';
                if (($payload['protocol_version'] ?? '') !== $expectedProtocol) {
                    throw new LocalWorkerProtocolException("Unsupported protocol_version '{$payload['protocol_version']}', expected '{$expectedProtocol}'", 'unsupported_protocol', 422);
                }

                // Verify snapshot hashes
                if (($payload['settings_sha256'] ?? '') !== ($inputManifestData['settings_sha256'] ?? '')) {
                    throw new LocalWorkerProtocolException("settings_sha256 mismatch against sealed input manifest", 'settings_mismatch', 422);
                }

                $expectedPrepSha = $inputManifestData['preparation']['inventory_sha256'] ?? null;
                if ($expectedPrepSha && ($payload['preparation_sha256'] ?? '') !== $expectedPrepSha) {
                    throw new LocalWorkerProtocolException("preparation_sha256 mismatch against sealed input manifest", 'preparation_mismatch', 422);
                }

                // Verify all admitted items are present in submitted lessons
                if (!is_array($inputManifestData['items'] ?? null) || $inputManifestData['items'] === []) {
                    throw new LocalWorkerProtocolException('Sealed input manifest has no admitted items.', 'incomplete_result', 422);
                }
                $requiredItemIds = array_values(array_unique(array_column($inputManifestData['items'], 'item_id')));
                $submittedItemIds = array_values(array_unique(array_column($payload['lessons'], 'item_id')));
                sort($requiredItemIds);
                sort($submittedItemIds);
                if ($requiredItemIds !== $submittedItemIds) {
                    throw new LocalWorkerProtocolException('Submitted item set does not exactly match the sealed input manifest.', 'incomplete_result', 422);
                }

                // The retained archives and successful terminal export are the
                // authoritative source for map cardinality and prompt lineage.
                $manifestItems = array_values($inputManifestData['items']);
                $archivesByItem = [];
                if (count($payload['archive_inventory']) !== count($manifestItems)) {
                    throw new LocalWorkerProtocolException('Retained archive inventory does not cover every sealed PDF.', 'incomplete_result', 422);
                }
                foreach ($manifestItems as $index => $manifestItem) {
                    $archiveRow = $payload['archive_inventory'][$index] ?? null;
                    if (!is_array($archiveRow)
                        || $archiveRow['item_id'] !== $manifestItem['item_id']
                        || $archiveRow['pdf_ordinal'] !== $index + 1) {
                        throw new LocalWorkerProtocolException('Retained archive inventory order differs from the sealed PDF order.', 'incomplete_result', 422);
                    }
                    $archiveArtifact = $referencedArtifacts[$archiveRow['archive_artifact_id']] ?? null;
                    if (!$archiveArtifact || $archiveArtifact->purpose !== 'authoring_raw_response'
                        || !hash_equals($archiveArtifact->digest_sha256, $archiveRow['archive_sha256'])) {
                        throw new LocalWorkerProtocolException('Retained archive artifact is missing or has the wrong purpose/digest.', 'incomplete_result', 422);
                    }
                    $archiveBytes = Storage::disk('v3_private')->get($archiveArtifact->storage_path);
                    $archive = LocalWorkerJsonDecoder::decode($archiveBytes, 4194304);
                    $workCount = $archive['work_count'] ?? null;
                    if (($archive['kind'] ?? null) !== 'v3_notebooklm_pdf_archive'
                        || ($archive['state'] ?? null) !== 'archived'
                        || ($archive['pdf_ordinal'] ?? null) !== $index + 1
                        || ($archive['pdf_sha256'] ?? null) !== $manifestItem['prepared_sha256']
                        || !is_int($workCount) || $workCount < 1
                        || !is_array($archive['files'] ?? null) || count($archive['files']) < 3) {
                        throw new LocalWorkerProtocolException('Retained PDF map/archive evidence is invalid.', 'incomplete_result', 422);
                    }
                    $archive['artifact_id'] = $archiveArtifact->id;
                    $archivesByItem[$manifestItem['item_id']] = $archive;
                }

                $exportEffect = LocalProviderEffect::query()->where('task_id', $task->id)
                    ->where('job_id', $task->job_id)->where('operation_kind', 'terminal_pipeline_export')
                    ->where('status', 'succeeded')->first();
                if (!$exportEffect || !$exportEffect->outcome_ref || $exportEffect->outcome_ref !== $exportEffect->id) {
                    throw new LocalWorkerProtocolException('A successful retained terminal export effect is required.', 'incomplete_result', 422);
                }
                $exportArtifactId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'local-worker:' . $exportEffect->id . ':raw-artifact')->toString();
                $exportArtifact = LocalArtifact::query()->where('task_id', $task->id)->where('job_id', $task->job_id)
                    ->where(function ($q) use ($exportArtifactId) {
                        $q->whereKey($exportArtifactId)->orWhere('logical_name', $exportArtifactId);
                    })->where('purpose', 'authoring_raw_response')->where('is_committed', true)
                    ->where('is_quarantine', false)->first();
                if (!$exportArtifact || !isset($referencedArtifacts[$exportArtifact->id])) {
                    throw new LocalWorkerProtocolException('Successful terminal export artifact is not retained in the submitted evidence.', 'incomplete_result', 422);
                }
                $exportBytes = Storage::disk('v3_private')->get($exportArtifact->storage_path);
                $export = LocalWorkerJsonDecoder::decode($exportBytes, 33554432);
                if (($export['kind'] ?? null) !== 'v3_notebooklm_scene_prompt_export'
                    || ($export['pdf_processing'] ?? null) !== 'per_pdf'
                    || ($export['pdf_count'] ?? null) !== count($manifestItems)
                    || !is_array($export['lessons'] ?? null)) {
                    throw new LocalWorkerProtocolException('Successful terminal export has an invalid retained prompt inventory.', 'incomplete_result', 422);
                }

                foreach ($manifestItems as $index => $manifestItem) {
                    $itemId = $manifestItem['item_id'];
                    $archive = $archivesByItem[$itemId];
                    $expectedCount = $archive['work_count'];
                    $exportLessons = array_values(array_filter($export['lessons'], fn ($entry) => ($entry['pdf_ordinal'] ?? null) === $index + 1));
                    usort($exportLessons, fn ($left, $right) => ($left['lesson_ordinal'] ?? 0) <=> ($right['lesson_ordinal'] ?? 0));
                    if (array_column($exportLessons, 'lesson_ordinal') !== range(1, $expectedCount)) {
                        throw new LocalWorkerProtocolException('Retained map and export do not contain the complete lesson ordinal set.', 'incomplete_result', 422);
                    }
                    $submitted = array_values(array_filter($payload['lessons'], fn ($entry) => $entry['item_id'] === $itemId));
                    usort($submitted, fn ($left, $right) => $left['lesson_ordinal'] <=> $right['lesson_ordinal']);
                    if (count($submitted) !== $expectedCount) {
                        throw new LocalWorkerProtocolException('Submitted lessons do not cover the complete retained PDF map.', 'incomplete_result', 422);
                    }
                    foreach ($submitted as $lessonIndex => $lesson) {
                        $exportLesson = $exportLessons[$lessonIndex];
                        $exportScenes = is_array($exportLesson['scenes'] ?? null) ? array_values($exportLesson['scenes']) : [];
                        $submittedScenes = array_values($lesson['scenes']);
                        if (($lesson['lesson_ordinal'] ?? null) !== $exportLesson['lesson_ordinal'] - 1
                            || ($lesson['lesson_title'] ?? null) !== ($exportLesson['lesson_title'] ?? null)
                            || count($submittedScenes) !== count($exportScenes)) {
                            throw new LocalWorkerProtocolException('Submitted lesson differs from the retained export/map.', 'incomplete_result', 422);
                        }
                        foreach ($submittedScenes as $sceneIndex => $scene) {
                            $exportScene = $exportScenes[$sceneIndex] ?? [];
                            if (($scene['prompt_text'] ?? null) !== ($exportScene['prompt'] ?? null)
                                || ($scene['scene_title'] ?? null) !== ($exportScene['section_title'] ?? null)) {
                                throw new LocalWorkerProtocolException('Submitted prompt differs from the retained terminal export.', 'prompt_mismatch', 422);
                            }
                        }
                    }
                }
            }

            $job = LocalJob::findOrFail($task->job_id);
            $run = SubjectRun::findOrFail($job->run_id);

            $acceptedLessons = 0;
            $acceptedScenes = 0;
            $createdPromptVersionIds = [];
            $requiredScenes = [];

            foreach ($payload['lessons'] as $lIdx => $lesson) {
                $itemId = $lesson['item_id'];
                $item = SubjectRunItem::where('id', $itemId)->where('run_id', $run->id)->first();
                if (!$item) {
                    throw new LocalWorkerProtocolException("Item '{$itemId}' does not belong to run", 'missing_source_item', 422);
                }

                $case = ProcessingCase::find($item->case_id);
                if (!$case) {
                    throw new LocalWorkerProtocolException("ProcessingCase missing for item '{$itemId}'", 'missing_case', 422);
                }

                // Verify raw response artifact and hash (LW-R09)
                $rawArtifactId = $lesson['raw_response_artifact_id'];
                $rawArtifact = LocalArtifact::where('task_id', $task->id)
                    ->where(fn($q) => $q->where('id', $rawArtifactId)->orWhere('logical_name', $rawArtifactId))
                    ->first();
                if (!$rawArtifact || $rawArtifact->is_quarantine) {
                    throw new LocalWorkerProtocolException("Raw response artifact '{$rawArtifactId}' missing or in quarantine", 'incomplete_result', 422);
                }
                if (!Storage::disk('v3_private')->exists($rawArtifact->storage_path)) {
                    throw new LocalWorkerProtocolException("Raw response file missing in storage for artifact '{$rawArtifactId}'", 'incomplete_result', 422);
                }
                $rawBytes = Storage::disk('v3_private')->get($rawArtifact->storage_path);
                if (hash('sha256', $rawBytes) !== $lesson['raw_response_sha256']) {
                    throw new LocalWorkerProtocolException("Raw response artifact hash mismatch for artifact '{$rawArtifactId}'", 'artifact_mismatch', 422);
                }

                // Extract First section and parse all 11 fields (LW-R10)
                $artifacts = \App\Support\NotebookArtifactEnvelope::extract($rawBytes);
                if (!$artifacts || !isset($artifacts['first'], $artifacts['third'])) {
                    throw new LocalWorkerProtocolException('Retained authoring response lacks the required FIRST and THIRD sections.', 'incomplete_result', 422);
                }
                $rawFirst = $artifacts['first'];
                $parsedSections = $this->projector->parseRawFirst($rawFirst);
                if (count($parsedSections) !== count($lesson['scenes'])) {
                    throw new LocalWorkerProtocolException(
                        "Parsed section count does not match the submitted scene count for item '{$item->id}', lesson {$lesson['lesson_ordinal']}.",
                        'incomplete_result',
                        422
                    );
                }

                $lessonOrdinal = (int) $lesson['lesson_ordinal'];
                $queryText = $lessonOrdinal === 0 ? 'Start with First lesson' : 'Continue';
                $framework = NotebookFrameworkVersion::findOrFail($case->notebook_framework_version_id);
                if ($this->terminalPromptParser->parse($artifacts['third']) !== array_column($lesson['scenes'], 'prompt_text')) {
                    throw new LocalWorkerProtocolException('Submitted prompts differ from the retained THIRD response.', 'prompt_mismatch', 422);
                }

                // Ensure lesson node
                $map = DB::table('v3_lesson_maps')->where('case_id', $case->id)->first();
                $mapId = $map ? $map->id : (string) Str::uuid();
                $expectedWorkCount = $archivesByItem[$itemId]['work_count'] ?? null;
                if (!is_int($expectedWorkCount) || $expectedWorkCount < 1
                    || ($map && (int) $map->work_count !== $expectedWorkCount)) {
                    throw new LocalWorkerProtocolException("Retained map count does not match the authoritative lesson map for case '{$case->id}'.", 'incomplete_result', 422);
                }
                if ($map) {
                    $existingOrdinals = DB::table('v3_lesson_nodes')->where('map_id', $mapId)
                        ->where('kind', 'lesson')->pluck('work_ordinal')->map(fn ($value) => (int) $value)->all();
                    if (array_filter($existingOrdinals, fn ($ordinal) => $ordinal < 0 || $ordinal >= $expectedWorkCount)) {
                        throw new LocalWorkerProtocolException("Existing lesson nodes exceed the retained map for case '{$case->id}'.", 'incomplete_result', 422);
                    }
                }
                if (!$map) {
                    $binding = DB::table('v3_knowledge_source_bindings')->where('case_id', $case->id)->first();
                    $activation = DB::table('v3_map_activations')->where('case_id', $case->id)->first();

                    if (!$binding || !$activation) {
                        throw new LocalWorkerProtocolException("Authoritative binding or map activation missing for case '{$case->id}'", 'missing_case_associations', 422);
                    }

                    DB::table('v3_lesson_maps')->insert([
                        'id' => $mapId,
                        'case_id' => $case->id,
                        'binding_id' => $binding->id,
                        'activation_id' => $activation->id,
                        'source_origin' => 'local_worker',
                        'state' => 'ready',
                        'page_count' => Document::findOrFail($item->document_id)->page_count,
                        'work_count' => $expectedWorkCount,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $node = DB::table('v3_lesson_nodes')->where('map_id', $mapId)->where('work_ordinal', $lessonOrdinal)->first();
                $nodeId = $node ? $node->id : (string) Str::uuid();
                if (!$node) {
                    DB::table('v3_lesson_nodes')->insert([
                        'id' => $nodeId,
                        'map_id' => $mapId,
                        'kind' => 'lesson',
                        'position_path' => sprintf('%03d', $lessonOrdinal),
                        'ordinal_in_parent' => $lessonOrdinal,
                        'title' => $lesson['lesson_title'],
                        'work_ordinal' => $lessonOrdinal,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $acceptedLessons++;
                }

                $query = DB::table('v3_lesson_queries')->where('case_id', $case->id)->where('target_work_ordinal', $lessonOrdinal)->first();
                $queryId = $query ? $query->id : (string) Str::uuid();
                if (!$query) {
                    DB::table('v3_lesson_queries')->insert([
                        'id' => $queryId,
                        'case_id' => $case->id,
                        'map_id' => $mapId,
                        'kind' => 'first',
                        'target_work_ordinal' => $lessonOrdinal,
                        'state' => 'recorded',
                        'source_origin' => 'local_worker',
                        'send_count' => 1,
                        'request_sha256' => hash('sha256', $queryText),
                        'canonical_prompt_sha256' => hash('sha256', $queryText),
                        'recorded_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // One durable work item owns every section of this lesson.
                $workItemId = (string) Str::uuid();
                DB::table('v3_prompt_work_items')->insert([
                    'id' => $workItemId,
                    'case_id' => $case->id,
                    'map_id' => $mapId,
                    'work_node_id' => $nodeId,
                    'work_ordinal' => $lessonOrdinal,
                    'expected_cursor_version' => 1,
                    'section_query_id' => $queryId,
                    'state' => 'receipt_committed',
                    'section_refs' => json_encode([]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $sectionRefs = [];

                foreach ($lesson['scenes'] as $sIdx => $scene) {
                    $sceneOrdinal = (int) $scene['scene_ordinal'];
                    $promptText = (string) $scene['prompt_text'];
                    $promptSha = $scene['prompt_sha256'];

                    $pSec = $parsedSections[$sIdx] ?? null;
                    $fields = $pSec['fields'] ?? [];
                    $missingFields = $pSec['missing_fields'] ?? LessonProjection::REQUIRED;
                    $isComplete = $pSec !== null && empty($missingFields);

                    $secIdentity = $fields['section_identity'] ?? $scene['scene_title'];
                    $coreUnderstanding = $fields['core_understanding'] ?? '';

                    $secId = (string) Str::uuid();
                    $sectionRefs[] = $secId;
                    DB::table('v3_teacher_sections')->insert([
                        'id' => $secId,
                        'case_id' => $case->id,
                        'map_id' => $mapId,
                        'work_node_id' => $nodeId,
                        'query_id' => $queryId,
                        'ordinal_in_work' => $sceneOrdinal,
                        'section_identity' => $secIdentity,
                        'core_understanding' => $coreUnderstanding,
                        'visual_first_explanation' => $fields['visual_first_explanation'] ?? '',
                        'visual_scene_script' => $fields['visual_scene_script'] ?? '',
                        'dynamic_view_blueprint' => $fields['dynamic_view_blueprint'] ?? '',
                        'visual_learning_outcome' => $fields['visual_learning_outcome'] ?? '',
                        'concept_check' => $fields['concept_check'] ?? '',
                        'conceptual_summary' => $fields['conceptual_summary'] ?? '',
                        'colloquial_egyptian_explanation' => $fields['colloquial_egyptian_explanation'] ?? '',
                        'laws_if_any' => $fields['laws_if_any'] ?? '',
                        'real_life_example' => $fields['real_life_example'] ?? '',
                        'first_prompt_sha256' => hash('sha256', $queryText),
                        'answer_sha256' => $lesson['raw_response_sha256'],
                        'origin' => 'local_worker',
                        'projection_status' => $isComplete ? 'complete' : 'partial',
                        'missing_fields_json' => $isComplete ? null : json_encode($missingFields),
                        'raw_bundle_ref' => $rawArtifact->id,
                        'subject_run_item_id' => $item->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if ($item->lecture_id) {
                        $teacherSection = TeacherSection::query()->findOrFail($secId);
                        app(LessonProjection::class)->project($teacherSection, (int) $item->lecture_id, (int) $item->owner_user_id, $item->id);
                        DB::table('v3_lesson_publications')->updateOrInsert(
                            ['work_node_id' => $nodeId],
                            ['lecture_id' => $item->lecture_id, 'updated_at' => now(), 'created_at' => now()]
                        );
                    }

                    $bodyPath = "prompt-versions/{$case->id}/{$secId}/v1_{$promptSha}.txt";
                    Storage::disk('v3_private')->put($bodyPath, $promptText);

                    $pvId = (string) Str::uuid();
                    ScenePromptVersion::forceCreate([
                        'id' => $pvId,
                        'case_id' => $case->id,
                        'work_item_id' => $workItemId,
                        'section_id' => $secId,
                        'version_no' => 1,
                        'predecessor_id' => null,
                        'origin' => 'third',
                        'body_path' => $bodyPath,
                        'body_sha256' => $promptSha,
                        'body_bytes' => strlen($promptText),
                        'scene_type' => $scene['generation_context']['scene_type'] ?? 'Interactive Visualization',
                        'section_fields_sha256' => PromptContextReader::computeSectionFieldsSha256($fields),
                        'canonical_third_sha256' => $framework->third_sha256,
                        'framework_version_id' => $framework->id,
                        'prompt_text' => $promptText,
                        'scene_title' => $scene['scene_title'],
                        'status' => 'active',
                        'created_at' => now(),
                    ]);

                    PromptSectionState::updateOrCreate(
                        ['section_id' => $secId],
                        [
                            'case_id' => $case->id,
                            'current_prompt_version_id' => $pvId,
                            'status' => 'awaiting_manual',
                            'revision_count' => 0,
                        ]
                    );

                    $acceptedScenes++;
                    $createdPromptVersionIds[] = $pvId;

                    $requiredScenes[] = [
                        'item_id' => $itemId,
                        'pdf_ordinal' => $item->pdf_ordinal,
                        'pdf_title' => $item->display_title,
                        'original_filename' => $item->original_filename,
                        'lesson_ordinal' => $lessonOrdinal,
                        'lesson_title' => $lesson['lesson_title'],
                        'scene_id' => $secId,
                        'prompt_version_id' => $pvId,
                        'prompt_sha256' => $promptSha,
                        'prompt_text' => $promptText,
                        'scene_title' => $scene['scene_title'],
                        'scene_ordinal' => $sceneOrdinal,
                        'template_version' => $scene['generation_context']['template_version'] ?? 'v1',
                        'scene_type' => $scene['generation_context']['scene_type'] ?? 'Interactive Visualization',
                    ];
                }

                DB::table('v3_prompt_work_items')->where('id', $workItemId)
                    ->update(['section_refs' => json_encode($sectionRefs)]);

                $item->saved_lesson_count = ($item->saved_lesson_count ?? 0) + 1;
                $item->prompt_count = ($item->prompt_count ?? 0) + count($lesson['scenes']);
                $item->save();
            }

            // Update run totals
            $run->total_lessons = ($run->total_lessons ?? 0) + $acceptedLessons;
            $run->total_scenes = ($run->total_scenes ?? 0) + $acceptedScenes;

            $receipt = [
                'schema_version' => 1,
                'result_id' => $resultId,
                'task_id' => $task->id,
                'job_id' => $job->id,
                'run_id' => $run->id,
                'subject_title' => $run->subject?->name,
                'status' => 'completed',
                'accepted_lessons' => $acceptedLessons,
                'accepted_scenes' => $acceptedScenes,
                'prompt_version_ids' => $createdPromptVersionIds,
                'scene_bindings' => $requiredScenes,
                'committed_at' => now()->toIso8601String(),
            ];

            // Record LocalResult
            LocalResult::create([
                'id' => $resultId,
                'task_id' => $task->id,
                'job_id' => $task->job_id,
                'device_id' => $device->id,
                'assignment_id' => $assignment->id,
                'assignment_epoch' => $assignment->assignment_epoch,
                'result_kind' => 'author_prompts',
                'result_digest' => $wireDigest ?? hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                'manifest_sha256' => $task->input_manifest_sha256,
                'receipt_payload' => $receipt,
            ]);

            $task->status = 'completed';
            $task->save();

            $assignment->status = 'released';
            $assignment->save();

            if ($job->output_scope === 'prompts_only') {
                $job->status = 'completed';
                $job->save();
                $run->state = 'completed';
                $run->save();
            } else {
                $job->status = 'awaiting_generation';
                $job->save();
                $run->state = 'awaiting_generation';
                $run->save();

                // Create follow-up return_scenes task with distinct sealed manifest (LW-R21)
                $returnTaskId = (string) Str::uuid();

                $baseManifest = $inputManifestData;
                $manifestItems = $baseManifest['items'];

                $returnManifestData = [
                    'schema_version' => 1,
                    'kind' => 'local_worker_input_manifest',
                    'task_id' => $returnTaskId,
                    'job_id' => $job->id,
                    'run_id' => $run->id,
                    'task_kind' => 'return_scenes',
                    'execution_mode' => 'local_worker_v1',
                    'output_scope' => $job->output_scope,
                    'protocol_id' => $baseManifest['protocol_id'],
                    'protocol_version' => $baseManifest['protocol_version'],
                    'settings_snapshot_id' => $baseManifest['settings_snapshot_id'],
                    'settings_sha256' => $baseManifest['settings_sha256'],
                    'runtime_settings_artifact_id' => $baseManifest['runtime_settings_artifact_id'],
                    'runtime_settings_sha256' => $baseManifest['runtime_settings_sha256'],
                    'preparation' => $baseManifest['preparation'],
                    'items' => $manifestItems,
                    'instruction_artifacts' => $baseManifest['instruction_artifacts'],
                    'artifacts' => $baseManifest['artifacts'],
                    'required_outputs' => [
                        'profile' => 'manual_scene_files_v1',
                        'format' => 'static_three_file_package',
                    ],
                    'resource_budget' => [
                        'max_provider_effects' => 100,
                        'max_output_bytes' => 536870912,
                        'timeout_seconds' => 7200,
                    ],
                    'capabilities' => ['scene_return_v1', 'static_three_file_v1'],
                    'required_scenes' => $requiredScenes,
                ];

                LocalWorkerV1Validator::validateInputManifest($returnManifestData);

                $returnManifestJson = json_encode($returnManifestData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $returnManifestSha = hash('sha256', $returnManifestJson);
                $returnManifestPath = "local_worker/{$job->id}/scene_return_manifest.json";
                Storage::disk('v3_private')->put($returnManifestPath, $returnManifestJson);

                $returnManifestArtifact = LocalArtifact::create([
                    'id' => (string) Str::uuid(),
                    'job_id' => $job->id,
                    'task_id' => $returnTaskId,
                    'purpose' => 'input_manifest',
                    'digest_sha256' => $returnManifestSha,
                    'size_bytes' => strlen($returnManifestJson),
                    'media_type' => 'application/json',
                    'logical_name' => 'scene_return_manifest.json',
                    'storage_path' => $returnManifestPath,
                    'is_quarantine' => false,
                    'is_committed' => true,
                ]);

                LocalTask::create([
                    'id' => $returnTaskId,
                    'job_id' => $job->id,
                    'task_kind' => 'return_scenes',
                    'status' => 'awaiting_pull',
                    'input_manifest_id' => $returnManifestArtifact->id,
                    'input_manifest_sha256' => $returnManifestSha,
                    'control_version' => 1,
                    'active_epoch' => 0,
                ]);
            }

            return $receipt;
        });
    }
}
