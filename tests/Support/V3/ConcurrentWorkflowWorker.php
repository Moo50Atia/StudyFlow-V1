<?php

/**
 * CLI worker script to test concurrent workflow start and event projection.
 * Usage: php ConcurrentWorkflowWorker.php --db=path --mode=start|event --case=id [--payload=json]
 */

require __DIR__.'/../../../vendor/autoload.php';

$app = require_once __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$options = getopt('', [
    'db:',
    'mode:',
    'case:',
    'payload:',
    'delay-ms:',
]);

$dbPath = $options['db'] ?? null;
$mode = $options['mode'] ?? 'start';
$caseId = $options['case'] ?? null;
$payloadJson = $options['payload'] ?? null;
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
    if ($mode === 'start') {
        $discoverer = $app->make(\App\Services\V3\Workflow\DiscoverCases::class);
        $count = $discoverer->discover();
        echo json_encode([
            'success' => true,
            'count' => $count,
        ]);
        exit(0);
    } elseif ($mode === 'event') {
        $projector = $app->make(\App\Services\V3\Workflow\ProjectCaseEvent::class);
        $payload = json_decode($payloadJson, true);
        $result = $projector->project($payload);
        echo json_encode([
            'success' => in_array($result['status'], [200, 201], true),
            'status' => $result['status'],
            'data' => $result,
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
