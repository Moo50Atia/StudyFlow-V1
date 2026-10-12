<?php

namespace App\Services\V3\WebPipeline;

use App\Models\V3\TeacherSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LessonPublication
{
    public function set(string $workNodeId, int $actorId, bool $publish): void
    {
        DB::transaction(function () use ($workNodeId, $actorId, $publish) {
            $publication = DB::table('v3_lesson_publications')->where('work_node_id', $workNodeId)->lockForUpdate()->first();
            if (! $publication) abort(404);
            $sources = TeacherSection::where('work_node_id', $workNodeId)->get();
            if ($publish) {
                if ($sources->isEmpty()) throw ValidationException::withMessages(['lesson' => 'No lesson sections are available.']);
                $missing = [];
                foreach ($sources as $source) {
                    if ($source->projection_status !== 'complete') $missing[] = "Section {$source->ordinal_in_work}: source incomplete";
                    foreach (app(LessonProjection::class)->missing($source) as $field) $missing[] = "Section {$source->ordinal_in_work}: {$field}";
                    if (! DB::table('v3_section_pipeline_links')->where('teacher_section_id', $source->id)->exists()) $missing[] = "Section {$source->ordinal_in_work}: legacy link";
                }
                if ($missing) throw ValidationException::withMessages(['lesson' => implode(', ', $missing)]);
            }
            DB::table('v3_lesson_publications')->where('id', $publication->id)->update([
                'published_at' => $publish ? now() : null,
                'published_by' => $publish ? $actorId : null,
                'updated_at' => now(),
            ]);
            $links = DB::table('v3_section_pipeline_links as l')
                ->join('v3_teacher_sections as s', 's.id', '=', 'l.teacher_section_id')
                ->where('s.work_node_id', $workNodeId)->select('l.section_id', 's.id as teacher_section_id')->get();
            foreach ($links as $link) {
                $approvalId = $publish ? DB::table('v3_approved_scenes as a')
                    ->join('v3_scene_publications as sp', 'sp.approval_id', '=', 'a.id')
                    ->where('a.section_id', $link->teacher_section_id)
                    ->whereNotIn('a.id', DB::table('v3_postapproval_corrections')->select('approval_id'))
                    ->orderByDesc('a.approved_at')->value('a.id') : null;
                DB::table('sections')->where('id', $link->section_id)->update([
                    'dynamic_view_link' => $approvalId ? route('student-scenes.file', [$approvalId, 'index.html']) : null,
                ]);
            }
            $this->event($workNodeId, $actorId, $publish ? 'publish' : 'unpublish');
        });
    }

    public function revokeForLegacySection(int $sectionId, int $actorId, array $changed): void
    {
        $nodeId = DB::table('v3_section_pipeline_links as l')
            ->join('v3_teacher_sections as s', 's.id', '=', 'l.teacher_section_id')
            ->where('l.section_id', $sectionId)->value('s.work_node_id');
        if (! $nodeId) return;
        DB::transaction(function () use ($nodeId, $actorId, $changed) {
            $publication = DB::table('v3_lesson_publications')->where('work_node_id', $nodeId)->lockForUpdate()->first();
            if ($publication?->published_at) {
                DB::table('v3_lesson_publications')->where('id', $publication->id)->update(['published_at' => null, 'published_by' => null, 'updated_at' => now()]);
                DB::table('sections')->whereIn('id', DB::table('v3_section_pipeline_links as l')
                    ->join('v3_teacher_sections as s', 's.id', '=', 'l.teacher_section_id')
                    ->where('s.work_node_id', $nodeId)->select('l.section_id'))->update(['dynamic_view_link' => null]);
            }
            $this->event($nodeId, $actorId, 'correction', $changed);
        });
    }

    private function event(string $nodeId, int $actorId, string $action, array $details = []): void
    {
        DB::table('v3_lesson_publication_events')->insert([
            'work_node_id' => $nodeId, 'actor_user_id' => $actorId,
            'action' => $action, 'details' => json_encode($details), 'created_at' => now(),
        ]);
    }
}
