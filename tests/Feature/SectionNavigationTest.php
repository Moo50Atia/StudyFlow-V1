<?php

use App\Models\User;
use App\Models\Subject;
use App\Models\Lecture;
use App\Models\Section;
use Inertia\Testing\AssertableInertia as Assert;

test('it displays next section and next lecture links correctly', function () {
    // Enable authentication for student
    $user = User::factory()->student()->create();

    $subject = Subject::factory()->create();

    // Create two lectures
    $lecture1 = Lecture::factory()->create(['subject_id' => $subject->id]);
    $lecture2 = Lecture::factory()->create(['subject_id' => $subject->id]);

    // Create three sections in lecture 1
    $section1 = Section::factory()->create(['lecture_id' => $lecture1->id]);
    $section2 = Section::factory()->create(['lecture_id' => $lecture1->id]);
    $section3 = Section::factory()->create(['lecture_id' => $lecture1->id]);

    // Fetch the first section
    $response = $this->actingAs($user)
        ->get(route('sections.show', $section1));

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('LearningContentShow')
        ->where('nextSection.id', $section2->id)
        ->where('nextLecture.id', $lecture2->id));

    // Fetch the third (last) section
    $responseLast = $this->actingAs($user)
        ->get(route('sections.show', $section3));

    $responseLast->assertStatus(200);
    $responseLast->assertInertia(fn (Assert $page) => $page
        ->component('LearningContentShow')
        ->where('nextSection', null)
        ->where('nextLecture.id', $lecture2->id));
});
