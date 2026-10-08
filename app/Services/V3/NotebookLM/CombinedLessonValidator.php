<?php

namespace App\Services\V3\NotebookLM;

class CombinedLessonValidator
{
    public const MAX_REPLY_BYTES = 1048576; // 1 MiB
    public const MAX_THIRD_PROMPT_BYTES = 65536;

    public const PERMITTED_SCENE_TYPES = [
        'Process',
        'Comparison',
        'Accumulation',
        'Parallel Flow',
        'Time Evolution',
    ];

    public const PREPARED_PERSPECTIVE_MAP_FIELDS = [
        'primary_learning_objective' => '/^(?:[•\-*]\s*)?Primary\s+learning\s+objective\s*[:.]\s*(.*)$/i',
        'scene_type' => '/^(?:[•\-*]\s*)?Scene\s+Type\s*[:.]\s*(.*)$/i',
        'baseline_objects_and_state' => '/^(?:[•\-*]\s*)?Baseline\s+objects\s+and\s+state\s*[:.]\s*(.*)$/i',
        'time_perspective' => '/^(?:[•\-*]\s*)?Time\s+Perspective\s*[:.]\s*(.*)$/i',
        'custom_perspectives' => '/^(?:[•\-*]\s*)?Custom\s+Perspectives\s*[:.]\s*(.*)$/i',
        'governing_behavior' => '/^(?:[•\-*]\s*)?Governing\s+Behavior\s*[:.]\s*(.*)$/i',
        'learner_investigations' => '/^(?:[•\-*]\s*)?Learner\s+Investigations\s*[:.]\s*(.*)$/i',
        'controls' => '/^(?:[•\-*]\s*)?Controls\s*[:.]\s*(.*)$/i',
        'linked_views' => '/^(?:[•\-*]\s*)?Linked\s+Views\s*[:.]\s*(.*)$/i',
        'visual_learning_goal' => '/^(?:[•\-*]\s*)?Visual\s+Learning\s+Goal\s*[:.]\s*(.*)$/i',
        'coverage' => '/^(?:[•\-*]\s*)?Coverage\s*[:.]\s*(.*)$/i',
        'acceptance_checks' => '/^(?:[•\-*]\s*)?Acceptance\s+Checks\s*[:.]\s*(.*)$/i',
        'evidence_gaps' => '/^(?:[•\-*]\s*)?Evidence\s+Gaps\s*[:.]\s*(.*)$/i',
    ];

    public const FIRST_LABEL_SPECS = [
        ['name' => 'section_identity', 'pattern' => '/^Section\s+Identity\s*:\s*(.*)$/i', 'can_empty' => false],
        ['name' => 'core_understanding', 'pattern' => '/^Core\s+Understanding\s*:\s*(.*)$/i', 'can_empty' => false],
        ['name' => 'visual_first_explanation', 'pattern' => '/^Visual-First\s+Explanation\s*:\s*(.*)$/i', 'can_empty' => false],
        ['name' => 'visual_scene_script', 'pattern' => '/^Visual\s+Scene\s+Script\s*:\s*(.*)$/i', 'can_empty' => false],
        ['name' => 'dynamic_view_blueprint', 'pattern' => '/^Dynamic\s+View\s+Blueprint\s*:\s*(.*)$/i', 'can_empty' => false],
        ['name' => 'visual_learning_outcome', 'pattern' => '/^Visual\s+Learning\s+Outcome\s*:\s*(.*)$/i', 'can_empty' => false],
        ['name' => 'concept_check', 'pattern' => '/^Concept\s+Check\s*:\s*(.*)$/i', 'can_empty' => false],
        ['name' => 'conceptual_summary', 'pattern' => '/^Conceptual\s+Summary\s*:\s*(.*)$/i', 'can_empty' => false],
        ['name' => 'colloquial_egyptian_explanation', 'pattern' => '/^(?:[•\-*]\s*)?شرح\s+للجزء\s+بالعامية\s+المصرية\s*:\s*(.*)$/u', 'can_empty' => false],
        ['name' => 'laws_if_any', 'pattern' => '/^القوانين\s*\((?:إن|ان)\s*(?:وُجدت|وجدت)\)\s*:\s*(.*)$/u', 'can_empty' => true],
        ['name' => 'real_life_example', 'pattern' => '/^(?:إسقاط|اسقاط)\s+على\s+مثال\s+أو\s+موقف\s+من\s+الحياة\s+اليومية\s+لتثبيت\s+الفهم\s*:\s*(.*)$/u', 'can_empty' => false],
    ];

