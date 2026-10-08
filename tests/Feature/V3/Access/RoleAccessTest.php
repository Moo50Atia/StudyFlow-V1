<?php

namespace Tests\Feature\V3\Access;

use Illuminate\Support\Facades\DB;
use Tests\Support\V3\AccessFixtures;

test('A01: Guest HTML GET /v3/teacher redirects to login with intended URL retained and no content', function () {
    $response = $this->get('/v3/teacher');

    $response->assertStatus(302)
        ->assertRedirect(route('login'));

    expect(session()->get('url.intended'))->toBe(url('/v3/teacher'));
});

test('A02: Guest JSON GET /v3/admin returns 401 and no operation data', function () {
    $response = $this->getJson('/v3/admin');

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('unauthenticated');
});

test('A03: Active enrolled teacher and admin GET /v3 redirects to correct role workspace which returns 200', function () {
    $teacher = AccessFixtures::createEnrolledTeacher('Teacher Alpha');
    $admin = AccessFixtures::createEnrolledAdmin('Admin Beta');

    // Teacher
    $responseTeacher = $this->actingAs($teacher)->get('/v3');
    $responseTeacher->assertStatus(302)
        ->assertRedirect(route('v3.teacher.workspace'));

    $this->actingAs($teacher)->get(route('v3.teacher.workspace'))
        ->assertStatus(200)
        ->assertSee('Teacher Alpha');

    // Admin
    $responseAdmin = $this->actingAs($admin)->get('/v3');
    $responseAdmin->assertStatus(302)
        ->assertRedirect(route('v3.admin.workspace'));

    $this->actingAs($admin)->get(route('v3.admin.workspace'))
        ->assertStatus(200)
        ->assertSee('Admin Beta');
});

test('A04: Student, missing access row or inactive user requests V3 get 403 access_denied', function () {
    // 1. Student
    $student = AccessFixtures::createStudent();
    $this->actingAs($student)->get('/v3')->assertStatus(403);
    $this->actingAs($student)->getJson('/v3')->assertStatus(403)
        ->assertJson([
            'error' => [
                'code' => 'access_denied',
                'message' => 'V3 access is unavailable.',
            ],
        ]);

    // 2. Teacher with missing access row
    $unregisteredTeacher = AccessFixtures::createTeacher('Unenrolled Teacher');
    $this->actingAs($unregisteredTeacher)->get('/v3')->assertStatus(403);
    $this->actingAs($unregisteredTeacher)->getJson('/v3/teacher')->assertStatus(403);

    // 3. Inactive teacher
    $inactiveTeacher = AccessFixtures::createEnrolledTeacher('Inactive Teacher', isActive: false);
    $this->actingAs($inactiveTeacher)->get('/v3/teacher')->assertStatus(403);
    $this->actingAs($inactiveTeacher)->getJson('/v3/teacher')->assertStatus(403);
});

test('A05: Teacher requests admin area and admin requests teacher area receive 403', function () {
    $teacher = AccessFixtures::createEnrolledTeacher();
    $admin = AccessFixtures::createEnrolledAdmin();

    // Teacher visiting admin area
    $this->actingAs($teacher)->get('/v3/admin')->assertStatus(403);
    $this->actingAs($teacher)->get('/v3/admin?resource=abc')->assertStatus(403);
    $this->actingAs($teacher)->getJson('/v3/admin')->assertStatus(403);

    // Admin visiting teacher area
    $this->actingAs($admin)->get('/v3/teacher')->assertStatus(403);
    $this->actingAs($admin)->get('/v3/teacher?resource=abc')->assertStatus(403);
    $this->actingAs($admin)->getJson('/v3/teacher')->assertStatus(403);
});

test('M04: Admin workspace before cases exist returns honest empty 200 page without legacy content queries', function () {
    $admin = AccessFixtures::createEnrolledAdmin();

    DB::enableQueryLog();

    $response = $this->actingAs($admin)->get('/v3/admin');

    $response->assertStatus(200);

    $queries = DB::getQueryLog();
    foreach ($queries as $query) {
        $sql = strtolower($query['query']);
        expect($sql)->not->toContain('lectures')
            ->and($sql)->not->toContain('sections')
            ->and($sql)->not->toContain('processing_cases');
    }
});
