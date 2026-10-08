<?php

/**
 * CLI worker script to test concurrent source discovery and activation.
 * Usage: php ConcurrentSourceWorker.php --db=path --mode=dispatch
 */

require __DIR__.'/../../../vendor/autoload.php';

$app = require_once __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$options = getopt('', [
    'db:',
    'mode:',
    'case:',
    'delay-ms:',
]);

$dbPath = $options['db'] ?? null;
$mode = $options['mode'] ?? 'dispatch';
$delayMs = isset($options['delay-ms']) ? (int) $options['delay-ms'] : 0;

if ($dbPath) {
    config(['database.connections.sqlite.database' => $dbPath]);
    Illuminate\Support\Facades\DB::purge('sqlite');
    Illuminate\Support\Facades\DB::reconnect('sqlite');
    Illuminate\Support\Facades\DB::statement('PRAGMA journal_mode = WAL;');
    Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout = 5000;');
}

if ($delayMs > 0) {
    usleep($delayMs * 1000);
}

try {
    if ($mode === 'dispatch') {
        $activator = $app->make(\App\Services\V3\Source\ActivateSource::class);
        $count = $activator->discoverAndDispatch();
        echo json_encode([
            'success' => true,
            'count' => $count,
        ]);
        exit(0);
    }
} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
    exit(1);
}
