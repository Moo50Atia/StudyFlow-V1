<?php

use App\Models\User;
use App\Models\Subject;
use App\Models\Lecture;
use App\Models\Section;

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
    // It should have the Next Section button pointing to section 2
    $response->assertSee(route('sections.show', $section2));
    // It should have the Next Lecture button pointing to lecture 2
    $response->assertSee(route('lectures.show', $lecture2));

    // Fetch the third (last) section
    $responseLast = $this->actingAs($user)
        ->get(route('sections.show', $section3));

    $responseLast->assertStatus(200);
    // It should not see a next section link inside the same lecture
    // Since section3 is the last section, there is no section4
    // But it should see Start Next Lecture pointing to lecture 2
    $responseLast->assertSee('Start Next Lecture');
    $responseLast->assertSee(route('lectures.show', $lecture2));
});