    /**
     * Parse and validate combined lesson envelope.
     *
     * @param string $rawText
     * @param int $expectedWorkOrdinal
     * @return array
     * @throws \InvalidArgumentException
     */
    public function validateCombinedBundle(string $rawText, int $expectedWorkOrdinal): array
    {
        if (trim($rawText) === '') {
            throw new \InvalidArgumentException("Combined response is empty.");
        }

        if (strlen($rawText) > self::MAX_REPLY_BYTES) {
            throw new \InvalidArgumentException("Combined response exceeds 1 MiB limit.");
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $rawText);

        $dFirst = "=== FIRST ===";
        $dSecond = "=== SECOND ===";
        $dThird = "=== THIRD ===";
        $dEnd = "=== END ===";

        $posFirst = strpos($normalized, $dFirst);
        $posSecond = strpos($normalized, $dSecond);
        $posThird = strpos($normalized, $dThird);
        $posEnd = strpos($normalized, $dEnd);

        if ($posFirst === false || $posSecond === false || $posThird === false || $posEnd === false) {
            throw new \InvalidArgumentException("Missing required envelope delimiter(s).");
        }

        if (! ($posFirst < $posSecond && $posSecond < $posThird && $posThird < $posEnd)) {
            throw new \InvalidArgumentException("Envelope delimiters out of required order.");
        }

        if (
            substr_count($normalized, $dFirst) !== 1
            || substr_count($normalized, $dSecond) !== 1
            || substr_count($normalized, $dThird) !== 1
            || substr_count($normalized, $dEnd) !== 1
        ) {
            throw new \InvalidArgumentException("Duplicate delimiter line detected.");
        }

        $firstPart = trim(substr($normalized, $posFirst + strlen($dFirst), $posSecond - ($posFirst + strlen($dFirst))));
        $secondPart = trim(substr($normalized, $posSecond + strlen($dSecond), $posThird - ($posSecond + strlen($dSecond))));
        $thirdPart = trim(substr($normalized, $posThird + strlen($dThird), $posEnd - ($posThird + strlen($dThird))));
        $afterEnd = trim(substr($normalized, $posEnd + strlen($dEnd)));

        if ($firstPart === '' || $secondPart === '' || $thirdPart === '') {
            throw new \InvalidArgumentException("Envelope contains empty artifact(s).");
        }

        // Validate terminal completion marker
        if (! preg_match('/✅\s*Lesson\s*\[?([0-9]+)\]?\s*Completed\.\s*Awaiting\s*[\'"]Continue[\'"]\s*for\s*the\s*next\s*lesson\.\s*$/u', $afterEnd, $markerMatch)) {
            throw new \InvalidArgumentException("Invalid or missing terminal completion marker: '{$afterEnd}'.");
        }

        $terminalOrdinal = (int) $markerMatch[1];
        if ($terminalOrdinal !== $expectedWorkOrdinal) {
            throw new \InvalidArgumentException("Terminal completion marker ordinal mismatch: expected {$expectedWorkOrdinal}, got {$terminalOrdinal}.");
        }

        // 1. Parse First artifact
        $firstParsed = $this->parseFirstArtifact($firstPart, $expectedWorkOrdinal);

        // 2. Parse Second artifact
        $secondParsed = $this->parseSecondArtifact($secondPart, $expectedWorkOrdinal, count($firstParsed['sections']));

        // 3. Parse Third artifact
        $thirdParsed = $this->parseThirdArtifact($thirdPart, $expectedWorkOrdinal, count($firstParsed['sections']));

        $lectureTitle = $firstParsed['lecture_header'];
        if (stripos($lectureTitle, 'lecture:') === 0) {
            $lectureTitle = trim(substr($lectureTitle, strlen('lecture:')));
        }

        return [
            'work_ordinal' => $expectedWorkOrdinal,
            'terminal_marker_ordinal' => $terminalOrdinal,
            'lecture_title' => $lectureTitle,
            'first' => $firstParsed,
            'second' => $secondParsed,
            'third' => $thirdParsed,
            'raw_first' => $firstPart,
            'raw_second' => $secondPart,
            'raw_third' => $thirdPart,
        ];
    }

