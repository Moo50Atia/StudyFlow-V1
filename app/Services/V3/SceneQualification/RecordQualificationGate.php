<?php

namespace App\Services\V3\SceneQualification;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecordQualificationGate
{
    /**
     * Record a qualification gate for prompt or scene stage.
     *
     * @param array{
     *     case_id: string,
     *     section_id: string,
     *     prompt_version_id: string,
     *     candidate_id?: ?string,
     *     gate_stage: string,
     *     basis: string,
     *     real_qualification_id?: ?string,
     *     waived_by_owner_id?: ?int,
     *     policy_version: string,
     *     policy_sha256: string,
     *     prompt_sha256: string,
     *     candidate_manifest_sha256?: ?string,
     *     missing_fields?: array<string>,
     *     acknowledgment_sha256?: ?string,
     *     command_id: string
     * } $data
     * @return array{id: string, is_replay: bool}
     */
    public function record(array $data): array
    {
        $commandId = $data['command_id'];
        $gateStage = $data['gate_stage'];
        $candidateId = $data['candidate_id'] ?? null;

        // Idempotency check on (command_id, gate_stage, candidate_id)
        $existing = DB::table('v3_qualification_gates')
            ->where('command_id', $commandId)
            ->where('gate_stage', $gateStage)
            ->where(function ($q) use ($candidateId) {
                if ($candidateId === null) {
                    $q->whereNull('candidate_id');
                } else {
                    $q->where('candidate_id', $candidateId);
                }
            })
            ->first();

        if ($existing) {
            return [
                'id' => (string) $existing->id,
                'is_replay' => true,
            ];
        }

        $id = (string) Str::uuid();
        $missingFieldsJson = json_encode($data['missing_fields'] ?? [], JSON_THROW_ON_ERROR);

        DB::table('v3_qualification_gates')->insert([
            'id' => $id,
            'case_id' => $data['case_id'],
            'section_id' => $data['section_id'],
            'prompt_version_id' => $data['prompt_version_id'],
            'candidate_id' => $candidateId,
            'gate_stage' => $gateStage,
            'basis' => $data['basis'],
            'real_qualification_id' => $data['real_qualification_id'] ?? null,
            'waived_by_owner_id' => $data['waived_by_owner_id'] ?? null,
            'policy_version' => $data['policy_version'],
            'policy_sha256' => $data['policy_sha256'],
            'prompt_sha256' => $data['prompt_sha256'],
            'candidate_manifest_sha256' => $data['candidate_manifest_sha256'] ?? null,
            'missing_fields_json' => $missingFieldsJson,
            'acknowledgment_sha256' => $data['acknowledgment_sha256'] ?? null,
            'command_id' => $commandId,
            'created_at' => now(),
        ]);

        return [
            'id' => $id,
            'is_replay' => false,
        ];
    }
}
