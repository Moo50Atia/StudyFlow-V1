<?php

namespace Tests\Feature\V3\Authoring;

use App\Models\V3\AuthoringRequest;
use App\Models\V3\CodingAttempt;
use App\Models\V3\PromptSectionState;
use App\Models\V3\ScenePromptVersion;
use App\Services\V3\Authoring\ClaimAuthoringRequest;
use App\Services\V3\Authoring\ClaimCodingAttempt;
use App\Services\V3\Authoring\ReconcileAuthoring;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\V3\AuthoringFixtures;
use Tests\Support\V3\WorkflowFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');

    $env = AuthoringFixtures::createAuthoringEnvironment();
    $this->teacher = $env['teacher'];
    $this->admin = $env['admin'];
    $this->settingsVersion = $env['settingsVersion'];
    $this->case = $env['case'];
    $this->map = $env['map'];

    $this->sec1 = AuthoringFixtures::createQualifiedSection($this->case, $this->map, 1, 'Prompt for section 1', true);

    $claimService = app(ClaimAuthoringRequest::class);
    $result = $claimService->claimInitial($this->case->id, $this->sec1['version']->id);
    $this->request = $result['request'];
});

test('A10: attempt reservation commits before outbound and exact replay returns 200', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/requests/{$this->request->id}/attempts";
    $body = [
        'schema_version' => 1,
        'ordinal' => 1,
    ];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $body);

    $response = $this->withHeaders($headers)->postJson($path, $body);
    $response->assertStatus(201);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $data = $response->json('data');
    expect($data['ordinal'])->toBe(1);
    expect($data['state'])->toBe('send_reserved');
    expect($data['provider']['id'])->toBe('gemini_api');
    expect($data['provider']['model'])->toBe('gemini-2.5-pro');
    expect($data['provider']['credential_ref'])->toBe('GEMINI_API_KEY');

    // Verify DB state
    $attempt = CodingAttempt::where('id', $data['attempt_ref'])->first();
    expect($attempt)->not->toBeNull();
    expect($attempt->state)->toBe('send_reserved');
    expect($attempt->ordinal)->toBe(1);

    // Verify request transitioned to authoring
    $this->request->refresh();
    expect($this->request->state)->toBe('authoring');

    // Exact replay with same ordinal returns 200 with identical data
    $replayResponse = $this->withHeaders($headers)->postJson($path, $body);
    $replayResponse->assertStatus(200);
    expect($replayResponse->json('data.attempt_ref'))->toBe($data['attempt_ref']);
    expect($replayResponse->json('data.state'))->toBe('send_reserved');
});

test('A10: cannot reserve next ordinal while prior attempt is send_reserved', function () {
    $path1 = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/requests/{$this->request->id}/attempts";
    $body1 = ['schema_version' => 1, 'ordinal' => 1];
    $headers1 = WorkflowFixtures::signCallbackRequest('POST', $path1, $body1);
    $this->withHeaders($headers1)->postJson($path1, $body1)->assertStatus(201);

    // Try to reserve ordinal 2 while attempt 1 is send_reserved
    $body2 = ['schema_version' => 1, 'ordinal' => 2];
    $headers2 = WorkflowFixtures::signCallbackRequest('POST', $path1, $body2);
    $response2 = $this->withHeaders($headers2)->postJson($path1, $body2);

    $response2->assertStatus(409);
    expect($response2->json('error.code'))->toBe('authoring_conflict');
});

test('A10: cannot skip ordinals when reserving', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/requests/{$this->request->id}/attempts";
    $body = ['schema_version' => 1, 'ordinal' => 2];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $body);

    $response = $this->withHeaders($headers)->postJson($path, $body);
    $response->assertStatus(409);
    expect($response->json('error.code'))->toBe('authoring_conflict');
});

test('A10: known failure allows advance to next distinct provider', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/requests/{$this->request->id}/attempts";
    $body1 = ['schema_version' => 1, 'ordinal' => 1];
    $headers1 = WorkflowFixtures::signCallbackRequest('POST', $path, $body1);
    $res1 = $this->withHeaders($headers1)->postJson($path, $body1);
    $res1->assertStatus(201);
    $attempt1Ref = $res1->json('data.attempt_ref');

    // Record known failure for attempt 1
    $resultPath = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/attempts/{$attempt1Ref}/result";
    $resultBody = [
        'schema_version' => 1,
        'outcome' => 'known_failure',
        'returned_model' => null,
        'provider_response_id' => null,
        'provider_response_json' => null,
        'package' => null,
        'failure_code' => 'provider_unavailable',
        'usage' => null,
        'duration_ms' => 150,
    ];
    $resultHeaders = WorkflowFixtures::signCallbackRequest('POST', $resultPath, $resultBody);
    $resResult = $this->withHeaders($resultHeaders)->postJson($resultPath, $resultBody);
    $resResult->assertStatus(201);
    expect($resResult->json('data.state'))->toBe('known_failure');

    // Now reserve ordinal 2: must succeed and return distinct provider from coding_order
    $body2 = ['schema_version' => 1, 'ordinal' => 2];
    $headers2 = WorkflowFixtures::signCallbackRequest('POST', $path, $body2);
    $res2 = $this->withHeaders($headers2)->postJson($path, $body2);
    $res2->assertStatus(201);

    $data2 = $res2->json('data');
    expect($data2['ordinal'])->toBe(2);
    expect($data2['provider']['id'])->toBe('deepseek_api');
    expect($data2['provider']['id'])->not->toBe('gemini_api');
});

