<?php

/**
 * CLI Worker for Settings Publication Concurrency Tests
 * Runs as an independent process against an isolated SQLite file.
 */

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$options = getopt('', ['db:', 'actor:', 'expected_version:', 'payload:']);

$dbPath = $options['db'] ?? null;
$actorId = (int) ($options['actor'] ?? 0);
$expectedVersion = (int) ($options['expected_version'] ?? 0);
$payloadJson = $options['payload'] ?? null;

if (! $dbPath || ! file_exists($dbPath)) {
    echo json_encode(['success' => false, 'error' => 'Database file missing']);
    exit(1);
}

if (! $payloadJson) {
    echo json_encode(['success' => false, 'error' => 'Payload missing']);
    exit(1);
}

$payload = json_decode($payloadJson, true);
if (! is_array($payload)) {
    echo json_encode(['success' => false, 'error' => 'Invalid payload JSON']);
    exit(1);
}

config(['database.connections.sqlite.database' => $dbPath]);
\Illuminate\Support\Facades\DB::purge('sqlite');
\Illuminate\Support\Facades\DB::reconnect('sqlite');

try {
    $service = $app->make(\App\Services\V3\Settings\PublishSettings::class);
    $version = $service->publish($payload, $expectedVersion, $actorId);

    echo json_encode([
        'success' => true,
        'version' => $version->version,
        'version_id' => $version->id,
        'checksum' => $version->checksum,
    ]);
    exit(0);
} catch (\App\Services\V3\Settings\SettingsConflict $e) {
    echo json_encode(['success' => false, 'status' => 409, 'code' => 'settings_conflict', 'error' => $e->getMessage()]);
    exit(0);
} catch (\App\Services\V3\Settings\SettingsUnavailable $e) {
    echo json_encode(['success' => false, 'status' => 503, 'code' => 'settings_unavailable', 'error' => $e->getMessage()]);
    exit(0);
} catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
    echo json_encode(['success' => false, 'status' => $e->getStatusCode(), 'error' => $e->getMessage()]);
    exit(0);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'status' => 500, 'error' => $e->getMessage()]);
    exit(0);
}
