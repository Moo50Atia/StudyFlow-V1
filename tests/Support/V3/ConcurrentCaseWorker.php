<?php

/**
 * CLI worker script to test concurrent case creation.
 * Usage: php ConcurrentCaseWorker.php --db=path --storage=path --user=id --doc=uuid --action=action --key=uuid [--publish=payload_json] [--actor=admin_id]
 */

require __DIR__.'/../../../vendor/autoload.php';

$app = require_once __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$options = getopt('', [
    'db:',
    'storage:',
    'user:',
    'doc:',
    'action:',
    'key:',
    'delay-ms:',
    'publish:',
    'actor:',
    'expected-version:',
]);

$dbPath = $options['db'] ?? null;
$storagePath = $options['storage'] ?? null;
$userId = isset($options['user']) ? (int) $options['user'] : null;
$docId = $options['doc'] ?? null;
$action = $options['action'] ?? 'process';
$key = $options['key'] ?? null;
$delayMs = isset($options['delay-ms']) ? (int) $options['delay-ms'] : 0;
$publishJson = $options['publish'] ?? null;
$actorId = isset($options['actor']) ? (int) $options['actor'] : null;
$expectedVersion = isset($options['expected-version']) ? (int) $options['expected-version'] : 0;

if ($dbPath) {
    config(['database.connections.sqlite.database' => $dbPath]);
    Illuminate\Support\Facades\DB::purge('sqlite');
    Illuminate\Support\Facades\DB::reconnect('sqlite');
    Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout = 10000;');
}

if ($storagePath) {
    config(['filesystems.disks.v3_private.root' => $storagePath]);
}

if ($delayMs > 0) {
    usleep($delayMs * 1000);
}

// Mode 1: Settings Publisher Worker
if ($publishJson && $actorId) {
    $payload = json_decode($publishJson, true);
    $publisher = $app->make(\App\Services\V3\Settings\PublishSettings::class);
    try {
        $version = $publisher->publish($payload, $expectedVersion, $actorId);
        echo json_encode([
            'success' => true,
            'type' => 'publish',
            'version_id' => $version->id,
            'version' => $version->version,
        ]);
        exit(0);
    } catch (\Throwable $e) {
        echo json_encode([
            'success' => false,
            'type' => 'publish',
            'error' => get_class($e),
            'message' => $e->getMessage(),
        ]);
        exit(1);
    }
}

// Mode 2: Case Starter Worker
$starter = $app->make(\App\Services\V3\Intake\StartProcessingCase::class);

try {
    $result = $starter->start($userId, $docId, $action, $key);
    echo json_encode([
        'success' => $result['success'],
        'status' => $result['status'] ?? ($result['is_replay'] ?? false ? 200 : 201),
        'code' => $result['code'] ?? null,
        'message' => $result['message'] ?? null,
        'is_replay' => $result['is_replay'] ?? false,
        'case_id' => isset($result['case']) ? $result['case']->id : null,
        'settings_version_id' => isset($result['case']) ? $result['case']->settings_version_id : null,
    ]);
    exit(0);
} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => get_class($e),
        'message' => $e->getMessage(),
    ]);
    exit(1);
}
