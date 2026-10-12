<?php

namespace Tests\Integration\V3\WebPipeline;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * PHPUnit entry point for the authoritative Python live web-pipeline gate.
 *
 * This test never starts a provider request by default. An operator must set
 * RUN_V3_LIVE_APPLICATION_GATE=1 (or V3_LIVE_GATE_ENABLED=1); the delegated
 * runner then checks the approved NotebookLM session, proof notebook, two
 * PDFs, Qwen model/key, and exact one-request spending acknowledgement before
 * it starts the full browser journey.
 */
class LiveApplicationGateTest extends TestCase
{
    public function test_live_two_pdf_application_chain(): void
    {
        $enabled = env('RUN_V3_LIVE_APPLICATION_GATE') === '1'
            || env('V3_LIVE_GATE_ENABLED') === '1';

        if (! $enabled) {
            $this->markTestSkipped(
                'Live application gate is opt-in; set RUN_V3_LIVE_APPLICATION_GATE=1 '
                .'or V3_LIVE_GATE_ENABLED=1 to invoke the prerequisite-checked Python gate.'
            );
        }

        $python = base_path('.venv/bin/python');
        if (! is_executable($python)) {
            $python = 'python3';
        }

        $process = new Process([
            $python,
            '-m',
            'pytest',
            '-c',
            'v3_engine/pyproject.toml',
            '--run-live',
            '--import-mode=importlib',
            'v3_engine/tests/web_pipeline/test_live_web_pipeline.py',
            '-q',
            '-ra',
            '--tb=short',
        ], base_path(), [
            'RUN_V3_LIVE_APPLICATION_GATE' => '1',
            'V3_RUN_LIVE' => '1',
        ]);
        $process->setTimeout(1800);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();
        if ($process->getExitCode() !== 0) {
            $this->fail(
                'The opted-in full live gate failed after launch. Inspect its private pytest logs; '
                .'the PHP wrapper does not convert an execution failure into a pass.'
            );
        }

        if (preg_match('/\b1 skipped\b/', $output) === 1) {
            $this->markTestSkipped(
                'The Python live gate reported missing prerequisites before launching the browser workflow.'
            );
        }

        $this->assertMatchesRegularExpression(
            '/\b1 passed\b/',
            $output,
            'The live gate must complete the joined browser workflow; an empty or malformed pytest run is not success.'
        );
    }
}
