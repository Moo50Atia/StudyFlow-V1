<?php

namespace Tests\Feature\V3;

use App\Http\Middleware\V3\VerifyWorkerSignature;
use App\Services\V3\Workflow\BridgeRequestSigner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BridgeSigningBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(VerifyWorkerSignature::class)->any('/_test_signing_boundary', function (Request $request) {
            return response()->json([
                'status' => 'ok',
                'received_body' => $request->json()->all(),
            ]);
        });
    }

    public function test_vector_1_empty_body_get(): void
    {
        $method = 'GET';
        $path = '/internal/v1/health';
        $timestamp = 1700000000;
        $secret = 'test-secret-key-12345';
        $body = [];

        $headers = BridgeRequestSigner::signBridgeRequest($method, $path, $body, $secret, $timestamp);

        $this->assertSame('1700000000', $headers['X-V3-Timestamp']);
        $this->assertSame('1', $headers['X-V3-Contract-Version']);
        $this->assertSame('6f611383ab8f82e063acfc362db301204d9e9f79f903fa3220ecbbebe78cefdb', $headers['X-V3-Signature']);
    }

    public function test_vector_2_utf8_arabic_post_with_query_canonicalization(): void
    {
        $method = 'POST';
        $path = '/api/internal/v1/case-events?ignored_query=1';
        $timestamp = 1700000000;
        $secret = 'test-secret-key-12345';
        $body = ['message' => 'مرحبا بالعالم', 'count' => 42];

        $headers = BridgeRequestSigner::signCallbackRequest($method, $path, $body, $secret, $timestamp);

        $this->assertSame('1700000000', $headers['X-V3-Timestamp']);
        $this->assertSame('1', $headers['X-V3-Contract-Version']);
        $this->assertSame('6a7db59da799b8fece525612e54d393fe32ce82659cec52fd4c67dde811177d6', $headers['X-V3-Signature']);
    }

    public function test_prepared_bridge_request_returns_the_exact_utf8_body_that_was_signed(): void
    {
        $payload = ['message' => 'مرحبا بالعالم', 'count' => 42];
        $timestamp = 1700000000;
        $secret = 'test-secret-key-12345';

        $prepared = BridgeRequestSigner::prepareBridgeRequest(
            'POST',
            '/internal/v1/review-decisions',
            $payload,
            $secret,
            $timestamp,
        );
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $bodyHash = hash('sha256', $rawBody);
        $expectedSignature = hash_hmac(
            'sha256',
            "POST\n/internal/v1/review-decisions\n{$timestamp}\n{$bodyHash}",
            $secret,
        );

        $this->assertSame($rawBody, $prepared['body']);
        $this->assertSame($expectedSignature, $prepared['headers']['X-V3-Signature']);
    }

    public function test_bridge_client_sends_the_exact_utf8_bytes_returned_by_the_signer(): void
    {
        $secret = 'test-secret-key-12345';
        $payload = [
            'case_id' => 'case_123',
            'label' => 'درس رياضيات',
        ];
        Http::fake(['*' => Http::response(['data' => ['workflow_id' => 'v3-case-case_123']], 202)]);

        $client = new \App\Services\V3\Workflow\BridgeClient('http://bridge.test', $secret);
        $result = $client->startWorkflow($payload);

        $this->assertSame(202, $result['status']);
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($payload, $secret): bool {
            $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $timestamp = $request->header('X-V3-Timestamp')[0] ?? '';
            $path = '/internal/v1/workflows/start';
            $expected = hash_hmac(
                'sha256',
                "POST\n{$path}\n{$timestamp}\n" . hash('sha256', $rawBody),
                $secret,
            );

            return $request->url() === 'http://bridge.test' . $path
                && $request->body() === $rawBody
                && hash_equals($expected, $request->header('X-V3-Signature')[0] ?? '');
        });
    }

    public function test_receiving_middleware_accepts_valid_signature_from_signer(): void
    {
        Config::set('services.v3.callback_hmac_key', 'test-signing-secret');
        $now = time();
        $body = ['event_key' => 'ev_test_1', 'status' => 'queued'];

        $headers = BridgeRequestSigner::signCallbackRequest('POST', '/_test_signing_boundary', $body, 'test-signing-secret', $now);

        $serverHeaders = [
            'HTTP_X_V3_TIMESTAMP' => $headers['X-V3-Timestamp'],
            'HTTP_X_V3_SIGNATURE' => $headers['X-V3-Signature'],
            'HTTP_X_V3_CONTRACT_VERSION' => $headers['X-V3-Contract-Version'],
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        $rawBody = json_encode($body, JSON_UNESCAPED_SLASHES);
        $response = $this->call('POST', '/_test_signing_boundary', [], [], [], $serverHeaders, $rawBody);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ok']);
    }

    public function test_receiving_middleware_rejects_tampered_body(): void
    {
        Config::set('services.v3.callback_hmac_key', 'test-signing-secret');
        $now = time();
        $body = ['event_key' => 'ev_test_1', 'status' => 'queued'];

        $headers = BridgeRequestSigner::signCallbackRequest('POST', '/_test_signing_boundary', $body, 'test-signing-secret', $now);

        $serverHeaders = [
            'HTTP_X_V3_TIMESTAMP' => $headers['X-V3-Timestamp'],
            'HTTP_X_V3_SIGNATURE' => $headers['X-V3-Signature'],
            'HTTP_X_V3_CONTRACT_VERSION' => $headers['X-V3-Contract-Version'],
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        // Tamper with body: change status to 'failed'
        $tamperedBody = json_encode(['event_key' => 'ev_test_1', 'status' => 'failed'], JSON_UNESCAPED_SLASHES);
        $response = $this->call('POST', '/_test_signing_boundary', [], [], [], $serverHeaders, $tamperedBody);

        $response->assertStatus(401);
        $response->assertJson(['error' => ['code' => 'worker_unauthenticated']]);
    }

    public function test_receiving_middleware_rejects_wrong_secret(): void
    {
        Config::set('services.v3.callback_hmac_key', 'correct-secret');
        $now = time();
        $body = ['key' => 'val'];

        $headers = BridgeRequestSigner::signCallbackRequest('POST', '/_test_signing_boundary', $body, 'wrong-secret', $now);

        $serverHeaders = [
            'HTTP_X_V3_TIMESTAMP' => $headers['X-V3-Timestamp'],
            'HTTP_X_V3_SIGNATURE' => $headers['X-V3-Signature'],
            'HTTP_X_V3_CONTRACT_VERSION' => $headers['X-V3-Contract-Version'],
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        $rawBody = json_encode($body, JSON_UNESCAPED_SLASHES);
        $response = $this->call('POST', '/_test_signing_boundary', [], [], [], $serverHeaders, $rawBody);

        $response->assertStatus(401);
        $response->assertJson(['error' => ['code' => 'worker_unauthenticated']]);
    }

    public function test_receiving_middleware_rejects_expired_timestamp(): void
    {
        Config::set('services.v3.callback_hmac_key', 'test-signing-secret');
        $expiredTs = time() - 301;
        $body = ['key' => 'val'];

        $headers = BridgeRequestSigner::signCallbackRequest('POST', '/_test_signing_boundary', $body, 'test-signing-secret', $expiredTs);

        $serverHeaders = [
            'HTTP_X_V3_TIMESTAMP' => $headers['X-V3-Timestamp'],
            'HTTP_X_V3_SIGNATURE' => $headers['X-V3-Signature'],
            'HTTP_X_V3_CONTRACT_VERSION' => $headers['X-V3-Contract-Version'],
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        $rawBody = json_encode($body, JSON_UNESCAPED_SLASHES);
        $response = $this->call('POST', '/_test_signing_boundary', [], [], [], $serverHeaders, $rawBody);

        $response->assertStatus(401);
        $response->assertJson(['error' => ['code' => 'worker_unauthenticated']]);
    }

    public function test_receiving_middleware_rejects_unsupported_contract_version(): void
    {
        Config::set('services.v3.callback_hmac_key', 'test-signing-secret');
        $now = time();
        $body = ['key' => 'val'];

        $headers = BridgeRequestSigner::signCallbackRequest('POST', '/_test_signing_boundary', $body, 'test-signing-secret', $now);
        $serverHeaders = [
            'HTTP_X_V3_TIMESTAMP' => $headers['X-V3-Timestamp'],
            'HTTP_X_V3_SIGNATURE' => $headers['X-V3-Signature'],
            'HTTP_X_V3_CONTRACT_VERSION' => '2',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        $rawBody = json_encode($body, JSON_UNESCAPED_SLASHES);
        $response = $this->call('POST', '/_test_signing_boundary', [], [], [], $serverHeaders, $rawBody);

        $response->assertStatus(401);
        $response->assertJson(['error' => ['code' => 'worker_unauthenticated']]);
    }

    public function test_production_codebase_does_not_import_tests_namespace(): void
    {
        $appPath = app_path();
        $cmd = "grep -rn 'use Tests\\\\' " . escapeshellarg($appPath);
        exec($cmd, $output, $returnCode);

        $this->assertNotSame(0, $returnCode, "Production classes under app/ still import Tests\\ namespace:\n" . implode("\n", $output));
    }
}
