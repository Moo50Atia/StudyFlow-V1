<?php

namespace App\Support;

/** Select the final complete response envelope, preserving original raw bytes. */
final class NotebookArtifactEnvelope
{
    public static function extract(string $raw): ?array
    {
        preg_match_all('/=== FIRST ===/', $raw, $starts, PREG_OFFSET_CAPTURE);
        foreach (array_reverse($starts[0]) as $start) {
            $markers = [[$start[1], $start[1] + strlen($start[0])]];
            foreach (['SECOND', 'THIRD', 'END'] as $name) {
                $delimiter = "=== {$name} ===";
                $position = strpos($raw, $delimiter, $markers[count($markers) - 1][1]);
                if ($position === false) break;
                $markers[] = [$position, $position + strlen($delimiter)];
            }
            if (count($markers) !== 4) continue;
            $parts = [];
            foreach (['first', 'second', 'third'] as $index => $name) {
                $parts[$name] = trim(substr($raw, $markers[$index][1], $markers[$index + 1][0] - $markers[$index][1]));
            }
            if (!in_array('', $parts, true)) return $parts;
        }
        return null;
    }
}
