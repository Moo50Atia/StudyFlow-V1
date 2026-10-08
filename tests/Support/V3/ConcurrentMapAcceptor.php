<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tempDb = $argv[1] ?? null;
$queryId = $argv[2] ?? null;
$barrierFile = $argv[3] ?? null;

if (! $tempDb || ! $queryId || ! $barrierFile) {
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
    $acceptor = $app->make(\App\Services\V3\Lessons\AcceptLessonMap::class);
    $result = $acceptor->acceptDivideResult(
        queryRef: $queryId,
        answer: "Chapter 1: Kinematics\nLesson 1.1: Motion",
        conversationOut: 'conv-race-1',
        parsedNodes: [
            [
                'kind' => 'chapter',
                'position_path' => '001',
                'parent_position_path' => null,
                'title' => 'Kinematics',
                'ordinal_in_parent' => 1,
                'work_ordinal' => null,
            ],
            [
                'kind' => 'lesson',
                'position_path' => '001.001',
                'parent_position_path' => '001',
                'title' => 'Motion',
                'ordinal_in_parent' => 1,
                'work_ordinal' => 1,
            ],
        ]
    );

    echo json_encode(['success' => true, 'result' => $result]);
    exit(0);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit(1);
}
