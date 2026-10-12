<?php

namespace Tests\Support\V3;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class WebPipelineRuntime
{
    private string $testRoot;
    private string $dbPath;
    private array $requestCounts = [];
    private bool $simulateCallbackFailure = false;
    private int $callbackFailureStatusCode = 500;

    public function __construct(?string $testRoot = null)
    {
        $this->testRoot = $testRoot ?? sys_get_temp_dir() . '/v3_runtime_' . Str::random(12);
        if (! File::isDirectory($this->testRoot)) {
            File::makeDirectory($this->testRoot, 0755, true);
        }
        $this->dbPath = $this->testRoot . '/test_pipeline.sqlite';
        if (! File::exists($this->dbPath)) {
            touch($this->dbPath);
        }
    }

    public function getTestRoot(): string
    {
        return $this->testRoot;
    }

    public function getDatabasePath(): string
    {
        return $this->dbPath;
    }

    public function initializeDatabase(): void
    {
        // Migrate database using artisan
        Artisan::call('migrate', [
            '--database' => 'sqlite',
            '--force' => true,
        ]);

        // Seed prerequisites (users, permissions, settings, templates)
        Artisan::call('v3:seed-harness');
    }

    public function recordRequest(string $endpoint): int
    {
        if (! isset($this->requestCounts[$endpoint])) {
            $this->requestCounts[$endpoint] = 0;
        }
        $this->requestCounts[$endpoint]++;
        return $this->requestCounts[$endpoint];
    }

    public function getRequestCount(string $endpoint): int
    {
        return $this->requestCounts[$endpoint] ?? 0;
    }

    public function resetRequestCounts(): void
    {
        $this->requestCounts = [];
    }

    public function setCallbackFailure(bool $fail, int $statusCode = 500): void
    {
        $this->simulateCallbackFailure = $fail;
        $this->callbackFailureStatusCode = $statusCode;
    }

    public function shouldFailCallback(): bool
    {
        return $this->simulateCallbackFailure;
    }

    public function getCallbackFailureStatusCode(): int
    {
        return $this->callbackFailureStatusCode;
    }

    public function cleanup(): void
    {
        if (File::isDirectory($this->testRoot)) {
            File::deleteDirectory($this->testRoot);
        }
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
