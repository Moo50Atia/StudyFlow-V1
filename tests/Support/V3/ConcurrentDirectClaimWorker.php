<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tempDb = $argv[1] ?? null;
$action = $argv[2] ?? null; // 'claim' or 'result'
$caseId = $argv[3] ?? null;
$barrierFile = $argv[4] ?? null;
$payloadJson = $argv[5] ?? null;

if (! $tempDb || ! $action || ! $caseId) {
    echo json_encode(['error' => 'Missing arguments']);
    exit(1);
}

config(['database.connections.sqlite.database' => $tempDb]);
if ($storageRoot = getenv('V3_PRIVATE_STORAGE_ROOT')) {
    config(['filesystems.disks.v3_private.root' => $storageRoot]);
}
Illuminate\Support\Facades\DB::purge('sqlite');
Illuminate\Support\Facades\DB::reconnect('sqlite');
Illuminate\Support\Facades\DB::statement('PRAGMA journal_mode = WAL;');
Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout = 5000;');

$payload = $payloadJson ? json_decode($payloadJson, true) : [];

// Signal ready and wait for barrier if requested
if ($barrierFile && $barrierFile !== 'none') {
    file_put_contents($barrierFile . '.' . getmypid(), 'ready');

    $timeout = time() + 10;
    while (! file_exists($barrierFile) && time() < $timeout) {
        usleep(5000);
    }
}

try {
    if ($action === 'claim') {
        $ledger = $app->make(\App\Services\V3\NotebookLM\DirectQueryLedger::class);
        $result = $ledger->claimQuery(
            caseId: $caseId,
            kind: $payload['kind'],
            workOrdinal: (int) $payload['work_ordinal'],
            bindingRef: $payload['binding_ref'],
            expectedCursorVersion: $payload['expected_cursor_version'] ?? null,
            conversationIn: $payload['conversation_in'] ?? null,
            requestSha256: $payload['request_sha256'] ?? null,
            historyLengthBefore: $payload['history_length_before'] ?? null,
            historyAnchorSha256: $payload['history_anchor_sha256'] ?? null,
        );
        echo json_encode(['success' => true, 'result' => $result]);
        exit(0);
    } elseif ($action === 'result') {
        $service = $app->make(\App\Services\V3\NotebookLM\AcceptCombinedLesson::class);
        $result = $service->acceptCombinedLesson(
            queryRef: $payload['query_ref'],
            payload: $payload['result_payload'],
        );
        echo json_encode(['success' => true, 'result' => $result]);
        exit(0);
    } elseif ($action === 'control') {
        $mode = $payload['mode'] ?? 'held';
        Illuminate\Support\Facades\DB::table('v3_case_operation_controls')
            ->updateOrInsert(
                ['case_id' => $caseId],
                ['mode' => $mode, 'control_version' => 2, 'updated_at' => now()]
            );
        echo json_encode(['success' => true, 'action' => 'control', 'mode' => $mode]);
        exit(0);
    } elseif ($action === 'cancel') {
        Illuminate\Support\Facades\DB::table('v3_processing_cases')
            ->where('id', $caseId)
            ->update(['state' => 'cancelled', 'updated_at' => now()]);
        Illuminate\Support\Facades\DB::table('v3_case_operation_controls')
            ->updateOrInsert(
                ['case_id' => $caseId],
                ['mode' => 'cancelled', 'control_version' => 2, 'updated_at' => now()]
            );
        echo json_encode(['success' => true, 'action' => 'cancel']);
        exit(0);
    } elseif ($action === 'suspend') {
        $userId = $payload['user_id'];
        Illuminate\Support\Facades\DB::table('v3_account_access')
            ->where('user_id', $userId)
            ->update(['is_active' => false, 'updated_at' => now()]);
        echo json_encode(['success' => true, 'action' => 'suspend']);
        exit(0);
    } else {
        echo json_encode(['error' => "Unknown action: {$action}"]);
        exit(1);
    }
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'code' => $e->getCode()]);
    exit(1);
}
