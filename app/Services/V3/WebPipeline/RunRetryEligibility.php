<?php

namespace App\Services\V3\WebPipeline;

use App\Models\V3\SubjectRun;
use App\Models\V3\LocalJob;
use Illuminate\Support\Facades\DB;

class RunRetryEligibility
{
    public static function allows(SubjectRun $run, string $state): bool
    {
        if (in_array($state, ['failed', 'paused_auth'], true)) return true;
        if ($state === 'paused_quota') {
            return LocalJob::query()->where('run_id', $run->id)->exists();
        }
        if ($state !== 'held_persistence') return false;
        $run->loadMissing('items');
        $cases = $run->items->pluck('case_id')->filter()->all();
        if (! $cases) return false;
        $queries = DB::table('v3_lesson_queries')->whereIn('case_id', $cases);
        return (clone $queries)->exists()
            && ! (clone $queries)->where('state', '!=', 'recorded')->exists();
    }
}
