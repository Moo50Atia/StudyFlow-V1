<?php

namespace App\Services\V3\LocalWorker\Protocol;

use JsonException;

class LocalWorkerJsonDecoder
{
    public const DEFAULT_MAX_SIZE = 262144; // 256 KiB
    public const MANIFEST_MAX_SIZE = 2097152; // 2 MiB
    public const MAX_DEPTH = 32;

    /**
     * Decode JSON string with strict UTF-8 checking, size bounding, and duplicate key rejection.
     *
     * @param string $json
     * @param int $maxSize
     * @return mixed
     * @throws LocalWorkerProtocolException
     */
    public static function decode(string $json, int $maxSize = self::DEFAULT_MAX_SIZE): mixed
    {
        if (strlen($json) > $maxSize) {
            throw new LocalWorkerProtocolException(
                "JSON payload exceeds maximum allowed size of {$maxSize} bytes",
                'bounds_exceeded',
                413
            );
        }

        if (!mb_check_encoding($json, 'UTF-8')) {
            throw new LocalWorkerProtocolException(
                "JSON payload contains invalid UTF-8 encoding",
                'invalid_utf8',
                422
            );
        }

        self::detectDuplicateKeys($json);

        try {
            return json_decode($json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LocalWorkerProtocolException(
                "Malformed JSON payload: " . $e->getMessage(),
                'malformed_json',
                422
            );
        }
    }

    /**
     * Scan JSON text to detect duplicate keys in objects.
     *
     * @param string $json
     * @throws LocalWorkerProtocolException
     */
    public static function detectDuplicateKeys(string $json): void
    {
        $len = strlen($json);
        $stack = []; // elements are: ['type' => 'object'|'array', 'keys' => array, 'expecting_colon' => bool, 'current_key' => string|null]
        $i = 0;

        while ($i < $len) {
            $char = $json[$i];

            // Skip whitespace
            if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
                $i++;
                continue;
            }

            if ($char === '{') {
                $stack[] = [
                    'type' => 'object',
                    'keys' => [],
                    'expecting_key' => true,
                    'after_key' => false,
                    'current_key' => null,
                ];
                $i++;
                continue;
            }

            if ($char === '[') {
                $stack[] = [
                    'type' => 'array',
                    'keys' => [],
                ];
                $i++;
                continue;
            }

            if ($char === '}') {
                if (empty($stack)) {
                    throw new LocalWorkerProtocolException("Unmatched closing brace '}' in JSON", 'malformed_json', 422);
                }
                $top = array_pop($stack);
                if ($top['type'] !== 'object') {
                    throw new LocalWorkerProtocolException("Mismatched closing bracket in JSON", 'malformed_json', 422);
                }
                $i++;
                continue;
            }

            if ($char === ']') {
                if (empty($stack)) {
                    throw new LocalWorkerProtocolException("Unmatched closing bracket ']' in JSON", 'malformed_json', 422);
                }
                $top = array_pop($stack);
                if ($top['type'] !== 'array') {
                    throw new LocalWorkerProtocolException("Mismatched closing brace in JSON", 'malformed_json', 422);
                }
                $i++;
                continue;
            }

            if ($char === '"') {
                // Parse string
                $start = $i;
                $i++;
                $escaped = false;
                while ($i < $len) {
                    if ($json[$i] === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($json[$i] === '"') {
                        break;
                    }
                    $i++;
                }

                if ($i >= $len) {
                    throw new LocalWorkerProtocolException("Unterminated string in JSON", 'malformed_json', 422);
                }

                $rawStr = substr($json, $start, $i - $start + 1);
                $decodedStr = json_decode($rawStr);
                $i++; // move past closing quote

                // Look at top of stack to see if this string was an object key
                if (!empty($stack)) {
                    $topIdx = count($stack) - 1;
                    if ($stack[$topIdx]['type'] === 'object' && $stack[$topIdx]['expecting_key']) {
                        // Look ahead for ':'
                        $colonIdx = $i;
                        while ($colonIdx < $len && ($json[$colonIdx] === ' ' || $json[$colonIdx] === "\t" || $json[$colonIdx] === "\n" || $json[$colonIdx] === "\r")) {
                            $colonIdx++;
                        }
                        if ($colonIdx < $len && $json[$colonIdx] === ':') {
                            $key = (string)$decodedStr;
                            if (isset($stack[$topIdx]['keys'][$key])) {
                                throw new LocalWorkerProtocolException(
                                    "Duplicate key '{$key}' detected in JSON object",
                                    'duplicate_key',
                                    422
                                );
                            }
                            $stack[$topIdx]['keys'][$key] = true;
                            $stack[$topIdx]['expecting_key'] = false;
                            $i = $colonIdx + 1; // move past colon
                            continue;
                        }
                    }
                }
                continue;
            }

            if ($char === ',') {
                if (!empty($stack)) {
                    $topIdx = count($stack) - 1;
                    if ($stack[$topIdx]['type'] === 'object') {
                        $stack[$topIdx]['expecting_key'] = true;
                    }
                }
                $i++;
                continue;
            }

            $i++;
        }
    }
}