    protected function parseFirstArtifact(string $text, int $expectedWorkOrdinal): array
    {
        $lines = explode("\n", $text);
        $lectureHeader = '';
        $lineIdx = 0;
        $totalLines = count($lines);

        while ($lineIdx < $totalLines) {
            $line = trim($lines[$lineIdx]);
            $lineIdx++;
            if ($line === '') {
                continue;
            }
            if (preg_match('/^Lecture\s*:\s*(.+)$/i', $line, $match)) {
                $lectureHeader = $match[1];
                break;
            } else {
                throw new \InvalidArgumentException("First artifact must begin with Lecture header, got '{$line}'.");
            }
        }

        $sections = [];
        $currentSecOrdinal = null;
        $currentFields = [];
        $currentLabelIdx = -1;

        $finalizeCurrentSection = function () use (&$currentSecOrdinal, &$currentFields, &$currentLabelIdx, &$sections) {
            if ($currentSecOrdinal !== null) {
                if (count($currentFields) !== count(self::FIRST_LABEL_SPECS)) {
                    $missing = [];
                    foreach (self::FIRST_LABEL_SPECS as $spec) {
                        if (! array_key_exists($spec['name'], $currentFields)) {
                            $missing[] = $spec['name'];
                        }
                    }
                    throw new \InvalidArgumentException("Section {$currentSecOrdinal} is missing fields: " . implode(', ', $missing));
                }

                foreach (self::FIRST_LABEL_SPECS as $spec) {
                    $val = $currentFields[$spec['name']];
                    if (! $spec['can_empty'] && trim($val) === '') {
                        throw new \InvalidArgumentException("Section {$currentSecOrdinal} field '{$spec['name']}' cannot be empty.");
                    }
                    $currentFields[$spec['name']] = trim($val);
                }

                $sections[] = [
                    'ordinal' => $currentSecOrdinal,
                    'fields' => $currentFields,
                ];
                $currentFields = [];
                $currentLabelIdx = -1;
            }
        };

        while ($lineIdx < $totalLines) {
            $rawLine = $lines[$lineIdx];
            $line = trim($rawLine);
            $lineIdx++;

            if ($line === '') {
                if ($currentLabelIdx >= 0) {
                    $currentFieldName = self::FIRST_LABEL_SPECS[$currentLabelIdx]['name'];
                    $currentFields[$currentFieldName] .= "\n";
                }
                continue;
            }

            // Check if section header
            if (preg_match('/^Section\s+([0-9]+)\s*:\s*$/i', $line, $secMatch)) {
                $finalizeCurrentSection();
                $secNum = (int) $secMatch[1];
                $expectedSecNum = count($sections) + 1;
                if ($secNum !== $expectedSecNum) {
                    throw new \InvalidArgumentException("Non-contiguous section numbering: expected Section {$expectedSecNum}, got Section {$secNum}.");
                }
                $currentSecOrdinal = $secNum;
                continue;
            }

            if ($currentSecOrdinal === null) {
                throw new \InvalidArgumentException("Unexpected content before Section 1: '{$line}'.");
            }

            // Check if line starts the next expected label in canonical order
            $nextLabelIdx = $currentLabelIdx + 1;
            if ($nextLabelIdx < count(self::FIRST_LABEL_SPECS)) {
                $spec = self::FIRST_LABEL_SPECS[$nextLabelIdx];
                if (preg_match($spec['pattern'], $line, $match)) {
                    $currentLabelIdx = $nextLabelIdx;
                    $currentFields[$spec['name']] = $match[1];
                    continue;
                }
            }

            // Check if matches any other label
            $matchedOther = false;
            foreach (self::FIRST_LABEL_SPECS as $idx => $spec) {
                if ($idx !== $currentLabelIdx && preg_match($spec['pattern'], $line)) {
                    throw new \InvalidArgumentException("Field '{$spec['name']}' out of canonical order or duplicated in Section {$currentSecOrdinal}.");
                }
            }

            // Continuation line for current field
            if ($currentLabelIdx >= 0) {
                $currentFieldName = self::FIRST_LABEL_SPECS[$currentLabelIdx]['name'];
                $currentFields[$currentFieldName] .= "\n" . $rawLine;
                continue;
            }

            throw new \InvalidArgumentException("Unrecognized line in Section {$currentSecOrdinal}: '{$line}'.");
        }

        $finalizeCurrentSection();

        if (empty($sections)) {
            throw new \InvalidArgumentException("First artifact contains no valid sections.");
        }

        return [
            'lecture_header' => $lectureHeader,
            'sections' => $sections,
        ];
    }

