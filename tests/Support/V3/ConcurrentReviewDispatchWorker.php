<?php

/**
 * Runs the real review outbox dispatcher against a shared disposable SQLite DB.
 * The fake bridge pauses after the dispatcher commits its `sending` claim so the
 * parent process can commit an admin control command before bridge acceptance.
 */

require __DIR__ . '/../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$options = getopt('', ['db:', 'barrier:']);
$dbPath = $options['db'] ?? null;
$barrierPath = $options['barrier'] ?? null;

if (! $dbPath || ! $barrierPath) {
    fwrite(STDERR, "Missing --db or --barrier.\n");
    exit(2);
}

config(['database.connections.sqlite.database' => $dbPath]);
Illuminate\Support\Facades\DB::purge('sqlite');
Illuminate\Support\Facades\DB::reconnect('sqlite');
Illuminate\Support\Facades\DB::statement('PRAGMA journal_mode = WAL;');
Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout = 10000;');

$gateway = new class($barrierPath) extends App\Services\V3\Workflow\ReviewSignalGateway {
    public function __construct(private string $barrierPath)
    {
        parent::__construct('http://127.0.0.1', 'test-only');
    }

    public function startCorrection(string $workflowId, array $data): array
    {
        file_put_contents($this->barrierPath . '.entered', '1', LOCK_EX);
        $deadline = microtime(true) + 15;
        while (! is_file($this->barrierPath . '.release') && microtime(true) < $deadline) {
            usleep(10000);
        }

        if (! is_file($this->barrierPath . '.release')) {
            return ['status' => 503, 'accepted' => false, 'error' => ['code' => 'test_barrier_timeout']];
        }

        return [
            'status' => 202,
            'accepted' => true,
            'data' => ['correction_ref' => $data['correction_ref'] ?? null],
        ];
    }
};

try {
    $processed = (new App\Services\V3\Review\DispatchReviewEffects($gateway))->dispatchPending();
    echo json_encode(['success' => true, 'processed' => $processed], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['success' => false, 'error' => $e->getMessage()]));
    exit(1);
}
