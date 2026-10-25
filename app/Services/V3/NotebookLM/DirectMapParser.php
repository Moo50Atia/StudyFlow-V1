<?php

namespace App\Services\V3\NotebookLM;

use InvalidArgumentException;

class DirectMapParser
{
    protected const LINE_PATTERN = '/^(\s*)((?:[│|]\s*)*[├└]──\s*)?(?:=>\s*)?(?:[-*]\s+)?(Chapter|Mini\s+Chapter|Lesson)(?:\s+(?:[0-9]+(?:\.[0-9]+)*|[IVXLCDMivxlcdm]+))?\s*(?::|-|=>)\s*(.+)$/iu';

    /**
     * Parse raw structural Divide response into ordered lesson nodes with single sibling counters across kinds.
     *
     * @return array<int, array{
     *     kind: 'chapter'|'mini_chapter'|'lesson',
     *     position_path: string,
     *     parent_position_path: ?string,
     *     title: string,
     *     ordinal_in_parent: int,
     *     work_ordinal: ?int
     * }>
     */
    public static function parse(string $rawText): array
    {
        $rawText = trim($rawText);
        if ($rawText === '') {
            throw new InvalidArgumentException("Divide response is empty");
        }

        // Some live replies join a parent and its lesson with an arrow on
        // one line. Split only when the arrow introduces a structural label.
        $rawText = preg_replace('/(?<=\S)\s*=>\s*(?=(?:Mini\s+Chapter|Lesson)\s+[0-9IVXLCDM]+\s*[:\-])/iu', "\n", $rawText) ?? $rawText;
        // A leading map heading labels the document, not another lesson.
        $rawText = preg_replace('/\A#{1,6}\s+(?:Structural|Lesson)\s+Map(?:\s*:\s*[^\r\n]+)?\s*\R/iu', '', $rawText, 1) ?? $rawText;
        $lines = preg_split("/\r\n|\n|\r/", $rawText);
        $lines = array_map(function ($line) {
            $line = preg_replace('/^(\s*)#{1,6}\s+(?=(?:Chapter|Mini\s+Chapter|Lesson)\b)/iu', '$1', $line) ?? $line;
            return preg_replace('/^(\s*(?:[-*]\s+)?)\*\*((?:Chapter|Mini\s+Chapter|Lesson)(?:\s+(?:\d+(?:\.\d+)*|[IVXLCDM]+))?\s*[:\-])\*\*\s*/iu', '$1$2 ', $line) ?? $line;
        }, $lines);
        $nodes = [];

        $currentChapter = null;
        $currentMini = null;
        $currentMiniIndent = null;
        $currentLessonIndent = null;

        $chapterCount = 0;
        $chapterCounters = [];
        $miniCounters = [];

        $chapterChildren = [];
        $miniChildren = [];

        $hasIndentation = false;
        foreach ($lines as $l) {
            $trimmed = trim($l);
            if ($trimmed !== '' && (strlen($l) - strlen(ltrim($l, ' ')) > 0 || preg_match('/[│|├└]/u', $l))) {
                $hasIndentation = true;
                break;
            }
        }

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '```') || str_ends_with($line, '```')) {
                throw new InvalidArgumentException("Code fences are prohibited in Divide output");
            }

            if (! preg_match(self::LINE_PATTERN, $rawLine, $matches)) {
                if (preg_match('/^(\s*)((?:[│|]\s*)*[├└]──\s*)(.+)$/u', $rawLine, $detail)) {
                    preg_match_all('/[│|]/u', $detail[2], $bars);
                    $depth = strlen($detail[1]) + (count($bars[0]) + 1) * 2;
                    if ($currentLessonIndent !== null && $depth > $currentLessonIndent
                        && ! preg_match('/^(Chapter|Mini\s+Chapter|Lesson)\b/iu', $detail[3])
                        && mb_strlen(trim($detail[3])) <= 255) {
                        // Subtopics belong to the preceding named Lesson, not
                        // extra work items. The complete raw map stays retained.
                        continue;
                    }
                }
                throw new InvalidArgumentException("Prose or invalid line format: '{$line}'");
            }

            $treePrefix = $matches[2] ?? '';
            preg_match_all('/[│|]/u', $treePrefix, $treeBars);
            $treeDepth = count($treeBars[0]) + (preg_match('/[├└]──/u', $treePrefix) ? 1 : 0);
            $indentLen = strlen($matches[1]) + ($treeDepth * 2);
            $rawKind = $matches[3];
            $title = trim($matches[4]);

            if (str_starts_with($title, '**') && str_ends_with($title, '**')) {
                $title = trim(substr($title, 2, -2));
            }

            if ($title === '' || mb_strlen($title) > 255) {
                throw new InvalidArgumentException("Invalid title length: '{$title}'");
            }

            $kindNorm = strtolower($rawKind);
            if (str_contains($kindNorm, 'mini')) {
                $kind = 'mini_chapter';
            } elseif (str_contains($kindNorm, 'chapter')) {
                $kind = 'chapter';
            } elseif (str_contains($kindNorm, 'lesson')) {
                $kind = 'lesson';
            } else {
                throw new InvalidArgumentException("Unrecognized node kind: '{$rawKind}'");
            }
            $currentLessonIndent = $kind === 'lesson' ? $indentLen : null;
            if ($kind === 'mini_chapter') {
                $currentMiniIndent = $indentLen;
            }

            // Match the Python protocol adapter: tree bars can repeat for
            // earlier siblings, but the semantic hierarchy has only three
            // levels, so a deeply prefixed Lesson is normalized to a leaf.
            if ($kind === 'lesson' && ! in_array($indentLen, [0, 2, 4], true)) {
                $indentLen = 4;
            }

            if ($hasIndentation) {
                if ($kind === 'chapter' && $indentLen !== 0) {
                    throw new InvalidArgumentException("Ambiguous indentation: Chapter has {$indentLen} spaces");
                } elseif (in_array($kind, ['mini_chapter', 'lesson'], true) && ! in_array($indentLen, [0, 2, 4], true)) {
                    throw new InvalidArgumentException("Ambiguous indentation: {$indentLen} spaces for line '{$line}'");
                }
                if ($indentLen === 2 && $kind === 'lesson' && $currentMiniIndent !== 0) {
                    $currentMini = null;
                }
            }

            if ($kind === 'chapter') {
                if ($currentChapter !== null && empty($chapterChildren[$currentChapter['position_path']])) {
                    throw new InvalidArgumentException("Chapter '{$currentChapter['title']}' has no children");
                }

                $chapterCount++;
                $posPath = sprintf('%03d', $chapterCount);
                $node = [
                    'kind' => 'chapter',
                    'position_path' => $posPath,
                    'parent_position_path' => null,
                    'title' => $title,
                    'ordinal_in_parent' => $chapterCount,
                    'work_ordinal' => null,
                ];
                $nodes[] = $node;
                $currentChapter = $node;
                $currentMini = null;
                $chapterCounters[$posPath] = 0;
                $chapterChildren[$posPath] = [];
            } elseif ($kind === 'mini_chapter') {
                if ($currentChapter === null) {
                    throw new InvalidArgumentException("Orphan Mini Chapter '{$title}' without parent Chapter");
                }

                $chapterCounters[$currentChapter['position_path']]++;
                $sibOrdinal = $chapterCounters[$currentChapter['position_path']];
                $posPath = sprintf('%s.%03d', $currentChapter['position_path'], $sibOrdinal);

                $node = [
                    'kind' => 'mini_chapter',
                    'position_path' => $posPath,
                    'parent_position_path' => $currentChapter['position_path'],
                    'title' => $title,
                    'ordinal_in_parent' => $sibOrdinal,
                    'work_ordinal' => null,
                ];
                $nodes[] = $node;
                $currentMini = $node;
                $miniCounters[$posPath] = 0;
                $chapterChildren[$currentChapter['position_path']][] = $node;
                $miniChildren[$posPath] = [];
            } elseif ($kind === 'lesson') {
                if ($currentChapter === null) {
                    throw new InvalidArgumentException("Orphan Lesson '{$title}' without parent Chapter");
                }

                if ($currentMini !== null) {
                    $miniCounters[$currentMini['position_path']]++;
                    $sibOrdinal = $miniCounters[$currentMini['position_path']];
                    $posPath = sprintf('%s.%03d', $currentMini['position_path'], $sibOrdinal);
                    $node = [
                        'kind' => 'lesson',
                        'position_path' => $posPath,
                        'parent_position_path' => $currentMini['position_path'],
                        'title' => $title,
                        'ordinal_in_parent' => $sibOrdinal,
                        'work_ordinal' => null,
                    ];
                    $miniChildren[$currentMini['position_path']][] = $node;
                } else {
                    $chapterCounters[$currentChapter['position_path']]++;
                    $sibOrdinal = $chapterCounters[$currentChapter['position_path']];
                    $posPath = sprintf('%s.%03d', $currentChapter['position_path'], $sibOrdinal);
                    $node = [
                        'kind' => 'lesson',
                        'position_path' => $posPath,
                        'parent_position_path' => $currentChapter['position_path'],
                        'title' => $title,
                        'ordinal_in_parent' => $sibOrdinal,
                        'work_ordinal' => null,
                    ];
                    $chapterChildren[$currentChapter['position_path']][] = $node;
                }

                $nodes[] = $node;
            }
        }

        if (empty($nodes)) {
            throw new InvalidArgumentException("No structural nodes found in Divide response");
        }

        if ($currentChapter !== null && empty($chapterChildren[$currentChapter['position_path']])) {
            throw new InvalidArgumentException("Chapter '{$currentChapter['title']}' has no children");
        }

        // Depth-first contiguous work ordinals W1..Wn
        $workOrdinal = 1;
        foreach ($nodes as &$nodeRef) {
            if ($nodeRef['kind'] === 'lesson') {
                $nodeRef['work_ordinal'] = $workOrdinal++;
            } elseif ($nodeRef['kind'] === 'mini_chapter') {
                if (empty($miniChildren[$nodeRef['position_path']])) {
                    // Promoted childless Mini Chapter!
                    $nodeRef['work_ordinal'] = $workOrdinal++;
                }
            }
        }
        unset($nodeRef);

        if ($workOrdinal === 1) {
            throw new InvalidArgumentException("Divide map contains no work items");
        }

        return $nodes;
    }
}
