<?php

namespace App\Services\V3\Settings;

class CanonicalSettings
{
    public static function canonicalize(array $payload): array
    {
        // Strip expected_active_version and framework tokens
        unset($payload['expected_active_version'], $payload['_token'], $payload['_method']);

        return static::sortRecursive($payload);
    }

    public static function encode(array $canonicalPayload): string
    {
        return json_encode($canonicalPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function checksum(array $canonicalPayload): string
    {
        return hash('sha256', static::encode($canonicalPayload));
    }

    public static function diffPaths(array $newPayload, ?array $oldPayload = null): array
    {
        $oldPayload = $oldPayload ?? [];
        $changed = [];

        // 1. limits
        $newLimits = $newPayload['limits'] ?? [];
        $oldLimits = $oldPayload['limits'] ?? [];
        foreach ($newLimits as $k => $v) {
            if (! array_key_exists($k, $oldLimits) || $oldLimits[$k] !== $v) {
                $changed[] = "limits.{$k}";
            }
        }

        // 2. qualification
        foreach (['prompt', 'scene'] as $target) {
            $newQ = $newPayload['qualification'][$target] ?? [];
            $oldQ = $oldPayload['qualification'][$target] ?? [];
            foreach (['min_score', 'min_confidence'] as $qField) {
                if (isset($newQ[$qField])) {
                    if (! isset($oldQ[$qField]) || $oldQ[$qField] !== $newQ[$qField]) {
                        $changed[] = "qualification.{$target}.{$qField}";
                    }
                }
            }
        }

        // 3. providers: prompt, feedback
        foreach (['prompt', 'feedback'] as $role) {
            $newP = $newPayload['providers'][$role] ?? [];
            $oldP = $oldPayload['providers'][$role] ?? [];
            foreach (['id', 'model', 'credential_ref'] as $pField) {
                if (isset($newP[$pField])) {
                    if (! isset($oldP[$pField]) || $oldP[$pField] !== $newP[$pField]) {
                        $changed[] = "providers.{$role}.{$pField}";
                    }
                }
            }
        }

        // 4. providers.coding_order (atomic leaf path)
        $newOrder = $newPayload['providers']['coding_order'] ?? null;
        $oldOrder = $oldPayload['providers']['coding_order'] ?? null;
        if ($newOrder !== null) {
            if ($oldOrder === null || json_encode($newOrder) !== json_encode($oldOrder)) {
                $changed[] = 'providers.coding_order';
            }
        }

        // 5. templates: feedback, scene
        foreach (['feedback', 'scene'] as $tmplKind) {
            $newT = $newPayload['templates'][$tmplKind] ?? [];
            $oldT = $oldPayload['templates'][$tmplKind] ?? [];
            foreach (['id', 'checksum'] as $tField) {
                if (isset($newT[$tField])) {
                    if (! isset($oldT[$tField]) || $oldT[$tField] !== $newT[$tField]) {
                        $changed[] = "templates.{$tmplKind}.{$tField}";
                    }
                }
            }
        }

        sort($changed);

        return array_values(array_unique($changed));
    }

    private static function sortRecursive(array $data): array
    {
        if (array_is_list($data)) {
            $result = [];
            foreach ($data as $item) {
                $result[] = is_array($item) ? static::sortRecursive($item) : static::normalizeScalar($item);
            }

            return $result;
        }

        ksort($data);
        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = is_array($value) ? static::sortRecursive($value) : static::normalizeScalar($value);
        }

        return $result;
    }

    private static function normalizeScalar(mixed $value): mixed
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        return $value;
    }
}
