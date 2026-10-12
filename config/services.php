<?php

return [
    'v3_teacher_paid_scenes_enabled' => (bool) env('V3_TEACHER_PAID_SCENES_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'v3' => [
        'bridge_url' => env('V3_BRIDGE_URL', 'http://127.0.0.1:8765'),
        'bridge_hmac_key' => env('V3_BRIDGE_HMAC_KEY'),
        'callback_hmac_key' => env('V3_CALLBACK_HMAC_KEY'),
        'jev_model' => env('JEV_MODEL'),
        'notebook_protocol' => env('V3_NOTEBOOK_PROTOCOL', 'legacy_staged_v1'),
        'notebooklm_storage_state' => env('V3_NOTEBOOKLM_STORAGE_STATE'),
        'notebooklm_base_url' => env('NOTEBOOKLM_BASE_URL', 'https://notebooklm.google.com'),
        'terminal_root' => env('V3_TERMINAL_ROOT', storage_path('app/private/v3/terminal')),
        'terminal_temporal_address' => env('V3_TERMINAL_TEMPORAL_ADDRESS', '127.0.0.1:7233'),
        'terminal_temporal_namespace' => env('V3_TERMINAL_TEMPORAL_NAMESPACE', 'v3-terminal'),
        'web_pipeline_bridge_url' => env('V3_WEB_PIPELINE_BRIDGE_URL', env('V3_BRIDGE_URL', 'http://127.0.0.1:8765')),
        'web_pipeline_hmac_secret' => env('V3_WEB_PIPELINE_HMAC_SECRET', env('V3_BRIDGE_HMAC_KEY')),
        'web_pipeline_enabled' => env('V3_WEB_PIPELINE_ENABLED', false),
        'default_mode' => env('V3_DEFAULT_MODE', 'export_manual'),
        'upload_tmp_dir' => env('V3_UPLOAD_TMP_DIR'),
        'enforce_upload_limits' => env('V3_ENFORCE_UPLOAD_LIMITS', true),
    ],

];
