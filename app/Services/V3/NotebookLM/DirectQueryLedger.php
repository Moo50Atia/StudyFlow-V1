<?php

namespace App\Services\V3\NotebookLM;

use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\LessonCursor;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonQuery;
use App\Models\V3\MapActivation;
use App\Models\V3\ProcessingCase;
use App\Services\V3\Admin\CaseOperationGuard;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class DirectQueryLedger
{
    public const PROTOCOL = 'notebooklm_combined_v1';

    public function __construct(
        protected CaseOperationGuard $operationGuard
    ) {}

    /**
     * Compute canonical request digest for direct protocol queries per Spec 016 line 93.
     */
    public static function computeRequestDigest(
        string $caseId,
        string $bindingRef,
        string $kind,
        int $workOrdinal,
        ?int $expectedCursorVersion,
        ?string $conversationIn,
        array $sourceRoles,
        string $frameworkSha256,
        string $responseFormatSha256,
        string $questionSha256
    ): string {
        $payload = [
            'protocol' => self::PROTOCOL,
            'case_id' => $caseId,
            'binding_ref' => $bindingRef,
            'kind' => $kind,
            'work_ordinal' => $workOrdinal,
            'expected_cursor_version' => $expectedCursorVersion,
            'conversation_in' => $conversationIn,
            'source_roles' => $sourceRoles,
            'framework_sha256' => $frameworkSha256,
            'response_format_sha256' => $responseFormatSha256,
            'question_sha256' => $questionSha256,
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return hash('sha256', $encoded);
    }

    /**
     * Atomically claim a direct lesson query.
     */
    public function claimQuery(
        string $caseId,
        string $kind,
        int $workOrdinal,
        string $bindingRef,
        ?int $expectedCursorVersion = null,
        ?string $conversationIn = null,
        ?string $requestSha256 = null,
        ?int $historyLengthBefore = null,
        ?string $historyAnchorSha256 = null,
        ?string $queryText = null
    ): array {
        return retry(10, function () use (
            $caseId,
            $kind,
            $workOrdinal,
            $bindingRef,
            $expectedCursorVersion,
            $conversationIn,
            $requestSha256,
            $historyLengthBefore,
            $historyAnchorSha256,
            $queryText
        ) {
            return DB::transaction(function () use (
                $caseId,
                $kind,
                $workOrdinal,
                $bindingRef,
                $expectedCursorVersion,
                $conversationIn,
                $requestSha256,
                $historyLengthBefore,
                $historyAnchorSha256,
                $queryText
            ) {
            $case = ProcessingCase::where('id', $caseId)->lockForUpdate()->first();
            if (! $case) {
                throw new RuntimeException("Case not found", 404);
            }
            if (! $case->isDirectProtocol() || ! in_array($kind, ['divide', 'execution', 'lesson_start', 'lesson_continue'], true)) {
                throw new DomainException('lesson_conflict');
            }

            if (! $this->operationGuard->isPermitted($caseId)) {
                throw new DomainException('lesson_conflict');
            }

            $binding = KnowledgeSourceBinding::where('id', $bindingRef)
                ->where('case_id', $caseId)
                ->where('state', 'bound')
                ->first();
            if (! $binding) {
                throw new RuntimeException("Source binding is unavailable", 404);
            }

            $lessonMap = LessonMap::where('case_id', $caseId)->first();
            $cursor = LessonCursor::where('case_id', $caseId)->lockForUpdate()->first();

            $questionSha = match ($kind) {
                'divide' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_DIVIDE_SHA256,
                'execution' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_EXECUTION_SHA256,
                'lesson_start' => hash('sha256', 'Start with First lesson'),
                'lesson_continue' => hash('sha256', 'Continue'),
            };
            if (in_array($kind, ['lesson_start', 'lesson_continue'], true) && MappedLessonQuestion::enabled($case)) {
                $questionSha = hash('sha256', MappedLessonQuestion::forOrdinal($case, $workOrdinal));
            }
            if ($queryText !== null && hash('sha256', $queryText) !== $questionSha) {
                throw new InvalidArgumentException('Direct protocol question differs from its canonical value');
            }
            $canonicalRequest = self::computeRequestDigest(
                $caseId, $bindingRef, $kind, $workOrdinal, $expectedCursorVersion,
                $conversationIn,
                $kind === 'divide' ? ['educational_pdf'] : ['educational_pdf', 'framework_steps'],
                $binding->frameworkVersion?->body_sha256 ?? '',
                $binding->response_format_sha256 ?? FrameworkCatalog::RESPONSE_FORMAT_SHA256,
                $questionSha
            );
            if ($requestSha256 !== null && ! hash_equals($canonicalRequest, $requestSha256)) {
                throw new DomainException('lesson_conflict');
            }

            // Validate Divide requirements
            if ($kind === 'divide') {
                if ($workOrdinal !== 0) {
                    throw new InvalidArgumentException("Divide query target ordinal must be 0");
                }
                if ($conversationIn !== null) {
                    throw new InvalidArgumentException("Divide query conversation_in must be null");
                }
            }

            // Validate Execution requirements
            if ($kind === 'execution') {
                if ($workOrdinal !== 0) {
                    throw new InvalidArgumentException("Execution query target ordinal must be 0");
                }
                if (empty($conversationIn)) {
                    throw new InvalidArgumentException("Execution query requires recorded Divide conversation");
                }
            }

            // Check existing query
            $existing = LessonQuery::where('case_id', $caseId)
                ->where('kind', $kind)
                ->where('target_work_ordinal', $workOrdinal)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->request_sha256 !== $canonicalRequest
                    || $existing->conversation_in !== $conversationIn
                    || ($existing->expected_cursor_version !== null && $existing->expected_cursor_version !== $expectedCursorVersion + (in_array($kind, ['lesson_start', 'lesson_continue'], true) ? 1 : 0))) {
                    throw new DomainException('lesson_conflict');
                }
                if ($existing->state === 'recorded') {
                    return [
                        'query_ref' => $existing->id,
                        'disposition' => 'already_recorded',
                        'claimed_cursor_version' => $existing->expected_cursor_version,
                        'state' => 'recorded',
                    ];
                }

                if ($existing->send_count > 0) {
                    return [
                        'query_ref' => $existing->id,
                        'disposition' => 'reconcile_only',
                        'claimed_cursor_version' => $existing->expected_cursor_version,
                        'state' => $existing->state,
                    ];
                }

                $existing->increment('send_count');
                $existing->update([
                    'state' => 'sending',
                    'sent_at' => now(),
                ]);

                return [
                    'query_ref' => $existing->id,
                    'disposition' => 'send_now',
                    'claimed_cursor_version' => $cursor?->version,
                    'state' => 'sending',
                ];
            }

            if ($kind === 'divide') {
                if ($lessonMap || $cursor || $expectedCursorVersion !== null) {
                    throw new DomainException('lesson_conflict');
                }
            } else {
                if (! $lessonMap || $lessonMap->state !== 'ready' || ! $cursor
                    || $expectedCursorVersion === null || $cursor->version !== $expectedCursorVersion
                    || $cursor->conversation_id !== $conversationIn) {
                    throw new DomainException('lesson_conflict');
                }
                $requiredPhase = match ($kind) {
                    'execution' => 'start_pending',
                    'lesson_start' => 'ready_to_start',
                    'lesson_continue' => 'ready_to_continue',
                };
                if ($cursor->phase !== $requiredPhase) {
                    throw new DomainException('lesson_conflict');
                }
                if ($kind === 'execution') {
                    $divide = LessonQuery::where('case_id', $caseId)->where('kind', 'divide')->where('state', 'recorded')->first();
                    if (! $divide || $divide->conversation_out !== $conversationIn) {
                        throw new DomainException('lesson_conflict');
                    }
                } else {
                    $nodeExists = \App\Models\V3\LessonNode::where('map_id', $lessonMap->id)
                        ->where('work_ordinal', $workOrdinal)->exists();
                    if (! $nodeExists || $cursor->current_work_ordinal !== $workOrdinal
                        || ($kind === 'lesson_start' && $workOrdinal !== 1)
                        || ($kind === 'lesson_continue' && $workOrdinal <= 1)) {
                        throw new DomainException('lesson_conflict');
                    }
                }
            }

            if (in_array($kind, ['lesson_start', 'lesson_continue'], true) && $cursor) {
                $cursor->increment('version');
                $cursor->update([
                    'phase' => 'processing_bundle',
                    'current_work_ordinal' => $workOrdinal,
                ]);
            }

            $claimedVersion = $cursor ? $cursor->version : $expectedCursorVersion;

            $queryRef = (string) Str::uuid();
            $created = LessonQuery::create([
                'id' => $queryRef,
                'case_id' => $caseId,
                'map_id' => $lessonMap?->id,
                'binding_id' => $binding->id,
                'kind' => $kind,
                'target_work_ordinal' => $workOrdinal,
                'state' => 'sending',
                'send_count' => 1,
                'request_sha256' => $canonicalRequest,
                'canonical_prompt_sha256' => $questionSha,
                'conversation_in' => $conversationIn,
                'expected_cursor_version' => $claimedVersion,
                'source_scope' => ($kind === 'divide') ? 'pdf' : 'pdf_and_steps',
                'history_length_before' => $historyLengthBefore,
                'history_anchor_sha256' => $historyAnchorSha256,
                'sent_at' => now(),
            ]);

            return [
                'query_ref' => $created->id,
                'disposition' => 'send_now',
                'claimed_cursor_version' => $cursor?->version,
                'state' => 'sending',
            ];
        });
        }, 50, function (\Throwable $e) {
            return $e instanceof \Illuminate\Database\QueryException;
        });
    }
}
