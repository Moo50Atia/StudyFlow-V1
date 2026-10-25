<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('v3:reconcile-intake')->everyMinute();
Schedule::command('v3:dispatch-workflows')->everyMinute();
Schedule::command('v3:dispatch-sources')->everyMinute();
Schedule::command('v3:dispatch-maps')->everyMinute();
Schedule::command('v3:reconcile-lessons')->everyMinute();
Schedule::command('v3:reconcile-notebook-protocol')->everyMinute();
Schedule::command('v3:reconcile-authoring')->everyMinute();
Schedule::command('v3:reconcile-scene-reviews')->everyMinute();
Schedule::command('v3:dispatch-review-effects')->everyMinute();
Schedule::command('v3:mark-dormant-reviews')->daily();
Schedule::command('v3:dispatch-admin-commands')->everyMinute();
Schedule::command('v3:purge-expired-evidence-exports')->hourly();
Schedule::command('v3:dispatch-web-pipeline')->everyMinute();
Schedule::command('v3:expire-local-worker-leases')->everyMinute();

Artisan::command('v3:mark-dormant-reviews', function (\App\Services\V3\Review\MarkDormantReviews $service) {
    $count = $service->mark();
    $this->info("Marked {$count} review items as dormant.");
})->purpose('Mark pending reviews as dormant when exceeding captured review_dormancy_days');

Artisan::command('v3:dispatch-admin-commands', function (\App\Services\V3\Admin\DispatchAdminCommands $service) {
    $count = $service->dispatch();
    $this->info("Dispatched {$count} admin commands.");
})->purpose('Dispatch pending and uncertain admin commands to Temporal bridge');

// A timestamp written by the scheduler itself, scoped to its container.
if (getenv("PRODUCT_PROJECT_PATH")) {
    Schedule::call(fn () => touch("/tmp/scheduler-heartbeat"))->everyMinute();
}
