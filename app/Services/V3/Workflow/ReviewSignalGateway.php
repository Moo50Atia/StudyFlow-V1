<?php

namespace App\Services\V3\Workflow;

use Illuminate\Support\Facades\Http;
use App\Services\V3\Workflow\BridgeRequestSigner;


class ReviewSignalGateway
{
    public function __construct(
        protected ?string $baseUrl = null,
        protected ?string $secretKey = null
    ) {
        $this->baseUrl = $baseUrl ?? config('services.v3.bridge_url', 'http://127.0.0.1:8765');
        $this->secretKey = $secretKey ?? config('services.v3.bridge_hmac_key');
    }

    public function startWebReview(string $reviewRef, array $identity): array
    {
        if (empty($this->secretKey)) {
            return ['status' => 503, 'accepted' => false];
        }
        $path = "/internal/v1/web-reviews/{$reviewRef}/start";
        $signed = BridgeRequestSigner::prepareBridgeRequest('POST', $path, $identity, $this->secretKey);
        try {
            $resp = Http::withHeaders($signed['headers'])->timeout(10)
                ->withBody($signed['body'], 'application/json')
                ->post(rtrim($this->baseUrl, '/') . $path);
            return [
                'status' => $resp->status(),
                'accepted' => in_array($resp->status(), [200, 202], true)
                    && $resp->json('data.review_ref') === $reviewRef,
            ];
        } catch (\Throwable) {
            return ['status' => 503, 'accepted' => false];
        }
    }

    public function sendDecisionSignal(string $workflowId, array $data): array
    {
        if (empty($this->secretKey)) {
            return [
                'status' => 503,
                'error' => ['code' => 'workflow_unavailable', 'message' => 'Bridge HMAC secret key is not configured.'],
                'accepted' => false,
            ];
        }

        $path = "/internal/v1/workflows/{$workflowId}/review-decisions";
        $url = rtrim($this->baseUrl, '/') . $path;
        $signed = BridgeRequestSigner::prepareBridgeRequest('POST', $path, $data, $this->secretKey);

        try {
            $resp = Http::withHeaders($signed['headers'])
                ->timeout(10)
                ->withBody($signed['body'], 'application/json')
                ->post($url);

            return [
                'status' => $resp->status(),
                'data' => $resp->json('data') ?? [],
                'error' => $resp->json('error') ?? null,
                'accepted' => ($resp->status() === 202 || $resp->status() === 200),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 503,
                'error' => ['code' => 'workflow_unavailable', 'message' => $e->getMessage()],
                'accepted' => false,
            ];
        }
    }

    public function queryDecision(string $workflowId, string $decisionRef): array
    {
        if (empty($this->secretKey)) {
            return [
                'status' => 503,
                'consumed' => false,
                'error' => ['code' => 'workflow_unavailable', 'message' => 'Bridge HMAC secret key is not configured.'],
            ];
        }

        $path = "/internal/v1/workflows/{$workflowId}/review-decisions/{$decisionRef}";
        $url = rtrim($this->baseUrl, '/') . $path;
        $headers = BridgeRequestSigner::signBridgeRequest('GET', $path, [], $this->secretKey);

        try {
            $resp = Http::withHeaders($headers)
                ->timeout(10)
                ->get($url);

            return [
                'status' => $resp->status(),
                'data' => $resp->json('data') ?? [],
                'consumed' => (bool) ($resp->json('data.consumed') ?? false),
                'error' => $resp->json('error') ?? null,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 503,
                'consumed' => false,
                'error' => ['code' => 'workflow_unavailable', 'message' => $e->getMessage()],
            ];
        }
    }

    public function startCorrection(string $workflowId, array $data): array
    {
        if (empty($this->secretKey)) {
            return ['status' => 503, 'accepted' => false, 'error' => ['code' => 'workflow_unavailable']];
        }
        $path = "/internal/v1/workflows/{$workflowId}/start-correction";
        $url = rtrim($this->baseUrl, '/') . $path;
        $signed = BridgeRequestSigner::prepareBridgeRequest('POST', $path, $data, $this->secretKey);
        try {
            $resp = Http::withHeaders($signed['headers'])->timeout(10)
                ->withBody($signed['body'], 'application/json')->post($url);
            return [
                'status' => $resp->status(),
                'data' => $resp->json('data') ?? [],
                'error' => $resp->json('error') ?? null,
                'accepted' => in_array($resp->status(), [200, 202], true)
                    && (($resp->json('data.correction_ref') ?? null) === ($data['correction_ref'] ?? null)),
            ];
        } catch (\Throwable $e) {
            return ['status' => 503, 'accepted' => false, 'error' => ['code' => 'workflow_unavailable']];
        }
    }
}
