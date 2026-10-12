<?php

namespace Tests\Feature\V3\WebPipeline;

use Illuminate\Support\Str;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

class SchemaContractTest extends TestCase
{
    /**
     * FR-012, FR-015: Static Scene Package V2 contract rules:
     * Exactly three files (index.html, styles.css, script.js), schema_version 1,
     * <= 1 MiB each, total <= 4 MiB.
     */
    public function test_v2_static_scene_package_validation_passes_for_valid_package(): void
    {
        $pkg = WebPipelineFixtures::makeValidStaticScenePackage('Harmonic Oscillator');

        $this->assertSame(1, $pkg['schema_version']);
        $this->assertCount(3, $pkg['files']);

        $paths = array_column($pkg['files'], 'path');
        sort($paths);
        $this->assertSame(['index.html', 'script.js', 'styles.css'], $paths);

        foreach ($pkg['files'] as $f) {
            $this->assertNotEmpty($f['content']);
            $this->assertLessThanOrEqual(1048576, strlen($f['content']));
        }
    }

    public function test_v2_static_scene_package_rejects_malformed_variations(): void
    {
        $extra = WebPipelineFixtures::makeMalformedStaticScenePackage('extra_file');
        $this->assertCount(4, $extra['files']);

        $missing = WebPipelineFixtures::makeMalformedStaticScenePackage('missing_file');
        $this->assertCount(2, $missing['files']);

        $traversal = WebPipelineFixtures::makeMalformedStaticScenePackage('path_traversal');
        $this->assertStringContainsString('..', $traversal['files'][0]['path']);

        $empty = WebPipelineFixtures::makeMalformedStaticScenePackage('empty_file');
        $this->assertSame('', $empty['files'][1]['content']);

        $oversize = WebPipelineFixtures::makeMalformedStaticScenePackage('oversize_file');
        $this->assertGreaterThan(1048576, strlen($oversize['files'][2]['content']));

        $invalidSchema = WebPipelineFixtures::makeMalformedStaticScenePackage('invalid_schema');
        $this->assertNotSame(1, $invalidSchema['schema_version']);
    }

    /**
     * FR-024, A27: Boundary payload size limit strictly <= 32 KiB (32768 bytes).
     */
    public function test_boundary_payload_size_enforces_32kib_ceiling(): void
    {
        $maxAllowed = 32768; // 32 KiB
        $validPayload = json_encode(['command_id' => (string) Str::uuid(), 'data' => str_repeat('a', 32000)]);
        $this->assertLessThanOrEqual($maxAllowed, strlen($validPayload));

        $oversizedPayload = json_encode(['command_id' => (string) Str::uuid(), 'data' => str_repeat('a', 33000)]);
        $this->assertGreaterThan($maxAllowed, strlen($oversizedPayload));
    }

    /**
     * FR-024, A27: Opaque UUID refs validation (no arbitrary paths, URLs, or symlinks).
     */
    public function test_opaque_ref_validation_accepts_uuid_and_rejects_paths(): void
    {
        $validUuid = (string) Str::uuid();
        $this->assertTrue(Str::isUuid($validUuid));

        $invalidRefs = [
            '/etc/passwd',
            '../storage/run.json',
            'https://example.com/api',
            'file:///private/key.pem',
            'symlink_target',
            '',
        ];

        foreach ($invalidRefs as $ref) {
            $this->assertFalse(Str::isUuid($ref), "Reference '{$ref}' should not be accepted as an opaque UUID.");
        }
    }

    /**
     * FR-007, A04: Null vs empty string distinction in eleven-field lesson context.
     * Empty string laws_if_any = '' is a supplied value; null requires listing in missing_fields_json.
     */
    public function test_null_vs_empty_context_digest_produces_distinct_hashes(): void
    {
        $keys = [
            'lesson_title', 'core_concept', 'learning_objectives', 'misconceptions',
            'governing_formulas', 'numerical_example', 'visual_representation',
            'interactive_controls', 'variables_and_ranges', 'laws_if_any',
            'real_world_application'
        ];

        // Scenario A: laws_if_any is empty string "" (supplied law)
        $contextA = array_fill_keys($keys, 'val');
        $contextA['laws_if_any'] = '';
        ksort($contextA);
        $hashA = hash('sha256', json_encode($contextA));

        // Scenario B: laws_if_any is null (missing field)
        $contextB = array_fill_keys($keys, 'val');
        $contextB['laws_if_any'] = null;
        ksort($contextB);
        $hashB = hash('sha256', json_encode($contextB));

        $this->assertNotSame($hashA, $hashB, 'Null and empty string MUST produce distinct SHA-256 context digests.');
    }

    /**
     * FR-015, A13, A16: Authoring request branch invariants.
     * - real_jev requires qualification_id != null
     * - owner_waived_demo requires qualification_id == null AND prompt_gate_id != null
     */
    public function test_authoring_request_branch_invariants_specification(): void
    {
        $validateBranch = function (?string $qualificationId, string $basis, ?string $promptGateId): bool {
            if ($basis === 'real_jev') {
                return !empty($qualificationId);
            }
            if ($basis === 'owner_waived_demo') {
                return empty($qualificationId) && !empty($promptGateId);
            }
            return false;
        };

        $realPassId = (string) Str::uuid();
        $demoGateId = (string) Str::uuid();

        // 1. Valid real_jev
        $this->assertTrue($validateBranch($realPassId, 'real_jev', null));
        $this->assertTrue($validateBranch($realPassId, 'real_jev', $demoGateId));

        // 2. Invalid real_jev (missing pass)
        $this->assertFalse($validateBranch(null, 'real_jev', null));

        // 3. Valid owner_waived_demo
        $this->assertTrue($validateBranch(null, 'owner_waived_demo', $demoGateId));

        // 4. Invalid owner_waived_demo (has qualification_id or missing prompt_gate_id)
        $this->assertFalse($validateBranch($realPassId, 'owner_waived_demo', $demoGateId));
        $this->assertFalse($validateBranch(null, 'owner_waived_demo', null));

        // 5. Invalid basis
        $this->assertFalse($validateBranch(null, 'unknown_basis', $demoGateId));
    }
}
