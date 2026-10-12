<?php

namespace App\Services\V3\WebPipeline;

use App\Models\User;
use App\Models\V3\SubjectRun;
use App\Models\V3\TeacherSection;
use App\Services\V3\Authoring\ImportManualScene;

class ManualSceneCommand
{
    public function __construct(
        protected ImportManualScene $importer
    ) {}

    /**
     * Dispatch or execute the manual scene import command.
     *
     * @param array<string, mixed> $payload
     * @return array{
     *     candidate_id: string,
     *     import_id: string,
     *     is_replay: bool
     * }
     */
    public function execute(User $actor, SubjectRun $run, TeacherSection $section, array $payload): array
    {
        return $this->importer->import($actor, $run, $section, $payload);
    }
}
