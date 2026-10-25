<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\AuthoringFixtures;

uses(RefreshDatabase::class);

test('an owned case without a map has an HTML waiting page while missing-map JSON and ownership checks stay strict', function () {
    Storage::fake('v3_private');
    $env = AuthoringFixtures::createAuthoringEnvironment();
    $case = $env['case']->replicate();
    $case->id = (string) Str::uuid();
    $case->idempotency_key = (string) Str::uuid();
    $case->save();
    $url = "/v3/teacher/cases/{$case->id}/lesson-map";
    $this->actingAs($env['teacher'])->get($url)->assertOk()
        ->assertInertia(fn ($page) => $page->component('Teacher/LessonMap')
            ->where('pending', true)->where('case.id', $case->id)->has('workItems', 0));
    $this->getJson($url)->assertNotFound();
    $this->actingAs(AccessFixtures::createEnrolledTeacher('Other teacher'))->get($url)->assertNotFound();
    $this->actingAs($env['admin'])->get($url)->assertForbidden();
});
