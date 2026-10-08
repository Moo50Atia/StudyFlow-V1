<?php

namespace App\Services\V3\Prompts;

use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptQualification;
use App\Models\V3\PromptSectionState;
use App\Models\V3\ScenePromptVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QualifiedPromptResolver
{
    public function __construct(
        protected PrivatePromptArtifactReader $artifactReader
    ) {}

    /**
     * Resolve a qualified prompt for 011 scene authoring handoff.
     * Rehashes body and checks current Section and pass under lock.
     *
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

            // Must be in qualified state
            if ($sectionState->status !== 'qualified') {
                throw new PromptUnavailableException("Prompt section is not qualified.");
            }

            /** @var PromptQualification|null $qualification */
            $qualification = PromptQualification::where('prompt_version_id', $version->id)
                ->first();

            if (! $qualification || $qualification->decision !== 'pass') {
                throw new PromptUnavailableException("Prompt does not have passing qualification.");
            }

            // Re-hash body on read from private disk
            $body = $this->artifactReader->read($version->body_path, $version->body_sha256);
            if (hash('sha256', $body) !== $version->body_sha256) {
                throw new PromptUnavailableException("Prompt body digest mismatch.");
            }

            $case = ProcessingCase::find($caseId);
            if (! $case) {
                throw new PromptUnavailableException("Case not found.");
            }

            return [
                'case_id' => $caseId,
                'section_ref' => $version->section_id,
                'prompt_version_ref' => $version->id,
                'prompt' => $body,
                'body_sha256' => $version->body_sha256,
                'qualification_ref' => $qualification->id,
                'settings_version_id' => $case->settings_version_id,
            ];
        });
    }
}
