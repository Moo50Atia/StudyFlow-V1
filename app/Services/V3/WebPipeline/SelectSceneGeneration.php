<?php

namespace App\Services\V3\WebPipeline;

use InvalidArgumentException;

class SelectSceneGeneration
{
    public const APPROVED_CATALOG = [
        'gemini_api' => [
            'provider_id' => 'gemini_api',
            'python_alias' => 'gemini',
            'credential_alias' => 'GEMINI_API_KEY',
            'approved_endpoint' => 'https://generativelanguage.googleapis.com',
            'default_model' => 'gemini-2.5-flash',
            'gemini_thinking_budget' => 24576,
            'gemini_max_output_tokens' => 65536,
        ],
        'glm_api' => [
            'provider_id' => 'glm_api',
            'python_alias' => 'glm',
            'credential_alias' => 'GLM_API_KEY',
            'approved_endpoint' => 'https://open.bigmodel.cn/api/paas/v4',
            'default_model' => 'glm-4-plus',
            'gemini_thinking_budget' => null,
            'gemini_max_output_tokens' => null,
        ],
        'deepseek_api' => [
            'provider_id' => 'deepseek_api',
            'python_alias' => 'deepseek',
            'credential_alias' => 'DEEPSEEK_API_KEY',
            'approved_endpoint' => 'https://api.deepseek.com',
            'default_model' => 'deepseek-chat',
            'gemini_thinking_budget' => null,
            'gemini_max_output_tokens' => null,
        ],
        'qwen_api' => [
            'provider_id' => 'qwen_api',
            'python_alias' => 'qwen',
            'credential_alias' => 'QWEN_API_KEY',
            'approved_endpoint' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
            'default_model' => 'qwen-plus',
            'gemini_thinking_budget' => null,
            'gemini_max_output_tokens' => null,
        ],
    ];

    /**
     * Map a catalog provider ID to its approved metadata.
     *
     * @return array<string, mixed>
     */
    public static function mapProvider(string $providerId): array
    {
        if (! isset(self::APPROVED_CATALOG[$providerId])) {
            throw new InvalidArgumentException("Unknown or unapproved provider ID '{$providerId}'.");
        }

        return self::APPROVED_CATALOG[$providerId];
    }

    /**
     * Capture provider selection and thinking parameters for a subject run.
     *
     * @param string $mode 'export_manual' | 'selected_api'
     * @return array<string, mixed>
     */
    public static function captureSelection(
        string $mode,
        ?string $providerId = null,
        ?string $model = null,
        ?int $thinkingBudget = null,
        ?string $customEndpoint = null
    ): array {
        if ($mode === 'export_manual') {
            return [
                'mode' => 'export_manual',
                'provider' => null,
                'requires_generation_key' => false,
                'captured_at' => now()->toIso8601String(),
            ];
        }

        if (! in_array($mode, ['selected_api', 'api_generate'], true)) {
            throw new InvalidArgumentException("Unsupported mode '{$mode}'. Must be export_manual, selected_api, or api_generate.");
        }

        $providerId = $providerId ?: 'gemini_api';
        $meta = self::mapProvider($providerId);

        $model = $model ?: $meta['default_model'];
        if (! is_string($model) || ! preg_match('/^[A-Za-z0-9._:\/-]{1,100}$/', $model)) {
            throw new InvalidArgumentException('Model must be 1-100 approved identifier characters.');
        }
        if ($providerId === 'gemini_api' && ! str_starts_with($model, 'gemini-2.5-')) {
            throw new InvalidArgumentException('Connected Gemini authoring currently supports Gemini 2.5 models.');
        }

        $endpoint = $customEndpoint ?: $meta['approved_endpoint'];
        if ($endpoint !== $meta['approved_endpoint']) {
            throw new InvalidArgumentException('Custom endpoints are not supported by the connected authoring worker.');
        }

        $budget = $meta['gemini_thinking_budget'];
        if ($providerId === 'gemini_api' && $thinkingBudget !== null) {
            if ($thinkingBudget < 0 || $thinkingBudget > 24576) {
                throw new InvalidArgumentException("Gemini thinking budget must be between 0 and 24576, got {$thinkingBudget}.");
            }
            $budget = $thinkingBudget;
        }

        return [
            'mode' => $mode,
            'requires_generation_key' => true,
            'provider' => [
                'id' => $meta['provider_id'],
                'alias' => $meta['python_alias'],
                'requested_model' => $model,
                'credential_alias' => $meta['credential_alias'],
                'endpoint' => $endpoint,
                'gemini_thinking_budget' => $budget,
                'gemini_max_output_tokens' => $meta['gemini_max_output_tokens'],
            ],
            'captured_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Validate approved HTTPS endpoint (rejecting arbitrary unapproved browser URLs).
     */
    public static function validateEndpoint(string $endpoint, string $providerId): void
    {
        $meta = self::mapProvider($providerId);
        $approvedHost = parse_url($meta['approved_endpoint'], PHP_URL_HOST);
        $actualHost = parse_url($endpoint, PHP_URL_HOST);
        $actualScheme = parse_url($endpoint, PHP_URL_SCHEME);

        $isLocal = in_array($actualHost, ['127.0.0.1', 'localhost'], true) && in_array($actualScheme, ['http', 'https'], true);

        if (! $isLocal && ($actualScheme !== 'https' || $actualHost !== $approvedHost)) {
            throw new InvalidArgumentException("Endpoint '{$endpoint}' is not an approved endpoint for '{$providerId}'. Expected host '{$approvedHost}'.");
        }
    }
}
