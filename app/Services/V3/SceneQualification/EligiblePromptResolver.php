<?php

namespace App\Services\V3\SceneQualification;

use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptQualification;
use App\Models\V3\PromptSectionState;
use App\Models\V3\ScenePromptVersion;
use App\Services\V3\Prompts\PrivatePromptArtifactReader;
use App\Services\V3\Prompts\PromptUnavailableException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EligiblePromptResolver
{
    public function __construct(
        protected PrivatePromptArtifactReader $artifactReader
    ) {}

    /**
     * Resolve an eligible prompt for scene authoring handoff.
     * Selects actual passed current prompt (real_jev) or exact current new-path demo prompt gate (owner_waived_demo).
     *
     * @return array{
     *     basis: string,
     *     case_id: string,
     *     section_ref: string,
     *     prompt_version_ref: string,
     *     prompt: string,
     *     body_sha256: string,
     *     qualification_ref: ?string,
     *     prompt_gate_id: ?string,
     *     settings_version_id: int,
     *     missing_fields: array<string>
     * }
     * @throws PromptUnavailableException
     */
    public function resolve(string $caseId, string $promptVersionRef): array
    {
        if (! Str::isUuid($caseId) || ! Str::isUuid($promptVersionRef)) {
            throw new PromptUnavailableException("Invalid case ID or prompt version ref.");
        }

        return DB::transaction(function () use ($caseId, $promptVersionRef) {
            /** @var ScenePromptVersion|null $version */
            $version = ScenePromptVersion::where('id', $promptVersionRef)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();

            if (! $version) {
                throw new PromptUnavailableException("Prompt is unavailable.");
            }

            /** @var PromptSectionState|null $sectionState */
            $sectionState = PromptSectionState::where('section_id', $version->section_id)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();

            if (! $sectionState) {
                throw new PromptUnavailableException("Prompt is unavailable.");
            }

            // Must be current prompt version
            if ($sectionState->current_prompt_version_id !== $version->id) {
                throw new PromptUnavailableException("Prompt version is superseded.");
            }

            $case = ProcessingCase::find($caseId);
            if (! $case) {
                throw new PromptUnavailableException("Case not found.");
            }

            // Re-hash body on read from private disk
            $body = $this->artifactReader->read($version->body_path, $version->body_sha256);
            if (hash('sha256', $body) !== $version->body_sha256) {
                throw new PromptUnavailableException("Prompt body digest mismatch.");
            }

            // Branch 1: Real JEV pass
            if ($sectionState->status === 'qualified') {
                /** @var PromptQualification|null $qualification */
                $qualification = PromptQualification::where('prompt_version_id', $version->id)
                    ->where('decision', 'pass')
                    ->first();

                if ($qualification) {
                    return [
                        'basis' => 'real_jev',
                        'case_id' => $caseId,
                        'section_ref' => (string) $version->section_id,
                        'prompt_version_ref' => (string) $version->id,
                        'prompt' => $body,
                        'body_sha256' => $version->body_sha256,
                        'qualification_ref' => (string) $qualification->id,
                        'prompt_gate_id' => null,
                        'settings_version_id' => (int) $case->settings_version_id,
                        'missing_fields' => [],
                    ];
                }
            }

            // Branch 2: Owner waived demo gate (permitted only for web pipeline)
            if ($case->isWebPipelineCase()) {
                $gate = DB::table('v3_qualification_gates')
                    ->where('case_id', $caseId)
                    ->where('section_id', $version->section_id)
                    ->where('prompt_version_id', $version->id)
                    ->whereNull('candidate_id')
                    ->where('gate_stage', 'prompt')
                    ->where('basis', 'owner_waived_demo')
                    ->first();

                if ($gate) {
                    if ((int) $gate->waived_by_owner_id !== (int) $case->owner_user_id) {
                        throw new PromptUnavailableException("Demo gate waived by foreign owner.");
                    }

                    if ($gate->prompt_sha256 !== $version->body_sha256) {
                        throw new PromptUnavailableException("Demo gate prompt hash mismatch.");
                    }

                    $missingFields = json_decode($gate->missing_fields_json, true);

                    return [
                        'basis' => 'owner_waived_demo',
                        'case_id' => $caseId,
                        'section_ref' => (string) $version->section_id,
                        'prompt_version_ref' => (string) $version->id,
                        'prompt' => $body,
                        'body_sha256' => $version->body_sha256,
                        'qualification_ref' => null,
                        'prompt_gate_id' => (string) $gate->id,
                        'settings_version_id' => (int) $case->settings_version_id,
                        'missing_fields' => is_array($missingFields) ? $missingFields : [],
                    ];
                }
            }

            throw new PromptUnavailableException("Prompt section is neither qualified nor demo waived.");
        });
    }
}
