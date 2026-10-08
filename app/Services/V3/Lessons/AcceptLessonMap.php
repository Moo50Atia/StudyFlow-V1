<?php

namespace App\Services\V3\Lessons;

use App\Models\V3\LessonCursor;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\LessonQuery;
use App\Models\V3\MapActivation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AcceptLessonMap
{
    public function __construct(
        protected LessonEvidenceStore $evidenceStore
    ) {}

    /**
     * Accept Divide query response and atomically persist pending map and nodes.
     *
     * @return array{query_ref: string, map_ref: string, cursor_version: ?int, state: string, created: bool}
     */
    public function acceptDivideResult(
        string $queryRef,
        string $answer,
        string $conversationOut,
        array $parsedNodes
    ): array {
        $query = LessonQuery::where('id', $queryRef)->first();
        if (! $query || $query->kind !== 'divide') {
            throw new \RuntimeException("Lesson query is unavailable.", 404);
        }

        $case = $query->processingCase;
        $isDirect = $case?->isDirectProtocol() ?? false;

        if (empty($parsedNodes)) {
            if ($isDirect) {
                $parsedNodes = \App\Services\V3\NotebookLM\DirectMapParser::parse($answer);
            } else {
                throw new \InvalidArgumentException("Parsed nodes cannot be empty.");
            }
        }

        $answerSha = hash('sha256', $answer);
        $answerPath = $this->evidenceStore->putAnswer($query->case_id, $query->id, $answer, $answerSha);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                return DB::transaction(function () use ($query, $answerSha, $answerPath, $answer, $conversationOut, $parsedNodes, $case, $isDirect) {
                    $lockedQuery = LessonQuery::where('id', $query->id)->lockForUpdate()->first();

                    if ($lockedQuery->state === 'recorded') {
                        if ($lockedQuery->answer_sha256 !== $answerSha) {
                            throw new \RuntimeException("Lesson state changed.", 409);
                        }
                        return [
                            'query_ref' => $lockedQuery->id,
                            'map_ref' => $lockedQuery->map_id,
                            'cursor_version' => null,
                            'state' => 'recorded',
                            'created' => false,
                        ];
                    }

                    $activation = MapActivation::where('case_id', $lockedQuery->case_id)
                        ->where('state', 'accepted')
                        ->first();
                    if (! $activation) {
                        throw new \RuntimeException("Map activation is unavailable.", 404);
                    }

                    $binding = $activation->knowledgeSourceBinding;
                    if (! $binding || $binding->state !== 'bound') {
                        throw new \RuntimeException("Knowledge source is unavailable.", 404);
                    }

                    if (! $isDirect) {
                        $extracted = $binding->extractedSource;
                        if (! $extracted) {
                            throw new \RuntimeException("Knowledge source is unavailable.", 404);
                        }
                        $pageCount = $extracted->page_count;
                        $coverageMode = 'verified_text_markers';
                        $mapState = 'pending';
                    } else {
                        $document = $case->document;
                        $pageCount = $document?->page_count ?? 1;
                        $coverageMode = 'not_requested_direct_pdf';
                        $mapState = 'ready';
                    }

                    // Count work nodes
                    $workCount = 0;
                    foreach ($parsedNodes as $node) {
                        if (! empty($node['work_ordinal'])) {
                            $workCount++;
                        }
                    }

                    if ($workCount === 0) {
                        throw new \InvalidArgumentException("Divide map must contain at least one work item.");
                    }

                    // Create or resolve LessonMap
                    $existingMap = LessonMap::where('case_id', $lockedQuery->case_id)->lockForUpdate()->first();
                    if ($existingMap) {
                        if ($existingMap->divide_query_id && $existingMap->divide_query_id !== $lockedQuery->id) {
                            throw new \RuntimeException("Lesson state changed.", 409);
                        }
                        $map = $existingMap;
                    } else {
                        $map = LessonMap::create([
                            'id' => (string) Str::uuid(),
                            'case_id' => $lockedQuery->case_id,
                            'binding_id' => $binding->id,
                            'activation_id' => $activation->id,
                            'state' => $mapState,
                            'coverage_mode' => $coverageMode,
                            'divide_query_id' => $lockedQuery->id,
                            'divide_prompt_sha256' => $lockedQuery->canonical_prompt_sha256,
                            'map_sha256' => hash('sha256', json_encode($parsedNodes)),
                            'page_count' => $pageCount,
                            'work_count' => $workCount,
                        ]);
                    }

                    // Persist nodes
                    $pathMap = [];
                    foreach ($parsedNodes as $nodeData) {
                        $posPath = $nodeData['position_path'];
                        $parentPos = $nodeData['parent_position_path'] ?? null;
                        $parentId = $parentPos ? ($pathMap[$parentPos] ?? null) : null;

                        $node = LessonNode::create([
                            'id' => (string) Str::uuid(),
                            'map_id' => $map->id,
                            'parent_id' => $parentId,
                            'kind' => $nodeData['kind'],
                            'position_path' => $posPath,
                            'ordinal_in_parent' => $nodeData['ordinal_in_parent'],
                            'title' => $nodeData['title'],
                            'work_ordinal' => $nodeData['work_ordinal'] ?? null,
                        ]);

                        $pathMap[$posPath] = $node->id;
                    }

                    // Update query
                    $lockedQuery->update([
                        'map_id' => $map->id,
                        'state' => 'recorded',
                        'answer_path' => $answerPath,
                        'answer_sha256' => $answerSha,
                        'answer_bytes' => strlen($answer),
                        'conversation_out' => $conversationOut,
                        'recorded_at' => now(),
                    ]);

                    $cursorVersion = null;
                    if ($isDirect) {
                        $cursor = LessonCursor::where('case_id', $lockedQuery->case_id)->lockForUpdate()->first();
                        if (! $cursor) {
                            $cursor = LessonCursor::create([
                                'case_id' => $lockedQuery->case_id,
                                'map_id' => $map->id,
                                'current_work_ordinal' => 1,
                                'phase' => 'start_pending',
                                'conversation_id' => $conversationOut,
                                'last_query_id' => $lockedQuery->id,
                                'version' => 1,
                            ]);
                        }
                        $cursorVersion = $cursor->version;
                    }

                    return [
                        'query_ref' => $lockedQuery->id,
                        'map_ref' => $map->id,
                        'cursor_version' => $cursorVersion,
                        'state' => 'recorded',
                        'created' => true,
                    ];
                }, 5);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($attempt === 5) {
                    throw $e;
                }
                usleep(50000 * $attempt);
            }
        }
    }

    /**
     * Accept Coverage query response, assign coverage to nodes, make map ready, and create cursor.
     *
     * @return array{query_ref: string, map_ref: string, cursor_version: int, state: string, created: bool}
     */
    public function acceptCoverageResult(
        string $queryRef,
        string $answer,
        string $conversationOut,
        array $parsedPages
    ): array {
        $query = LessonQuery::where('id', $queryRef)->first();
        if (! $query || $query->kind !== 'coverage') {
            throw new \RuntimeException("Lesson query is unavailable.", 404);
        }

        if (empty($parsedPages)) {
            throw new \InvalidArgumentException("Parsed pages cannot be empty.");
        }

        $answerSha = hash('sha256', $answer);
        $answerPath = $this->evidenceStore->putAnswer($query->case_id, $query->id, $answer, $answerSha);

        return DB::transaction(function () use ($query, $answerSha, $answerPath, $answer, $conversationOut, $parsedPages) {
            $lockedQuery = LessonQuery::where('id', $query->id)->lockForUpdate()->first();

            if ($lockedQuery->state === 'recorded') {
                if ($lockedQuery->answer_sha256 !== $answerSha) {
                    throw new \RuntimeException("Lesson state changed.", 409);
                }
                $cursor = LessonCursor::where('case_id', $lockedQuery->case_id)->first();
                return [
                    'query_ref' => $lockedQuery->id,
                    'map_ref' => $lockedQuery->map_id,
                    'cursor_version' => $cursor?->version ?? 1,
                    'state' => 'recorded',
                    'created' => false,
                ];
            }

            $map = LessonMap::where('case_id', $lockedQuery->case_id)->lockForUpdate()->first();
            if (! $map) {
                throw new \RuntimeException("Lesson map is unavailable.", 404);
            }

            // Map page assignments to work ordinals
            $workPagesMap = [];
            foreach ($parsedPages as $pageItem) {
                $pageNum = (int) $pageItem['page'];
                $workOrds = $pageItem['work_ordinals'] ?? [];
                foreach ($workOrds as $w) {
                    $workPagesMap[$w][] = $pageNum;
                }
            }

            // Update work nodes with coverage pages
            $workNodes = LessonNode::where('map_id', $map->id)
                ->whereNotNull('work_ordinal')
                ->get();

            foreach ($workNodes as $wNode) {
                $pages = array_values(array_unique($workPagesMap[$wNode->work_ordinal] ?? []));
                sort($pages);
                $wNode->update(['coverage_pages' => $pages]);
            }

            // Mark map ready
            $map->update([
                'coverage_query_id' => $lockedQuery->id,
                'state' => 'ready',
            ]);

            // Create initial cursor
            $cursor = LessonCursor::firstOrCreate(
                ['case_id' => $lockedQuery->case_id],
                [
                    'map_id' => $map->id,
                    'current_work_ordinal' => 1,
                    'phase' => 'first_pending',
                    'conversation_id' => $conversationOut,
                    'version' => 1,
                ]
            );

            // Update query
            $lockedQuery->update([
                'map_id' => $map->id,
                'state' => 'recorded',
                'answer_path' => $answerPath,
                'answer_sha256' => $answerSha,
                'answer_bytes' => strlen($answer),
                'conversation_out' => $conversationOut,
                'recorded_at' => now(),
            ]);

            return [
                'query_ref' => $lockedQuery->id,
                'map_ref' => $map->id,
                'cursor_version' => $cursor->version,
                'state' => 'recorded',
                'created' => true,
            ];
        }, 5);
    }
}
