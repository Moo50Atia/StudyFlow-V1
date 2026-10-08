<?php

/**
 * CLI Worker for Concurrency Integration Tests
 * Runs as an independent process against an isolated SQLite file.
 */

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$options = getopt('', ['db:', 'actor:', 'target:', 'role:', 'is_active:', 'version:', 'expected_role:', 'reason:']);

$dbPath = $options['db'] ?? null;
$actorId = (int) ($options['actor'] ?? 0);
$targetId = (int) ($options['target'] ?? 0);
$role = $options['role'] ?? 'teacher';
$isActive = filter_var($options['is_active'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
$expectedVersion = (int) ($options['version'] ?? 0);
$expectedRole = $options['expected_role'] ?? $role;
$reason = $options['reason'] ?? 'change_role';

if (! $dbPath || ! file_exists($dbPath)) {
    echo json_encode(['success' => false, 'error' => 'Database file missing']);
    exit(1);
}

// Reconfigure SQLite connection to point to temp db
config(['database.connections.sqlite.database' => $dbPath]);
\Illuminate\Support\Facades\DB::purge('sqlite');
\Illuminate\Support\Facades\DB::reconnect('sqlite');

try {
    $actor = \App\Models\User::find($actorId);
    if (! $actor) {
        echo json_encode(['success' => false, 'error' => 'Actor not found']);
        exit(1);
    }

    $service = $app->make(\App\Services\V3\Access\ChangeAccountAccess::class);
    $result = $service->updateAccess(
        actor: $actor,
        targetId: $targetId,
        role: $role,
        isActive: $isActive,
        expectedVersion: $expectedVersion,
        expectedRole: $expectedRole,
        reasonCode: $reason
    );

    echo json_encode(['success' => true, 'result' => $result]);
    exit(0);
} catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
    echo json_encode(['success' => false, 'status' => $e->getStatusCode(), 'error' => $e->getMessage()]);
    exit(0);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'status' => 500, 'error' => $e->getMessage()]);
    exit(0);
}
