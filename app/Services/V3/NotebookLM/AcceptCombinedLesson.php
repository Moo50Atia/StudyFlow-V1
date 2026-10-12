<?php

namespace App\Services\V3\NotebookLM;

use App\Jobs\V3\NotifyLessonCompletionJob;
use App\Models\V3\LessonCompletion;
use App\Models\V3\LessonCursor;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\LessonQuery;
use App\Models\V3\NotebookFrameworkVersion;
use App\Models\V3\NotebookLessonBundle;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptSectionState;
use App\Models\V3\PromptTurn;
use App\Models\V3\PromptWorkItem;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\TeacherSection;
use App\Services\V3\Lessons\LessonCursorService;
use App\Services\V3\Lessons\LessonEvidenceStore;
use App\Services\V3\Prompts\PromptContextReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AcceptCombinedLesson
{
    public function __construct(
        protected CombinedLessonValidator $validator,
        protected LessonEvidenceStore $evidenceStore
    ) {}

    /**
     * Accept and atomically persist combined lesson response.
     *
     * @param string $queryRef
     * @param array $payload
     * @return array
     */
    public function acceptCombinedLesson(string $queryRef, array $payload): array
    {
        // 1. Check query
        $query = LessonQuery::where('id', $queryRef)->first();
        if (! $query || ! in_array($query->kind, ['lesson_start', 'lesson_continue'], true)) {
            throw new \RuntimeException("Lesson query is unavailable.", 404);
        }

        // 2. Check case
        $case = ProcessingCase::where('id', $query->case_id)->first();
        if (! $case) {
            throw new \RuntimeException("Case is unavailable.", 404);
        }

        if (! $case->isDirectProtocol()) {
            throw new \RuntimeException("Protocol conflict: case is not using notebooklm_combined_v1.", 409);
        }

        if (! app(\App\Services\V3\Admin\CaseOperationGuard::class)->isPermitted($case->id)) {
            $answer = (string) ($payload['answer'] ?? '');
            if ($answer !== '') {
                $answerSha = hash('sha256', $answer);
                $answerPath = "answers/{$case->id}/{$query->id}_{$answerSha}.txt";
                Storage::disk('v3_private')->put($answerPath, $answer);
            }
            throw new \RuntimeException("Operation conflict: case is held or cancelled.", 409);
        }

        // 3. Check payload structure
        if (($payload['schema_version'] ?? 0) !== 1) {
            throw new \InvalidArgumentException("Invalid schema version.", 422);
        }

        $answer = (string) ($payload['answer'] ?? '');
        if (trim($answer) === '') {
            throw new \InvalidArgumentException("Answer cannot be empty.", 422);
        }

        $answerSha = hash('sha256', $answer);
        if ($answerSha !== ($payload['answer_sha256'] ?? '')) {
            throw new \InvalidArgumentException("Answer SHA256 mismatch.", 422);
        }

        $conversationOut = (string) ($payload['conversation_out'] ?? '');
        if (trim($conversationOut) === '') {
            throw new \InvalidArgumentException("Conversation out cannot be empty.", 422);
        }
        if ($conversationOut !== $query->conversation_in) {
            throw new \RuntimeException("Conversation identity changed.", 409);
        }
        if (! isset($payload['expected_cursor_version'])
            || (int) $payload['expected_cursor_version'] !== (int) $query->expected_cursor_version) {
            throw new \RuntimeException("Stale cursor version.", 409);
        }

        // 4. Idempotent replay check
        if ($query->state === 'recorded') {
            if ($query->answer_sha256 !== $answerSha || $query->conversation_out !== $conversationOut) {
                throw new \RuntimeException("Query conflict: already recorded with different outcome.", 409);
            }

            if (isset($payload['expected_cursor_version']) && (int) $payload['expected_cursor_version'] !== (int) $query->expected_cursor_version) {
                throw new \RuntimeException("Stale cursor version.", 409);
            }

            $bundle = NotebookLessonBundle::where('query_id', $query->id)->first();
            if ($bundle) {
                $completion = LessonCompletion::find($bundle->completion_id);

                return [
                    'status' => 200,
                    'data' => [
                        'bundle_ref' => $bundle->id,
                        'completion_ref' => $completion->completion_ref,
                        'work_ordinal' => (int) $bundle->work_ordinal,
                        'cursor_version' => (int) $bundle->committed_cursor_version,
                        'section_refs' => $completion->section_refs,
                        'original_prompt_refs' => $completion->third_output_refs,
                    ],
                ];
            }
        }

        // 5. Cursor check
        $cursor = LessonCursor::where('case_id', $case->id)->first();
        if (! $cursor) {
            throw new \RuntimeException("Lesson cursor is unavailable.", 404);
        }

        if ($cursor->phase !== 'processing_bundle') {
            throw new \RuntimeException("Cursor conflict: cursor is in phase '{$cursor->phase}', expected 'processing_bundle'.", 409);
        }

        if ((int) $cursor->current_work_ordinal !== (int) $query->target_work_ordinal) {
            throw new \RuntimeException("Cursor conflict: current work ordinal mismatch.", 409);
        }

        if (isset($payload['expected_cursor_version']) && (int) $payload['expected_cursor_version'] !== (int) $cursor->version) {
            throw new \RuntimeException("Cursor conflict: expected cursor version mismatch.", 409);
        }

        // 6. Validate combined bundle
        try {
            $parsed = $this->validator->validateCombinedBundle($answer, (int) $query->target_work_ordinal);
        } catch (\InvalidArgumentException $e) {
            // Retain private raw evidence and block query
            $answerPath = "answers/{$case->id}/{$query->id}_{$answerSha}.txt";
            Storage::disk('v3_private')->put($answerPath, $answer);

            $query->update([
                'state' => 'blocked',
                'error_code' => 'protocol_validation',
                'answer_path' => $answerPath,
                'answer_sha256' => $answerSha,
                'answer_bytes' => strlen($answer),
                'conversation_out' => $conversationOut,
            ]);

            throw $e;
        }

        $mappedTitle = LessonNode::where('map_id', $query->map_id)
            ->where('work_ordinal', $query->target_work_ordinal)->value('title');
        if ($mappedTitle === null || ! $this->matchesMappedTitle(
            $parsed['lecture_title'],
            (int) $query->target_work_ordinal,
            $mappedTitle
        )) {
            $answerPath = "answers/{$case->id}/{$query->id}_{$answerSha}.txt";
            Storage::disk('v3_private')->put($answerPath, $answer);
            $query->update([
                'state' => 'blocked', 'error_code' => 'protocol_validation',
                'answer_path' => $answerPath, 'answer_sha256' => $answerSha,
                'answer_bytes' => strlen($answer), 'conversation_out' => $conversationOut,
            ]);
            throw new \InvalidArgumentException("Combined lesson does not match the mapped work item.", 422);
        }

        // 7. Stage artifacts on private disk
        $answerPath = "answers/{$case->id}/{$query->id}_{$answerSha}.txt";
        Storage::disk('v3_private')->put($answerPath, $answer);

        $secondSlice = $parsed['raw_second'];
        $secondSha = hash('sha256', $secondSlice);
        $secondAnswerPath = "prompt-versions/{$case->id}/second_slice_{$query->id}_{$secondSha}.txt";
        Storage::disk('v3_private')->put($secondAnswerPath, $secondSlice);

        $thirdSlice = $parsed['raw_third'];
        $thirdSha = hash('sha256', $thirdSlice);
        $thirdAnswerPath = "prompt-versions/{$case->id}/third_slice_{$query->id}_{$thirdSha}.txt";
        Storage::disk('v3_private')->put($thirdAnswerPath, $thirdSlice);

        // Pre-commit barrier for deterministic concurrency testing
        if (! empty($payload['pre_commit_barrier'])) {
            $barrier = $payload['pre_commit_barrier'];
            file_put_contents($barrier . '.reached', (string) getmypid());
            $timeout = microtime(true) + 10.0;
            while (! file_exists($barrier . '.release') && microtime(true) < $timeout) {
                usleep(5000);
            }
        }

        // 8. Atomic database transaction
        return retry(50, function () use (
            $case,
            $query,
            $cursor,
            $parsed,
            $answer,
            $answerSha,
            $answerPath,
            $conversationOut,
            $secondAnswerPath,
            $secondSha,
            $thirdAnswerPath,
            $thirdSha
        ) {
            return DB::transaction(function () use (
                $case,
                $query,
                $cursor,
                $parsed,
                $answer,
                $answerSha,
                $answerPath,
                $conversationOut,
                $secondAnswerPath,
                $secondSha,
                $thirdAnswerPath,
                $thirdSha
            ) {
                $lockedCase = ProcessingCase::where('id', $case->id)->lockForUpdate()->first();
                $control = \App\Models\V3\CaseOperationControl::where('case_id', $case->id)->lockForUpdate()->first();
                $ownerAccess = $lockedCase && $lockedCase->owner_user_id
                    ? DB::table('v3_account_access')->where('user_id', $lockedCase->owner_user_id)->lockForUpdate()->first()
                    : null;

                if (! $lockedCase || $lockedCase->state === 'cancelled'
                    || ($control && $control->mode !== 'normal')
                    || ($ownerAccess && ! (bool) $ownerAccess->is_active)) {
                    throw new \RuntimeException("Operation conflict: case is held or cancelled.", 409);
                }
                $lockedQuery = LessonQuery::where('id', $query->id)->lockForUpdate()->first();
                if ($lockedQuery->state === 'recorded') {
                    if ($lockedQuery->answer_sha256 !== $answerSha
                        || $lockedQuery->conversation_out !== $conversationOut) {
                        throw new \RuntimeException("Query result changed.", 409);
                    }
                    $bundle = NotebookLessonBundle::where('query_id', $lockedQuery->id)->first();
                    if ($bundle) {
                        $completion = LessonCompletion::find($bundle->completion_id);
                        return [
                            'status' => 200,
                            'data' => [
                                'bundle_ref' => $bundle->id,
                                'completion_ref' => $completion->completion_ref,
                                'work_ordinal' => (int) $bundle->work_ordinal,
                                'cursor_version' => (int) $bundle->committed_cursor_version,
                                'section_refs' => $completion->section_refs,
                                'original_prompt_refs' => $completion->third_output_refs,
                            ],
                        ];
                    }
                }
                $lockedCursor = LessonCursor::where('case_id', $case->id)->lockForUpdate()->first();
                if (! $lockedCursor || ! in_array($lockedQuery->state, ['sending', 'uncertain'], true)
                    || $lockedCursor->phase !== 'processing_bundle'
                    || (int) $lockedCursor->current_work_ordinal !== (int) $lockedQuery->target_work_ordinal
                    || (int) $lockedCursor->version !== (int) $lockedQuery->expected_cursor_version
                    || $lockedQuery->conversation_in !== $conversationOut) {
                    throw new \RuntimeException("Cursor or query state changed.", 409);
                }

                $map = LessonMap::where('case_id', $case->id)->where('state', 'ready')->first();
            if (! $map) {
                throw new \RuntimeException("Lesson map is unavailable.", 404);
            }

            $workNode = LessonNode::where('map_id', $map->id)
                ->where('work_ordinal', $lockedQuery->target_work_ordinal)
                ->first();
            if (! $workNode) {
                throw new \RuntimeException("Work node is unavailable.", 404);
            }
            if (! $this->matchesMappedTitle(
                $parsed['lecture_title'],
                (int) $lockedQuery->target_work_ordinal,
                $workNode->title
            )) {
                throw new \InvalidArgumentException("Combined lesson does not match the mapped work item.", 422);
            }

            $framework = NotebookFrameworkVersion::find($case->notebook_framework_version_id);
            if (! $framework) {
                throw new \RuntimeException("Framework version is unavailable.", 503);
            }

            // Create TeacherSection rows
            $sectionRefs = [];
            $sectionModels = [];
            foreach ($parsed['first']['sections'] as $fSec) {
                $secId = (string) Str::uuid();
                $f = $fSec['fields'];

                $secModel = TeacherSection::create([
                    'id' => $secId,
                    'case_id' => $case->id,
                    'map_id' => $map->id,
                    'work_node_id' => $workNode->id,
                    'query_id' => $lockedQuery->id,
                    'ordinal_in_work' => (int) $fSec['ordinal'],
                    'section_identity' => trim($f['section_identity']),
                    'core_understanding' => trim($f['core_understanding']),
                    'visual_first_explanation' => trim($f['visual_first_explanation']),
                    'visual_scene_script' => trim($f['visual_scene_script']),
                    'dynamic_view_blueprint' => trim($f['dynamic_view_blueprint']),
                    'visual_learning_outcome' => trim($f['visual_learning_outcome']),
                    'concept_check' => trim($f['concept_check']),
                    'conceptual_summary' => trim($f['conceptual_summary']),
                    'colloquial_egyptian_explanation' => trim($f['colloquial_egyptian_explanation']),
                    'laws_if_any' => trim($f['laws_if_any']),
                    'real_life_example' => trim($f['real_life_example']),
                    'first_prompt_sha256' => $framework->first_sha256,
                    'answer_sha256' => $answerSha,
                    'subject_run_item_id' => $case->subject_run_item_id,
                ]);

                $sectionRefs[] = $secId;
                $sectionModels[] = $secModel;

                if ($case->subject_run_item_id) {
                    $linkedItem = \App\Models\V3\SubjectRunItem::find($case->subject_run_item_id);
                    if ($linkedItem?->lecture_id) {
                        app(\App\Services\V3\WebPipeline\LessonProjection::class)->project(
                            $secModel, (int) $linkedItem->lecture_id, (int) $linkedItem->owner_user_id, $linkedItem->id
                        );
                        DB::table('v3_lesson_publications')->updateOrInsert(
                            ['work_node_id' => $workNode->id],
                            ['lecture_id' => $linkedItem->lecture_id, 'updated_at' => now(), 'created_at' => now()]
                        );
                    }
                }
            }

            // Create PromptWorkItem
            $workItem = PromptWorkItem::create([
                'id' => (string) Str::uuid(),
                'case_id' => $case->id,
                'map_id' => $map->id,
                'work_node_id' => $workNode->id,
                'work_ordinal' => (int) $lockedQuery->target_work_ordinal,
                'expected_cursor_version' => $lockedCursor->version,
                'section_query_id' => $lockedQuery->id,
                'state' => 'receipt_committed',
                'section_refs' => $sectionRefs,
            ]);

            // Create 3 logical PromptTurn rows
            $execQuery = LessonQuery::where('case_id', $case->id)->where('kind', 'execution')->first();
            $execOriginQueryId = $execQuery ? $execQuery->id : $lockedQuery->id;

            $execTurn = PromptTurn::create([
                'id' => (string) Str::uuid(),
                'work_item_id' => $workItem->id,
                'step' => 'execution',
                'origin' => 'combined_reuse',
                'origin_query_id' => $execOriginQueryId,
                'origin_turn_id' => null,
                'state' => 'recorded',
                'send_count' => 0,
                'request_sha256' => hash('sha256', "016-logical-turn-v1\n{$workItem->id}\nexecution\n{$execOriginQueryId}\n" . PromptContextReader::CANONICAL_EXECUTION_SHA256),
                'canonical_sha256' => PromptContextReader::CANONICAL_EXECUTION_SHA256,
                'answer_sha256' => null,
                'conversation_in' => '',
                'conversation_out' => $conversationOut,
                'recorded_at' => now(),
            ]);

            $secondTurn = PromptTurn::create([
                'id' => (string) Str::uuid(),
                'work_item_id' => $workItem->id,
                'step' => 'second',
                'origin' => 'combined_reuse',
                'origin_query_id' => $lockedQuery->id,
                'origin_turn_id' => null,
                'state' => 'recorded',
                'send_count' => 0,
                'request_sha256' => hash('sha256', "016-logical-turn-v1\n{$workItem->id}\nsecond\n{$lockedQuery->id}\n{$framework->second_sha256}"),
                'canonical_sha256' => $framework->second_sha256,
                'answer_sha256' => $secondSha,
                'conversation_in' => '',
                'conversation_out' => $conversationOut,
                'answer_path' => $secondAnswerPath,
                'answer_bytes' => strlen($parsed['raw_second']),
                'parsed_mappings' => $parsed['second']['sections'],
                'recorded_at' => now(),
            ]);

            $thirdTurn = PromptTurn::create([
                'id' => (string) Str::uuid(),
                'work_item_id' => $workItem->id,
                'step' => 'third',
                'origin' => 'combined_reuse',
                'origin_query_id' => $lockedQuery->id,
                'origin_turn_id' => null,
                'state' => 'recorded',
                'send_count' => 0,
                'request_sha256' => hash('sha256', "016-logical-turn-v1\n{$workItem->id}\nthird\n{$lockedQuery->id}\n{$framework->third_sha256}"),
                'canonical_sha256' => $framework->third_sha256,
                'answer_sha256' => $thirdSha,
                'conversation_in' => '',
                'conversation_out' => $conversationOut,
                'answer_path' => $thirdAnswerPath,
                'answer_bytes' => strlen($parsed['raw_third']),
                'recorded_at' => now(),
            ]);

            // Create ScenePromptVersion rows
            $promptRefs = [];
            foreach ($parsed['third']['sections'] as $idx => $tSec) {
                $secId = $sectionRefs[$idx];
                $fSec = $parsed['first']['sections'][$idx];

                $promptBody = $tSec['prompt_body'];
                $promptSha = hash('sha256', $promptBody);
                $bodyPath = "prompt-versions/{$case->id}/{$secId}/v1_{$promptSha}.txt";
                Storage::disk('v3_private')->put($bodyPath, $promptBody);

                $fieldsSha = PromptContextReader::computeSectionFieldsSha256($fSec['fields']);

                $pv = ScenePromptVersion::forceCreate([
                    'id' => (string) Str::uuid(),
                    'case_id' => $case->id,
                    'work_item_id' => $workItem->id,
                    'section_id' => $secId,
                    'version_no' => 1,
                    'predecessor_id' => null,
                    'origin' => 'third',
                    'third_turn_id' => $thirdTurn->id,
                    'feedback_effect_id' => null,
                    'body_path' => $bodyPath,
                    'body_sha256' => $promptSha,
                    'body_bytes' => strlen($promptBody),
                    'scene_type' => $tSec['scene_type'],
                    'section_fields_sha256' => $fieldsSha,
                    'canonical_third_sha256' => $framework->third_sha256,
                    'framework_version_id' => $case->notebook_framework_version_id,
                    'created_at' => now(),
                ]);

                $promptRefs[] = $pv->id;

                PromptSectionState::updateOrCreate(
                    ['section_id' => $secId],
                    [
                        'case_id' => $case->id,
                        'current_prompt_version_id' => $pv->id,
                        'status' => 'awaiting_qualification',
                        'revision_count' => 0,
                    ]
                );
            }

            // Advance cursor
            $newCursorVersion = $lockedCursor->version + 1;
            $isLast = (int) $lockedQuery->target_work_ordinal >= (int) $map->work_count;
            $newPhase = $isLast ? 'complete' : 'ready_to_continue';

            $completionRef = (string) Str::uuid();
            $completionSha256 = LessonCursorService::computeCompletionDigest(
                caseId: $case->id,
                mapRef: $map->id,
                workOrdinal: (int) $lockedQuery->target_work_ordinal,
                expectedCursorVersion: (int) $lockedCursor->version,
                sectionRefs: $sectionRefs,
                thirdOutputRefs: $promptRefs,
                finalConversationRef: $conversationOut,
                completionRef: $completionRef,
            );

            // Create LessonCompletion
            $completion = LessonCompletion::create([
                'id' => (string) Str::uuid(),
                'case_id' => $case->id,
                'map_id' => $map->id,
                'work_node_id' => $workNode->id,
                'work_ordinal' => (int) $lockedQuery->target_work_ordinal,
                'cursor_version' => $newCursorVersion,
                'completion_ref' => $completionRef,
                'completion_sha256' => $completionSha256,
                'section_refs' => $sectionRefs,
                'third_output_refs' => $promptRefs,
                'final_conversation_id' => $conversationOut,
                'signal_state' => 'committed',
                'signal_attempts' => 0,
            ]);

            // Update PromptWorkItem
            $workItem->update([
                'execution_turn_id' => $execTurn->id,
                'second_turn_id' => $secondTurn->id,
                'third_turn_id' => $thirdTurn->id,
                'completion_ref' => $completionRef,
                'completion_sha256' => $completionSha256,
                'final_conversation_id' => $conversationOut,
                'receipt_committed_at' => now(),
            ]);

            // Compute projection digest
            $projectionList = [];
            foreach ($parsed['first']['sections'] as $idx => $fSec) {
                $sSec = $parsed['second']['sections'][$idx];
                $tSec = $parsed['third']['sections'][$idx];
                $projectionList[] = [
                    'ordinal' => $fSec['ordinal'],
                    'fields' => $fSec['fields'],
                    'second_text' => $sSec['placeholder'],
                    'third_text' => $tSec['prompt_body'],
                    'scene_type' => $tSec['scene_type'],
                ];
            }
            $projectionSha = hash('sha256', json_encode($projectionList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // Create NotebookLessonBundle
            $bundle = NotebookLessonBundle::create([
                'id' => (string) Str::uuid(),
                'case_id' => $case->id,
                'query_id' => $lockedQuery->id,
                'work_node_id' => $workNode->id,
                'work_item_id' => $workItem->id,
                'completion_id' => $completion->id,
                'work_ordinal' => (int) $lockedQuery->target_work_ordinal,
                'framework_version_id' => $case->notebook_framework_version_id,
                'answer_sha256' => $answerSha,
                'projection_sha256' => $projectionSha,
                'committed_cursor_version' => $newCursorVersion,
                'created_at' => now(),
            ]);

            // Update Cursor
            $lockedCursor->update([
                'phase' => $newPhase,
                'current_work_ordinal' => $isLast ? (int) $lockedQuery->target_work_ordinal : (int) $lockedQuery->target_work_ordinal + 1,
                'last_completion_id' => $completion->id,
                'conversation_id' => $conversationOut,
                'version' => $newCursorVersion,
            ]);

            // Update Query
            $lockedQuery->update([
                'state' => 'recorded',
                'answer_path' => $answerPath,
                'answer_sha256' => $answerSha,
                'answer_bytes' => strlen($answer),
                'conversation_out' => $conversationOut,
                'recorded_at' => now(),
            ]);

            DB::afterCommit(function () use ($case, $completion) {
                NotifyLessonCompletionJob::dispatch($case->id, $completion->id);
            });

            return [
                'status' => 201,
                'data' => [
                    'bundle_ref' => $bundle->id,
                    'completion_ref' => $completionRef,
                    'work_ordinal' => (int) $lockedQuery->target_work_ordinal,
                    'cursor_version' => $newCursorVersion,
                    'section_refs' => $sectionRefs,
                    'original_prompt_refs' => $promptRefs,
                ],
            ];
        });
        }, 50, function (\Throwable $e) {
            return $e instanceof \Illuminate\Database\QueryException;
        });
    }

    private function matchesMappedTitle(string $actualTitle, int $workOrdinal, string $mappedTitle): bool
    {
        $actual = $this->normalizeMappedTitle($actualTitle, $workOrdinal);
        $mapped = $this->normalizeMappedTitle('W' . $workOrdinal . ' | ' . $mappedTitle, $workOrdinal);

        if ($actual === $mapped) {
            return true;
        }

        // Some combined responses name the chapter before the exact mapped lesson.
        return preg_match('/^.+\s+[-–—]\s+' . preg_quote($mapped, '/') . '$/iu', $actual) === 1;
    }

    private function normalizeMappedTitle(string $title, int $workOrdinal): string
    {
        $title = trim($title);
        $title = preg_replace('/^W' . $workOrdinal . '\\s*\\|\\s*/i', '', $title) ?? $title;
        // NotebookLM can retain a chapter heading before the lesson title.
        $title = preg_replace('/^.*\\bLesson\\s+' . $workOrdinal . '\\s*:\\s*/iu', '', $title) ?? $title;
        $title = preg_replace('/^Lesson\\s+' . $workOrdinal . '\\s*:\\s*/i', '', $title) ?? $title;
        // NotebookLM may append source citation markers to structural titles.
        $title = preg_replace('/(?:\\s+\\[\\d+\\])+\\s*$/u', '', $title) ?? $title;
        $title = preg_replace('/\\s+/u', ' ', trim($title)) ?? trim($title);

        return $title;
    }
}
