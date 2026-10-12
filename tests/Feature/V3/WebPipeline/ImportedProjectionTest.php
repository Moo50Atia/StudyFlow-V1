<?php

namespace Tests\Feature\V3\WebPipeline;

use App\Services\V3\WebPipeline\ProjectLessonBundle;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

class ImportedProjectionTest extends TestCase
{
    protected ProjectLessonBundle $projector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projector = app(ProjectLessonBundle::class);
    }

    public function test_eleven_field_completeness_and_exact_structure(): void
    {
        $lessonData = WebPipelineFixtures::makeElevenFieldLesson(withMissingFields: false);

        $projected = $this->projector->projectFirstFields($lessonData);

        $expectedFields = [
            'lesson_title',
            'core_concept',
            'learning_objectives',
            'misconceptions',
            'governing_formulas',
            'numerical_example',
            'visual_representation',
            'interactive_controls',
            'variables_and_ranges',
            'laws_if_any',
            'real_world_application',
        ];

        foreach ($expectedFields as $field) {
            $this->assertArrayHasKey($field, $projected['fields']);
            $this->assertNotNull($projected['fields'][$field]);
        }

        $this->assertEmpty($projected['missing_fields']);
        $this->assertSame("Newton's First and Second Laws", $projected['fields']['laws_if_any']);
    }

    public function test_null_distinction_preserves_missing_fields_and_empty_strings(): void
    {
        $lessonData = WebPipelineFixtures::makeElevenFieldLesson(withMissingFields: true);

        $projected = $this->projector->projectFirstFields($lessonData);

        // Null fields are identified in missing_fields
        $this->assertContains('misconceptions', $projected['missing_fields']);
        $this->assertContains('visual_representation', $projected['missing_fields']);
        $this->assertNull($projected['fields']['misconceptions']);
        $this->assertNull($projected['fields']['visual_representation']);

        // Empty string laws_if_any is NOT considered missing, it is a supplied empty law
        $this->assertSame('', $projected['fields']['laws_if_any']);
        $this->assertNotContains('laws_if_any', $projected['missing_fields']);
    }

    public function test_arbitrary_scene_types_and_multiple_scenes_preserved_in_order(): void
    {
        $scenes = [
            [
                'scene_ordinal' => 1,
                'scene_title' => 'Custom 3D Phase Space',
                'scene_type' => 'Arbitrary Experimental Visualization 3D',
                'generated_prompt' => "Exact Third prompt body for scene 1 with specific tokens.\nNo rewrites allowed.",
            ],
            [
                'scene_ordinal' => 2,
                'scene_title' => 'Tension Graph',
                'scene_type' => 'Nonstandard Graph Plotter',
                'generated_prompt' => "Exact Third prompt body for scene 2 verbatim.\nPreserve line breaks.",
            ],
        ];

        $projectedScenes = $this->projector->projectScenes($scenes);

        $this->assertCount(2, $projectedScenes);
        $this->assertSame('Arbitrary Experimental Visualization 3D', $projectedScenes[0]['scene_type']);
        $this->assertSame('Nonstandard Graph Plotter', $projectedScenes[1]['scene_type']);
        $this->assertSame($scenes[0]['generated_prompt'], $projectedScenes[0]['prompt_body']);
        $this->assertSame($scenes[1]['generated_prompt'], $projectedScenes[1]['prompt_body']);
    }
}
