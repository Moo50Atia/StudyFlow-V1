<?php

namespace Tests\Feature\V3\SceneQualification;

use App\Services\V3\SceneQualification\SceneEvidenceAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\V3\SceneQualificationFixtures;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');
    $this->env = SceneQualificationFixtures::createCandidateEnvironment();
    $this->case = $this->env['case'];
    $this->candidate = $this->env['candidate'];
});

test('S13: evidence assembler includes all eleven Section fields in exact 009 order', function () {
    $assembler = app(SceneEvidenceAssembler::class);

    $checkReport = [
        'schema_version' => 1,
        'candidate_ref' => $this->candidate->id,
        'manifest_sha256' => $this->candidate->manifest_sha256,
        'checker_version' => 'chromium-pw-1.63.0-v1',
        'checks' => [],
        'observations' => [
            'dom_text' => 'Pendulum oscillation simulator with angle controls',
            'controls' => [
                ['ordinal' => 1, 'role' => 'button', 'name' => 'Toggle', 'before_sha256' => 'a', 'after_sha256' => 'b', 'pointer_changed' => true, 'keyboard_changed' => true, 'focusable' => true],
            ],
            'resource_events' => [],
            'runtime_errors' => [],
            'asset_failures' => [],
            'console_error_count' => 0,
            'page_error_count' => 0,
            'forbidden_request_count' => 0,
        ],
        'screenshot_sha256' => hash('sha256', ''),
        'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(),
    ];

    $evidence = $assembler->assemble($this->case->id, $this->candidate->id, $checkReport);

    expect($evidence['schema_version'])->toBe(1);
    expect($evidence['candidate_ref'])->toBe($this->candidate->id);
    expect($evidence['manifest_sha256'])->toBe($this->candidate->manifest_sha256);

    $expectedFields = [
        'section_identity',
        'core_understanding',
        'visual_first_explanation',
        'visual_scene_script',
        'dynamic_view_blueprint',
        'visual_learning_outcome',
        'concept_check',
        'conceptual_summary',
        'colloquial_egyptian_explanation',
        'laws_if_any',
        'real_life_example',
    ];

    expect(array_keys($evidence['section_fields']))->toBe($expectedFields);
    expect(count($evidence['coverage_matrix']))->toBe(11);

    foreach ($evidence['coverage_matrix'] as $idx => $row) {
        expect($row['field_name'])->toBe($expectedFields[$idx]);
        expect($row['expected_sha256'])->toBeString();
        expect(is_bool($row['applicable']))->toBeTrue();
        expect(is_array($row['literal_evidence_refs']))->toBeTrue();
        expect(is_array($row['interaction_refs']))->toBeTrue();
    }
});
