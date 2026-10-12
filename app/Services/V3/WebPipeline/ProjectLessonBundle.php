<?php

namespace App\Services\V3\WebPipeline;

class ProjectLessonBundle
{
    public const SCHEMA_COLUMNS = [
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

    public const SCHEMA_PIPELINE = [
        'lesson_title',
        'core_concept',
        'learning_objectives',
        'misconceptions',
        'governing_formulas',
        'numerical_example',
        'visual_representation',
        'interactive_controls',
        'variables_and_ranges',
        'laws_if_any',
        'real_world_application',
    ];

    public const ELEVEN_FIELDS = self::SCHEMA_COLUMNS;

    protected const NEXT_HEADER_LOOKAHEAD = '(?=(?:\n(?:Section\s+Identity|Core\s+Understanding|Visual-First\s+Explanation|Visual\s+Scene\s+Script|Dynamic\s+View\s+Blueprint|Visual\s+Learning\s+Outcome|Concept\s+Check|Conceptual\s+Summary|(?:[•\-*]\s*)?شرح\s+للجزء\s+بالعامية\s+المصرية|القوانين|(?:إسقاط|اسقاط)\s+على\s+مثال|Section\s+[0-9]+)\s*:?|\z))';

    /**
     * Regex patterns for extracting canonical First fields from raw Markdown/text.
     */
    protected const FIELD_PATTERNS = [
        'section_identity' => '/(?:^|\n)Section\s+Identity\s*:?\s*\n?(.*?)' . self::NEXT_HEADER_LOOKAHEAD . '/us',
        'core_understanding' => '/(?:^|\n)Core\s+Understanding\s*:?\s*\n?(.*?)' . self::NEXT_HEADER_LOOKAHEAD . '/us',
        'visual_first_explanation' => '/(?:^|\n)Visual-First\s+Explanation\s*:?\s*\n?(.*?)' . self::NEXT_HEADER_LOOKAHEAD . '/us',
        'visual_scene_script' => '/(?:^|\n)Visual\s+Scene\s+Script\s*:?\s*\n?(.*?)' . self::NEXT_HEADER_LOOKAHEAD . '/us',
        'dynamic_view_blueprint' => '/(?:^|\n)Dynamic\s+View\s+Blueprint\s*:?\s*\n?(.*?)' . self::NEXT_HEADER_LOOKAHEAD . '/us',
        'visual_learning_outcome' => '/(?:^|\n)Visual\s+Learning\s+Outcome\s*:?\s*\n?(.*?)' . self::NEXT_HEADER_LOOKAHEAD . '/us',
        'concept_check' => '/(?:^|\n)Concept\s+Check\s*:?\s*\n?(.*?)' . self::NEXT_HEADER_LOOKAHEAD . '/us',
        'conceptual_summary' => '/(?:^|\n)Conceptual\s+Summary\s*:?\s*\n?(.*?)' . self::NEXT_HEADER_LOOKAHEAD . '/us',
        'colloquial_egyptian_explanation' => '/(?:^|\n)(?:[•\-*]\s*)?شرح\s+للجزء\s+بالعامية\s+المصرية\s*:?\s*\n?(.*?)' . self::NEXT_HEADER_LOOKAHEAD . '/us',
        'laws_if_any' => '/(?:^|\n)القوانين\s*(?:\((?:إن|ان)\s*(?:وُجدت|وجدت)\))?\s*:?\s*\n?(.*?)' . self::NEXT_HEADER_LOOKAHEAD . '/us',
        'real_life_example' => '/(?:^|\n)(?:إسقاط|اسقاط)\s+على\s+مثال\s+أو\s+موقف\s+من\s+الحياة\s+اليومية\s+لتثبيت\s+الفهم\s*:?\s*\n?(.*?)' . self::NEXT_HEADER_LOOKAHEAD . '/us',
    ];

    /**
     * Parse raw First response text into sections with eleven canonical fields.
     *
     * @return array<int, array{ordinal: int, title: string, fields: array<string, ?string>, missing_fields: array<string>, context_digest: string}>
     */
    public function parseRawFirst(string $rawFirst): array
    {
        if (trim($rawFirst) === '') {
            return [];
        }

        // Split into sections by "Section X: Title" pattern
        $sectionBlocks = preg_split('/(?:^|\n)Section\s+([0-9]+)\s*:\s*([^\n]*)/u', $rawFirst, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (count($sectionBlocks) <= 1) {
            // No explicit section markers; treat entire text as Section 1
            $projected = $this->extractFieldsFromText($rawFirst);
            return [
                [
                    'ordinal' => 1,
                    'title' => 'Section 1',
                    'fields' => $projected['fields'],
                    'missing_fields' => $projected['missing_fields'],
                    'context_digest' => $projected['context_digest'],
                ],
            ];
        }

        $results = [];
        // Blocks: [0] = header/intro, then triplets [1]=ordinal, [2]=title, [3]=content, [4]=ordinal, ...
        for ($i = 1; $i < count($sectionBlocks); $i += 3) {
            $ordinal = (int) $sectionBlocks[$i];
            $title = trim($sectionBlocks[$i + 1] ?? "Section {$ordinal}");
            $content = $sectionBlocks[$i + 2] ?? '';

            $projected = $this->extractFieldsFromText($content);
            $results[] = [
                'ordinal' => $ordinal,
                'title' => $title !== '' ? $title : "Section {$ordinal}",
                'fields' => $projected['fields'],
                'missing_fields' => $projected['missing_fields'],
                'context_digest' => $projected['context_digest'],
            ];
        }

        return $results;
    }

    /**
     * Extract fields from text chunk using canonical regex patterns.
     */
    protected function extractFieldsFromText(string $text): array
    {
        $rawFields = [];
        foreach (self::FIELD_PATTERNS as $fieldName => $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $val = trim($matches[1]);
                $rawFields[$fieldName] = $val !== '' ? $val : '';
            } else {
                $rawFields[$fieldName] = null;
            }
        }

        return $this->projectFirstFields($rawFields);
    }

    /**
     * Project eleven First fields, distinguishing NULL (missing) from empty string.
     *
     * @return array{fields: array<string, ?string>, missing_fields: array<string>, is_complete: bool, context_digest: string}
     */
    public function projectFirstFields(array $rawFields): array
    {
        // Detect if input uses pipeline keys (e.g. lesson_title, core_concept)
        // laws_if_any is present in both schemas, so check pipeline-unique keys
        $uniquePipelineKeys = [
            'lesson_title',
            'core_concept',
            'learning_objectives',
            'misconceptions',
            'governing_formulas',
            'numerical_example',
            'visual_representation',
            'interactive_controls',
            'variables_and_ranges',
            'real_world_application',
        ];
        $hasPipelineKeys = false;
        foreach ($uniquePipelineKeys as $k) {
            if (array_key_exists($k, $rawFields)) {
                $hasPipelineKeys = true;
                break;
            }
        }

        $targetKeys = $hasPipelineKeys ? self::SCHEMA_PIPELINE : self::SCHEMA_COLUMNS;
        $fields = [];
        $missingFields = [];

        foreach ($targetKeys as $key) {
            if (array_key_exists($key, $rawFields)) {
                $val = $rawFields[$key];
                if (is_null($val)) {
                    $fields[$key] = null;
                    $missingFields[] = $key;
                } else {
                    $fields[$key] = is_array($val) ? json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $val;
                }
            } else {
                $fields[$key] = null;
                $missingFields[] = $key;
            }
        }

        $digest = $this->computeContextDigest($fields);

        return [
            'fields' => $fields,
            'missing_fields' => $missingFields,
            'is_complete' => empty($missingFields),
            'context_digest' => $digest,
        ];
    }

    /**
     * Canonical context digest separating null and empty string.
     */
    public function computeContextDigest(array $fields): string
    {
        ksort($fields);
        $canonical = json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return hash('sha256', $canonical);
    }

    /**
     * Project scene prompts, preserving arbitrary scene types and verbatim prompt text.
     *
     * @return array<int, array{scene_ordinal: int, scene_title: string, scene_type: string, prompt_body: string, prompt_sha256: string}>
     */
    public function projectScenes(array $rawScenes): array
    {
        $projected = [];

        foreach ($rawScenes as $item) {
            $ordinal = $item['scene_ordinal'] ?? ($item['ordinal'] ?? 1);
            $title = $item['scene_title'] ?? ($item['title'] ?? ($item['section_title'] ?? "Scene {$ordinal}"));
            $sceneType = $item['scene_type'] ?? 'Interactive Visualization';
            $promptBody = $item['prompt'] ?? ($item['generated_prompt'] ?? ($item['prompt_text'] ?? ($item['prompt_body'] ?? '')));

            $projected[] = [
                'scene_ordinal' => (int) $ordinal,
                'scene_title' => (string) $title,
                'scene_type' => (string) $sceneType,
                'prompt_body' => (string) $promptBody,
                'prompt_sha256' => hash('sha256', (string) $promptBody),
            ];
        }

        usort($projected, fn ($a, $b) => $a['scene_ordinal'] <=> $b['scene_ordinal']);

        return $projected;
    }
}
