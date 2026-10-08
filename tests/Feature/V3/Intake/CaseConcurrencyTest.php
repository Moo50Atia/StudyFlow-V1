<?php

namespace Tests\Feature\V3\Intake;

use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Services\V3\Settings\PublishSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Fixtures\V3\Pdf\PdfFixtures;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class CaseConcurrencyTest extends TestCase
{
    protected string $tempDb;

    protected string $tempStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDb = sys_get_temp_dir().'/v3_case_conc_'.uniqid().'.sqlite';
        touch($this->tempDb);

        $this->tempStorage = sys_get_temp_dir().'/v3_case_storage_'.uniqid();
        mkdir($this->tempStorage, 0755, true);
        mkdir($this->tempStorage.'/quarantine', 0755, true);
        mkdir($this->tempStorage.'/accepted', 0755, true);

        config(['database.connections.sqlite.database' => $this->tempDb]);
        config(['filesystems.disks.v3_private.root' => $this->tempStorage]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::statement('PRAGMA busy_timeout = 10000;');

        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }

        if (is_dir($this->tempStorage)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempStorage, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                @$todo($fileinfo->getRealPath());
            }
            @rmdir($this->tempStorage);
        }

        parent::tearDown();
    }

    protected function createReadyDocumentInTemp(int $teacherId, int $pages = 3): Document
    {
        $docId = (string) Str::uuid();
        $pdfBytes = PdfFixtures::minimalValidPdf($pages, 'Concurrent PDF');
        $sha256 = hash('sha256', $pdfBytes);
        $size = strlen($pdfBytes);

        file_put_contents($this->tempStorage."/accepted/{$docId}.pdf", $pdfBytes);

        return Document::forceCreate([
            'id' => $docId,
            'owner_user_id' => $teacherId,
            'sha256' => $sha256,
            'byte_size' => $size,
            'page_count' => $pages,
            'state' => 'ready',
            'storage_path' => "accepted/{$docId}.pdf",
            'accepted_at' => now(),
        ]);
    }

    public function test_i13_parallel_same_key_race_converges_on_one_case_with_replay(): void
    {
        $teacher = AccessFixtures::createEnrolledTeacher('Teacher Case Conc');
        $admin = AccessFixtures::createEnrolledAdmin('Admin Case Conc');

        SettingsFixtures::registerDefaultTemplates();
        $payload = SettingsFixtures::validPayload();
        $publisher = app(PublishSettings::class);
        $settingsVersion = $publisher->publish($payload, 0, $admin->id);

        $doc = $this->createReadyDocumentInTemp($teacher->id);
        $sameKey = (string) Str::uuid();
        $workerScript = base_path('tests/Support/V3/ConcurrentCaseWorker.php');

        $proc1 = new Process([
            'php', $workerScript,
            '--db='.$this->tempDb,
            '--storage='.$this->tempStorage,
            '--user='.$teacher->id,
            '--doc='.$doc->id,
            '--action=process',
            '--key='.$sameKey,
        ]);

        $proc2 = new Process([
            'php', $workerScript,
            '--db='.$this->tempDb,
            '--storage='.$this->tempStorage,
            '--user='.$teacher->id,
            '--doc='.$doc->id,
            '--action=process',
            '--key='.$sameKey,
        ]);

        $proc1->start();
        $proc2->start();

        $proc1->wait();
        $proc2->wait();

        $out1 = json_decode($proc1->getOutput(), true);
        $out2 = json_decode($proc2->getOutput(), true);

        expect($out1['success'])->toBeTrue()
            ->and($out2['success'])->toBeTrue();

        // One must be 201, one must be 200 (replay)
        $statuses = [$out1['status'], $out2['status']];
        sort($statuses);
        expect($statuses)->toBe([200, 201]);

        // Both must reference the exact same case_id
        expect($out1['case_id'])->toBe($out2['case_id']);

        // Database must contain exactly 1 row
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        expect(ProcessingCase::where('document_id', $doc->id)->count())->toBe(1);
    }

    public function test_i13_parallel_different_key_first_process_race_permits_one_and_conflicts_second(): void
    {
        $teacher = AccessFixtures::createEnrolledTeacher('Teacher First Conc');
        $admin = AccessFixtures::createEnrolledAdmin('Admin First Conc');

        SettingsFixtures::registerDefaultTemplates();
        $payload = SettingsFixtures::validPayload();
        $publisher = app(PublishSettings::class);
        $publisher->publish($payload, 0, $admin->id);

        $doc = $this->createReadyDocumentInTemp($teacher->id);
        $keyA = (string) Str::uuid();
        $keyB = (string) Str::uuid();
        $workerScript = base_path('tests/Support/V3/ConcurrentCaseWorker.php');

        $procA = new Process([
            'php', $workerScript,
            '--db='.$this->tempDb,
            '--storage='.$this->tempStorage,
            '--user='.$teacher->id,
            '--doc='.$doc->id,
            '--action=process',
            '--key='.$keyA,
        ]);

        $procB = new Process([
            'php', $workerScript,
            '--db='.$this->tempDb,
            '--storage='.$this->tempStorage,
            '--user='.$teacher->id,
            '--doc='.$doc->id,
            '--action=process',
            '--key='.$keyB,
        ]);

        $procA->start();
        $procB->start();

        $procA->wait();
        $procB->wait();

        $outA = json_decode($procA->getOutput(), true);
        $outB = json_decode($procB->getOutput(), true);

        $statuses = [$outA['status'], $outB['status']];
        sort($statuses);

        // One process gets 201 (new case created), the other gets 409 (duplicate_choice_required)
        expect($statuses)->toBe([201, 409]);

        $failedOutput = $outA['status'] === 409 ? $outA : $outB;
        expect($failedOutput['code'])->toBe('duplicate_choice_required');

        // Database must contain exactly 1 row
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        expect(ProcessingCase::where('document_id', $doc->id)->count())->toBe(1);
    }

    public function test_i13_parallel_process_again_different_keys_creates_distinct_runs(): void
    {
        $teacher = AccessFixtures::createEnrolledTeacher('Teacher Again Conc');
        $admin = AccessFixtures::createEnrolledAdmin('Admin Again Conc');

        SettingsFixtures::registerDefaultTemplates();
        $payload = SettingsFixtures::validPayload();
        $publisher = app(PublishSettings::class);
        $settings = $publisher->publish($payload, 0, $admin->id);

        $doc = $this->createReadyDocumentInTemp($teacher->id);

        // Seed initial case
        ProcessingCase::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $teacher->id,
            'document_id' => $doc->id,
            'settings_version_id' => $settings->id,
            'idempotency_key' => (string) Str::uuid(),
            'action' => 'process',
            'state' => 'awaiting_workflow',
            'state_version' => 1,
        ]);

        $key1 = (string) Str::uuid();
        $key2 = (string) Str::uuid();
        $workerScript = base_path('tests/Support/V3/ConcurrentCaseWorker.php');

        $proc1 = new Process([
            'php', $workerScript,
            '--db='.$this->tempDb,
            '--storage='.$this->tempStorage,
            '--user='.$teacher->id,
            '--doc='.$doc->id,
            '--action=process_again',
            '--key='.$key1,
        ]);

        $proc2 = new Process([
            'php', $workerScript,
            '--db='.$this->tempDb,
            '--storage='.$this->tempStorage,
            '--user='.$teacher->id,
            '--doc='.$doc->id,
            '--action=process_again',
            '--key='.$key2,
        ]);

        $proc1->start();
        $proc2->start();

        $proc1->wait();
        $proc2->wait();

        $out1 = json_decode($proc1->getOutput(), true);
        $out2 = json_decode($proc2->getOutput(), true);

        // Both process_again with different keys should succeed!
        expect($out1['success'])->toBeTrue()
            ->and($out2['success'])->toBeTrue()
            ->and($out1['status'])->toBe(201)
            ->and($out2['status'])->toBe(201)
            ->and($out1['case_id'])->not->toBe($out2['case_id']);

        // Database must contain exactly 3 cases for this document
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        expect(ProcessingCase::where('document_id', $doc->id)->count())->toBe(3);
    }

    public function test_i15_case_start_and_settings_publication_race_serializes_cleanly(): void
    {
        $teacher = AccessFixtures::createEnrolledTeacher('Teacher Pub Race');
        $admin = AccessFixtures::createEnrolledAdmin('Admin Pub Race');

        SettingsFixtures::registerDefaultTemplates();
        $payload1 = SettingsFixtures::validPayload(0);
        $publisher = app(PublishSettings::class);
        $version1 = $publisher->publish($payload1, 0, $admin->id);

        $doc = $this->createReadyDocumentInTemp($teacher->id);
        $key = (string) Str::uuid();
        $workerScript = base_path('tests/Support/V3/ConcurrentCaseWorker.php');

        $payload2 = SettingsFixtures::validPayload(1, [
            'limits' => [
                'max_pdf_bytes' => 20 * 1024 * 1024,
                'max_pdf_pages' => 150,
                'max_ocr_pages' => 30,
            ],
        ]);

        // Process 1: Settings publisher publishes version 2
        $procPub = new Process([
            'php', $workerScript,
            '--db='.$this->tempDb,
            '--publish='.json_encode($payload2),
            '--actor='.$admin->id,
            '--expected-version=1',
        ]);

        // Process 2: Teacher starts case
        $procCase = new Process([
            'php', $workerScript,
            '--db='.$this->tempDb,
            '--storage='.$this->tempStorage,
            '--user='.$teacher->id,
            '--doc='.$doc->id,
            '--action=process',
            '--key='.$key,
        ]);

        $procPub->start();
        $procCase->start();

        $procPub->wait();
        $procCase->wait();

        $outPub = json_decode($procPub->getOutput(), true);
        $outCase = json_decode($procCase->getOutput(), true);

        expect($outPub['success'])->toBeTrue()
            ->and($outCase['success'])->toBeTrue();

        // The case must have captured either version 1 or version 2
        expect(in_array($outCase['settings_version_id'], [$version1->id, $outPub['version_id']], true))->toBeTrue();

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $savedCase = ProcessingCase::where('idempotency_key', $key)->first();
        expect($savedCase)->not->toBeNull()
            ->and($savedCase->state)->toBe('awaiting_workflow')
            ->and($savedCase->state_version)->toBe(1);
    }
}
