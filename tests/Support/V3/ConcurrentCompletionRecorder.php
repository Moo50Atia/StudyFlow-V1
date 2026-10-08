<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tempDb = $argv[1] ?? null;
$caseId = $argv[2] ?? null;
$barrierFile = $argv[3] ?? null;
$payloadJson = $argv[4] ?? null;

if (! $tempDb || ! $caseId || ! $barrierFile || ! $payloadJson) {
    echo json_encode(['error' => 'Missing arguments']);
    exit(1);
}

config(['database.connections.sqlite.database' => $tempDb]);
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
    $service = $app->make(\App\Services\V3\Lessons\LessonCursorService::class);
    $payload = json_decode($payloadJson, true);
    $result = $service->recordCompletion($caseId, $payload);

    echo json_encode(['success' => true, 'result' => $result]);
    exit(0);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'code' => $e->getCode()]);
    exit(1);
}
