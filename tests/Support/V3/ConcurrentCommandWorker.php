<?php

/**
 * CLI worker script to test concurrent pipeline command reservation and racing.
 * Usage: php ConcurrentCommandWorker.php --db=path --run=uuid --actor=id --action=name --expected-version=int [--command-id=uuid] [--request-sha=sha] [--delay-ms=int]
 */

require __DIR__ . '/../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$options = getopt('', [
    'db:',
    'run:',
    'actor:',
    'action:',
    'expected-version:',
    'command-id:',
    'request-sha:',
    'delay-ms:',
]);

$dbPath = $options['db'] ?? null;
$runId = $options['run'] ?? null;
$actorId = isset($options['actor']) ? (int) $options['actor'] : 1;
$action = $options['action'] ?? 'pause';
$expectedVersion = isset($options['expected-version']) ? (int) $options['expected-version'] : 1;
$commandId = $options['command-id'] ?? (string) Illuminate\Support\Str::uuid();
$requestSha = $options['request-sha'] ?? hash('sha256', "cmd-{$commandId}");
$delayMs = isset($options['delay-ms']) ? (int) $options['delay-ms'] : 0;

if ($dbPath) {
    config(['database.connections.sqlite.database' => $dbPath]);
    Illuminate\Support\Facades\DB::purge('sqlite');
    Illuminate\Support\Facades\DB::reconnect('sqlite');
    Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout = 10000;');
}

if ($delayMs > 0) {
    usleep($delayMs * 1000);
}

try {
    $ledger = app(\App\Services\V3\WebPipeline\CommandLedger::class);
    $res = $ledger->recordCommand(
        runId: $runId,
        actorUserId: $actorId,
        action: $action,
        expectedVersion: $expectedVersion,
        commandId: $commandId,
        requestSha: $requestSha
    );

    if ($res['status'] === 'duplicate') {
        echo json_encode([
            'success' => true,
            'result' => [
                'status' => 'duplicate',
                'state_version' => $res['state_version'],
            ],
        ]);
        exit(0);
    }

    $applied = $ledger->applyCommand($res['command']);
    $run = \App\Models\V3\SubjectRun::find($runId);

    echo json_encode([
        'success' => true,
        'result' => [
            'status' => 'applied',
            'state_version' => (int) $run->state_version,
        ],
    ]);
} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
    ]);
}
