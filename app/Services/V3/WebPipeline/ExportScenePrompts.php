<?php

namespace App\Services\V3\WebPipeline;

use App\Models\V3\PromptSectionState;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use App\Models\V3\TeacherSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExportScenePrompts
{
    /**
     * Export exact scene prompt descriptions and associations for a run, lecture or section.
     *
     * @param SubjectRun $run
     * @param string|null $itemId
     * @param string|null $sceneId
     * @return array
     */
    public function export(SubjectRun $run, ?string $itemId = null, ?string $sceneId = null): array
    {
        $itemsQuery = SubjectRunItem::where('run_id', $run->id);
        if ($itemId) {
            $itemsQuery->where('id', $itemId);
        }
        $items = $itemsQuery->orderBy('pdf_ordinal')->get();
        $itemsByCaseId = $items->filter(fn ($it) => ! empty($it->case_id))->keyBy('case_id');
        $caseIds = $itemsByCaseId->keys()->all();

        $sectionsQuery = TeacherSection::whereIn('case_id', $caseIds);
        if ($sceneId) {
            $sectionsQuery->where('id', $sceneId);
        }
        $sections = $sectionsQuery->with('lessonNode')->get();

        $prompts = [];

        foreach ($sections as $sec) {
            $item = $itemsByCaseId->get($sec->case_id);
            $state = PromptSectionState::where('section_id', $sec->id)->first();
            $pv = null;

            if ($state && $state->current_prompt_version_id) {
                $pv = ScenePromptVersion::find($state->current_prompt_version_id);
            }
            if (! $pv) {
                $pv = ScenePromptVersion::where('section_id', $sec->id)->orderBy('version_no', 'desc')->first();
            }

            // Historical imports can contain a teacher section without a
            // corresponding Third prompt. Export only durable prompts; never
            // invent a placeholder row for content that does not exist.
            if (! $pv) {
                continue;
            }

            $promptText = '';
            if ($pv && $pv->body_path && Storage::disk('v3_private')->exists($pv->body_path)) {
                $promptText = Storage::disk('v3_private')->get($pv->body_path);
            }

            $prompts[] = [
                'prompt_version_id' => $pv?->id,
                'scene_id' => $sec->id,
                'item_id' => $item?->id,
                'pdf_ordinal' => $item?->pdf_ordinal ?? 1,
                'lesson_ordinal' => $sec->lessonNode?->work_ordinal,
                'scene_ordinal' => $sec->ordinal_in_work,
                'filename' => $item?->original_filename ?? $item?->display_title,
                'section_title' => $sec->section_identity ?? "Section {$sec->ordinal_in_work}",
                'scene_type' => $pv?->scene_type ?? 'Interactive Visualization',
                'prompt_text' => $promptText,
                'prompt_sha256' => $pv?->body_sha256,
                'template_sha256' => $pv?->canonical_third_sha256,
                'body_bytes' => $pv?->body_bytes ?? strlen($promptText),
            ];
        }

        // Keep the captured PDF / lesson / section order; titles are labels.
        usort($prompts, function ($a, $b) {
            return [$a['pdf_ordinal'], $a['lesson_ordinal'] ?? PHP_INT_MAX, $a['scene_ordinal'], $a['scene_id']]
                <=> [$b['pdf_ordinal'], $b['lesson_ordinal'] ?? PHP_INT_MAX, $b['scene_ordinal'], $b['scene_id']];
        });

        return [
            'schema_version' => 1,
            'run_id' => $run->id,
            'subject_id' => $run->subject_id,
            'mode' => $run->mode,
            'total_prompts' => count($prompts),
            'prompts' => $prompts,
        ];
    }

    /**
     * Format export data into structured human-readable text.
     */
    public function formatText(array $exportData): string
    {
        $lines = [
            "=== V3 SCENE PROMPT EXPORT ===",
            "Run ID: {$exportData['run_id']}",
            "Mode: {$exportData['mode']}",
            "Total Prompts: {$exportData['total_prompts']}",
            "",
        ];

        foreach ($exportData['prompts'] as $idx => $p) {
            $num = $idx + 1;
            $lines[] = "--- [Prompt {$num}] {$p['section_title']} ---";
            $lines[] = "File: {$p['filename']} (PDF Ordinal: {$p['pdf_ordinal']})";
            $lines[] = "Scene Type: {$p['scene_type']}";
            $lines[] = "Prompt Version ID: {$p['prompt_version_id']}";
            $lines[] = "Prompt SHA256: {$p['prompt_sha256']}";
            $lines[] = "Template SHA256: {$p['template_sha256']}";
            $lines[] = "Prompt Text:";
            $lines[] = trim($p['prompt_text']);
            $lines[] = "";
        }

        return implode("\n", $lines);
    }
}
