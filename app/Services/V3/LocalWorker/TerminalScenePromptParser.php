<?php

namespace App\Services\V3\LocalWorker;

/** Parses scene prompts from the terminal's retained THIRD artifact. */
final class TerminalScenePromptParser
{
    /** @return list<string> */
    public function parse(string $third): array
    {
        preg_match_all('/^Lec\s*W?\s*\d+\s*\(\s*Sec\s*(\d+)\s*\)\s*:\s*(.*)$/miu', $third, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $prompts = [];
        foreach ($matches as $index => $match) {
            $start = $match[0][1] + strlen($match[0][0]);
            $stop = $matches[$index + 1][0][1] ?? strlen($third);
            $body = trim($match[2][0] . "\n" . substr($third, $start, $stop - $start));
            if (str_starts_with($body, '"') || str_starts_with($body, "'")) {
                $quote = $body[0];
                $body = substr($body, 1);
                if (str_ends_with($body, $quote)) {
                    $body = substr($body, 0, -1);
                }
            }
            $prompts[] = trim($body);
        }

        return $prompts;
    }
}
