<?php

namespace App\Support\V3;

use App\Models\V3\ApprovedTemplate;
use Illuminate\Support\Facades\DB;

class HarnessSettings
{
    public const VALID_FEEDBACK_TEMPLATE_ID = 'feedback-default-v1';

    public const VALID_FEEDBACK_BODY = "You are a pedagogical feedback generator for educational visual scenes.\nProvide concise critique.";

    public const VALID_SCENE_TEMPLATE_ID = 'scene-default-v1';

    public const VALID_SCENE_BODY = "You are a Three.js scene code generator.\nOutput valid standalone JavaScript.";

    public static function registerApprovedTemplate(
        string $kind,
        string $id,
        string $body
    ): ApprovedTemplate {
        $checksum = hash('sha256', $body);

        DB::table('v3_approved_templates')->updateOrInsert(
            ['id' => $id],
            [
                'kind' => $kind,
                'body' => $body,
                'checksum' => $checksum,
                'registered_at' => now(),
            ]
        );

        return ApprovedTemplate::findOrFail($id);
    }

    public static function registerDefaultTemplates(): array
    {
        $feedback = static::registerApprovedTemplate(
            'feedback',
            static::VALID_FEEDBACK_TEMPLATE_ID,
            static::VALID_FEEDBACK_BODY
        );

        $scene = static::registerApprovedTemplate(
            'scene',
            static::VALID_SCENE_TEMPLATE_ID,
            static::VALID_SCENE_BODY
        );

        return [$feedback, $scene];
    }

    public static function validPayload(int $expectedActiveVersion = 0, array $overrides = []): array
    {
        $feedbackChecksum = hash('sha256', static::VALID_FEEDBACK_BODY);
        $sceneChecksum = hash('sha256', static::VALID_SCENE_BODY);

        $payload = [
            'expected_active_version' => $expectedActiveVersion,
            'limits' => [
                'max_pdf_bytes' => 10485760,
                'max_pdf_pages' => 100,
                'max_ocr_pages' => 20,
                'technical_retries' => 3,
                'retry_delay_seconds' => 60,
                'prompt_revisions' => 2,
                'scene_repairs' => 2,
                'coding_attempts' => 3,
                'review_dormancy_days' => 14,
            ],
            'qualification' => [
                'prompt' => [
                    'min_score' => 80,
                    'min_confidence' => 75,
                ],
                'scene' => [
                    'min_score' => 85,
                    'min_confidence' => 80,
                ],
            ],
            'providers' => [
                'prompt' => [
                    'id' => 'gemini_api',
                    'model' => 'gemini-2.5-pro',
                    'credential_ref' => 'GEMINI_API_KEY',
                ],
                'feedback' => [
                    'id' => 'gemini_api',
                    'model' => 'gemini-2.5-flash',
                    'credential_ref' => 'GEMINI_API_KEY',
                ],
                'coding_order' => [
                    [
                        'id' => 'gemini_api',
                        'model' => 'gemini-2.5-pro',
                        'credential_ref' => 'GEMINI_API_KEY',
                    ],
                    [
                        'id' => 'deepseek_api',
                        'model' => 'deepseek-coder',
                        'credential_ref' => 'DEEPSEEK_API_KEY',
                    ],
                ],
            ],
            'templates' => [
                'feedback' => [
                    'id' => static::VALID_FEEDBACK_TEMPLATE_ID,
                    'checksum' => $feedbackChecksum,
                ],
                'scene' => [
                    'id' => static::VALID_SCENE_TEMPLATE_ID,
                    'checksum' => $sceneChecksum,
                ],
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }
}
