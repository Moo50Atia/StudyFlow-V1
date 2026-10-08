<?php

namespace Tests\Feature\V3;

use App\Services\V3\Workflow\BridgeClient;
use App\Services\V3\Workflow\BridgeRequestSigner;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class InternalSecretConfigurationTest extends TestCase
{
    public function test_absent_callback_secret_fails_closed_with_401(): void
    {
        Config::set('services.v3.callback_hmac_key', null);

        $now = time();
        $body = ['event_key' => 'ev_1'];
        // Attempt signature with the former published default key
        $headers = BridgeRequestSigner::signCallbackRequest('POST', '/api/internal/v1/case-events', $body, 'dev-callback-hmac-key', $now);

        $serverHeaders = [
            'HTTP_X_V3_TIMESTAMP' => $headers['X-V3-Timestamp'],
            'HTTP_X_V3_SIGNATURE' => $headers['X-V3-Signature'],
            'HTTP_X_V3_CONTRACT_VERSION' => '1',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        $resp = $this->call('POST', '/api/internal/v1/case-events', [], [], [], $serverHeaders, json_encode($body));

        $resp->assertStatus(401);
        $resp->assertJson(['error' => ['code' => 'worker_unauthenticated']]);
    }

    public function test_empty_callback_secret_fails_closed_with_401(): void
    {
        Config::set('services.v3.callback_hmac_key', '');

        $now = time();
        $body = ['event_key' => 'ev_1'];
        $headers = BridgeRequestSigner::signCallbackRequest('POST', '/api/internal/v1/case-events', $body, 'dev-callback-hmac-key', $now);

        $serverHeaders = [
            'HTTP_X_V3_TIMESTAMP' => $headers['X-V3-Timestamp'],
            'HTTP_X_V3_SIGNATURE' => $headers['X-V3-Signature'],
            'HTTP_X_V3_CONTRACT_VERSION' => '1',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        $resp = $this->call('POST', '/api/internal/v1/case-events', [], [], [], $serverHeaders, json_encode($body));

        $resp->assertStatus(401);
        $resp->assertJson(['error' => ['code' => 'worker_unauthenticated']]);
    }

    public function test_configured_callback_secret_rejects_former_public_default(): void
    {
        Config::set('services.v3.callback_hmac_key', 'production-grade-callback-secret-12345');

        $now = time();
        $body = ['event_key' => 'ev_1'];
        // Sign with former default
        $headers = BridgeRequestSigner::signCallbackRequest('POST', '/api/internal/v1/case-events', $body, 'dev-callback-hmac-key', $now);

        $serverHeaders = [
            'HTTP_X_V3_TIMESTAMP' => $headers['X-V3-Timestamp'],
            'HTTP_X_V3_SIGNATURE' => $headers['X-V3-Signature'],
            'HTTP_X_V3_CONTRACT_VERSION' => '1',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        $resp = $this->call('POST', '/api/internal/v1/case-events', [], [], [], $serverHeaders, json_encode($body));

        $resp->assertStatus(401);
        $resp->assertJson(['error' => ['code' => 'worker_unauthenticated']]);
    }

    public function test_bridge_key_cannot_authenticate_callback_direction(): void
    {
        $bridgeKey = 'independent-bridge-key-abc';
        $callbackKey = 'independent-callback-key-xyz';

        Config::set('services.v3.bridge_hmac_key', $bridgeKey);
        Config::set('services.v3.callback_hmac_key', $callbackKey);

        $now = time();
        $body = ['event_key' => 'ev_1'];
        // Sign with bridge key instead of callback key
        $headers = BridgeRequestSigner::signCallbackRequest('POST', '/api/internal/v1/case-events', $body, $bridgeKey, $now);

        $serverHeaders = [
            'HTTP_X_V3_TIMESTAMP' => $headers['X-V3-Timestamp'],
            'HTTP_X_V3_SIGNATURE' => $headers['X-V3-Signature'],
            'HTTP_X_V3_CONTRACT_VERSION' => '1',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        $resp = $this->call('POST', '/api/internal/v1/case-events', [], [], [], $serverHeaders, json_encode($body));

        $resp->assertStatus(401);
        $resp->assertJson(['error' => ['code' => 'worker_unauthenticated']]);
    }

    public function test_bridge_client_fails_closed_when_bridge_secret_is_unset(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        Config::set('services.v3.bridge_hmac_key', null);

        $client = new BridgeClient('http://127.0.0.1:8765', null);
        $result = $client->startWorkflow([
            'case_id' => '00000000-0000-0000-0000-000000000001',
            'settings_version_id' => 1,
            'settings_checksum' => str_repeat('a', 64),
            'workflow_id' => 'v3-case-00000000-0000-0000-0000-000000000001',
            'retry_policy' => ['technical_retries' => 1, 'retry_delay_seconds' => 1],
        ]);

        $this->assertSame(503, $result['status']);
        $this->assertSame('workflow_unavailable', $result['error']['code']);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

}
