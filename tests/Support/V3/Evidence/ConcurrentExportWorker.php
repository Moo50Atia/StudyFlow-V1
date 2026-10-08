<?php

// CLI worker for concurrent export race tests

$options = getopt('', [
    'db:',
    'actor:',
    'role:',
    'url:',
    'payload:',
    'barrier-dir:',
    'worker-id:',
]);

if (! isset($options['db'], $options['actor'], $options['url'], $options['payload'], $options['barrier-dir'], $options['worker-id'])) {
    fwrite(STDERR, "Missing required arguments\n");
    exit(1);
}

$dbPath = $options['db'];
$actorId = (int) $options['actor'];
$url = $options['url'];
$payload = json_decode($options['payload'], true);
$barrierDir = $options['barrier-dir'];
$workerId = $options['worker-id'];

putenv("DB_DATABASE={$dbPath}");
$_ENV['DB_DATABASE'] = $dbPath;

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$consoleKernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$consoleKernel->bootstrap();
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);

// Configure SQLite database
config(['database.default' => 'sqlite']);
config(['database.connections.sqlite.database' => $dbPath]);
config(['database.connections.sqlite.busy_timeout' => 10000]);
config(['database.connections.sqlite.journal_mode' => 'WAL']);
config(['database.connections.sqlite.transaction_mode' => 'IMMEDIATE']);
\Illuminate\Support\Facades\DB::purge('sqlite');
\Illuminate\Support\Facades\DB::reconnect('sqlite');
\Illuminate\Support\Facades\DB::statement('PRAGMA journal_mode = WAL;');
\Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout = 10000;');

// Signal ready
file_put_contents("{$barrierDir}/ready_{$workerId}", time());

// Wait for go barrier
$goFile = "{$barrierDir}/go";
$startWait = microtime(true);
while (! file_exists($goFile)) {
    usleep(1000); // 1ms
    if (microtime(true) - $startWait > 5.0) {
        fwrite(STDERR, "Timed out waiting for barrier go signal\n");
        exit(2);
    }
}

// Log in actor
$user = \App\Models\User::find($actorId);
\Illuminate\Support\Facades\Auth::login($user);

// Execute POST request
$request = \Illuminate\Http\Request::create(
    $url,
    'POST',
    [],
    [],
    [],
    [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ],
    json_encode($payload)
);

$response = $kernel->handle($request);
$content = $response->getContent();
$statusCode = $response->getStatusCode();

echo json_encode([
    'worker_id' => $workerId,
    'status' => $statusCode,
    'body' => json_decode($content, true),
]);

exit($statusCode >= 200 && $statusCode < 500 ? 0 : 3);
