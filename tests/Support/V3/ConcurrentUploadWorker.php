<?php

/**
 * CLI Worker for Intake Concurrency Integration Tests
 * Runs as an independent process against an isolated SQLite file and private storage root.
 */

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$options = getopt('', ['db:', 'attempt:', 'storage:']);

$dbPath = $options['db'] ?? null;
$attemptId = $options['attempt'] ?? null;
$storageRoot = $options['storage'] ?? null;

if (! $dbPath || ! file_exists($dbPath) || ! $attemptId) {
    echo json_encode(['success' => false, 'error' => 'Missing arguments']);
    exit(1);
}

// Reconfigure SQLite connection to point to temp db
config(['database.connections.sqlite.database' => $dbPath]);
\Illuminate\Support\Facades\DB::purge('sqlite');
\Illuminate\Support\Facades\DB::reconnect('sqlite');
\Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout = 10000;');

if ($storageRoot) {
    config(['filesystems.disks.v3_private.root' => $storageRoot]);
}

try {
    // Bind inspector double that approves valid PDF
    $qpdfMock = \Mockery::mock(\App\Services\V3\Intake\QpdfAdapter::class);
    $qpdfMock->shouldReceive('check')->andReturn(['valid' => true, 'page_count' => 1]);
    $clamMock = \Mockery::mock(\App\Services\V3\Intake\ClamAvAdapter::class);
    $clamMock->shouldReceive('scan')->andReturn(['valid' => true]);

    $inspector = new \App\Services\V3\Intake\PdfInspector($qpdfMock, $clamMock);
    $store = $app->make(\App\Services\V3\Intake\PrivatePdfStore::class);
    $coordinator = new \App\Services\V3\Intake\IntakeCoordinator($store, $inspector);

    $maxTries = 5;
    $success = false;
    while ($maxTries > 0 && ! $success) {
        try {
            $coordinator->processAttempt($attemptId);
            $success = true;
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'database is locked') || str_contains($e->getMessage(), 'busy')) {
                $maxTries--;
                usleep(100000); // 100ms
            } else {
                throw $e;
            }
        }
    }

    $attempt = \App\Models\V3\UploadAttempt::find($attemptId);

    echo json_encode([
        'success' => true,
        'attempt_id' => $attemptId,
        'state' => $attempt?->state,
        'duplicate' => $attempt?->duplicate,
        'document_id' => $attempt?->document_id,
    ]);
    exit(0);
} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    exit(1);
}
