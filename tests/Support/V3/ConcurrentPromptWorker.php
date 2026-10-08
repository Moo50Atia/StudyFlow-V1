<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tempDb = $argv[1] ?? null;
$caseId = $argv[2] ?? null;
$barrierFile = $argv[3] ?? null;
$action = $argv[4] ?? null; // 'claim_turn' or 'claim_work_item'
$targetRef = $argv[5] ?? null; // work_item_ref or work_ordinal
$step = $argv[6] ?? null; // step name if claim_turn
$payloadJson = $argv[7] ?? null;

if (! $tempDb || ! $caseId || ! $barrierFile || ! $action || ! $payloadJson) {
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
    $payload = json_decode($payloadJson, true);

    if ($action === 'claim_turn') {
        $service = $app->make(\App\Services\V3\Prompts\RecordPromptTurn::class);
        $result = $service->claimTurn($caseId, $targetRef, $step, $payload);
        echo json_encode([
            'success' => true,
            'disposition' => $result['disposition'],
            'turn_ref' => $result['turn']->id,
        ]);
        exit(0);
    } elseif ($action === 'claim_work_item') {
        $service = $app->make(\App\Services\V3\Prompts\ClaimPromptWorkItem::class);
        $result = $service->claim($caseId, (int) $targetRef, $payload);
        echo json_encode([
            'success' => true,
            'status' => $result['status'],
            'work_item_ref' => $result['work_item']->id,
        ]);
        exit(0);
    } else {
        echo json_encode(['error' => "Unknown action: {$action}"]);
        exit(1);
    }
} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'class' => get_class($e),
    ]);
    exit(1);
}
