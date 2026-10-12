<?php

namespace App\Services\V3\Authoring;

class StaticScenePackage
{
    public const REQUIRED_FILES = ['index.html', 'styles.css', 'script.js'];
    public const MAX_FILE_BYTES = 1048576;   // 1 MiB
    public const MAX_TOTAL_BYTES = 4194304;  // 4 MiB

    public const MIME_TYPES = [
        'index.html' => 'text/html',
        'styles.css' => 'text/css',
        'script.js' => 'application/javascript',
    ];

    /**
     * Validate a static three-file scene package.
     *
     * @param array<string, mixed> $package
     * @return array{
     *     schema_version: int,
     *     files: array<int, array{path: string, mime: string, content: string, bytes: int, sha256: string}>,
     *     total_bytes: int,
     *     package_sha256: string,
     *     source_manifest_sha256: string
     * }
     * @throws AuthoringValidationException
     */
    public static function validate(array $package): array
    {
        $schemaVersion = $package['schema_version'] ?? null;
        if ($schemaVersion !== 1) {
            throw new AuthoringValidationException("Package schema_version must be 1, got " . json_encode($schemaVersion));
        }

        $files = $package['files'] ?? null;
        if (! is_array($files) || ! array_is_list($files)) {
            throw new AuthoringValidationException("Package files must be a list of file objects.");
        }

        if (count($files) !== 3) {
            throw new AuthoringValidationException("Package must contain exactly 3 files, got " . count($files));
        }

        $seenPaths = [];
        $normalizedFiles = [];
        $totalBytes = 0;

        foreach ($files as $file) {
            if (! is_array($file) || ! isset($file['path'], $file['content'])) {
                throw new AuthoringValidationException("Each file entry must have 'path' and 'content'.");
            }

            $path = $file['path'];
            $content = $file['content'];

            if (! is_string($path) || ! is_string($content)) {
                throw new AuthoringValidationException("File path and content must be strings.");
            }

            if (! in_array($path, self::REQUIRED_FILES, true)) {
                throw new AuthoringValidationException("Disallowed or invalid file path: '{$path}'. Only index.html, styles.css, script.js are allowed.");
            }

            if (isset($seenPaths[$path])) {
                throw new AuthoringValidationException("Duplicate file path: '{$path}'.");
            }
            $seenPaths[$path] = true;

            if (str_contains($content, "\0")) {
                throw new AuthoringValidationException("File content for '{$path}' contains forbidden NUL bytes.");
            }

            if (! mb_check_encoding($content, 'UTF-8')) {
                throw new AuthoringValidationException("File content for '{$path}' is not valid UTF-8.");
            }

            $bytes = strlen($content);
            if ($bytes < 1) {
                throw new AuthoringValidationException("File '{$path}' cannot be empty.");
            }

            if ($bytes > self::MAX_FILE_BYTES) {
                throw new AuthoringValidationException("File '{$path}' size ({$bytes} bytes) exceeds the maximum limit of " . self::MAX_FILE_BYTES . " bytes.");
            }

            $totalBytes += $bytes;
            $sha256 = hash('sha256', $content);

            $normalizedFiles[$path] = [
                'path' => $path,
                'mime' => self::MIME_TYPES[$path] ?? 'application/octet-stream',
                'content' => $content,
                'bytes' => $bytes,
                'sha256' => $sha256,
            ];
        }

        foreach (self::REQUIRED_FILES as $req) {
            if (! isset($normalizedFiles[$req])) {
                throw new AuthoringValidationException("Missing required file: '{$req}'.");
            }
        }

        if ($totalBytes > self::MAX_TOTAL_BYTES) {
            throw new AuthoringValidationException("Total package size ({$totalBytes} bytes) exceeds limit of " . self::MAX_TOTAL_BYTES . " bytes.");
        }

        // Ordered canonically
        $orderedFiles = [
            $normalizedFiles['index.html'],
            $normalizedFiles['styles.css'],
            $normalizedFiles['script.js'],
        ];

        $sourceManifestSha256 = self::computeSourceManifestSha256($orderedFiles);
        $packageSha256 = self::computePackageSha256($package);

        return [
            'schema_version' => 1,
            'files' => $orderedFiles,
            'total_bytes' => $totalBytes,
            'package_sha256' => $packageSha256,
            'source_manifest_sha256' => $sourceManifestSha256,
        ];
    }

    /**
     * Compute source manifest sha256 for the three files.
     *
     * @param array<int, array{path: string, mime: string, content: string, bytes: int, sha256: string}> $files
     */
    public static function computeSourceManifestSha256(array $files): string
    {
        $manifest = [
            'schema_version' => 1,
            'files' => array_map(fn($f) => [
                'path' => $f['path'],
                'bytes' => $f['bytes'],
                'sha256' => $f['sha256'],
            ], $files),
        ];

        return hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    /**
     * Deterministic sha256 for the input package.
     */
    public static function computePackageSha256(array $package): string
    {
        return hash('sha256', json_encode($package, JSON_THROW_ON_ERROR));
    }
}