test('A11: budget cap and all-known-failure exhaustion blocks request', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/requests/{$this->request->id}/attempts";

    // Exhaust all 2 attempts (settings has 2 providers in coding_order)
    for ($ordinal = 1; $ordinal <= 2; $ordinal++) {
        $body = ['schema_version' => 1, 'ordinal' => $ordinal];
        $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $body);
        $res = $this->withHeaders($headers)->postJson($path, $body);
        $res->assertStatus(201);
        $attemptRef = $res->json('data.attempt_ref');

        $resultPath = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/attempts/{$attemptRef}/result";
        $resultBody = [
            'schema_version' => 1,
            'outcome' => 'known_failure',
            'returned_model' => null,
            'provider_response_id' => null,
            'provider_response_json' => null,
            'package' => null,
            'failure_code' => 'provider_unavailable',
            'usage' => null,
            'duration_ms' => 100,
        ];
        $resultHeaders = WorkflowFixtures::signCallbackRequest('POST', $resultPath, $resultBody);
        $this->withHeaders($resultHeaders)->postJson($resultPath, $resultBody)->assertStatus(201);
    }

    // Verify request is now blocked
    $this->request->refresh();
    expect($this->request->state)->toBe('blocked');
    expect($this->request->safe_error_code)->toBe('authoring_provider_unavailable');

    // Attempting ordinal 3 fails with conflict
    $body3 = ['schema_version' => 1, 'ordinal' => 3];
    $headers3 = WorkflowFixtures::signCallbackRequest('POST', $path, $body3);
    $res3 = $this->withHeaders($headers3)->postJson($path, $body3);
    $res3->assertStatus(409);
});

test('A12: uncertain attempt halts fallback and cannot reserve next ordinal', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/requests/{$this->request->id}/attempts";
    $body1 = ['schema_version' => 1, 'ordinal' => 1];
    $headers1 = WorkflowFixtures::signCallbackRequest('POST', $path, $body1);
    $res1 = $this->withHeaders($headers1)->postJson($path, $body1);
    $res1->assertStatus(201);
    $attempt1Ref = $res1->json('data.attempt_ref');

    // Record uncertain outcome (timeout)
    $resultPath = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/attempts/{$attempt1Ref}/result";
    $resultBody = [
        'schema_version' => 1,
        'outcome' => 'uncertain',
        'returned_model' => null,
        'provider_response_id' => null,
        'provider_response_json' => null,
        'package' => null,
        'failure_code' => 'timeout',
        'usage' => null,
        'duration_ms' => 60000,
    ];
    $resultHeaders = WorkflowFixtures::signCallbackRequest('POST', $resultPath, $resultBody);
    $resResult = $this->withHeaders($resultHeaders)->postJson($resultPath, $resultBody);
    $resResult->assertStatus(201);
    expect($resResult->json('data.state'))->toBe('uncertain');

    $this->request->refresh();
    expect($this->request->state)->toBe('uncertain');

    // Cannot advance to ordinal 2 after uncertain
    $body2 = ['schema_version' => 1, 'ordinal' => 2];
    $headers2 = WorkflowFixtures::signCallbackRequest('POST', $path, $body2);
    $res2 = $this->withHeaders($headers2)->postJson($path, $body2);
    $res2->assertStatus(409);
});

test('A12: readback returns attempt status without raw content', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/requests/{$this->request->id}/attempts";
    $body = ['schema_version' => 1, 'ordinal' => 1];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $body);
    $res = $this->withHeaders($headers)->postJson($path, $body);
    $attemptRef = $res->json('data.attempt_ref');

    $readbackPath = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/attempts/{$attemptRef}";
    $readbackHeaders = WorkflowFixtures::signCallbackRequest('GET', $readbackPath, []);
    $readbackRes = $this->withHeaders($readbackHeaders)->get($readbackPath);

    $readbackRes->assertStatus(200);
    $data = $readbackRes->json('data');
    expect($data['attempt_ref'])->toBe($attemptRef);
    expect($data['request_ref'])->toBe($this->request->id);
    expect($data['ordinal'])->toBe(1);
    expect($data['state'])->toBe('send_reserved');
    expect($data['candidate_ref'])->toBeNull();
    expect($data['result_sha256'])->toBeNull();
});

