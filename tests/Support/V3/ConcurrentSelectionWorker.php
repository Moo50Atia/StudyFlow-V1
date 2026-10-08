<?php

/**
 * CLI Worker for Selection Concurrency Tests
 * Runs as an independent process against an isolated SQLite file.
 */

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$options = getopt('', ['db:', 'consumer_table:']);

$dbPath = $options['db'] ?? null;
$consumerTable = $options['consumer_table'] ?? 'v3_test_consumer_cases';

if (! $dbPath || ! file_exists($dbPath)) {
    echo json_encode(['success' => false, 'error' => 'Database file missing']);
    exit(1);
}

config(['database.connections.sqlite.database' => $dbPath]);
\Illuminate\Support\Facades\DB::purge('sqlite');
\Illuminate\Support\Facades\DB::reconnect('sqlite');

try {
    $selector = $app->make(\App\Services\V3\Settings\SelectCaseSettings::class);

    $result = \Illuminate\Support\Facades\DB::transaction(function () use ($selector, $consumerTable) {
        $selected = $selector->selectForNewCase(\Illuminate\Support\Facades\DB::connection());

        \Illuminate\Support\Facades\DB::table($consumerTable)->insert([
            'settings_version_id' => $selected->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $selected->id,
            'version' => $selected->version,
        ];
    });

    echo json_encode([
        'success' => true,
        'selected_version_id' => $result['id'],
        'selected_version' => $result['version'],
    ]);
    exit(0);
} catch (\App\Services\V3\Settings\SettingsUnavailable $e) {
    echo json_encode(['success' => false, 'code' => 'settings_unavailable', 'error' => $e->getMessage()]);
    exit(0);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit(0);
}
