<?php

namespace Tests\Feature\V3\Intake;

use App\Models\V3\Document;
use App\Models\V3\SettingsVersion;
use App\Models\V3\UploadAttempt;
use App\Services\V3\Intake\PrivatePdfStore;
use App\Services\V3\Intake\ReconcileIntake;
use App\Services\V3\Settings\PublishSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Fixtures\V3\Pdf\PdfFixtures;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class IntakeConcurrencyTest extends TestCase
{
    protected string $tempDb;

    protected string $tempStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDb = sys_get_temp_dir().'/v3_intake_conc_'.uniqid().'.sqlite';
        touch($this->tempDb);

        $this->tempStorage = sys_get_temp_dir().'/v3_storage_conc_'.uniqid();
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

    public function test_i09_two_concurrent_same_owner_uploads_converge_on_one_ready_document(): void
    {
        $teacher = AccessFixtures::createEnrolledTeacher('Teacher Concurrent');
        $admin = AccessFixtures::createEnrolledAdmin('Admin Concurrent');

        SettingsFixtures::registerDefaultTemplates();
        $payload = SettingsFixtures::validPayload();
        $publisher = app(PublishSettings::class);
        $settingsVersion = $publisher->publish($payload, 0, $admin->id);

        $pdfBytes = PdfFixtures::minimalValidPdf(1, 'Simultaneous Content');
        $sha256 = hash('sha256', $pdfBytes);
        $size = strlen($pdfBytes);

        $attempt1Id = (string) Str::uuid();
        $attempt2Id = (string) Str::uuid();

        file_put_contents($this->tempStorage."/quarantine/{$attempt1Id}.pdf", $pdfBytes);
        file_put_contents($this->tempStorage."/quarantine/{$attempt2Id}.pdf", $pdfBytes);

        UploadAttempt::forceCreate([
            'id' => $attempt1Id,
            'owner_user_id' => $teacher->id,
            'upload_settings_version_id' => $settingsVersion->id,
            'state' => 'quarantined',
            'staging_path' => "quarantine/{$attempt1Id}.pdf",
            'byte_size' => $size,
            'expires_at' => now()->addHours(24),
        ]);

        UploadAttempt::forceCreate([
            'id' => $attempt2Id,
            'owner_user_id' => $teacher->id,
            'upload_settings_version_id' => $settingsVersion->id,
            'state' => 'quarantined',
            'staging_path' => "quarantine/{$attempt2Id}.pdf",
            'byte_size' => $size,
            'expires_at' => now()->addHours(24),
        ]);

        $workerScript = base_path('tests/Support/V3/ConcurrentUploadWorker.php');

        $proc1 = new Process([
            'php', $workerScript,
            '--db='.$this->tempDb,
            '--attempt='.$attempt1Id,
            '--storage='.$this->tempStorage,
        ]);

        $proc2 = new Process([
            'php', $workerScript,
            '--db='.$this->tempDb,
            '--attempt='.$attempt2Id,
            '--storage='.$this->tempStorage,
        ]);

        $proc1->start();
        $proc2->start();

        $proc1->wait();
        $proc2->wait();

        // Reconnect to temp DB to inspect final state
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::statement('PRAGMA busy_timeout = 10000;');

        // Run reconciler in case one attempt was left in retry_pending while the first was promoting
        $reconciler = app(ReconcileIntake::class);
        $reconciler->reconcile();

        // Re-process if still retry_pending
        $att2 = UploadAttempt::find($attempt2Id);
        if ($att2 && $att2->state === 'retry_pending') {
            $att2->next_retry_at = null;
            $att2->save();
            $procRetry = new Process([
                'php', $workerScript,
                '--db='.$this->tempDb,
                '--attempt='.$attempt2Id,
                '--storage='.$this->tempStorage,
            ]);
            $procRetry->run();
        }

        $att1 = UploadAttempt::find($attempt1Id);
        if ($att1 && $att1->state === 'retry_pending') {
            $att1->next_retry_at = null;
            $att1->save();
            $procRetry = new Process([
                'php', $workerScript,
                '--db='.$this->tempDb,
                '--attempt='.$attempt1Id,
                '--storage='.$this->tempStorage,
            ]);
            $procRetry->run();
        }

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        // Assert exactly ONE Document row exists for this teacher/hash
        $docs = Document::where('owner_user_id', $teacher->id)->where('sha256', $sha256)->get();
        $this->assertCount(1, $docs, 'Expected exactly one Document for same-owner identical uploads.');

        $doc = $docs->first();
        $this->assertEquals('ready', $doc->state);

        // Assert both attempts are accepted and reference the same document
        $final1 = UploadAttempt::find($attempt1Id);
        $final2 = UploadAttempt::find($attempt2Id);

        $this->assertEquals('accepted', $final1->state);
        $this->assertEquals('accepted', $final2->state);
        $this->assertEquals($doc->id, $final1->document_id);
        $this->assertEquals($doc->id, $final2->document_id);

        // One must be duplicate=false and the other duplicate=true
        $duplicates = [$final1->duplicate, $final2->duplicate];
        $this->assertContains(true, $duplicates);
        $this->assertContains(false, $duplicates);

        // Physical accepted file exists and matches
        $this->assertFileExists($this->tempStorage.'/'.$doc->storage_path);
        $this->assertEquals($size, filesize($this->tempStorage.'/'.$doc->storage_path));
        $this->assertEquals($sha256, hash_file('sha256', $this->tempStorage.'/'.$doc->storage_path));
    }

    public function test_i09_two_concurrent_cross_owner_uploads_create_separate_documents(): void
    {
        $teacherA = AccessFixtures::createEnrolledTeacher('Teacher Cross A');
        $teacherB = AccessFixtures::createEnrolledTeacher('Teacher Cross B');
        $admin = AccessFixtures::createEnrolledAdmin('Admin Cross');

        SettingsFixtures::registerDefaultTemplates();
        $payload = SettingsFixtures::validPayload();
        $publisher = app(PublishSettings::class);
        $settingsVersion = $publisher->publish($payload, 0, $admin->id);

        $pdfBytes = PdfFixtures::minimalValidPdf(1, 'Cross Curriculum');
        $sha256 = hash('sha256', $pdfBytes);
        $size = strlen($pdfBytes);

        $attemptAId = (string) Str::uuid();
        $attemptBId = (string) Str::uuid();

        file_put_contents($this->tempStorage."/quarantine/{$attemptAId}.pdf", $pdfBytes);
        file_put_contents($this->tempStorage."/quarantine/{$attemptBId}.pdf", $pdfBytes);

        UploadAttempt::forceCreate([
            'id' => $attemptAId,
            'owner_user_id' => $teacherA->id,
            'upload_settings_version_id' => $settingsVersion->id,
            'state' => 'quarantined',
            'staging_path' => "quarantine/{$attemptAId}.pdf",
            'byte_size' => $size,
            'expires_at' => now()->addHours(24),
        ]);

        UploadAttempt::forceCreate([
            'id' => $attemptBId,
            'owner_user_id' => $teacherB->id,
            'upload_settings_version_id' => $settingsVersion->id,
            'state' => 'quarantined',
            'staging_path' => "quarantine/{$attemptBId}.pdf",
            'byte_size' => $size,
            'expires_at' => now()->addHours(24),
        ]);

        $workerScript = base_path('tests/Support/V3/ConcurrentUploadWorker.php');

        $procA = new Process([
            'php', $workerScript,
            '--db='.$this->tempDb,
            '--attempt='.$attemptAId,
            '--storage='.$this->tempStorage,
        ]);

        $procB = new Process([
            'php', $workerScript,
            '--db='.$this->tempDb,
            '--attempt='.$attemptBId,
            '--storage='.$this->tempStorage,
        ]);

        $procA->start();
        $procB->start();

        $procA->wait();
        $procB->wait();

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        // A SQLite write-lock loser is intentionally scheduled for bounded retry.
        // Exercise that recovery now instead of requiring both racing workers to
        // finish in their first attempt.
        foreach ([$attemptAId, $attemptBId] as $attemptId) {
            $attempt = UploadAttempt::find($attemptId);
            if ($attempt && $attempt->state === 'retry_pending') {
                $attempt->next_retry_at = now()->subSecond();
                $attempt->save();
                $retry = new Process([
                    'php', $workerScript,
                    '--db='.$this->tempDb,
                    '--attempt='.$attemptId,
                    '--storage='.$this->tempStorage,
                ]);
                $retry->run();
                $this->assertTrue($retry->isSuccessful(), $retry->getErrorOutput());
            }
        }

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $finalA = UploadAttempt::find($attemptAId);
        $finalB = UploadAttempt::find($attemptBId);

        $this->assertEquals('accepted', $finalA->state);
        $this->assertEquals('accepted', $finalB->state);
        $this->assertFalse((bool) $finalA->duplicate);
        $this->assertFalse((bool) $finalB->duplicate);

        $this->assertNotEquals($finalA->document_id, $finalB->document_id);

        $docA = Document::find($finalA->document_id);
        $docB = Document::find($finalB->document_id);

        $this->assertEquals('ready', $docA->state);
        $this->assertEquals('ready', $docB->state);
        $this->assertNotEquals($docA->storage_path, $docB->storage_path);

        $this->assertFileExists($this->tempStorage.'/'.$docA->storage_path);
        $this->assertFileExists($this->tempStorage.'/'.$docB->storage_path);
    }
}