test('A12: reconciler marks abandoned send_reserved attempt uncertain after 5 minutes', function () {
    $attempt = CodingAttempt::forceCreate([
        'id' => (string) Str::uuid(),
        'request_id' => $this->request->id,
        'case_id' => $this->case->id,
        'ordinal' => 1,
        'provider_id' => 'gemini_api',
        'requested_model' => 'gemini-2.5-flash',
        'credential_ref' => 'gemini_api_key',
        'state' => 'send_reserved',
        'send_reserved_at' => now()->subMinutes(6),
        'created_at' => now()->subMinutes(6),
        'updated_at' => now()->subMinutes(6),
    ]);

    $this->request->update(['state' => 'authoring']);

    $reconciler = app(ReconcileAuthoring::class);
    $counts = $reconciler->reconcile();

    expect($counts['uncertain_attempts'])->toBeGreaterThanOrEqual(1);

    $attempt->refresh();
    expect($attempt->state)->toBe('uncertain');

    $this->request->refresh();
    expect($this->request->state)->toBe('uncertain');
});

test('A13: concurrent_claim two processes on shared DB reserve exactly one ordinal', function () {
    $origDb = config('database.connections.sqlite.database');

    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    // Shared WAL SQLite database and filesystem storage
    $tempDb = sys_get_temp_dir() . '/v3_authoring_race_' . uniqid() . '.sqlite';
    touch($tempDb);

    $tempStorage = sys_get_temp_dir() . '/v3_authoring_storage_' . uniqid();
    mkdir($tempStorage, 0755, true);

    config([
        'database.connections.sqlite.database' => $tempDb,
        'filesystems.disks.v3_private.root' => $tempStorage,
    ]);
    DB::purge('sqlite');
    DB::reconnect('sqlite');
    DB::statement('PRAGMA journal_mode = WAL;');
    DB::statement('PRAGMA busy_timeout = 5000;');

    Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);

    $env = AuthoringFixtures::createAuthoringEnvironment();
    $case = $env['case'];
    $map = $env['map'];
    $sec = AuthoringFixtures::createQualifiedSection($case, $map, 1, 'Prompt race', true);

    @mkdir(dirname($tempStorage . '/' . $sec['version']->body_path), 0755, true);
    file_put_contents($tempStorage . '/' . $sec['version']->body_path, $sec['promptText']);

    $claimService = app(ClaimAuthoringRequest::class);
    $reqResult = $claimService->claimInitial($case->id, $sec['version']->id);
    $req = $reqResult['request'];

    $barrierFile = sys_get_temp_dir() . '/v3_auth_barrier_' . uniqid();
    $workerScript = base_path('tests/Support/V3/ConcurrentAttemptWorker.php');

    $p1 = new Process(['php', $workerScript, $tempDb, $case->id, $req->id, '1', $barrierFile, $tempStorage]);
    $p2 = new Process(['php', $workerScript, $tempDb, $case->id, $req->id, '1', $barrierFile, $tempStorage]);

    $p1->start();
    $p2->start();

    $p1Pid = $p1->getPid();
    $p2Pid = $p2->getPid();

    $timeout = time() + 5;
    while ((! file_exists($barrierFile . '.' . $p1Pid) || ! file_exists($barrierFile . '.' . $p2Pid)) && time() < $timeout) {
        usleep(10000);
    }

    // Release barrier
    touch($barrierFile);

    $p1->wait();
    $p2->wait();

    @unlink($barrierFile);
    @unlink($barrierFile . '.' . $p1Pid);
    @unlink($barrierFile . '.' . $p2Pid);
    @unlink($tempDb);

    // Clean tempStorage
    $it = new \RecursiveDirectoryIterator($tempStorage, \RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()) {
            @rmdir($file->getRealPath());
        } else {
            @unlink($file->getRealPath());
        }
    }
    @rmdir($tempStorage);

    $out1 = json_decode($p1->getOutput(), true);
    $out2 = json_decode($p2->getOutput(), true);

    expect($out1)->not->toBeNull('P1 failed: ' . $p1->getOutput());
    expect($out2)->not->toBeNull('P2 failed: ' . $p2->getOutput());

    $statuses = [$out1['status_code'], $out2['status_code']];
    expect($statuses)->toContain(201);

    // Verify DB count: exactly 1 attempt for ordinal 1
    $attemptCount = DB::table('v3_coding_attempts')
        ->where('request_id', $req->id)
        ->where('ordinal', 1)
        ->count();
    expect($attemptCount)->toBe(1);

    config(['database.connections.sqlite.database' => $origDb]);
    DB::purge('sqlite');
    DB::reconnect('sqlite');
    DB::statement('PRAGMA foreign_keys = ON;');
    if ($origDb === ':memory:') {
        Artisan::call('migrate:fresh', ['--database' => 'sqlite', '--force' => true]);
    }
});
