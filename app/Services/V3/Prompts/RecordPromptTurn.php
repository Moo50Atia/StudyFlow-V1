<?php

namespace App\Services\V3\Prompts;

use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptSectionState;
use App\Models\V3\PromptTurn;
use App\Models\V3\PromptWorkItem;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\TeacherSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RecordPromptTurn
{
    public const MAX_ANSWER_BYTES = 4404019; // 4.2 MiB

    public static function computeTurnRequestSha256(
        string $caseId,
        string $mapRef,
        int $workOrdinal,
        string $step,
        string $canonicalDigest,
        array $sectionRefs,
        string $origin,
        ?string $originQueryRef,
        ?string $originTurnRef,
        string $conversationIn
    ): string {
        $secJoined = implode(',', $sectionRefs);
        $oq = $originQueryRef ?? '';
        $ot = $originTurnRef ?? '';
        $convLen = strlen($conversationIn);

        $payload = "010-turn-v1\n"
            . $caseId . "\n"
            . $mapRef . "\n"
            . $workOrdinal . "\n"
            . $step . "\n"
            . $canonicalDigest . "\n"
            . $secJoined . "\n"
            . $origin . "\n"
            . $oq . "\n"
            . $ot . "\n"
            . "{$convLen}:{$conversationIn}";

        return hash('sha256', $payload);
    }

    public function claimTurn(string $caseId, string $workItemRef, string $step, array $payload): array
    {
        if (! in_array($step, ['execution', 'second', 'third'], true)) {
            throw new PromptValidationException("Invalid step '{$step}'.");
        }

        $origin = $payload['origin'] ?? null;
        if (! in_array($origin, ['sent', 'execution_reuse', 'continue_reuse'], true)) {
            throw new PromptValidationException("Invalid origin '{$origin}'.");
        }

        return retry(15, function () use ($caseId, $workItemRef, $step, $origin, $payload) {
            return DB::transaction(function () use ($caseId, $workItemRef, $step, $origin, $payload) {
                /** @var PromptWorkItem|null $workItem */
                $workItem = PromptWorkItem::where('id', $workItemRef)
                    ->where('case_id', $caseId)
                    ->lockForUpdate()
                    ->first();

                if (! $workItem) {
                    throw new PromptUnavailableException("Prompt work item not found.");
                }

                // Step sequence enforcement
                if ($step === 'execution' && (int) $workItem->work_ordinal !== 1) {
                    throw new PromptConflictException("Execution step is only valid for work ordinal 1.");
                }

                if ($step === 'second') {
                    if ((int) $workItem->work_ordinal === 1 && ! $workItem->execution_turn_id) {
                        throw new PromptConflictException("Second step on work 1 requires execution turn.");
                    }
                }

                if ($step === 'third') {
                    if (! $workItem->second_turn_id) {
                        throw new PromptConflictException("Third step requires second turn.");
                    }
                }

                $conversationIn = (string) ($payload['conversation_in'] ?? '');
                $canonicalSha = (string) ($payload['canonical_sha256'] ?? '');
                $originQueryRef = $payload['origin_query_ref'] ?? null;
                $originTurnRef = $payload['origin_turn_ref'] ?? null;

                $requestSha = self::computeTurnRequestSha256(
                    $caseId,
                    $workItem->map_id,
                    (int) $workItem->work_ordinal,
                    $step,
                    $canonicalSha,
                    $workItem->section_refs,
                    $origin,
                    $originQueryRef,
                    $originTurnRef,
                    $conversationIn
                );

                /** @var PromptTurn|null $existing */
                $existing = PromptTurn::where('work_item_id', $workItem->id)
                    ->where('step', $step)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if ($existing->request_sha256 === $requestSha) {
                        if ($existing->state === 'recorded') {
                            return [
                                'turn' => $existing,
                                'disposition' => 'recorded',
                                'request_sha256' => $requestSha,
                            ];
                        }

                        return [
                            'turn' => $existing,
                            'disposition' => 'reconcile',
                            'request_sha256' => $requestSha,
                        ];
                    }

                    throw new PromptConflictException("Turn already exists with conflicting request digest.");
                }

                $sendCount = ($origin === 'sent') ? 1 : 0;
                try {
                    $turn = PromptTurn::create([
                        'id' => (string) Str::uuid(),
                        'work_item_id' => $workItem->id,
                        'step' => $step,
                        'origin' => $origin,
                        'origin_query_id' => $originQueryRef,
                        'origin_turn_id' => $originTurnRef,
                        'state' => 'claimed',
                        'send_count' => $sendCount,
                        'request_sha256' => $requestSha,
                        'canonical_sha256' => $canonicalSha,
                        'conversation_in' => $conversationIn,
                        'sent_at' => ($origin === 'sent') ? now() : null,
                    ]);

                    return [
                        'turn' => $turn,
                        'disposition' => 'send_now',
                        'request_sha256' => $requestSha,
                    ];
                } catch (\Illuminate\Database\QueryException $qe) {
                    // Check for unique collision on (work_item_id, step)
                    $turn = PromptTurn::where('work_item_id', $workItem->id)
                        ->where('step', $step)
                        ->first();
                    if ($turn && $turn->request_sha256 === $requestSha) {
                        return [
                            'turn' => $turn,
                            'disposition' => 'reconcile',
                            'request_sha256' => $requestSha,
                        ];
                    }
                    throw $qe;
                }
            });
        }, 50, function (\Throwable $e) {
            $msg = $e->getMessage();
            return str_contains($msg, 'database is locked')
                || str_contains($msg, 'busy')
                || str_contains($msg, 'Deadlock')
                || str_contains($msg, 'deadlock')
                || str_contains($msg, 'serialization');
        });
    }

    public function recordResult(string $caseId, string $turnRef, array $payload): array
    {
        $answer = (string) ($payload['answer'] ?? '');
        $expectedSha = $payload['answer_sha256'] ?? '';
        $actualSha = hash('sha256', $answer);

        if ($actualSha !== $expectedSha) {
            throw new PromptValidationException("Answer SHA-256 digest mismatch.");
        }

        if (strlen($answer) > self::MAX_ANSWER_BYTES) {
            throw new PromptValidationException("Answer size exceeds maximum allowed bytes.");
        }

        return DB::transaction(function () use ($caseId, $turnRef, $payload, $answer, $actualSha) {
            /** @var PromptTurn|null $turn */
            $turn = PromptTurn::where('id', $turnRef)->lockForUpdate()->first();
            if (! $turn) {
                throw new PromptUnavailableException("Turn not found.");
            }

            /** @var PromptWorkItem|null $workItem */
            $workItem = PromptWorkItem::where('id', $turn->work_item_id)
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();
            if (! $workItem) {
                throw new PromptUnavailableException("Work item not found.");
            }

            if ($turn->state === 'recorded') {
                if ($turn->answer_sha256 === $actualSha) {
                    $originalPromptRefs = [];
                    if ($turn->step === 'third') {
                        $originalPromptRefs = ScenePromptVersion::where('work_item_id', $workItem->id)
                            ->where('version_no', 1)
                            ->pluck('id')
                            ->all();
                    }

                    return [
                        'turn' => $turn,
                        'original_prompt_refs' => $originalPromptRefs,
                    ];
                }

                throw new PromptConflictException("Turn already recorded with different answer.");
            }

            // Write raw answer privately
            $artifactPath = "prompt-turns/{$caseId}/{$turn->id}/{$actualSha}.txt";
            Storage::disk('v3_private')->put($artifactPath, $answer);

            $turn->update([
                'state' => 'recorded',
                'answer_sha256' => $actualSha,
                'answer_path' => $artifactPath,
                'answer_bytes' => strlen($answer),
                'conversation_out' => $payload['conversation_out'] ?? null,
                'parsed_mappings' => $payload['blocks'] ?? null,
                'recorded_at' => now(),
            ]);

            $originalPromptRefs = [];

            if ($turn->step === 'execution') {
                $workItem->update([
                    'execution_turn_id' => $turn->id,
                    'state' => ($payload['execution_shape'] === 'ready') ? 'awaiting_second' : $workItem->state,
                ]);
            } elseif ($turn->step === 'second') {
                $workItem->update([
                    'second_turn_id' => $turn->id,
                    'state' => 'awaiting_third',
                ]);
            } elseif ($turn->step === 'third') {
                $workItem->update([
                    'third_turn_id' => $turn->id,
                    'state' => 'originals_ready',
                    'final_conversation_id' => $payload['conversation_out'] ?? null,
                ]);

                // Record version-1 prompt for each block
                $blocks = $payload['blocks'] ?? [];
                $sections = TeacherSection::where('case_id', $caseId)
                    ->whereIn('id', $workItem->section_refs)
                    ->get()
                    ->keyBy('id');

                foreach ($blocks as $block) {
                    $sectionRef = $block['section_ref'];
                    $promptBody = trim((string) ($block['text'] ?? ''));

                    /** @var TeacherSection|null $sec */
                    $sec = $sections->get($sectionRef);
                    if (! $sec) {
                        throw new PromptValidationException("Block section ref {$sectionRef} not found in work item.");
                    }

                    // Extract scene type (first word/phrase)
                    $sceneType = 'Process';
                    foreach (['Process', 'Comparison', 'Accumulation', 'Parallel Flow', 'Time Evolution'] as $candidate) {
                        if (str_starts_with($promptBody, $candidate)) {
                            $sceneType = $candidate;
                            break;
                        }
                    }

                    $bodySha = hash('sha256', $promptBody);
                    $bodyPath = "prompt-versions/{$caseId}/{$sectionRef}/v1_{$bodySha}.txt";
                    Storage::disk('v3_private')->put($bodyPath, $promptBody);

                    $fields = [];
                    foreach (PromptContextReader::ELEVEN_FIELDS as $fn) {
                        $fields[$fn] = (string) ($sec->{$fn} ?? '');
                    }
                    $fieldsSha = PromptContextReader::computeSectionFieldsSha256($fields);

                    $promptVersion = ScenePromptVersion::forceCreate([
                        'id' => (string) Str::uuid(),
                        'case_id' => $caseId,
                        'work_item_id' => $workItem->id,
                        'section_id' => $sectionRef,
                        'version_no' => 1,
                        'predecessor_id' => null,
                        'origin' => 'third',
                        'third_turn_id' => $turn->id,
                        'feedback_effect_id' => null,
                        'body_path' => $bodyPath,
                        'body_sha256' => $bodySha,
                        'body_bytes' => strlen($promptBody),
                        'scene_type' => $sceneType,
                        'section_fields_sha256' => $fieldsSha,
                        'canonical_third_sha256' => PromptContextReader::CANONICAL_THIRD_SHA256,
                        'created_at' => now(),
                    ]);

                    $originalPromptRefs[] = $promptVersion->id;

                    // Update or create section state
                    PromptSectionState::updateOrCreate(
                        ['section_id' => $sectionRef],
                        [
                            'case_id' => $caseId,
                            'current_prompt_version_id' => $promptVersion->id,
                            'status' => 'awaiting_qualification',
                            'revision_count' => 0,
                        ]
                    );
                }
            }

            return [
                'turn' => $turn,
                'original_prompt_refs' => $originalPromptRefs,
            ];
        });
    }

    public function recordUncertain(string $caseId, string $turnRef, string $errorCode): PromptTurn
    {
        return DB::transaction(function () use ($caseId, $turnRef, $errorCode) {
            /** @var PromptTurn|null $turn */
            $turn = PromptTurn::where('id', $turnRef)->lockForUpdate()->first();
            if (! $turn) {
                throw new PromptUnavailableException("Turn not found.");
            }

            /** @var PromptWorkItem|null $workItem */
            $workItem = PromptWorkItem::where('id', $turn->work_item_id)
                ->where('case_id', $caseId)
                ->first();
            if (! $workItem) {
                throw new PromptUnavailableException("Work item not found.");
            }

            if ($turn->state === 'recorded') {
                return $turn;
            }

            $turn->update([
                'state' => 'uncertain',
                'error_code' => $errorCode,
            ]);

            return $turn;
        });
    }
}
