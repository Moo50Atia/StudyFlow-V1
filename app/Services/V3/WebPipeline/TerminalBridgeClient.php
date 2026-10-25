<?php

namespace App\Services\V3\WebPipeline;

use App\Services\V3\Workflow\BridgeRequestSigner;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TerminalBridgeClient
{
    protected string $baseUrl;
    protected string $secretKey;

    public function __construct(?string $baseUrl = null, ?string $secretKey = null)
    {
        $this->baseUrl = $baseUrl ?? config('services.v3.bridge_url', 'http://127.0.0.1:8765');
        $this->secretKey = $secretKey ?? config('services.v3.bridge_hmac_key', '');
    }

    /**
     * Submit an admission payload to the Python application pipeline bridge.
     */
    public function submitAdmission(array $payload): array
    {
        return $this->request('POST', '/internal/v1/application-pipeline/submissions', $payload);
    }

    /** Start the durable browser qualification workflow for an immutable candidate. */
    public function qualifySceneCandidate(array $payload): array
    {
        return $this->request('POST', '/internal/v1/application-pipeline/qualifications', $payload);
    }

    public function preparePdfs(array $payload): array
    {
        return $this->request('POST', '/internal/v1/application-pipeline/pdf-preparations', $payload, 600)['data'] ?? [];
    }

    /**
     * Send an application command to the bridge.
     */
    public function sendCommand(array $payload): array
    {
        return $this->request('POST', '/internal/v1/application-pipeline/commands', $payload);
    }

    /**
     * Query receipt for a command.
     */
    public function getReceipt(string $commandId): array
    {
        return $this->request('GET', "/internal/v1/application-pipeline/receipts/{$commandId}");
    }

    /**
     * Query status of a run.
     */
    public function getRunStatus(string $runId): array
    {
        return $this->request('GET', "/internal/v1/application-pipeline/runs/{$runId}/status", [], 3);
    }

    /**
     * Perform signed HTTP request to Python bridge.
     */
    protected function request(string $method, string $path, array $body = [], int $timeoutSeconds = 15): array
    {
        $prepared = BridgeRequestSigner::prepareBridgeRequest($method, $path, $body, $this->secretKey);
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        $httpClient = Http::withHeaders($prepared['headers'])->timeout($timeoutSeconds);

        if (strtoupper($method) === 'GET') {
            $resp = $httpClient->get($url);
        } else {
            $resp = $httpClient->withBody($prepared['body'], 'application/json')->post($url);
        }

        if ($resp->status() === 409) {
            $json = $resp->json();
            $code = $json['error']['code'] ?? 'operation_conflict';
            $msg = $json['error']['message'] ?? 'Conflict';
            throw new HttpException(409, $msg, null, [], 409);
        }

        if ($resp->failed()) {
            throw new HttpException($resp->status(), 'Bridge request failed: ' . $resp->body());
        }

        return $resp->json() ?? [];
    }
}
