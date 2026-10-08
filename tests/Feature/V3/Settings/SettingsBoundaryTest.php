<?php

namespace Tests\Feature\V3\Settings;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;

beforeEach(function () {
    SettingsFixtures::registerDefaultTemplates();
    $this->admin = AccessFixtures::createAdmin('Enrolled Admin');
    AccessFixtures::provisionV3($this->admin, true);

    $this->teacher = AccessFixtures::createTeacher('Enrolled Teacher');
    AccessFixtures::provisionV3($this->teacher, true);

    $this->student = AccessFixtures::createStudent('Enrolled Student');
    // Students never have active V3 access

    $this->inactiveAdmin = AccessFixtures::createAdmin('Inactive Admin');
    AccessFixtures::provisionV3($this->inactiveAdmin, false);
});

test('S04: guest is denied from settings routes with 401 JSON or 302 login redirect', function () {
    // Guest JSON
    $res1 = $this->getJson(route('v3.admin.settings.show'));
    $res1->assertStatus(401)
        ->assertJson([
            'error' => [
                'code' => 'unauthenticated',
            ],
        ]);
    expect($res1->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');

    $res2 = $this->postJson(route('v3.admin.settings.store'), SettingsFixtures::validPayload());
    $res2->assertStatus(401);
    expect($res2->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');

    // Guest HTML
    $res3 = $this->get(route('v3.admin.settings.show'));
    $res3->assertStatus(302)->assertRedirect('/login');
    expect($res3->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
});

test('S04: teacher, student, and inactive admin are denied from settings with 403 access_denied', function () {
    // Teacher denied
    $res1 = $this->actingAs($this->teacher)->getJson(route('v3.admin.settings.show'));
    $res1->assertStatus(403)
        ->assertJson([
            'error' => [
                'code' => 'access_denied',
                'message' => 'V3 access is unavailable.',
            ],
        ]);
    expect($res1->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');

    $res2 = $this->actingAs($this->teacher)->postJson(route('v3.admin.settings.store'), SettingsFixtures::validPayload());
    $res2->assertStatus(403);
    expect($res2->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');

    // Student denied
    $res3 = $this->actingAs($this->student)->getJson(route('v3.admin.settings.show'));
    $res3->assertStatus(403);
    expect($res3->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');

    // Inactive admin denied
    $res4 = $this->actingAs($this->inactiveAdmin)->getJson(route('v3.admin.settings.show'));
    $res4->assertStatus(403);
    expect($res4->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');

    $res5 = $this->actingAs($this->inactiveAdmin)->postJson(route('v3.admin.settings.store'), SettingsFixtures::validPayload());
    $res5->assertStatus(403);
    expect($res5->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
});

test('S04: CSRF token is required on browser settings POST requests', function () {
    $testCsrf = new class($this->app, app(Encrypter::class)) extends ValidateCsrfToken
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    };
    $this->app->instance(ValidateCsrfToken::class, $testCsrf);

    $res = $this->actingAs($this->admin)->post(route('v3.admin.settings.store'), SettingsFixtures::validPayload());
    $res->assertStatus(419);
    expect($res->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
});

test('S04: legacy routes do not expose settings actions', function () {
    // /users cannot publish settings
    $res1 = $this->actingAs($this->admin)
        ->postJson('/users/settings', SettingsFixtures::validPayload());
    expect(in_array($res1->status(), [404, 405], true))->toBeTrue();

    // /study-flow cannot publish settings
    $res2 = $this->actingAs($this->admin)
        ->postJson('/study-flow/settings', SettingsFixtures::validPayload());
    expect(in_array($res2->status(), [404, 405], true))->toBeTrue();
});
