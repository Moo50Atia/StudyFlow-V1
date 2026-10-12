<?php

namespace App\Services\V3\WebPipeline;

use App\Models\Section;
use App\Models\V3\SectionPipelineLink;
use App\Models\V3\TeacherSection;
use Illuminate\Support\Facades\DB;

class LessonProjection
{
    public const FIELDS = [
        'section_identity' => 'title',
        'conceptual_summary' => 'quick_summary',
        'core_understanding' => 'core_concept',
        'colloquial_egyptian_explanation' => 'egyptian_explain',
        'laws_if_any' => 'formulas',
        'real_life_example' => 'real_life',
    ];

    public const REQUIRED = [
        'section_identity', 'core_understanding', 'visual_first_explanation',
        'visual_scene_script', 'dynamic_view_blueprint', 'visual_learning_outcome',
        'concept_check', 'conceptual_summary', 'colloquial_egyptian_explanation',
        'laws_if_any', 'real_life_example',
    ];

    public function missing(TeacherSection $source): array
    {
        return array_values(array_filter(self::REQUIRED, fn ($field) => trim((string) $source->{$field}) === ''));
    }

    public function project(TeacherSection $source, int $lectureId, int $itemOwnerId, string $itemId, bool $dryRun = false): ?Section
    {
        $link = SectionPipelineLink::where('teacher_section_id', $source->id)->first();
        $legacy = $link ? Section::find($link->section_id) : null;
        $values = [];
        foreach (self::FIELDS as $from => $to) {
            $incoming = trim((string) $source->{$from});
            if ($incoming !== '' && (! $legacy || trim((string) $legacy->{$to}) === '')) {
                $values[$to] = $incoming;
            }
        }
        $values['title'] ??= trim((string) ($legacy?->title ?: $source->section_identity)) ?: 'Untitled section';
        if ($dryRun) return $legacy;

        return DB::transaction(function () use ($source, $lectureId, $itemOwnerId, $itemId, $link, $legacy, $values) {
            if ($legacy) {
                $legacy->fill($values)->save();
            } else {
                $legacy = Section::create(['lecture_id' => $lectureId] + $values);
                SectionPipelineLink::create([
                    'section_id' => $legacy->id,
                    'teacher_section_id' => $source->id,
                    'item_id' => $itemId,
                    'owner_user_id' => $itemOwnerId,
                ]);
            }
            return $legacy;
        });
    }
}
