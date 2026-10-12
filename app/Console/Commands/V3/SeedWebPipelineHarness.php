<?php

namespace App\Console\Commands\V3;

use App\Models\Lecture;
use App\Models\Subject;
use App\Models\User;
use App\Services\V3\NotebookLM\FrameworkCatalog;
use App\Services\V3\Settings\PublishSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Support\V3\HarnessSettings;

class SeedWebPipelineHarness extends Command
{
    protected $signature = 'v3:seed-harness {--json : Output JSON details}';
    protected $description = 'Seed disposable prerequisites (users, permissions, settings, templates) for the V3 web pipeline test harness without seeding pipeline outputs.';

    public function handle(): int
    {
        $database = config('database.connections.sqlite.database');
        $privateRoot = config('filesystems.disks.v3_private.root');
        $testingRoot = realpath(storage_path('testing')) ?: storage_path('testing');
        if (! app()->environment('testing')
            || ! is_string($database)
            || ! str_starts_with($database, $testingRoot . DIRECTORY_SEPARATOR)
            || ! is_string($privateRoot)
            || ! str_starts_with($privateRoot, $testingRoot . DIRECTORY_SEPARATOR)) {
            $this->error('Fixture seeding requires a disposable test database and private root under storage/testing.');
            return self::FAILURE;
        }
        $now = now();

        // 1. Users
        $teacherA = User::updateOrCreate(
            ['email' => 'teacher_a@example.com'],
            [
                'name' => 'Teacher Alpha',
                'password' => 'password',
                'role' => 'teacher',
            ]
        );

        $teacherB = User::updateOrCreate(
            ['email' => 'teacher_b@example.com'],
            [
                'name' => 'Teacher Beta',
                'password' => 'password',
                'role' => 'teacher',
            ]
        );

        $admin = User::updateOrCreate(
            ['email' => 'admin_v3@example.com'],
            [
                'name' => 'Pipeline Admin',
                'password' => 'password',
                'role' => 'admin',
            ]
        );

        $student = User::updateOrCreate(
            ['email' => 'student_c@example.com'],
            [
                'name' => 'Student Charlie',
                'password' => 'password',
                'role' => 'student',
            ]
        );
        DB::table('users')->whereIn('id', [$teacherA->id, $teacherB->id, $admin->id, $student->id])
            ->update(['email_verified_at' => $now]);

        // 2. V3 Account Access
        foreach ([$teacherA, $teacherB, $admin] as $u) {
            DB::table('v3_account_access')->updateOrInsert(
                ['user_id' => $u->id],
                [
                    'is_active' => true,
                    'version' => 1,
                    'provisioned_at' => $now,
                    'disabled_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        // 3. Subject and Initial Lecture for Teacher A
        $subject = Subject::firstOrCreate(
            ['name' => 'Quantum Physics'],
            ['description' => 'Physics curriculum subject']
        );

        $initialLecture = Lecture::firstOrCreate(
            [
                'subject_id' => $subject->id,
                'title' => 'Initial Seed Lecture',
            ]
        );

        // 4. Permissions
        DB::table('teacher_permissions')->updateOrInsert(
            [
                'teacher_id' => $teacherA->id,
                'subject_id' => $subject->id,
                'lecture_id' => $initialLecture->id,
            ],
            [
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        // 5. Approved Templates & Settings
        HarnessSettings::registerDefaultTemplates();
        $payload = HarnessSettings::validPayload();
        $publisher = app(PublishSettings::class);
        $publisher->publish($payload, 0, $admin->id);
        $settingsId = (int) DB::table('v3_settings_versions')->max('id');

        // 6. Seed the real framework with its actual slices and digest. A
        // placeholder makes scripted tests pass but live NotebookLM then
        // invents a field schema Laravel cannot accept.
        $frameworkPath = base_path('steps.txt');
        $framework = app(FrameworkCatalog::class)->importFramework(
            $frameworkPath,
            hash_file('sha256', $frameworkPath),
            $admin->id,
        );
        $frameworkId = $framework->id;

        $result = [
            'status' => 'seeded',
            'framework_version_id' => $frameworkId,
            'teacher_a' => [
                'id' => $teacherA->id,
                'email' => $teacherA->email,
            ],
            'teacher_b' => [
                'id' => $teacherB->id,
                'email' => $teacherB->email,
            ],
            'admin' => [
                'id' => $admin->id,
                'email' => $admin->email,
            ],
            'student' => [
                'id' => $student->id,
                'email' => $student->email,
            ],
            'subject' => [
                'id' => $subject->id,
                'name' => $subject->name,
            ],
            'lecture' => [
                'id' => $initialLecture->id,
                'title' => $initialLecture->title,
            ],
            'settings_version_id' => $settingsId,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        } else {
            $this->info("Web Pipeline test harness seeded successfully.");
            $this->info("Teacher: {$teacherA->email} / Subject: {$subject->id} ({$subject->name})");
        }

        return 0;
    }
}
