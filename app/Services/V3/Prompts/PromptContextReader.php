<?php

namespace App\Services\V3\Prompts;

use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\LessonCursor;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\LessonQuery;
use App\Models\V3\ProcessingCase;
use App\Models\V3\TeacherSection;
use Illuminate\Support\Facades\Storage;

class PromptContextReader
{
    public const CANONICAL_EXECUTION_SHA256 = '5ab2448f34c9b2b90199347772d10d7d217ba9fd468dffc28e3169bd0a7fdce9';
    public const CANONICAL_SECOND_SHA256 = 'a8776affc821a1b913a4685b309dc0304afc03c7389fcfe44b366b6f5683f203';
    public const CANONICAL_THIRD_SHA256 = '07798d744b973ec11248897d2efe0e769ab481c4a21cce7562696dd131217955';

    public const ELEVEN_FIELDS = [
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

    public static function computeSectionFieldsSha256(array $fields): string
    {
        $buffer = "010-section-v1\n";
        foreach (self::ELEVEN_FIELDS as $fieldName) {
            $val = str_replace(["\r\n", "\r"], "\n", (string) ($fields[$fieldName] ?? ''));
            $len = strlen($val);
            $buffer .= "{$fieldName}\n{$len}:{$val}\n";
        }
        return hash('sha256', $buffer);
    }

    public function read(string $caseId, int $workOrdinal): array
    {
        /** @var ProcessingCase|null $case */
        $case = ProcessingCase::find($caseId);
        if (! $case) {
            throw new PromptUnavailableException("Case not found.");
        }

        /** @var KnowledgeSourceBinding|null $binding */
        $binding = KnowledgeSourceBinding::where('case_id', $caseId)
            ->where('state', 'bound')
            ->first();
        if (! $binding) {
            throw new PromptUnavailableException("Source binding not found or not bound.");
        }

        /** @var LessonMap|null $map */
        $map = LessonMap::where('case_id', $caseId)->first();
        if (! $map || $map->state !== 'ready') {
            throw new PromptUnavailableException("Lesson map not found or not ready.");
        }

        /** @var LessonCursor|null $cursor */
        $cursor = LessonCursor::where('case_id', $caseId)->first();
        if (! $cursor || (int) $cursor->current_work_ordinal !== $workOrdinal || $cursor->phase !== 'awaiting_010') {
            throw new PromptUnavailableException("Cursor not at awaiting_010 for work ordinal {$workOrdinal}.");
        }

        /** @var LessonNode|null $workNode */
        $workNode = LessonNode::where('map_id', $map->id)
            ->where('work_ordinal', $workOrdinal)
            ->first();
        if (! $workNode) {
            throw new PromptUnavailableException("Work node not found for ordinal {$workOrdinal}.");
        }

        $sections = TeacherSection::where('case_id', $caseId)
            ->where('work_node_id', $workNode->id)
            ->orderBy('ordinal_in_work')
            ->get();

        if ($sections->isEmpty()) {
            throw new PromptUnavailableException("No teacher sections found for work ordinal {$workOrdinal}.");
        }

        $sectionRefs = $sections->pluck('id')->all();
        $firstQueryId = $sections->first()->query_id;

        $sectionsData = [];
        foreach ($sections as $sec) {
            $fields = [];
            foreach (self::ELEVEN_FIELDS as $fn) {
                $fields[$fn] = (string) ($sec->{$fn} ?? '');
            }

            $sectionsData[] = [
                'section_ref' => $sec->id,
                'ordinal_in_work' => (int) $sec->ordinal_in_work,
                'fields' => $fields,
            ];
        }

        $continueOrigin = null;
        if ($workOrdinal > 1) {
            /** @var LessonQuery|null $continueQuery */
            $continueQuery = LessonQuery::where('case_id', $caseId)
                ->where('kind', 'continue')
                ->where('target_work_ordinal', $workOrdinal)
                ->first();

            if ($continueQuery && $continueQuery->state === 'recorded') {
                $rawAnswer = '';
                // Read from private disk if path exists, or retrieve
                $artifactPath = "lesson-evidence/{$caseId}/continue_w{$workOrdinal}_{$continueQuery->answer_sha256}.txt";
                if (Storage::disk('v3_private')->exists($artifactPath)) {
                    $rawAnswer = Storage::disk('v3_private')->get($artifactPath);
                }

                $continueOrigin = [
                    'query_ref' => $continueQuery->id,
                    'answer' => $rawAnswer,
                    'answer_sha256' => $continueQuery->answer_sha256,
                    'conversation_out' => $continueQuery->conversation_out,
                ];
            }
        }

        return [
            'case_id' => $caseId,
            'map_ref' => $map->id,
            'work_node_ref' => $workNode->id,
            'work_ordinal' => $workOrdinal,
            'work_path' => $workNode->position_path,
            'section_query_ref' => $firstQueryId,
            'section_refs' => $sectionRefs,
            'sections' => $sectionsData,
            'conversation_ref' => $cursor->conversation_id,
            'cursor_version' => (int) $cursor->version,
            'phase' => $cursor->phase,
            'canonical_digests' => [
                'execution' => self::CANONICAL_EXECUTION_SHA256,
                'second' => self::CANONICAL_SECOND_SHA256,
                'third' => self::CANONICAL_THIRD_SHA256,
            ],
            'continue_origin' => $continueOrigin,
        ];
    }
}
