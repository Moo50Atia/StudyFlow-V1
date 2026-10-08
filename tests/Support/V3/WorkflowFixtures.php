<?php

namespace Tests\Support\V3;

use App\Models\User;
use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\WorkflowLink;
use Illuminate\Support\Str;

class WorkflowFixtures
{
    public static function signCallbackRequest(
        string $method,
        string $path,
        array $body,
        ?string $secret = null,
        ?int $timestamp = null
    ): array {
        $secret = $secret ?? config('services.v3.callback_hmac_key', 'dev-callback-hmac-key');
        $ts = $timestamp ?? time();
        $rawBody = ($method === 'GET' && empty($body)) ? '' : json_encode($body, JSON_UNESCAPED_SLASHES);
        $bodySha256 = strtolower(hash('sha256', $rawBody));
        $normalizedPath = '/' . ltrim($path, '/');
        $stringToSign = strtoupper($method) . "\n" . $normalizedPath . "\n" . $ts . "\n" . $bodySha256;
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

    public static function signBridgeRequest(
        string $method,
        string $path,
        array $body,
        ?string $secret = null,
        ?int $timestamp = null
    ): array {
        $secret = $secret ?? config('services.v3.bridge_hmac_key', 'dev-bridge-hmac-key');
        $ts = $timestamp ?? time();
        $rawBody = ($method === 'GET' && empty($body)) ? '' : json_encode($body, JSON_UNESCAPED_SLASHES);
        $bodySha256 = strtolower(hash('sha256', $rawBody));
        $normalizedPath = '/' . ltrim($path, '/');
        $stringToSign = strtoupper($method) . "\n" . $normalizedPath . "\n" . $ts . "\n" . $bodySha256;
        $sig = strtolower(hash_hmac('sha256', $stringToSign, $secret));

        return [
            'X-V3-Timestamp' => (string) $ts,
            'X-V3-Signature' => $sig,
            'X-V3-Contract-Version' => '1',
            'Content-Type' => 'application/json',
        ];
    }

    public static function computePayloadDigest(array $event): string
    {
        $errorRep = array_key_exists('error_code', $event) && $event['error_code'] !== null
            ? $event['error_code']
            : '-';

        $parts = [
            (string) ($event['schema_version'] ?? 1),
            (string) ($event['event_key'] ?? ''),
            (string) ($event['case_id'] ?? ''),
            (string) ($event['workflow_id'] ?? ''),
            (string) ($event['run_id'] ?? ''),
            (string) ($event['expected_state_version'] ?? 1),
            (string) ($event['event_type'] ?? ''),
            (string) ($event['stage_code'] ?? ''),
            (string) ($event['status_code'] ?? ''),
            $errorRep,
        ];

        return hash('sha256', implode("\n", $parts));
    }

    public static function createCaseWithLink(
        User $owner,
        SettingsVersion $settingsVersion,
        string $state = 'awaiting_workflow',
        int $stateVersion = 1,
        string $startState = 'pending',
        ?string $runId = null
    ): array {
        $doc = Document::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $owner->id,
            'sha256' => hash('sha256', 'doc-' . Str::random(10)),
            'byte_size' => 1024,
            'page_count' => 1,
            'state' => 'ready',
            'storage_path' => 'accepted/' . Str::uuid() . '.pdf',
            'accepted_at' => now(),
        ]);

        $case = ProcessingCase::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $owner->id,
            'document_id' => $doc->id,
            'settings_version_id' => $settingsVersion->id,
            'idempotency_key' => (string) Str::uuid(),
            'action' => 'process',
            'state' => $state,
            'state_version' => $stateVersion,
        ]);

        $link = WorkflowLink::forceCreate([
            'case_id' => $case->id,
            'workflow_id' => "v3-case-{$case->id}",
            'run_id' => $runId,
            'settings_version_id' => $settingsVersion->id,
            'settings_checksum' => $settingsVersion->checksum,
            'start_state' => $startState,
        ]);

        return [
            'document' => $doc,
            'case' => $case,
            'link' => $link,
        ];
    }
}
