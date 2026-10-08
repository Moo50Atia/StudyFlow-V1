<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Models\V3\LessonQuery;
use App\Models\V3\ProcessingCase;
use App\Services\V3\Lessons\LessonEvidenceStore;
use App\Services\V3\Lessons\LessonQueryLedger;
use App\Services\V3\NotebookLM\DirectQueryLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LessonQueryController extends Controller
{
    public function __construct(
        protected LessonQueryLedger $queryLedger,
        protected LessonEvidenceStore $evidenceStore,
        protected \App\Services\V3\Lessons\AcceptLessonMap $mapAcceptor,
        protected \App\Services\V3\Lessons\AcceptFirstSections $firstSectionAcceptor,
        protected DirectQueryLedger $directQueryLedger
    ) {}

    public function findDirect(string $caseId, string $kind, int $ordinal): JsonResponse
    {
        $case = ProcessingCase::find($caseId);
        if (! $case || ! $case->isDirectProtocol()) {
            return response()->json(['error' => ['code' => 'resource_unavailable', 'message' => 'Query is unavailable.']], 404)
                ->header('Cache-Control', 'private, no-store');
        }
        $query = LessonQuery::where('case_id', $caseId)->where('kind', $kind)
            ->where('target_work_ordinal', $ordinal)->first();
        if (! $query) {
            return response()->json(['data' => null], 200)->header('Cache-Control', 'private, no-store');
        }
        return response()->json(['data' => [
            'query_ref' => $query->id,
            'binding_ref' => $query->binding_id,
            'state' => $query->state,
            'conversation_in' => $query->conversation_in,
            'conversation_out' => $query->conversation_out,
            'history_length_before' => $query->history_length_before,
            'history_anchor_sha256' => $query->history_anchor_sha256,
            'expected_cursor_version' => $query->expected_cursor_version,
        ]], 200)->header('Cache-Control', 'private, no-store');
    }

    /**
     * POST /api/internal/v1/lesson-queries/{case_id}/{kind}/{ordinal}/claim
     */
    public function claim(Request $request, string $caseId, string $kind, int $ordinal): JsonResponse
    {
        $payload = $request->all();

        $case = ProcessingCase::where('id', $caseId)->first();
        if ($case && $case->isDirectProtocol()) {
            if (($payload['schema_version'] ?? 0) !== 1
                || empty($payload['binding_ref'])
                || ! in_array($kind, ['divide', 'execution', 'lesson_start', 'lesson_continue'], true)
                || ! is_string($payload['request_sha256'] ?? null)
                || ! is_string($payload['query_text'] ?? null)
            ) {
                return response()->json([
                    'error' => [
                        'code' => 'lesson_invalid',
                        'message' => 'Lesson request is invalid.',
                    ],
                ], 422)->header('Cache-Control', 'private, no-store');
            }

            try {
                $result = $this->directQueryLedger->claimQuery(
                    caseId: $caseId,
                    kind: $kind,
                    workOrdinal: $ordinal,
                    bindingRef: $payload['binding_ref'],
                    expectedCursorVersion: $payload['expected_cursor_version'] ?? null,
                    conversationIn: $payload['conversation_in'] ?? null,
                    requestSha256: $payload['request_sha256'] ?? null,
                    historyLengthBefore: $payload['history_length_before'] ?? null,
                    historyAnchorSha256: $payload['history_anchor_sha256'] ?? null,
                    queryText: $payload['query_text'] ?? null
                );

                return response()->json([
                    'data' => $result,
                ], 200)->header('Cache-Control', 'private, no-store');
            } catch (\InvalidArgumentException $e) {
                return response()->json([
                    'error' => [
                        'code' => 'lesson_invalid',
                        'message' => 'Lesson request is invalid.',
                    ],
                ], 422)->header('Cache-Control', 'private, no-store');
            } catch (\DomainException $e) {
                return response()->json([
                    'error' => [
                        'code' => 'lesson_conflict',
                        'message' => 'Lesson state changed.',
                    ],
                ], 409)->header('Cache-Control', 'private, no-store');
            } catch (\RuntimeException $e) {
                $code = $e->getCode();
                if ($code === 409) {
                    return response()->json([
                        'error' => [
                            'code' => 'lesson_conflict',
                            'message' => 'Lesson state changed.',
                        ],
                    ], 409)->header('Cache-Control', 'private, no-store');
                }
                return response()->json([
                    'error' => [
                        'code' => 'lesson_unavailable',
                        'message' => 'Lesson is unavailable.',
                    ],
                ], 404)->header('Cache-Control', 'private, no-store');
            }
        }

        if (($payload['schema_version'] ?? 0) !== 1
            || empty($payload['activation_id'])
            || empty($payload['binding_ref'])
            || ! array_key_exists('query_text', $payload)
            || ! in_array($kind, ['divide', 'coverage', 'first', 'continue'], true)
        ) {
            return response()->json([
                'error' => [
                    'code' => 'lesson_invalid',
                    'message' => 'Lesson request is invalid.',
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        try {
            $result = $this->queryLedger->claimQuery(
                caseId: $caseId,
                kind: $kind,
                targetWorkOrdinal: $ordinal,
                activationId: $payload['activation_id'],
                bindingRef: $payload['binding_ref'],
                queryText: $payload['query_text'],
                conversationIn: $payload['conversation_in'] ?? null
            );

            return response()->json([
                'data' => $result,
            ], 200)->header('Cache-Control', 'private, no-store');
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'lesson_invalid',
                    'message' => 'Lesson request is invalid.',
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            if ($code === 409) {
                return response()->json([
                    'error' => [
                        'code' => 'lesson_conflict',
                        'message' => 'Lesson state changed.',
                    ],
                ], 409)->header('Cache-Control', 'private, no-store');
            }
            return response()->json([
                'error' => [
                    'code' => 'lesson_unavailable',
                    'message' => 'Lesson is unavailable.',
                ],
            ], 404)->header('Cache-Control', 'private, no-store');
        }
    }

    /**
     * GET /api/internal/v1/lesson-queries/{query_ref}
     */
    public function show(string $queryRef): JsonResponse
    {
        $query = LessonQuery::where('id', $queryRef)->first();
        if (! $query) {
            return response()->json([
                'error' => [
                    'code' => 'lesson_unavailable',
                    'message' => 'Lesson is unavailable.',
                ],
            ], 404)->header('Cache-Control', 'private, no-store');
        }

        return response()->json([
            'data' => [
                'state' => $query->state,
                'answer_sha256' => $query->answer_sha256,
                'conversation_out' => $query->conversation_out,
                'error_code' => $query->error_code,
            ],
        ], 200)->header('Cache-Control', 'private, no-store');
    }

    /**
     * POST /api/internal/v1/lesson-queries/{query_ref}/result
     */
    public function result(Request $request, string $queryRef): JsonResponse
    {
        $query = LessonQuery::where('id', $queryRef)->first();
        if (! $query) {
            return response()->json([
                'error' => [
                    'code' => 'lesson_unavailable',
                    'message' => 'Lesson is unavailable.',
                ],
            ], 404)->header('Cache-Control', 'private, no-store');
        }

        $case = $query->processingCase;
        $isDirect = $case?->isDirectProtocol() ?? false;

        $payload = json_decode($request->getContent(), true) ?? [];
        if (($payload['schema_version'] ?? 0) !== 1
            || ! array_key_exists('answer', $payload)
            || ! array_key_exists('conversation_out', $payload)
        ) {
            return response()->json([
                'error' => [
                    'code' => 'lesson_invalid',
                    'message' => 'Lesson request is invalid.',
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        if (! $isDirect && ! array_key_exists('parsed', $payload)) {
            return response()->json([
                'error' => [
                    'code' => 'lesson_invalid',
                    'message' => 'Lesson request is invalid.',
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        try {
            if ($query->kind === 'divide') {
                $nodes = $payload['parsed']['nodes'] ?? null;
                if ($isDirect && empty($nodes)) {
                    $nodes = \App\Services\V3\NotebookLM\DirectMapParser::parse($payload['answer']);
                }
                if (! is_array($nodes) || empty($nodes)) {
                    throw new \InvalidArgumentException('Direct Divide result has no nodes.');
                }

                $res = $this->mapAcceptor->acceptDivideResult(
                    queryRef: $queryRef,
                    answer: $payload['answer'],
                    conversationOut: $payload['conversation_out'],
                    parsedNodes: $nodes
                );
                $status = ($res['created'] ?? false) ? 201 : 200;
                unset($res['created']);
                return response()->json(['data' => $res], $status)->header('Cache-Control', 'private, no-store');
            }

            if ($query->kind === 'execution') {
                if (! $isDirect) {
                    return response()->json([
                        'error' => ['code' => 'protocol_conflict', 'message' => 'Execution query only valid for direct protocol.'],
                    ], 409)->header('Cache-Control', 'private, no-store');
                }

                if (trim($payload['answer']) !== 'System Ready') {
                    $this->blockDirectQueryWithAnswer($query, $payload['answer'], $payload['conversation_out']);
                    return response()->json([
                        'error' => ['code' => 'protocol_validation', 'message' => 'Execution query requires exact System Ready acknowledgement.'],
                    ], 422)->header('Cache-Control', 'private, no-store');
                }

                $answerSha = hash('sha256', $payload['answer']);
                if (($payload['answer_sha256'] ?? null) !== $answerSha) {
                    $this->blockDirectQueryWithAnswer($query, $payload['answer'], $payload['conversation_out']);
                    return response()->json([
                        'error' => ['code' => 'protocol_validation', 'message' => 'Execution answer digest is invalid.'],
                    ], 422)->header('Cache-Control', 'private, no-store');
                }
                $answerPath = $this->evidenceStore->putAnswer($query->case_id, $query->id, $payload['answer'], $answerSha);

                $cursorVersion = \Illuminate\Support\Facades\DB::transaction(function () use ($query, $payload, $answerSha, $answerPath) {
                    $locked = LessonQuery::where('id', $query->id)->lockForUpdate()->firstOrFail();
                    if ($locked->state === 'recorded') {
                        if ($locked->answer_sha256 !== $answerSha || $locked->conversation_out !== $payload['conversation_out']) {
                            throw new \RuntimeException('Execution result changed.', 409);
                        }
                        return \App\Models\V3\LessonCursor::where('case_id', $locked->case_id)->value('version');
                    }
                    $cursor = \App\Models\V3\LessonCursor::where('case_id', $locked->case_id)->lockForUpdate()->first();
                    if (! $cursor || $cursor->phase !== 'start_pending'
                        || $cursor->version !== (int) $locked->expected_cursor_version
                        || $cursor->conversation_id !== $locked->conversation_in
                        || $payload['conversation_out'] !== $locked->conversation_in
                        || ! app(\App\Services\V3\Admin\CaseOperationGuard::class)->isPermitted($locked->case_id)) {
                        throw new \RuntimeException('Execution state changed.', 409);
                    }
                    $locked->update([
                        'state' => 'recorded', 'answer_path' => $answerPath,
                        'answer_sha256' => $answerSha, 'answer_bytes' => strlen($payload['answer']),
                        'conversation_out' => $payload['conversation_out'], 'recorded_at' => now(),
                    ]);
                    $cursor->update([
                        'phase' => 'ready_to_start', 'last_query_id' => $locked->id,
                        'version' => $cursor->version + 1,
                    ]);
                    return $cursor->version;
                });

                return response()->json([
                    'data' => [
                        'query_ref' => $query->id,
                        'state' => 'recorded',
                        'status' => 'acknowledged',
                        'cursor_version' => $cursorVersion,
                    ],
                ], 200)->header('Cache-Control', 'private, no-store');
            }

            if ($query->kind === 'coverage') {
                $pages = $payload['parsed']['pages'] ?? null;
                if (! is_array($pages) || empty($pages)) {
                    return response()->json([
                        'error' => ['code' => 'lesson_invalid', 'message' => 'Lesson request is invalid.'],
                    ], 422)->header('Cache-Control', 'private, no-store');
                }

                $res = $this->mapAcceptor->acceptCoverageResult(
                    queryRef: $queryRef,
                    answer: $payload['answer'],
                    conversationOut: $payload['conversation_out'],
                    parsedPages: $pages
                );
                $status = ($res['created'] ?? false) ? 201 : 200;
                unset($res['created']);
                return response()->json(['data' => $res], $status)->header('Cache-Control', 'private, no-store');
            }

            if (in_array($query->kind, ['first', 'continue'], true)) {
                $parsed = $payload['parsed'] ?? null;
                if (! is_array($parsed)) {
                    return response()->json([
                        'error' => ['code' => 'lesson_invalid', 'message' => 'Lesson request is invalid.'],
                    ], 422)->header('Cache-Control', 'private, no-store');
                }

                $res = $this->firstSectionAcceptor->acceptFirstSections(
                    queryRef: $queryRef,
                    answer: $payload['answer'],
                    conversationOut: $payload['conversation_out'],
                    parsed: $parsed
                );
                $status = ($res['created'] ?? false) ? 201 : 200;
                unset($res['created']);
                return response()->json(['data' => $res], $status)->header('Cache-Control', 'private, no-store');
            }

            return response()->json([
                'data' => [
                    'query_ref' => $query->id,
                    'map_ref' => $query->map_id,
                    'cursor_version' => 1,
                    'state' => 'recorded',
                ],
            ], 200)->header('Cache-Control', 'private, no-store');
        } catch (\InvalidArgumentException $e) {
            if ($isDirect && $query->kind === 'divide' && is_string($payload['answer'] ?? null)) {
                $this->blockDirectQueryWithAnswer($query, $payload['answer'], $payload['conversation_out']);
            }
            return response()->json([
                'error' => [
                    'code' => 'lesson_invalid',
                    'message' => 'Lesson request is invalid.',
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            if ($code === 409) {
                return response()->json([
                    'error' => [
                        'code' => 'lesson_conflict',
                        'message' => 'Lesson state changed.',
                    ],
                ], 409)->header('Cache-Control', 'private, no-store');
            }
            return response()->json([
                'error' => [
                    'code' => 'lesson_unavailable',
                    'message' => 'Lesson is unavailable.',
                ],
            ], 404)->header('Cache-Control', 'private, no-store');
        }
    }

    private function blockDirectQueryWithAnswer(LessonQuery $query, string $answer, string $conversationOut): void
    {
        $sha = hash('sha256', $answer);
        $path = $this->evidenceStore->putAnswer($query->case_id, $query->id, $answer, $sha);
        \Illuminate\Support\Facades\DB::transaction(function () use ($query, $answer, $sha, $path, $conversationOut) {
            $locked = LessonQuery::where('id', $query->id)->lockForUpdate()->first();
            if ($locked && in_array($locked->state, ['sending', 'uncertain'], true)) {
                $locked->update([
                    'state' => 'blocked', 'error_code' => 'protocol_validation',
                    'answer_path' => $path, 'answer_sha256' => $sha,
                    'answer_bytes' => strlen($answer), 'conversation_out' => $conversationOut,
                ]);
            }
        });
    }

    /**
     * POST /api/internal/v1/lesson-queries/{query_ref}/uncertain
     */
    public function uncertain(Request $request, string $queryRef): JsonResponse
    {
        $query = LessonQuery::where('id', $queryRef)->first();
        if (! $query) {
            return response()->json([
                'error' => [
                    'code' => 'lesson_unavailable',
                    'message' => 'Lesson is unavailable.',
                ],
            ], 404)->header('Cache-Control', 'private, no-store');
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $errorCode = $payload['error_code'] ?? 'unknown_uncertainty';

        $query->update([
            'state' => 'uncertain',
            'error_code' => $errorCode,
        ]);

        return response()->json([
            'data' => [
                'query_ref' => $query->id,
                'state' => 'uncertain',
                'error_code' => $errorCode,
            ],
        ], 200)->header('Cache-Control', 'private, no-store');
    }
}
