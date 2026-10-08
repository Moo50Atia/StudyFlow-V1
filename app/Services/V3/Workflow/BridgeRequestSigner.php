<?php

namespace App\Services\V3\Workflow;

class BridgeRequestSigner
{
    /**
     * Sign an outgoing request from Laravel to the Python workflow bridge.
     */
    public static function signBridgeRequest(
        string $method,
        string $path,
        array|string $body = [],
        ?string $secret = null,
        ?int $timestamp = null
    ): array {
        return self::prepareBridgeRequest($method, $path, $body, $secret, $timestamp)['headers'];
    }

    /**
     * Serialize once, then sign and return the exact bytes the HTTP caller must send.
     * @return array{headers: array<string,string>, body: string}
     */
    public static function prepareBridgeRequest(
        string $method,
        string $path,
        array|string $body = [],
        ?string $secret = null,
        ?int $timestamp = null
    ): array {
        $secret = $secret ?? config('services.v3.bridge_hmac_key');
        if (empty($secret)) {
            throw new \InvalidArgumentException('Bridge HMAC secret key is not configured.');
        }
        $ts = $timestamp ?? time();
        if (is_array($body) && $body === []) {
            $rawBody = '';
        } elseif (is_string($body)) {
            $rawBody = $body;
        } else {
            $rawBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $bodySha256 = strtolower(hash('sha256', $rawBody));

        $normalizedPath = '/' . ltrim($path, '/');
        $pathOnly = parse_url($normalizedPath, PHP_URL_PATH) ?: $normalizedPath;

        $stringToSign = strtoupper($method) . "\n" . $pathOnly . "\n" . $ts . "\n" . $bodySha256;
        $sig = strtolower(hash_hmac('sha256', $stringToSign, $secret));

        return [
            'headers' => [
                'X-V3-Timestamp' => (string) $ts,
                'X-V3-Signature' => $sig,
                'X-V3-Contract-Version' => '1',
                'Content-Type' => 'application/json',
            ],
            'body' => $rawBody,
        ];
    }

    /**
     * Sign an internal callback request to Laravel.
     */
    public static function signCallbackRequest(
        string $method,
        string $path,
        array|string $body = [],
        ?string $secret = null,
        ?int $timestamp = null
    ): array {
        $secret = $secret ?? config('services.v3.callback_hmac_key');
        if (empty($secret)) {
            throw new \InvalidArgumentException('Callback HMAC secret key is not configured.');
        }
        $ts = $timestamp ?? time();
        if (strtoupper($method) === 'GET' && empty($body)) {
            $rawBody = '';
        } elseif (is_string($body)) {
            $rawBody = $body;
        } else {
            $rawBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $bodySha256 = strtolower(hash('sha256', $rawBody));

        $normalizedPath = '/' . ltrim($path, '/');
        $pathOnly = parse_url($normalizedPath, PHP_URL_PATH) ?: $normalizedPath;

        $stringToSign = strtoupper($method) . "\n" . $pathOnly . "\n" . $ts . "\n" . $bodySha256;
        $sig = strtolower(hash_hmac('sha256', $stringToSign, $secret));

        return [
            'HTTP_X_V3_TIMESTAMP' => (string) $ts,
            'HTTP_X_V3_SIGNATURE' => $sig,
            'HTTP_X_V3_CONTRACT_VERSION' => '1',
            'X-V3-Timestamp' => (string) $ts,
            'X-V3-Signature' => $sig,
            'X-V3-Contract-Version' => '1',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }
}