    protected function parseSecondArtifact(string $text, int $expectedWorkOrdinal, int $expectedSectionCount): array
    {
        $lines = explode("\n", $text);
        $lectureHeader = '';
        $lineIdx = 0;
        $totalLines = count($lines);

        while ($lineIdx < $totalLines) {
            $line = trim($lines[$lineIdx]);
            $lineIdx++;
            if ($line === '') {
                continue;
            }
            if (preg_match('/^Lecture\s*:\s*(.+)$/i', $line, $match)) {
                $lectureHeader = $match[1];
                break;
            } else {
                throw new \InvalidArgumentException("Second artifact must begin with Lecture header, got '{$line}'.");
            }
        }

        $sections = [];
        $currentSecOrdinal = null;
        $currentPlaceholder = null;
        $currentFields = [];
        $inPerspectiveMap = false;
        $currentFieldKey = null;

        $finalizeSecondSection = function () use (
            &$currentSecOrdinal,
            &$currentPlaceholder,
            &$currentFields,
            &$inPerspectiveMap,
            &$currentFieldKey,
            &$sections
        ) {
            if ($currentSecOrdinal !== null) {
                if ($currentPlaceholder === null) {
                    throw new \InvalidArgumentException("Second section {$currentSecOrdinal} missing placeholder.");
                }
                if (count($currentFields) !== count(self::PREPARED_PERSPECTIVE_MAP_FIELDS)) {
                    $missing = array_diff(array_keys(self::PREPARED_PERSPECTIVE_MAP_FIELDS), array_keys($currentFields));
                    throw new \InvalidArgumentException("Second section {$currentSecOrdinal} missing Prepared Perspective Map field(s): " . implode(', ', $missing));
                }
                foreach (self::PREPARED_PERSPECTIVE_MAP_FIELDS as $fieldName => $pattern) {
                    $val = $currentFields[$fieldName] ?? '';
                    if (trim($val) === '') {
                        throw new \InvalidArgumentException("Second section {$currentSecOrdinal} field '{$fieldName}' cannot be empty.");
                    }
                    $currentFields[$fieldName] = trim($val);
                }

                $sections[] = [
                    'ordinal' => $currentSecOrdinal,
                    'placeholder' => $currentPlaceholder,
                    'perspective_map' => $currentFields,
                ];
                $currentFields = [];
                $currentPlaceholder = null;
                $inPerspectiveMap = false;
                $currentFieldKey = null;
            }
        };

        while ($lineIdx < $totalLines) {
            $line = trim($lines[$lineIdx]);
            $lineIdx++;
            if ($line === '') {
                continue;
            }

            if (preg_match('/^Lec\s*([0-9]+)\s*\(\s*Sec\s*([0-9]+)\s*\)\s*:\s*$/i', $line, $headerMatch)) {
                $finalizeSecondSection();
                $secNum = (int) $headerMatch[2];
                $expectedSecNum = count($sections) + 1;
                if ($secNum !== $expectedSecNum) {
                    throw new \InvalidArgumentException("Second section numbering non-contiguous: expected Sec{$expectedSecNum}, got Sec{$secNum}.");
                }
                $currentSecOrdinal = $secNum;
                continue;
            }

            if ($currentSecOrdinal === null) {
                throw new \InvalidArgumentException("Unexpected content before LecW (SecY) header: '{$line}'.");
            }

            if ($currentPlaceholder === null) {
                if ((str_starts_with($line, '"') && str_ends_with($line, '"')) || (str_starts_with($line, "'") && str_ends_with($line, "'"))) {
                    $currentPlaceholder = trim(substr($line, 1, -1));
                    continue;
                } elseif (stripos($line, 'prepared perspective map') === 0) {
                    throw new \InvalidArgumentException("Missing quoted placeholder before Prepared Perspective Map in section {$currentSecOrdinal}.");
                }
            }

            if (stripos($line, 'prepared perspective map') === 0) {
                $inPerspectiveMap = true;
                continue;
            }

            if ($inPerspectiveMap) {
                $matchedField = false;
                foreach (self::PREPARED_PERSPECTIVE_MAP_FIELDS as $fName => $pat) {
                    if (preg_match($pat, $line, $m)) {
                        $currentFieldKey = $fName;
                        $currentFields[$fName] = trim($m[1]);
                        $matchedField = true;
                        break;
                    }
                }
                if ($matchedField) {
                    continue;
                }

                if ($currentFieldKey !== null) {
                    $currentFields[$currentFieldKey] .= "\n" . $line;
                    continue;
                }

                throw new \InvalidArgumentException("Unrecognized line in Prepared Perspective Map for section {$currentSecOrdinal}: '{$line}'.");
            }
        }

        $finalizeSecondSection();

        if (count($sections) !== $expectedSectionCount) {
            throw new \InvalidArgumentException("Section count mismatch: First had {$expectedSectionCount} sections, Second had " . count($sections) . ".");
        }

        return [
            'lecture_header' => $lectureHeader,
            'sections' => $sections,
        ];
    }

