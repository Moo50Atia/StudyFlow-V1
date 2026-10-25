<?php

namespace App\Services\V3\NotebookLM;

use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SubjectRunItem;

class MappedLessonQuestion
{
    public const RUN_PROTOCOL = 'notebooklm_per_pdf_web_v3';

    public static function enabled(ProcessingCase $case): bool
    {
        return in_array(SubjectRunItem::where('case_id', $case->id)->first()?->run?->protocol,
            ['notebooklm_per_pdf_web_v2', self::RUN_PROTOCOL], true);
    }

    /** Exact questions shared by the signed binding response and query ledger. */
    public static function forCase(ProcessingCase $case): array
    {
        if (! self::enabled($case)) {
            return [];
        }
        $map = LessonMap::where('case_id', $case->id)->where('state', 'ready')->first();
        if (! $map) {
            return [];
        }
        $questions = [];
        foreach (LessonNode::where('map_id', $map->id)->whereNotNull('work_ordinal')->orderBy('work_ordinal')->get() as $node) {
            $ordinal = (int) $node->work_ordinal;
            $command = $ordinal === 1 ? 'Start with First lesson' : 'Continue';
            $topic = json_encode($node->title, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $questions[$ordinal] = $command . "\n\n"
                . "Process ONLY mapped work item W{$ordinal} of {$map->work_count}.\n"
                . "Mapped topic: {$topic}\n"
                . "Use this exact topic from the recorded map, regardless of which topic you think comes next. Do not repeat another work item or read ahead.\n"
                . "Apply First, Second and Third from steps.txt to this topic only.\n"
                . "Use Lecture: W{$ordinal} | {$node->title} in all three artifacts.\n"
                . "End with exactly: ✅ Lesson [{$ordinal}] Completed. Awaiting 'Continue' for the next lesson.";
        }
        return $questions;
    }

    public static function forOrdinal(ProcessingCase $case, int $ordinal): string
    {
        return self::forCase($case)[$ordinal]
            ?? throw new \InvalidArgumentException('Mapped lesson question is unavailable.');
    }
}
