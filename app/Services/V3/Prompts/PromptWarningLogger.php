<?php

namespace App\Services\V3\Prompts;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PromptWarningLogger
{
    public const ALLOWED_WARNING_CODES = [
        'prompt_turn_uncertain',
        'prompt_feedback_uncertain',
        'prompt_artifact_missing',
        'prompt_provider_unavailable',
    ];

    /**
     * Log a warning once per condition per 24 hours with IDs only.
     */
    public static function warnOnce(string $code, array $context): void
    {
        $caseId = $context['case_id'] ?? 'global';
        $subKey = $context['artifact_path'] ?? $context['turn_ref'] ?? $context['effect_ref'] ?? 'general';
        $cacheKey = "v3_prompt_warn:{$code}:{$caseId}:{$subKey}";

        if (Cache::has($cacheKey)) {
            return;
        }

        // Sanitize context to ensure only safe ID and status keys are included
        $safeContext = [];
        $allowedKeys = [
            'code',
            'case_id',
            'turn_ref',
            'effect_ref',
            'attempt_ref',
            'artifact_path',
            'status',
            'error_code',
            'provider_id',
            'template_id',
        ];

        foreach ($context as $key => $value) {
            if (in_array($key, $allowedKeys, true) && (is_string($value) || is_numeric($value) || is_null($value))) {
                $safeContext[$key] = $value;
            }
        }

        Log::warning($code, $safeContext);
        Cache::put($cacheKey, true, now()->addHours(24));
    }
}
