<?php

namespace Tests\Unit\V3\LocalWorker;

use App\Services\V3\LocalWorker\TerminalScenePromptParser;
use PHPUnit\Framework\TestCase;

class TerminalScenePromptParserTest extends TestCase
{
    public function test_it_preserves_order_and_terminal_prompt_text(): void
    {
        $third = <<<'TEXT'
LecW1 (Sec1):
"A quoted first prompt.
Continue it exactly."

Lec2 (Sec4):
Second prompt.
TEXT;

        self::assertSame([
            "A quoted first prompt.\n\nContinue it exactly.",
            'Second prompt.',
        ], (new TerminalScenePromptParser())->parse($third));
    }
}