    protected function parseThirdArtifact(string $text, int $expectedWorkOrdinal, int $expectedSectionCount): array
    {
        $lines = explode("\n", $text);
        $lectureHeader = '';
        $lineIdx = 0;
        $totalLines = count($lines);

        while ($lineIdx < $totalLines) {
            $line = trim($lines[$lineIdx]);
            $lineIdx++;
            if ($line === '') {
                continue;
            }
            if (preg_match('/^Lecture\s*:\s*(.+)$/i', $line, $match)) {
                $lectureHeader = $match[1];
                break;
            } else {
                throw new \InvalidArgumentException("Third artifact must begin with Lecture header, got '{$line}'.");
            }
        }

        $sections = [];
        $currentSecOrdinal = null;
        $promptLines = [];
        $inPrompt = false;

        $finalizeThirdSection = function () use (&$currentSecOrdinal, &$promptLines, &$inPrompt, &$sections) {
            if ($currentSecOrdinal !== null) {
                if (empty($promptLines)) {
                    throw new \InvalidArgumentException("Third section {$currentSecOrdinal} has empty prompt.");
                }

                $rawPrompt = trim(implode("\n", $promptLines));
                if (! (str_starts_with($rawPrompt, '"') && str_ends_with($rawPrompt, '"'))) {
                    throw new \InvalidArgumentException("Third section {$currentSecOrdinal} prompt must be enclosed in quotes.");
                }

                $promptBody = trim(substr($rawPrompt, 1, -1));
                if (strlen($promptBody) > self::MAX_THIRD_PROMPT_BYTES) {
                    throw new \InvalidArgumentException("Third section {$currentSecOrdinal} prompt exceeds " . self::MAX_THIRD_PROMPT_BYTES . " bytes.");
                }

                $firstPromptLine = trim(explode("\n", $promptBody)[0]);

                if (preg_match('/^Scene\s+Type\s*:/i', $firstPromptLine)) {
                    throw new \InvalidArgumentException("Third section {$currentSecOrdinal} invalid v1 type prefix 'Scene Type:'; must start with permitted type name.");
                }

                $detectedType = null;
                foreach (self::PERMITTED_SCENE_TYPES as $pType) {
                    if (preg_match('/^' . preg_quote($pType, '/') . '\b/i', $firstPromptLine)) {
                        $detectedType = $pType;
                        break;
                    }
                }

                if ($detectedType === null) {
                    throw new \InvalidArgumentException("Third section {$currentSecOrdinal} prompt must begin with permitted type name.");
                }

                $sections[] = [
                    'ordinal' => $currentSecOrdinal,
                    'scene_type' => $detectedType,
                    'prompt_body' => $promptBody,
                ];

                $currentSecOrdinal = null;
                $promptLines = [];
                $inPrompt = false;
            }
        };

        while ($lineIdx < $totalLines) {
            $rawLine = $lines[$lineIdx];
            $stripped = trim($rawLine);
            $lineIdx++;

            if ($stripped === '' && ! $inPrompt) {
                continue;
            }

            if (preg_match('/^Lec\s*([0-9]+)\s*\(\s*Sec\s*([0-9]+)\s*\)\s*:\s*$/i', $stripped, $headerMatch)) {
                $finalizeThirdSection();
                $secNum = (int) $headerMatch[2];
                $expectedSecNum = count($sections) + 1;
                if ($secNum !== $expectedSecNum) {
                    throw new \InvalidArgumentException("Third section numbering non-contiguous: expected Sec{$expectedSecNum}, got Sec{$secNum}.");
                }
                $currentSecOrdinal = $secNum;
                $inPrompt = false;
                continue;
            }

            if ($currentSecOrdinal === null) {
                throw new \InvalidArgumentException("Unexpected content before LecW (SecY) header: '{$stripped}'.");
            }

            if (! $inPrompt) {
                if (str_starts_with($stripped, '"')) {
                    $inPrompt = true;
                    $promptLines[] = $stripped;
                    if (str_ends_with($stripped, '"') && strlen($stripped) > 1) {
                        $finalizeThirdSection();
                    }
                } else {
                    throw new \InvalidArgumentException("Third section {$currentSecOrdinal} prompt must begin with quote, got '{$stripped}'.");
                }
            } else {
                $promptLines[] = $rawLine;
                if (str_ends_with($stripped, '"') && ! str_ends_with($stripped, '\\"')) {
                    $finalizeThirdSection();
                }
            }
        }

        $finalizeThirdSection();

        if (count($sections) !== $expectedSectionCount) {
            throw new \InvalidArgumentException("Section count mismatch: First had {$expectedSectionCount} sections, Third had " . count($sections) . ".");
        }

        return [
            'lecture_header' => $lectureHeader,
            'sections' => $sections,
        ];
    }
}
