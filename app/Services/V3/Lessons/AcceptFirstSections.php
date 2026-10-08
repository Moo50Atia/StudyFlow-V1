<?php

namespace App\Services\V3\Lessons;

use App\Models\V3\LessonCursor;
use App\Models\V3\LessonNode;
use App\Models\V3\LessonQuery;
use App\Models\V3\TeacherSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AcceptFirstSections
{
    public const REQUIRED_FIELDS = [
        'section_identity',
        'core_understanding',
        'visual_first_explanation',
        'visual_scene_script',
        'dynamic_view_blueprint',
        'visual_learning_outcome',
        'concept_check',
        'conceptual_summary',
        'colloquial_egyptian_explanation',
        'laws_if_any',
        'real_life_example',
    ];

    public function __construct(
        protected LessonEvidenceStore $evidenceStore
    ) {}

    /**
     * Accept First/Continue query response and atomically persist teacher sections and update cursor.
     *
     * @return array{query_ref: string, map_ref: string, cursor_version: ?int, state: string, created: bool}
     */
    public function acceptFirstSections(
        string $queryRef,
        string $answer,
        string $conversationOut,
        array $parsed
    ): array {
        $query = LessonQuery::where('id', $queryRef)->first();
        if (! $query || ! in_array($query->kind, ['first', 'continue'], true)) {
            throw new \RuntimeException("Lesson query is unavailable.", 404);
        }

        // Validate work ordinal
        if (! isset($parsed['work_ordinal']) || (int) $parsed['work_ordinal'] !== (int) $query->target_work_ordinal) {
            throw new \InvalidArgumentException("Mismatched work ordinal.");
        }

        // Validate sections
        $sections = $parsed['sections'] ?? null;
        if (! is_array($sections) || empty($sections)) {
            throw new \InvalidArgumentException("Sections array cannot be empty.");
        }

        $expectedOrdinal = 1;
        foreach ($sections as $section) {
            if (! is_array($section) || ! isset($section['ordinal']) || (int) $section['ordinal'] !== $expectedOrdinal) {
                throw new \InvalidArgumentException("Non-contiguous or invalid section ordinal.");
            }
            $expectedOrdinal++;

            $fields = $section['fields'] ?? null;
            if (! is_array($fields)) {
                throw new \InvalidArgumentException("Section fields must be an array.");
            }

            foreach (self::REQUIRED_FIELDS as $field) {
                if (! array_key_exists($field, $fields) || ! is_string($fields[$field])) {
                    throw new \InvalidArgumentException("Missing or non-string section field: {$field}");
                }
                if ($field !== 'laws_if_any' && trim($fields[$field]) === '') {
                    throw new \InvalidArgumentException("Empty required section field: {$field}");
                }
            }
        }

        $answerSha = hash('sha256', $answer);
        $answerPath = $this->evidenceStore->putAnswer($query->case_id, $query->id, $answer, $answerSha);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                return DB::transaction(function () use ($query, $answerSha, $answerPath, $answer, $conversationOut, $sections) {
                    $lockedQuery = LessonQuery::where('id', $query->id)->lockForUpdate()->first();

                    if ($lockedQuery->state === 'recorded') {
                        if ($lockedQuery->answer_sha256 !== $answerSha) {
                            throw new \RuntimeException("Lesson state changed.", 409);
                        }
                        $cursor = LessonCursor::where('case_id', $lockedQuery->case_id)->first();
                        return [
                            'query_ref' => $lockedQuery->id,
                            'map_ref' => $lockedQuery->map_id,
                            'cursor_version' => $cursor?->version,
                            'state' => 'recorded',
                            'created' => false,
                        ];
                    }

                    $workNode = LessonNode::where('map_id', $lockedQuery->map_id)
                        ->where('work_ordinal', $lockedQuery->target_work_ordinal)
                        ->first();
                    if (! $workNode) {
                        throw new \RuntimeException("Lesson work node is unavailable.", 404);
                    }

                    $cursor = LessonCursor::where('case_id', $lockedQuery->case_id)->lockForUpdate()->first();
                    if (! $cursor) {
                        throw new \RuntimeException("Lesson cursor is unavailable.", 404);
                    }

                    // Create teacher sections
                    foreach ($sections as $section) {
                        $f = $section['fields'];
                        TeacherSection::create([
                            'id' => (string) Str::uuid(),
                            'case_id' => $lockedQuery->case_id,
                            'map_id' => $lockedQuery->map_id,
                            'work_node_id' => $workNode->id,
                            'query_id' => $lockedQuery->id,
                            'ordinal_in_work' => (int) $section['ordinal'],
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
                            'first_prompt_sha256' => $lockedQuery->canonical_prompt_sha256 ?? hash('sha256', ''),
                            'answer_sha256' => $answerSha,
                        ]);
                    }

                    // Move cursor to awaiting_010
                    $newVersion = $cursor->version + 1;
                    $cursor->update([
                        'phase' => 'awaiting_010',
                        'current_work_ordinal' => (int) $lockedQuery->target_work_ordinal,
                        'last_query_id' => $lockedQuery->id,
                        'conversation_id' => $conversationOut,
                        'version' => $newVersion,
                    ]);

                    // Update query to recorded
                    $lockedQuery->update([
                        'state' => 'recorded',
                        'answer_path' => $answerPath,
                        'answer_sha256' => $answerSha,
                        'answer_bytes' => strlen($answer),
                        'conversation_out' => $conversationOut,
                        'recorded_at' => now(),
                    ]);

                    return [
                        'query_ref' => $lockedQuery->id,
                        'map_ref' => $lockedQuery->map_id,
                        'cursor_version' => $newVersion,
                        'state' => 'recorded',
                        'created' => true,
                    ];
                });
            } catch (\Illuminate\Database\QueryException $e) {
                if ($attempt === 5) {
                    throw $e;
                }
                usleep(50000 * $attempt);
            }
        }

        throw new \RuntimeException("Transaction retry failed.", 500);
    }
}
