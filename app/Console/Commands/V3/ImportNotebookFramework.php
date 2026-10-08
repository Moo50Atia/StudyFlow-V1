<?php

namespace App\Console\Commands\V3;

use App\Services\V3\NotebookLM\FrameworkCatalog;
use Illuminate\Console\Command;
use Throwable;

class ImportNotebookFramework extends Command
{
    protected $signature = 'v3:import-notebook-framework
                            {--path= : Path to the framework steps file}
                            {--expected-sha256= : Expected SHA-256 digest of the file}
                            {--admin-user= : ID of the active admin user performing approval}
                            {--profile=expanded-root-v1 : Framework profile name}';

    protected $description = 'Import and approve an immutable notebook framework steps file';

    public function handle(FrameworkCatalog $catalog): int
    {
        $path = $this->option('path');
        $expectedSha256 = $this->option('expected-sha256');
        $adminUserId = $this->option('admin-user');
        $profile = $this->option('profile') ?: 'expanded-root-v1';

        if (! $path || ! $expectedSha256 || ! $adminUserId) {
            $this->error('Missing required options: --path, --expected-sha256, and --admin-user are all required.');

            return self::FAILURE;
        }

        try {
            $version = $catalog->importFramework(
                (string) $path,
                strtolower((string) $expectedSha256),
                (int) $adminUserId,
                (string) $profile
            );

            $this->info("Framework imported successfully. Ref: {$version->id}, SHA256: {$version->body_sha256}, Profile: {$version->profile}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Failed to import framework: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
