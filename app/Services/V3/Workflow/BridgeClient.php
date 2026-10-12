<?php

namespace App\Services\V3\Workflow;

use Illuminate\Support\Facades\Http;
use App\Services\V3\Workflow\BridgeRequestSigner;


class BridgeClient
{
    public function __construct(
        protected ?string $baseUrl = null,
        protected ?string $secretKey = null
    ) {
        $this->baseUrl = $baseUrl ?? config('services.v3.bridge_url', 'http://127.0.0.1:8765');
        $this->secretKey = $secretKey ?? config('services.v3.bridge_hmac_key');
    }

    protected function unconfiguredResponse(): array
    {
        return [
            'status' => 503,
            'error' => [
                'code' => 'workflow_unavailable',
                'message' => 'Bridge HMAC secret key is not configured.',
            ],
        ];
    }

    public function startWorkflow(array $data): array
    {
        if (empty($this->secretKey)) {
            return $this->unconfiguredResponse();
        }

        $path = '/internal/v1/workflows/start';
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
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 503,
                'error' => [
                    'code' => 'workflow_unavailable',
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    public function describeWorkflow(string $workflowId): array
    {
        if (empty($this->secretKey)) {
            return $this->unconfiguredResponse();
        }

        $path = "/internal/v1/workflows/{$workflowId}";
        $url = rtrim($this->baseUrl, '/') . $path;
        $headers = BridgeRequestSigner::signBridgeRequest('GET', $path, [], $this->secretKey);

        try {
            $resp = Http::withHeaders($headers)
                ->timeout(10)
                ->get($url);

            return [
                'status' => $resp->status(),
                'data' => $resp->json('data') ?? [],
                'error' => $resp->json('error') ?? null,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 503,
                'error' => [
                    'code' => 'workflow_unavailable',
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    public function activateSource(string $workflowId, array $data): array
    {
        if (empty($this->secretKey)) {
            return $this->unconfiguredResponse();
        }

        $path = "/internal/v1/workflows/{$workflowId}/activate-source";
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
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 503,
                'error' => [
                    'code' => 'workflow_unavailable',
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    public function activateMap(string $workflowId, array $data): array
    {
        if (empty($this->secretKey)) {
            return $this->unconfiguredResponse();
        }

        $path = "/internal/v1/workflows/{$workflowId}/activate-map";
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
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 503,
                'error' => [
                    'code' => 'workflow_unavailable',
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    public function notifyLessonReady(string $workflowId, array $data): array
    {
        if (empty($this->secretKey)) {
            return $this->unconfiguredResponse();
        }

        $path = "/internal/v1/workflows/{$workflowId}/lesson-ready";
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
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 503,
                'error' => [
                    'code' => 'workflow_unavailable',
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    public function probeHealth(): array
    {
        if (empty($this->secretKey)) {
            return $this->unconfiguredResponse();
        }

        $path = '/internal/v1/health';
        $url = rtrim($this->baseUrl, '/') . $path;
        $headers = BridgeRequestSigner::signBridgeRequest('GET', $path, [], $this->secretKey);

        try {
            $resp = Http::withHeaders($headers)
                ->timeout(2)
                ->get($url);

            return [
                'status' => $resp->status(),
                'data' => $resp->json('data') ?? [],
                'error' => $resp->json('error') ?? null,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 503,
                'error' => [
                    'code' => 'workflow_unavailable',
                    'message' => 'Bridge probe failed',
                ],
            ];
        }
    }

    public function checkNotebookLmHealth(): array
    {
        if (empty($this->secretKey)) {
            return $this->unconfiguredResponse();
        }

        $path = '/internal/v1/notebooklm/health';
        $signed = BridgeRequestSigner::prepareBridgeRequest('POST', $path, [], $this->secretKey);

        try {
            $resp = Http::withHeaders($signed['headers'])
                ->timeout(20)
                ->withBody($signed['body'], 'application/json')
                ->post(rtrim($this->baseUrl, '/') . $path);

            return [
                'status' => $resp->status(),
                'data' => $resp->json('data') ?? [],
                'error' => $resp->json('error') ?? null,
            ];
        } catch (\Throwable) {
            return [
                'status' => 503,
                'error' => [
                    'code' => 'notebooklm_unavailable',
                    'message' => 'NotebookLM health could not be verified.',
                ],
            ];
        }
    }

    public function notifyNotebookReconciled(string $workflowId, array $data): array
    {
        if (empty($this->secretKey)) {
            return $this->unconfiguredResponse();
        }

        $path = "/internal/v1/workflows/{$workflowId}/notebook-reconciled";
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
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 503,
                'error' => [
                    'code' => 'workflow_unavailable',
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    public function queryConsumedQuery(string $workflowId, string $queryRef): array
    {
        if (empty($this->secretKey)) {
            return $this->unconfiguredResponse();
        }

        $path = "/internal/v1/workflows/{$workflowId}/consumed-query";
        $url = rtrim($this->baseUrl, '/') . $path . '?query_ref=' . urlencode($queryRef);
        $headers = BridgeRequestSigner::signBridgeRequest('GET', $path, ['query_ref' => $queryRef], $this->secretKey);

        try {
            $resp = Http::withHeaders($headers)
                ->timeout(5)
                ->get($url);

            return [
                'status' => $resp->status(),
                'data' => $resp->json('data') ?? [],
                'error' => $resp->json('error') ?? null,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 503,
                'error' => [
                    'code' => 'workflow_unavailable',
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }
}
