<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tempDb = $argv[1] ?? null;
$caseId = $argv[2] ?? null;
$requestRef = $argv[3] ?? null;
$ordinal = isset($argv[4]) ? (int) $argv[4] : null;
$barrierFile = $argv[5] ?? null;
$storagePath = $argv[6] ?? null;

if (! $tempDb || ! $caseId || ! $requestRef || ! $ordinal || ! $barrierFile) {
    echo json_encode(['error' => 'Missing arguments']);
    exit(1);
}

config(['database.connections.sqlite.database' => $tempDb]);
if ($storagePath) {
    config(['filesystems.disks.v3_private.root' => $storagePath]);
}
Illuminate\Support\Facades\DB::purge('sqlite');
Illuminate\Support\Facades\DB::reconnect('sqlite');
Illuminate\Support\Facades\DB::statement('PRAGMA journal_mode = WAL;');
Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout = 5000;');

// Signal ready
file_put_contents($barrierFile . '.' . getmypid(), 'ready');

// Wait for go signal
$timeout = time() + 10;
while (! file_exists($barrierFile) && time() < $timeout) {
    usleep(10000);
}

try {
    $service = $app->make(\App\Services\V3\Authoring\ClaimCodingAttempt::class);
    $result = $service->claim($caseId, $requestRef, [
        'schema_version' => 1,
        'ordinal' => $ordinal,
    ]);

    echo json_encode([
        'status_code' => $result['status_code'],
        'attempt_ref' => $result['data']['attempt_ref'],
        'ordinal' => $result['data']['ordinal'],
        'state' => $result['data']['state'],
    ]);
    exit(0);
} catch (\App\Services\V3\Authoring\AuthoringConflictException $e) {
    echo json_encode([
        'status_code' => 409,
        'error' => 'authoring_conflict',
    ]);
    exit(0);
} catch (\Throwable $e) {
    echo json_encode([
        'status_code' => 500,
        'error' => $e->getMessage(),
    ]);
    exit(1);
}
