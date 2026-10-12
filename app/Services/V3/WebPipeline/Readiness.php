<?php

namespace App\Services\V3\WebPipeline;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class Readiness
{
    public const REQUIRED_UPLOAD_MAX_FILESIZE_BYTES = 104857600;    // 100 MiB
    public const REQUIRED_POST_MAX_SIZE_BYTES = 1153433600;          // 1100 MiB
    public const REQUIRED_MAX_FILE_UPLOADS = 20;

    /**
     * Run all readiness diagnostics for the V3 Web Pipeline.
     *
     * @param bool|null $enforceLimits Override whether upload limit requirements block overall readiness
     * @return array{
     *     ready: bool,
     *     opt_in: bool,
     *     default_mode: string,
     *     legacy_protocol_default: string,
     *     checks: array<string, array{status: string, message: string, detail?: mixed}>
     * }
     */
    public function check(?bool $enforceLimits = null): array
    {
        $shouldEnforce = $enforceLimits ?? (bool) config('services.v3.enforce_upload_limits', true);
        $checks = [];
        $overallReady = true;

        // 1. Opt-in web pipeline enablement check
        $enabled = (bool) config('services.v3.web_pipeline_enabled', false);
        $checks['web_pipeline_enabled'] = [
            'status' => $enabled ? 'pass' : 'warn',
            'message' => $enabled
                ? 'Web pipeline integration is explicitly enabled.'
                : 'Web pipeline integration is currently disabled (opt-in). Set V3_WEB_PIPELINE_ENABLED=true to activate.',
        ];

        // 2. Default mode: must default to export_manual
        $mode = (string) config('services.v3.default_mode', 'export_manual');
        $checks['default_mode'] = [
            'status' => $mode === 'export_manual' ? 'pass' : 'warn',
            'message' => "Default generation mode is '{$mode}'.",
            'detail' => $mode,
        ];

        // 3. Legacy protocol defaults unchanged
        $legacyProtocol = (string) config('services.v3.notebook_protocol', 'legacy_staged_v1');
        $checks['legacy_protocol_default'] = [
            'status' => $legacyProtocol === 'legacy_staged_v1' ? 'pass' : 'warn',
            'message' => "Legacy notebook protocol default is '{$legacyProtocol}'.",
            'detail' => $legacyProtocol,
        ];

        // 4. PHP upload_max_filesize >= 100 MiB
        $uploadMaxRaw = ini_get('upload_max_filesize') ?: '2M';
        $uploadMaxBytes = $this->parseSizeToBytes($uploadMaxRaw);
        $uploadMaxPass = $uploadMaxBytes >= self::REQUIRED_UPLOAD_MAX_FILESIZE_BYTES;
        $checks['upload_max_filesize'] = [
            'status' => $uploadMaxPass ? 'pass' : ($shouldEnforce ? 'fail' : 'warn'),
            'message' => "Effective upload_max_filesize is {$uploadMaxRaw} (" . number_format($uploadMaxBytes) . " bytes). Required: >= 100 MiB.",
            'detail' => ['current_raw' => $uploadMaxRaw, 'current_bytes' => $uploadMaxBytes, 'required_bytes' => self::REQUIRED_UPLOAD_MAX_FILESIZE_BYTES],
        ];
        if (! $uploadMaxPass && $shouldEnforce) {
            $overallReady = false;
        }

        // 5. PHP post_max_size >= 1100 MiB
        $postMaxRaw = ini_get('post_max_size') ?: '8M';
        $postMaxBytes = $this->parseSizeToBytes($postMaxRaw);
        $postMaxPass = $postMaxBytes >= self::REQUIRED_POST_MAX_SIZE_BYTES;
        $checks['post_max_size'] = [
            'status' => $postMaxPass ? 'pass' : ($shouldEnforce ? 'fail' : 'warn'),
            'message' => "Effective post_max_size is {$postMaxRaw} (" . number_format($postMaxBytes) . " bytes). Required: >= 1100 MiB.",
            'detail' => ['current_raw' => $postMaxRaw, 'current_bytes' => $postMaxBytes, 'required_bytes' => self::REQUIRED_POST_MAX_SIZE_BYTES],
        ];
        if (! $postMaxPass && $shouldEnforce) {
            $overallReady = false;
        }

        // 6. PHP max_file_uploads >= 20
        $maxUploadsRaw = (int) (ini_get('max_file_uploads') ?: 20);
        $maxUploadsPass = $maxUploadsRaw >= self::REQUIRED_MAX_FILE_UPLOADS;
        $checks['max_file_uploads'] = [
            'status' => $maxUploadsPass ? 'pass' : ($shouldEnforce ? 'fail' : 'warn'),
            'message' => "Effective max_file_uploads is {$maxUploadsRaw}. Required: >= 20.",
            'detail' => ['current' => $maxUploadsRaw, 'required' => self::REQUIRED_MAX_FILE_UPLOADS],
        ];
        if (! $maxUploadsPass && $shouldEnforce) {
            $overallReady = false;
        }

        // 7. PHP upload_tmp_dir and private permissions
        $tmpDir = config('services.v3.upload_tmp_dir') ?: ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
        $tmpDirExists = is_dir($tmpDir);
        $tmpDirWritable = is_writable($tmpDir);
        $tmpPerms = $tmpDirExists ? fileperms($tmpDir) : 0;
        // Check if world-writable (other write bit 0002)
        $isWorldWritable = ($tmpPerms & 0002) !== 0;

        $tmpDirPass = $tmpDirExists && $tmpDirWritable;
        $checks['upload_tmp_dir'] = [
            'status' => $tmpDirPass ? ($isWorldWritable ? 'warn' : 'pass') : 'fail',
            'message' => $tmpDirPass
                ? ($isWorldWritable ? "Upload tmp dir {$tmpDir} is writable but world-writable; private directory recommended." : "Upload tmp dir {$tmpDir} is private and writable.")
                : "Upload tmp dir {$tmpDir} does not exist or is not writable.",
            'detail' => ['path' => $tmpDir, 'writable' => $tmpDirWritable, 'world_writable' => $isWorldWritable],
        ];
        if (! $tmpDirPass) {
            $overallReady = false;
        }

        // 8. Private storage disk check
        $privateDir = storage_path('app/private/v3');
        if (! File::exists($privateDir)) {
            File::makeDirectory($privateDir, 0750, true, true);
        }
        $privateWritable = is_writable($privateDir);
        $checks['private_storage'] = [
            'status' => $privateWritable ? 'pass' : 'fail',
            'message' => $privateWritable ? "Private storage path {$privateDir} is accessible and writable." : "Private storage path {$privateDir} is not writable.",
            'detail' => ['path' => $privateDir, 'writable' => $privateWritable],
        ];
        if (! $privateWritable) {
            $overallReady = false;
        }

        // 9. Bridge URL and secret configuration (zero secret printing)
        $bridgeUrl = config('services.v3.bridge_url');
        $bridgeKey = config('services.v3.bridge_hmac_key');
        $bridgeConfigured = ! empty($bridgeUrl) && ! empty($bridgeKey);
        $checks['bridge_configuration'] = [
            'status' => $bridgeConfigured ? 'pass' : 'warn',
            'message' => $bridgeConfigured ? 'Application pipeline bridge and HMAC signing key are configured.' : 'Bridge URL or HMAC signing key is missing.',
            'detail' => ['bridge_url' => $bridgeUrl, 'hmac_key_present' => ! empty($bridgeKey)],
        ];

        // 10. Provider catalog: safe presence report without printing keys or spending probes
        $providers = [
            'gemini' => ! empty(env('GEMINI_API_KEY')),
            'glm' => ! empty(env('GLM_API_KEY')),
            'deepseek' => ! empty(env('DEEPSEEK_API_KEY')),
            'qwen' => ! empty(env('QWEN_API_KEY')),
        ];
        $checks['provider_credentials'] = [
            'status' => 'pass',
            'message' => 'Provider credentials inspected safely with zero API probe calls.',
            'detail' => $providers,
        ];

        return [
            'ready' => $overallReady,
            'opt_in' => $enabled,
            'default_mode' => $mode,
            'legacy_protocol_default' => $legacyProtocol,
            'checks' => $checks,
        ];
    }

    /**
     * Parse human-readable PHP shorthand size into integer bytes.
     */
    public function parseSizeToBytes(string $size): int
    {
        $size = trim($size);
        if ($size === '' || $size === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($size, -1));
        $value = (int) $size;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => (int) $size,
        };
    }
}
