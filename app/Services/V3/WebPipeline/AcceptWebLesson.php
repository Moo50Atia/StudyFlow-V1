<?php

namespace App\Services\V3\WebPipeline;

use App\Models\Lecture;
use App\Models\Section;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\LessonQuery;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptSectionState;
use App\Models\V3\PromptWorkItem;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SectionPipelineLink;
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AcceptWebLesson
{
    public function __construct(
        protected ProjectLessonBundle $projector
    ) {}

    /**
     * Accept and persist web lesson bundle without losing Third prompt descriptions.
     *
     * @param array $bundleData
     * @return array{outcome: string, section_id: string, prompt_version_id: string}
     */
    public function accept(array $bundleData): array
    {
        return DB::transaction(function () use ($bundleData) {
            $caseId = $bundleData['case_id'];
            $itemId = $bundleData['item_id'];
            $runId = $bundleData['run_id'];
            $lessonOrdinal = (int) ($bundleData['lesson_ordinal'] ?? 1);
            $firstRaw = $bundleData['first_raw'] ?? [];
            $thirdPrompt = $bundleData['third_prompt'] ?? '';
            $sceneType = $bundleData['scene_type'] ?? 'Interactive Visualization';

            $case = ProcessingCase::find($caseId);
            $item = SubjectRunItem::find($itemId);
            $run = SubjectRun::find($runId);
            $ownerId = $item ? $item->owner_user_id : ($run ? $run->owner_user_id : 1);

            $fwId = $case?->notebook_framework_version_id ?? $run?->framework_version_id;
            $framework = $fwId ? DB::table('v3_notebook_framework_versions')->where('id', $fwId)->first() : null;
            $firstSha = $framework?->first_sha256 ?? hash('sha256', \App\Services\V3\NotebookLM\FrameworkCatalog::RESPONSE_FORMAT_TEXT);
            $thirdSha = $framework?->third_sha256 ?? hash('sha256', 'third');

            // 1. Ensure binding and lesson map
            $map = LessonMap::where('case_id', $caseId)->first();
            if (! $map) {
                $settingsVersionId = $case?->settings_version_id ?? DB::table('v3_settings_versions')->value('id') ?? 1;
                $docId = $case?->document_id ?? $item?->document_id;
                $doc = $docId ? DB::table('v3_documents')->where('id', $docId)->first() : null;
                $docSha = $doc?->sha256 ?? $item?->input_sha256 ?? $bundleData['input_sha256'] ?? $bundleData['source_sha256'] ?? hash('sha256', "doc-{$itemId}");
                $docBytes = $doc?->byte_size ?? $item?->input_bytes ?? $bundleData['input_bytes'] ?? 1024;
                if (! $docId || ! $doc) {
                    $docId = $docId ?: (string) Str::uuid();
                    DB::table('v3_documents')->insert([
                        'id' => $docId,
                        'owner_user_id' => $ownerId,
                        'sha256' => $docSha,
                        'byte_size' => $docBytes,
                        'page_count' => 1,
                        'state' => 'ready',
                        'storage_path' => "accepted/{$docId}.pdf",
                        'accepted_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // A live web intake can already have an accepted source activation
                // from DirectSourceCoordinator. Reuse that case-scoped activation;
                // the table intentionally permits only one activation per case.
                $srcActivation = DB::table('v3_source_activations')
                    ->where('case_id', $caseId)
                    ->first();
                if (! $srcActivation) {
                    $srcActId = (string) Str::uuid();
                    DB::table('v3_source_activations')->insert([
                        'id' => $srcActId,
                        'case_id' => $caseId,
                        'workflow_id' => "v3-src-{$caseId}",
                        'state' => 'accepted',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $srcActId = (string) $srcActivation->id;
                }

                $binding = DB::table('v3_knowledge_source_bindings')
                    ->where('case_id', $caseId)
                    ->first();
                if (! $binding) {
                    $srcId = (string) Str::uuid();
                    DB::table('v3_extracted_sources')->insert([
                        'id' => $srcId,
                        'case_id' => $caseId,
                        'activation_id' => $srcActId,
                        'document_id' => $docId,
                        'settings_version_id' => $settingsVersionId,
                        'status' => 'ready',
                        'artifact_path' => "extracted/{$caseId}.txt",
                        'text_sha256' => $docSha,
                        'text_bytes' => $docBytes,
                        'page_count' => 1,
                        'page_report' => json_encode([]),
                        'extractor_version' => 'web-v1',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $bindId = (string) Str::uuid();
                    DB::table('v3_knowledge_source_bindings')->insert([
                        'id' => $bindId,
                        'case_id' => $caseId,
                        'extracted_source_id' => $srcId,
                        'subject_run_item_id' => $itemId,
                        'adapter_kind' => 'notebooklm',
                        'adapter_version' => '0.3.1',
                        'notebook_title' => "v3-case-{$caseId}",
                        'source_title' => "v3-text-{$caseId}",
                        'state' => 'bound',
                        'text_sha256' => $docSha,
                        'text_bytes' => $docBytes,
                        'transfer_status' => 'confirmed',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $bindId = (string) $binding->id;
                }

                $existingMapActivation = DB::table('v3_map_activations')
                    ->where('case_id', $caseId)
                    ->first();
                if (! $existingMapActivation) {
                    $actId = (string) Str::uuid();
                    DB::table('v3_map_activations')->insert([
                        'id' => $actId,
                        'case_id' => $caseId,
                        'binding_id' => $bindId,
                        'workflow_id' => "v3-case-{$caseId}",
                        'state' => 'accepted',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $actId = (string) $existingMapActivation->id;
                }

                $mapId = (string) Str::uuid();
                DB::table('v3_lesson_maps')->insert([
                    'id' => $mapId,
                    'case_id' => $caseId,
                    'binding_id' => $bindId,
                    'activation_id' => $actId,
                    'source_origin' => 'live',
                    'state' => 'ready',
                    'page_count' => 1,
                    'work_count' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $map = LessonMap::find($mapId);
            }

            // 2. Ensure lesson node
            $node = LessonNode::where('map_id', $map->id)->where('work_ordinal', $lessonOrdinal)->first();
            if (! $node) {
                $nodeId = (string) Str::uuid();
                DB::table('v3_lesson_nodes')->insert([
                    'id' => $nodeId,
                    'map_id' => $map->id,
                    'kind' => 'lesson',
                    'position_path' => sprintf('%03d', $lessonOrdinal),
                    'ordinal_in_parent' => $lessonOrdinal,
                    'title' => $firstRaw['title'] ?? ($firstRaw['lesson_title'] ?? "Lesson {$lessonOrdinal}"),
                    'work_ordinal' => $lessonOrdinal,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $node = LessonNode::find($nodeId);
            }

            // 3. Ensure lesson query
            $convIn = $bundleData['conversation_id'] ?? $bundleData['conversation_in'] ?? null;
            if (! $convIn) {
                $convIn = DB::table('v3_lesson_queries')
                    ->where('case_id', $caseId)
                    ->whereNotNull('conversation_in')
                    ->value('conversation_in');
            }
            $convOut = $bundleData['conversation_out'] ?? $convIn;

            $query = LessonQuery::where('case_id', $caseId)
                ->where('kind', 'first')
                ->where('target_work_ordinal', $lessonOrdinal)
                ->first();
            if (! $query) {
                $queryId = (string) Str::uuid();
                DB::table('v3_lesson_queries')->insert([
                    'id' => $queryId,
                    'case_id' => $caseId,
                    'map_id' => $map->id,
                    'kind' => 'first',
                    'target_work_ordinal' => $lessonOrdinal,
                    'state' => 'recorded',
                    'source_origin' => 'live',
                    'send_count' => 1,
                    'request_sha256' => hash('sha256', "first-{$caseId}-{$lessonOrdinal}"),
                    'canonical_prompt_sha256' => $firstSha,
                    'conversation_in' => $convIn,
                    'conversation_out' => $convOut,
                    'recorded_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $query = LessonQuery::find($queryId);
            }

            // 4. Replay fence and duplicate check
            $sectionOrdinal = (int) ($bundleData['section_ordinal'] ?? $bundleData['ordinal_in_work'] ?? 1);
            $incomingAnswerSha = hash('sha256', json_encode($firstRaw));
            $incomingPromptSha = hash('sha256', $thirdPrompt);

            $existingSection = DB::table('v3_teacher_sections')
                ->where('work_node_id', $node->id)
                ->where('ordinal_in_work', $sectionOrdinal)
                ->first();

            if ($existingSection) {
                $existingPrompt = DB::table('v3_scene_prompt_versions')
                    ->where('section_id', $existingSection->id)
                    ->where('version_no', 1)
                    ->first();

                if ($existingSection->answer_sha256 === $incomingAnswerSha
                    && $existingPrompt
                    && $existingPrompt->body_sha256 === $incomingPromptSha) {
                    // Exact duplicate retry: return identical references without extra records or inflated counts
                    return [
                        'outcome' => 'completed',
                        'section_id' => (string) $existingSection->id,
                        'prompt_version_id' => (string) $existingPrompt->id,
                    ];
                }

                abort(response()->json([
                    'error' => [
                        'code' => 'operation_conflict',
                        'message' => 'Divergent bundle digest for recorded lesson section',
                    ],
                ], 409));
            }

            $isFirstSectionForNode = ! DB::table('v3_teacher_sections')
                ->where('work_node_id', $node->id)
                ->exists();

            // 5. Project First fields
            $proj = $this->projector->projectFirstFields($firstRaw);
            $title = $firstRaw['section_identity'] ?? ($firstRaw['title'] ?? ($firstRaw['lesson_title'] ?? "Section {$lessonOrdinal}"));

            $secId = (string) Str::uuid();
            DB::table('v3_teacher_sections')->insert([
                'id' => $secId,
                'case_id' => $caseId,
                'map_id' => $map->id,
                'work_node_id' => $node->id,
                'query_id' => $query->id,
                'ordinal_in_work' => $sectionOrdinal,
                'section_identity' => $title,
                'core_understanding' => $proj['fields']['core_understanding'] ?? ($firstRaw['summary'] ?? ($proj['fields']['core_concept'] ?? null)),
                'visual_first_explanation' => $proj['fields']['visual_first_explanation'] ?? ($proj['fields']['visual_representation'] ?? null),
                'visual_scene_script' => $proj['fields']['visual_scene_script'] ?? ($proj['fields']['interactive_controls'] ?? null),
                'dynamic_view_blueprint' => $proj['fields']['dynamic_view_blueprint'] ?? ($proj['fields']['variables_and_ranges'] ?? null),
                'visual_learning_outcome' => $proj['fields']['visual_learning_outcome'] ?? (is_array($firstRaw['learning_objectives'] ?? null)
                    ? json_encode($firstRaw['learning_objectives'])
                    : ($firstRaw['learning_objectives'] ?? ($proj['fields']['learning_objectives'] ?? null))),
                'concept_check' => $proj['fields']['concept_check'] ?? ($proj['fields']['misconceptions'] ?? null),
                'conceptual_summary' => $proj['fields']['conceptual_summary'] ?? ($firstRaw['summary'] ?? ($proj['fields']['lesson_title'] ?? null)),
                'colloquial_egyptian_explanation' => $proj['fields']['colloquial_egyptian_explanation'] ?? null,
                'laws_if_any' => $proj['fields']['laws_if_any'] ?? ($firstRaw['laws_if_any'] ?? null),
                'real_life_example' => $proj['fields']['real_life_example'] ?? ($proj['fields']['real_world_application'] ?? null),
                'first_prompt_sha256' => $firstSha,
                'answer_sha256' => $incomingAnswerSha,
                'origin' => 'pipeline',
                'projection_status' => $proj['is_complete'] ? 'complete' : 'incomplete',
                'missing_fields_json' => json_encode($proj['missing_fields']),
                'subject_run_item_id' => $itemId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 6. Ensure prompt work item
            $workItem = PromptWorkItem::where('case_id', $caseId)
                ->where('work_ordinal', $lessonOrdinal)
                ->first();
            if (! $workItem) {
                $cursorVersion = (int) ($bundleData['cursor_version'] ?? $bundleData['expected_cursor_version'] ?? 1);
                $workItem = PromptWorkItem::create([
                    'id' => (string) Str::uuid(),
                    'case_id' => $caseId,
                    'map_id' => $map->id,
                    'work_node_id' => $node->id,
                    'work_ordinal' => $lessonOrdinal,
                    'expected_cursor_version' => $cursorVersion,
                    'section_query_id' => $query->id,
                    'state' => 'receipt_committed',
                    'section_refs' => [$secId],
                ]);
            } else {
                $refs = $workItem->section_refs ?? [];
                if (! in_array($secId, $refs, true)) {
                    $refs[] = $secId;
                    $workItem->update(['section_refs' => $refs]);
                }
            }

            // 7. Save prompt body to private storage
            $promptSha = $incomingPromptSha;
            $promptBytes = strlen($thirdPrompt);
            $bodyPath = "prompt-versions/{$caseId}/{$secId}/v1_{$promptSha}.txt";
            Storage::disk('v3_private')->put($bodyPath, $thirdPrompt);

            // 8. Create ScenePromptVersion
            $pvId = (string) Str::uuid();
            $pv = ScenePromptVersion::forceCreate([
                'id' => $pvId,
                'case_id' => $caseId,
                'work_item_id' => $workItem->id,
                'section_id' => $secId,
                'version_no' => 1,
                'predecessor_id' => null,
                'origin' => 'third',
                'body_path' => $bodyPath,
                'body_sha256' => $promptSha,
                'body_bytes' => $promptBytes,
                'scene_type' => $sceneType,
                'section_fields_sha256' => $proj['context_digest'],
                'canonical_third_sha256' => $thirdSha,
                'created_at' => now(),
            ]);

            // 9. PromptSectionState
            PromptSectionState::updateOrCreate(
                ['section_id' => $secId],
                [
                    'case_id' => $caseId,
                    'current_prompt_version_id' => $pv->id,
                    'status' => 'awaiting_manual',
                    'revision_count' => 0,
                ]
            );

            // 10. Project the NotebookLM fields into the linked legacy lesson.
            if ($item && $item->lecture_id) {
                app(LessonProjection::class)->project(\App\Models\V3\TeacherSection::findOrFail($secId), $item->lecture_id, $ownerId, $itemId);
                DB::table('v3_lesson_publications')->updateOrInsert(
                    ['work_node_id' => $node->id],
                    ['lecture_id' => $item->lecture_id, 'updated_at' => now(), 'created_at' => now()]
                );
            }

            // 11. Update counts - only increment lesson counts for the first section of a lesson node
            if ($item) {
                if ($isFirstSectionForNode) {
                    $item->saved_lesson_count += 1;
                }
                $item->prompt_count += 1;
                $item->save();
            }
            if ($run) {
                if ($isFirstSectionForNode) {
                    $run->total_lessons = ($run->total_lessons ?? 0) + 1;
                }
                $run->total_scenes = ($run->total_scenes ?? 0) + 1;
                $run->save();
            }

            return [
                'outcome' => 'completed',
                'section_id' => $secId,
                'prompt_version_id' => $pvId,
            ];
        });
    }
}
