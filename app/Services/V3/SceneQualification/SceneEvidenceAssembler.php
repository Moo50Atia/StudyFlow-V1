<?php

namespace App\Services\V3\SceneQualification;

use App\Models\V3\ProcessingCase;
use App\Models\V3\SceneCandidate;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\TeacherSection;
use App\Services\V3\Authoring\SceneCandidateResolver;
use App\Services\V3\Prompts\QualifiedPromptResolver;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class SceneEvidenceAssembler
{
    public function __construct(
        protected SceneCandidateResolver $candidateResolver,
        protected QualifiedPromptResolver $promptResolver
    ) {}

    public function assemble(string $caseId, string $candidateRef, array $checkReport): array
    {
        $candidate = SceneCandidate::where('id', $candidateRef)
            ->where('case_id', $caseId)
            ->first();

        if (!$candidate) {
            throw new NotFoundHttpException("Candidate not found");
        }

        $resolved = $this->candidateResolver->resolve($caseId, $candidateRef);
        $files = $resolved['files'] ?? [];

        $promptVersion = ScenePromptVersion::where('id', $candidate->prompt_version_id)->first();
        if (!$promptVersion) {
            throw new NotFoundHttpException("Prompt version not found");
        }

        $promptBody = Storage::disk('v3_private')->get($promptVersion->body_path);
        if ($promptBody === null) {
            throw new ServiceUnavailableHttpException(null, "Prompt body artifact missing");
        }

        $section = TeacherSection::where('id', $candidate->section_id)->first();
        if (!$section) {
            throw new NotFoundHttpException("Section not found");
        }

        $expectedFieldNames = [
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

        $sectionFields = [];
        $coverageMatrix = [];

        // Build observations text for token scanning
        $domText = mb_strtolower($checkReport['observations']['dom_text'] ?? '', 'UTF-8');
        $controls = $checkReport['observations']['controls'] ?? [];

        $observedControlIds = [];
        foreach ($controls as $idx => $c) {
            $observedControlIds[] = "control:" . ($c['ordinal'] ?? ($idx + 1));
        }

        foreach ($expectedFieldNames as $fname) {
            $val = $section->{$fname} ?? '';
            if ($val === null) {
                $val = '';
            }
            $valStr = (string) $val;
            $sectionFields[$fname] = $valStr;

            $valSha = hash('sha256', $valStr);
            $applicable = !($fname === 'laws_if_any' && trim($valStr) === '');

            // Token extraction for literal evidence refs
            $literalRefs = [];
            $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($valStr, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
            $meaningfulTokens = array_filter($tokens, fn($t) => mb_strlen($t, 'UTF-8') >= 3);

            if (!empty($meaningfulTokens)) {
                foreach ($meaningfulTokens as $tok) {
                    if (str_contains($domText, $tok)) {
                        $literalRefs[] = 'dom_text';
                        break;
                    }
                }
                foreach ($files as $f) {
                    $fContent = mb_strtolower($f['content'] ?? '', 'UTF-8');
                    foreach ($meaningfulTokens as $tok) {
                        if (str_contains($fContent, $tok)) {
                            $literalRefs[] = "file:{$f['path']}";
                            break;
                        }
                    }
                }
                foreach ($controls as $idx => $c) {
                    $cName = mb_strtolower($c['name'] ?? '', 'UTF-8');
                    foreach ($meaningfulTokens as $tok) {
                        if (str_contains($cName, $tok)) {
                            $literalRefs[] = "control:" . ($c['ordinal'] ?? ($idx + 1));
                            break;
                        }
                    }
                }
            }

            $literalRefs = array_values(array_unique($literalRefs));
            sort($literalRefs);

            // Interaction refs: visual_scene_script, dynamic_view_blueprint, concept_check
            $interactionRefs = [];
            if (in_array($fname, ['visual_scene_script', 'dynamic_view_blueprint', 'concept_check'], true)) {
                $interactionRefs = $observedControlIds;
            }

            $coverageMatrix[] = [
                'field_name' => $fname,
                'expected_sha256' => $valSha,
                'applicable' => $applicable,
                'literal_evidence_refs' => $literalRefs,
                'interaction_refs' => $interactionRefs,
            ];
        }

        return [
            'schema_version' => 1,
            'candidate_ref' => $candidate->id,
            'manifest_sha256' => $candidate->manifest_sha256,
            'prompt_version_ref' => $promptVersion->id,
            'prompt_body_sha256' => $promptVersion->body_sha256,
            'section_fields' => $sectionFields,
            'prompt' => $promptBody,
            'files' => $files,
            'check_report' => $checkReport,
            'coverage_matrix' => $coverageMatrix,
        ];
    }
}
