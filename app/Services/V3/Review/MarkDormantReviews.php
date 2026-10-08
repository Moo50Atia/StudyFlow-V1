<?php

namespace App\Services\V3\Review;

use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\TeacherReviewItem;
use Illuminate\Support\Facades\DB;

class MarkDormantReviews
{
    public function mark(): int
    {
        $pendingItems = TeacherReviewItem::where('status', 'pending')->get();
        $count = 0;

        foreach ($pendingItems as $item) {
            $case = ProcessingCase::find($item->case_id);
            if (! $case) {
                continue;
            }

            $settings = SettingsVersion::find($case->settings_version_id);
            if (! $settings) {
                continue;
            }

            $dormancyDays = (int) ($settings->payload['limits']['review_dormancy_days'] ?? 14);
            if (! $item->ready_at) {
                continue;
            }

            $dormantThreshold = $item->ready_at->copy()->addDays($dormancyDays);
            if ($dormantThreshold->isPast()) {
                DB::transaction(function () use ($item) {
                    $lockedItem = TeacherReviewItem::where('id', $item->id)->lockForUpdate()->first();
                    if ($lockedItem && $lockedItem->status === 'pending') {
                        $lockedItem->update([
                            'status' => 'dormant',
                            'dormant_at' => now(),
                            'state_version' => $lockedItem->state_version + 1,
                        ]);
                    }
                });
                $count++;
            }
        }

        return $count;
    }
}
