<?php

namespace Tests\Integration\V3\Sources;

use Tests\TestCase;

class LiveNotebookLMTest extends TestCase
{
    public function test_k17_live_provider_gate_status(): void
    {
        $hasCookies = ! empty(env('NOTEBOOKLM_SESSION_COOKIES'))
            || ! empty(env('NOTEBOOKLM_COOKIES'))
            || ! empty(env('NOTEBOOKLM_SESSION'));

        if (! $hasCookies) {
            $this->markTestSkipped('GATE BLOCKED: Dedicated disposable NotebookLM session credentials not provisioned in environment.');
        }

        $this->assertTrue($hasCookies);
    }
}
