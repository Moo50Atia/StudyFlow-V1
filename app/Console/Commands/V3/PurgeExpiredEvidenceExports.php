<?php

namespace App\Console\Commands\V3;

use App\Models\V3\EvidenceExport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PurgeExpiredEvidenceExports extends Command
{
    protected $signature = 'v3:purge-expired-evidence-exports';

    protected $description = 'Purge temporary evidence export files at least 24 hours after their 15-minute expiration under pilot-retain-v1';

    public function handle(): int
    {
        $cutoff = Carbon::now()->subHours(24);

        $expiredExports = EvidenceExport::whereNull('file_removed_at')
            ->where('expires_at', '<=', $cutoff)
            ->get();

        $purgedCount = 0;
        $disk = Storage::disk('v3_private');

        foreach ($expiredExports as $export) {
            try {
                $snapshotPath = $export->snapshot_path;

                // Safety guard: ensure the path is strictly within the 015 export prefix
                if (! str_starts_with($snapshotPath, 'evidence-exports/')) {
                    Log::warning("Skipping export purge for unrecognized path: {$snapshotPath}", [
                        'export_id' => $export->id,
                    ]);

                    continue;
                }

                if ($disk->exists($snapshotPath)) {
                    // Check whether any other non-purged export still references this file
                    $otherReferences = EvidenceExport::where('snapshot_path', $snapshotPath)
                        ->where('id', '!=', $export->id)
                        ->whereNull('file_removed_at')
                        ->where('expires_at', '>', $cutoff)
                        ->exists();

                    if (! $otherReferences) {
                        $disk->delete($snapshotPath);
                    }
                }

                $export->update([
                    'file_removed_at' => Carbon::now()->setTimezone('UTC'),
                ]);

                $purgedCount++;
            } catch (\Throwable $e) {
                Log::error("Failed to purge expired evidence export {$export->id}: {$e->getMessage()}", [
                    'export_id' => $export->id,
                    'exception' => $e,
                ]);
            }
        }

        $this->info("Purged {$purgedCount} expired evidence export files.");

        return self::SUCCESS;
    }
}
