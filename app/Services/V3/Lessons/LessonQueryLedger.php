<?php

namespace App\Services\V3\Lessons;

use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonQuery;
use App\Models\V3\MapActivation;
use App\Models\V3\ProcessingCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LessonQueryLedger
{
    public const CANONICAL_DIVIDE_SHA256 = '77620579df1bb2fb51876116b1266a32fe58ecd963486c3d76d5c98c46d012ff';
    public const CANONICAL_EXECUTION_SHA256 = '5ab2448f34c9b2b90199347772d10d7d217ba9fd468dffc28e3169bd0a7fdce9';
    public const CANONICAL_FIRST_SHA256 = '04450e9fd7e0962ceb4f60a1f641e43b671d7be2622718e202af0ca92683b788';
    public const CANONICAL_SECOND_SHA256 = 'a8776affc821a1b913a4685b309dc0304afc03c7389fcfe44b366b6f5683f203';
    public const CANONICAL_THIRD_SHA256 = '07798d744b973ec11248897d2efe0e769ab481c4a21cce7562696dd131217955';

    public const MAX_QUERY_BYTES = 16384;

    /**
     * Compute deterministic request hash per Spec 009 contract section 36.
     */
    public static function computeRequestSha256(
        string $caseId,
        string $bindingRef,
        ?string $mapRef,
        string $kind,
        int $targetWorkOrdinal,
        ?string $conversationIn,
        string $queryText
    ): string {
        $conv = $conversationIn ?? '';
        $map = $mapRef ?? '';
        $queryHash = hash('sha256', $queryText);

        $payload = "009-query-v1\n"
            . $caseId . "\n"
            . $bindingRef . "\n"
            . $map . "\n"
            . $kind . "\n"
            . $targetWorkOrdinal . "\n"
            . strlen($conv) . ":" . $conv . "\n"
            . $queryHash;

        return hash('sha256', $payload);
    }

    /**
     * Atomically claim a query before external provider execution.
     *
     * @return array{query_ref: string, disposition: string, request_sha256: string}
     */
    public function claimQuery(
        string $caseId,
        string $kind,
        int $targetWorkOrdinal,
        string $activationId,
        string $bindingRef,
        string $queryText,
        ?string $conversationIn = null
    ): array {
        if (strlen($queryText) > self::MAX_QUERY_BYTES) {
            throw new \InvalidArgumentException("Query exceeds 16,384 bytes limit");
        }

        return DB::transaction(function () use (
            $caseId,
            $kind,
            $targetWorkOrdinal,
            $activationId,
            $bindingRef,
            $queryText,
            $conversationIn
        ) {
            // Lock case
            $case = ProcessingCase::where('id', $caseId)->lockForUpdate()->first();
            if (! $case) {
                throw new \RuntimeException("Case not found", 404);
            }

            if (! app(\App\Services\V3\Admin\CaseOperationGuard::class)->isPermitted($caseId)) {
                throw new \DomainException('lesson_conflict');
            }

            // Verify activation and binding
            $activation = MapActivation::where('id', $activationId)
                ->where('case_id', $caseId)
                ->first();
            if (! $activation || $activation->state !== 'accepted') {
                throw new \RuntimeException("Map activation is unavailable", 404);
            }

            $binding = KnowledgeSourceBinding::where('id', $bindingRef)
                ->where('case_id', $caseId)
                ->where('state', 'bound')
                ->first();
            if (! $binding) {
                throw new \RuntimeException("Source binding is unavailable", 404);
            }

            // Optional map reference
            $lessonMap = LessonMap::where('case_id', $caseId)->first();
            $mapRef = $lessonMap?->id;

            $requestSha256 = self::computeRequestSha256(
                $caseId,
                $bindingRef,
                $mapRef,
                $kind,
                $targetWorkOrdinal,
                $conversationIn,
                $queryText
            );

            // For continue kind: validate cursor phase and target work ordinal
            if ($kind === 'continue') {
                $cursor = \App\Models\V3\LessonCursor::where('case_id', $caseId)->lockForUpdate()->first();
                if (! $cursor) {
                    throw new \RuntimeException("Cursor is unavailable", 404);
                }
                if ($cursor->phase === 'complete') {
                    throw new \RuntimeException("Lesson completed: continuation disallowed", 409);
                }
                if (! in_array($cursor->phase, ['ready_to_continue', 'continue_pending'], true)) {
                    throw new \RuntimeException("Cursor not ready for continue", 409);
                }
                if ((int) $cursor->current_work_ordinal + 1 !== $targetWorkOrdinal) {
                    throw new \RuntimeException("Target work ordinal mismatch for continue", 409);
                }
                if (trim($queryText) !== 'Continue') {
                    throw new \InvalidArgumentException("Continue query must be exactly 'Continue'");
                }
            }

            // Check existing query
            $existing = LessonQuery::where('case_id', $caseId)
                ->where('kind', $kind)
                ->where('target_work_ordinal', $targetWorkOrdinal)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->request_sha256 !== $requestSha256) {
                    throw new \RuntimeException("Query conflict: request parameters changed", 409);
                }

                if ($existing->state === 'recorded') {
                    return [
                        'query_ref' => $existing->id,
                        'disposition' => 'recorded',
                        'request_sha256' => $requestSha256,
                    ];
                }

                return [
                    'query_ref' => $existing->id,
                    'disposition' => 'reconcile',
                    'request_sha256' => $requestSha256,
                ];
            }

            $canonicalSha = match ($kind) {
                'divide' => self::CANONICAL_DIVIDE_SHA256,
                'first' => self::CANONICAL_FIRST_SHA256,
                default => null,
            };

            $query = LessonQuery::create([
                'id' => (string) Str::uuid(),
                'case_id' => $caseId,
                'map_id' => $mapRef,
                'kind' => $kind,
                'target_work_ordinal' => $targetWorkOrdinal,
                'state' => 'sending',
                'send_count' => 1,
                'request_sha256' => $requestSha256,
                'canonical_prompt_sha256' => $canonicalSha,
                'conversation_in' => $conversationIn,
                'sent_at' => now(),
            ]);

            if ($kind === 'continue' && isset($cursor) && $cursor->phase === 'ready_to_continue') {
                $cursor->update([
                    'phase' => 'continue_pending',
                    'version' => $cursor->version + 1,
                ]);
            }

            return [
                'query_ref' => $query->id,
                'disposition' => 'send_now',
                'request_sha256' => $requestSha256,
            ];
        });
    }
}
