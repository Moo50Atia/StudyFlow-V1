<?php

namespace App\Console\Commands\V3;

use App\Models\V3\SubjectRunItem;
use App\Models\V3\TeacherSection;
use App\Services\V3\WebPipeline\LessonProjection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillLegacyLessons extends Command
{
    protected $signature = 'v3:backfill-legacy-lessons {--dry-run : Report changes without writing}';
    protected $description = 'Reconcile V3 lesson sections with legacy sections and report incomplete imports';

    public function handle(LessonProjection $projection): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $partial = 0;
        TeacherSection::orderBy('id')->chunk(100, function ($sources) use ($projection, $dryRun, &$created, &$partial) {
            foreach ($sources as $source) {
                $itemId = $source->subject_run_item_id ?: DB::table('v3_processing_cases')->where('id', $source->case_id)->value('subject_run_item_id');
                $item = $itemId ? SubjectRunItem::find($itemId) : null;
                if (! $item?->lecture_id) continue;
                $missing = $projection->missing($source);
                if ($missing) {
                    $partial++;
                    $this->warn("{$source->id}: missing " . implode(', ', $missing));
                }
                $existing = DB::table('v3_section_pipeline_links')->where('teacher_section_id', $source->id)->exists();
                if (! $existing) $created++;
                if ($dryRun) continue;
                DB::transaction(function () use ($projection, $source, $item) {
                    $projection->project($source, (int) $item->lecture_id, (int) $item->owner_user_id, $item->id);
                    DB::table('v3_lesson_publications')->updateOrInsert(
                        ['work_node_id' => $source->work_node_id],
                        ['lecture_id' => $item->lecture_id, 'updated_at' => now(), 'created_at' => now()]
                    );
                });
            }
        });
        $this->info(($dryRun ? 'Would create' : 'Created') . " {$created} links; {$partial} incomplete sections reported.");
        return self::SUCCESS;
    }
}
