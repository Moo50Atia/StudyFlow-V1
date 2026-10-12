<?php

namespace Tests\Support\V3;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AccessFixtures
{
    public const SENTINEL_A = 'SENTINEL_CONTENT_FOR_TEACHER_A';

    public const SENTINEL_B = 'SENTINEL_CONTENT_FOR_TEACHER_B';

    public static function setUpFixtureSchema(): void
    {
        if (! Schema::hasTable('v3_test_owned_fixtures')) {
            Schema::create('v3_test_owned_fixtures', function ($table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('owner_user_id');
                $table->uuid('parent_id')->nullable();
                $table->text('content');
                $table->timestamps();
            });
        }
    }

    public static function createUser(string $role = 'student', string $name = 'Test User', ?string $email = null, string $password = 'password'): User
    {
        $email = $email ?? ('user_'.strtolower(Str::random(8)).'@example.com');

        return User::forceCreate([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make($password),
            'role' => $role,
        ]);
    }

    public static function createTeacher(string $name = 'Teacher A', ?string $email = null): User
    {
        return static::createUser('teacher', $name, $email);
    }

    public static function createAdmin(string $name = 'Admin', ?string $email = null): User
    {
        return static::createUser('admin', $name, $email);
    }

    public static function createStudent(string $name = 'Student', ?string $email = null): User
    {
        return static::createUser('student', $name, $email);
    }

    public static function provisionV3(User $user, bool $isActive = true, int $version = 1, ?\DateTimeInterface $disabledAt = null): void
    {
        DB::table('v3_account_access')->updateOrInsert(
            ['user_id' => $user->id],
            [
                'is_active' => $isActive,
                'version' => $version,
                'provisioned_at' => now(),
                'disabled_at' => $disabledAt ?? ($isActive ? null : now()),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public static function createEnrolledTeacher(string $name = 'Teacher A', ?string $email = null, bool $isActive = true): User
    {
        $teacher = static::createTeacher($name, $email);
        static::provisionV3($teacher, $isActive);

        return $teacher;
    }

    public static function createEnrolledAdmin(string $name = 'Admin', ?string $email = null, bool $isActive = true): User
    {
        $admin = static::createAdmin($name, $email);
        static::provisionV3($admin, $isActive);

        return $admin;
    }

    public static function createOwnedFixture(User $owner, string $content, ?string $parentId = null, ?string $id = null): OwnedFixture
    {
        static::setUpFixtureSchema();

        return OwnedFixture::create([
            'id' => $id ?? (string) Str::uuid(),
            'owner_user_id' => $owner->id,
            'parent_id' => $parentId,
            'content' => $content,
        ]);
    }
}
