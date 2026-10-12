<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->indexExists('v3_notebook_instances', 'v3_notebook_instances_adapter_provider_nb_unique')) {
            Schema::table('v3_notebook_instances', function (Blueprint $table) {
                $table->unique(['adapter_kind', 'provider_notebook_id'], 'v3_notebook_instances_adapter_provider_nb_unique');
            });
        }
        if (! Schema::hasColumn('v3_notebook_instances', 'provider_framework_source_id')) {
            Schema::table('v3_notebook_instances', function (Blueprint $table) {
                $table->string('provider_framework_source_id', 255)->nullable()->after('provider_notebook_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v3_notebook_instances', 'provider_framework_source_id')) {
            Schema::table('v3_notebook_instances', function (Blueprint $table) {
                $table->dropColumn('provider_framework_source_id');
            });
        }
        if ($this->indexExists('v3_notebook_instances', 'v3_notebook_instances_adapter_provider_nb_unique')) {
            Schema::table('v3_notebook_instances', function (Blueprint $table) {
                $table->dropUnique('v3_notebook_instances_adapter_provider_nb_unique');
            });
        }
    }

    private function indexExists(string $table, string $name): bool
    {
        $connection = Schema::getConnection();
        $indexes = $connection->getSchemaBuilder()->getIndexes($table);
        foreach ($indexes as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }
        return false;
    }
};
