<?php

namespace Tests\Integration\V3\Settings;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class SelectionConcurrencyTest extends TestCase
{
    protected string $tempDb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDb = sys_get_temp_dir().'/v3_settings_select_concurrency_'.uniqid().'.sqlite';
        touch($this->tempDb);

        config(['database.connections.sqlite.database' => $this->tempDb]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);

        // Create test consumer table
        Schema::create('v3_test_consumer_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settings_version_id')->constrained('v3_settings_versions')->restrictOnDelete();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }

        parent::tearDown();
    }

    public function test_s09_concurrent_selection_and_publication_linearizes_without_corruption(): void
    {
        $now = now();

        $adminId = DB::table('users')->insertGetId([
            'name' => 'Admin Concurrent',
            'email' => 'admin_conc_sel@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('v3_account_access')->insert([
            'user_id' => $adminId,
            'is_active' => true,
            'version' => 1,
            'provisioned_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('v3_approved_templates')->insert([
            [
                'id' => SettingsFixtures::VALID_FEEDBACK_TEMPLATE_ID,
                'kind' => 'feedback',
                'body' => SettingsFixtures::VALID_FEEDBACK_BODY,
                'checksum' => hash('sha256', SettingsFixtures::VALID_FEEDBACK_BODY),
                'registered_at' => $now,
            ],
            [
                'id' => SettingsFixtures::VALID_SCENE_TEMPLATE_ID,
                'kind' => 'scene',
                'body' => SettingsFixtures::VALID_SCENE_BODY,
                'checksum' => hash('sha256', SettingsFixtures::VALID_SCENE_BODY),
                'registered_at' => $now,
            ],
        ]);

        // Publish version 1 first
        $publisher = app(\App\Services\V3\Settings\PublishSettings::class);
        $v1 = $publisher->publish(SettingsFixtures::validPayload(0), 0, $adminId);

        // Now run SelectionWorker concurrently with PublicationWorker (publishing v2 with expected_version = 1)
        $selectWorkerScript = base_path('tests/Support/V3/ConcurrentSelectionWorker.php');
        $publishWorkerScript = base_path('tests/Support/V3/ConcurrentSettingsWorker.php');

        $procSelect = new Process([
            'php', $selectWorkerScript,
            '--db='.$this->tempDb,
            '--consumer_table=v3_test_consumer_cases',
        ]);

        $payload2 = json_encode(SettingsFixtures::validPayload(1, ['limits' => ['max_pdf_bytes' => 45000000]]));
        $procPublish = new Process([
            'php', $publishWorkerScript,
            '--db='.$this->tempDb,
            '--actor='.$adminId,
            '--expected_version=1',
            '--payload='.$payload2,
        ]);

        $procSelect->start();
        $procPublish->start();

        $procSelect->wait();
        $procPublish->wait();

        $outSelect = json_decode($procSelect->getOutput(), true);
        $outPublish = json_decode($procPublish->getOutput(), true);

        // Selection must succeed
        $this->assertTrue($outSelect['success'] ?? false, 'Selection worker must succeed: '.$procSelect->getOutput());
        // Publication must succeed
        $this->assertTrue($outPublish['success'] ?? false, 'Publication worker must succeed: '.$procPublish->getOutput());

        $selectedVersion = $outSelect['selected_version'];
        $this->assertContains($selectedVersion, [1, 2], 'Selected version must be either 1 or 2.');

        // Reconnect to verify consumer table row
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $consumerRow = DB::table('v3_test_consumer_cases')->first();
        $this->assertNotNull($consumerRow);
        $this->assertEquals($outSelect['selected_version_id'], $consumerRow->settings_version_id);

        // Version in database must have matching checksum and not be half-written
        $selectedVersionRow = DB::table('v3_settings_versions')->where('id', $consumerRow->settings_version_id)->first();
        $this->assertNotNull($selectedVersionRow);
        $canonical = \App\Services\V3\Settings\CanonicalSettings::canonicalize(json_decode($selectedVersionRow->payload, true));
        $expectedChecksum = hash('sha256', \App\Services\V3\Settings\CanonicalSettings::encode($canonical));
        $this->assertSame($expectedChecksum, $selectedVersionRow->checksum);
    }
}
