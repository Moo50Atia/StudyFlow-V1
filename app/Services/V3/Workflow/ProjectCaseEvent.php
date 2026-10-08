<?php

namespace App\Services\V3\Workflow;

use App\Models\V3\CaseEvent;
use App\Models\V3\ProcessingCase;
use App\Models\V3\WorkflowLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProjectCaseEvent
{
    public const STAGE_CODES = [
        'intake',
        'extraction',
        'sourcing',
        'mapping',
        'sections',
        'prompting',
        'prompt_generation',
        'prompt_qualification',
        'scene_generation',
        'scene_authoring',
        'scene_qualification',
        'teacher_review',
        'complete',
    ];

    public const STATUS_CODES = [
        'queued',
        'running',
        'awaiting_review',
        'approved',
        'blocked',
        'failed',
    ];

    public const ERROR_CODES = [
        'validation',
        'policy_denied',
        'source_unreadable',
        'source_unavailable',
        'prompt_generation_failed',
        'prompt_qualification_failed',
        'scene_generation_failed',
        'scene_qualification_failed',
        'provider_unavailable',
        'provider_rate_limited',
        'policy_violation',
        'internal',
        'settings_unavailable',
        'workflow_start_overdue',
        'workflow_history_missing',
        'workflow_identity_conflict',
        'milestone_rejected',
        'temporal_closed',
    ];

    public const EVENT_TYPES = [
        'workflow_started',
        'stage_started',
        'stage_completed',
        'workflow_failed',
        'source_blocked',
        'map_blocked',
    ];


    public function project(array $payload): array
    {
        $this->validatePayload($payload);

        return retry(10, function () use ($payload) {
            return DB::transaction(function () use ($payload) {
            $caseId = $payload['case_id'];

            /** @var ProcessingCase|null $case */
            $case = ProcessingCase::where('id', $caseId)->lockForUpdate()->first();
            if (! $case) {
                return [
                    'status' => 404,
                    'error' => [
                        'code' => 'case_not_found',
                        'message' => "Processing case '{$caseId}' not found.",
                    ],
                ];
            }

            /** @var WorkflowLink|null $link */
            $link = WorkflowLink::where('case_id', $caseId)->lockForUpdate()->first();
            if (! $link) {
                return [
                    'status' => 404,
                    'error' => [
                        'code' => 'case_not_found',
                        'message' => "Workflow link for case '{$caseId}' not found.",
                    ],
                ];
            }

            if ($link->workflow_id !== $payload['workflow_id']) {
                return [
                    'status' => 409,
                    'error' => [
                        'code' => 'workflow_conflict',
                        'message' => "Workflow ID mismatch: expected '{$link->workflow_id}', got '{$payload['workflow_id']}'.",
                    ],
                ];
            }

            if ($link->run_id !== null && $link->run_id !== $payload['run_id']) {
                return [
                    'status' => 409,
                    'error' => [
                        'code' => 'workflow_conflict',
                        'message' => "Run ID mismatch: expected '{$link->run_id}', got '{$payload['run_id']}'.",
                    ],
                ];
            }

            $payloadDigest = $this->computePayloadDigest($payload);

            // Check duplicate event_key
            /** @var CaseEvent|null $existingEvent */
            $existingEvent = CaseEvent::where('case_id', $caseId)
                ->where('event_key', $payload['event_key'])
                ->first();

            if ($existingEvent) {
                if ($existingEvent->payload_digest === $payloadDigest) {
                    return [
                        'status' => 200,
                        'event' => $existingEvent,
                        'case' => $case,
                        'is_duplicate' => true,
                    ];
                }

                return [
                    'status' => 409,
                    'error' => [
                        'code' => 'event_conflict',
                        'message' => "Event key '{$payload['event_key']}' already exists with different payload.",
                    ],
                ];
            }

            // Expected state version check
            if ((int) $payload['expected_state_version'] !== (int) $case->state_version) {
                return [
                    'status' => 409,
                    'error' => [
                        'code' => 'version_conflict',
                        'message' => "Stale or conflicting state version. Expected: {$payload['expected_state_version']}, Current: {$case->state_version}.",
                    ],
                ];
            }

            // Transition validation
            $currentState = $case->state;
            $newStatus = $payload['status_code'];

            if ($currentState === 'awaiting_workflow') {
                if ($payload['event_type'] !== 'workflow_started' || $newStatus !== 'queued') {
                    return [
                        'status' => 422,
                        'error' => [
                            'code' => 'contract_invalid',
                            'message' => "Cases in awaiting_workflow can only transition to queued via workflow_started.",
                        ],
                    ];
                }
            } elseif (in_array($currentState, ['blocked', 'failed', 'cancelled'], true)) {
                return [
                    'status' => 409,
                    'error' => [
                        'code' => 'version_conflict',
                        'message' => "Case is in terminal state '{$currentState}'.",
                    ],
                ];
            }

            if ($payload['event_type'] === 'map_blocked') {
                $hasMapActivation = DB::table('v3_map_activations')
                    ->where('case_id', $caseId)
                    ->exists();
                if (!$hasMapActivation) {
                    return [
                        'status' => 422,
                        'error' => [
                            'code' => 'contract_invalid',
                            'message' => "Unproven map_blocked: No active map activation for case '{$caseId}'.",
                        ],
                    ];
                }
            }

            // Provenance checks for prompt milestones
            if (preg_match('/^prompt:work:(\d+):started$/', $payload['event_key'], $matches)) {
                $ordinal = (int) $matches[1];
                $hasWork = DB::table('v3_prompt_work_items')
                    ->where('case_id', $caseId)
                    ->where('work_ordinal', $ordinal)
                    ->exists();
                if (!$hasWork) {
                    return [
                        'status' => 422,
                        'error' => [
                            'code' => 'contract_invalid',
                            'message' => "Unproven prompt work started: No claimed work item for case '{$caseId}' work {$ordinal}.",
                        ],
                    ];
                }
            } elseif (preg_match('/^prompt:work:(\d+):originals$/', $payload['event_key'], $matches)) {
                $ordinal = (int) $matches[1];
                $hasCommittedWork = DB::table('v3_prompt_work_items')
                    ->where('case_id', $caseId)
                    ->where('work_ordinal', $ordinal)
                    ->whereNotNull('completion_ref')
                    ->where('state', 'receipt_committed')
                    ->exists();
                if (!$hasCommittedWork) {
                    return [
                        'status' => 422,
                        'error' => [
                            'code' => 'contract_invalid',
                            'message' => "Unproven prompt originals: No completed work item with receipt for case '{$caseId}' work {$ordinal}.",
                        ],
                    ];
                }
            } elseif ($payload['event_key'] === 'prompt:qualification:started') {
                $hasAwaiting = DB::table('v3_prompt_section_states')
                    ->where('case_id', $caseId)
                    ->where('status', 'awaiting_qualification')
                    ->exists();
                if (!$hasAwaiting) {
                    return [
                        'status' => 422,
                        'error' => [
                            'code' => 'contract_invalid',
                            'message' => "Unproven prompt qualification started: No section awaiting qualification for case '{$caseId}'.",
                        ],
                    ];
                }
            } elseif ($payload['event_key'] === 'prompt:qualification:completed') {
                $hasQualified = DB::table('v3_prompt_section_states')
                    ->where('case_id', $caseId)
                    ->where('status', 'qualified')
                    ->exists();
                $hasNonTerminal = DB::table('v3_prompt_section_states')
                    ->where('case_id', $caseId)
                    ->whereNotIn('status', ['qualified', 'blocked'])
                    ->exists();
                $hasAny = DB::table('v3_prompt_section_states')
                    ->where('case_id', $caseId)
                    ->exists();
                if (!$hasQualified || $hasNonTerminal || !$hasAny) {
                    return [
                        'status' => 422,
                        'error' => [
                            'code' => 'contract_invalid',
                            'message' => "Unproven prompt qualification completed: Sections are not fully qualified or terminal for case '{$caseId}'.",
                        ],
                    ];
                }
            } elseif ($payload['event_key'] === 'prompt:qualification:all_blocked') {
                $cursorComplete = DB::table('v3_lesson_cursors')
                    ->where('case_id', $caseId)
                    ->where('phase', 'complete')
                    ->exists();
                $hasAny = DB::table('v3_prompt_section_states')
                    ->where('case_id', $caseId)
                    ->exists();
                $hasUnblocked = DB::table('v3_prompt_section_states')
                    ->where('case_id', $caseId)
                    ->where('status', '!=', 'blocked')
                    ->exists();
                if (!$cursorComplete || !$hasAny || $hasUnblocked) {
                    return [
                        'status' => 422,
                        'error' => [
                            'code' => 'contract_invalid',
                            'message' => "Unproven prompt qualification all_blocked: Cursor not complete or unblocked sections remain for case '{$caseId}'.",
                        ],
                    ];
                }
            } elseif (preg_match('/^scene:request:([0-9a-fA-F-]+):started$/', $payload['event_key'], $matches)) {
                $reqId = $matches[1];
                $hasRequest = DB::table('v3_authoring_requests')
                    ->where('case_id', $caseId)
                    ->where('id', $reqId)
                    ->exists();
                if (!$hasRequest) {
                    return [
                        'status' => 422,
                        'error' => [
                            'code' => 'contract_invalid',
                            'message' => "Unproven scene request started: No authoring request for case '{$caseId}' request '{$reqId}'.",
                        ],
                    ];
                }
            } elseif (preg_match('/^scene:request:([0-9a-fA-F-]+):candidate$/', $payload['event_key'], $matches)) {
                $reqId = $matches[1];
                $hasCandidate = DB::table('v3_scene_candidates')
                    ->where('case_id', $caseId)
                    ->where('request_id', $reqId)
                    ->exists();
                if (!$hasCandidate) {
                    return [
                        'status' => 422,
                        'error' => [
                            'code' => 'contract_invalid',
                            'message' => "Unproven scene candidate: No scene candidate for case '{$caseId}' request '{$reqId}'.",
                        ],
                    ];
                }
            } elseif (preg_match('/^scene:check:([0-9a-fA-F-]+):started$/', $payload['event_key'], $matches)) {
                $candidateRef = $matches[1];
                $hasCheckAttempt = DB::table('v3_scene_check_attempts')
                    ->where('case_id', $caseId)
                    ->where('candidate_id', $candidateRef)
                    ->exists();
                if (! $hasCheckAttempt) {
                    return [
                        'status' => 422,
                        'error' => [
                            'code' => 'contract_invalid',
                            'message' => "Unproven scene check started: No check attempt for case '{$caseId}' candidate '{$candidateRef}'.",
                        ],
                    ];
                }
            } elseif (preg_match('/^scene:qualification:([0-9a-fA-F-]+):passed$/', $payload['event_key'], $matches)) {
                $candidateRef = $matches[1];
                $hasPassedQualification = DB::table('v3_scene_qualifications')
                    ->where('case_id', $caseId)
                    ->where('candidate_id', $candidateRef)
                    ->where('decision', 'pass')
                    ->exists();
                if (! $hasPassedQualification) {
                    return [
                        'status' => 422,
                        'error' => [
                            'code' => 'contract_invalid',
                            'message' => "Unproven scene qualification passed: No passing qualification for case '{$caseId}' candidate '{$candidateRef}'.",
                        ],
                    ];
                }
            } elseif ($payload['event_key'] === 'scene:qualification:all_blocked' && $payload['event_type'] === 'workflow_failed' && $payload['stage_code'] === 'scene_qualification') {
                $cursorComplete = DB::table('v3_lesson_cursors')
                    ->where('case_id', $caseId)
                    ->where('phase', 'complete')
                    ->exists();
                $hasNonTerminalPrompt = DB::table('v3_prompt_section_states')
                    ->where('case_id', $caseId)
                    ->whereNotIn('status', ['qualified', 'blocked'])
                    ->exists();
                $hasUnblockedCandidate = DB::table('v3_scene_candidate_states')
                    ->where('case_id', $caseId)
                    ->whereNotIn('status', ['blocked', 'superseded'])
                    ->exists();
                if (! $cursorComplete || $hasNonTerminalPrompt || $hasUnblockedCandidate) {
                    return [
                        'status' => 422,
                        'error' => [
                            'code' => 'contract_invalid',
                            'message' => "Unproven scene qualification all_blocked for case '{$caseId}'.",
                        ],
                    ];
                }
            } elseif ($payload['event_type'] === 'workflow_failed' && $payload['stage_code'] === 'scene_generation') {
                $cursorComplete = DB::table('v3_lesson_cursors')
                    ->where('case_id', $caseId)
                    ->where('phase', 'complete')
                    ->exists();
                $hasNonTerminalPrompt = DB::table('v3_prompt_section_states')
                    ->where('case_id', $caseId)
                    ->whereNotIn('status', ['qualified', 'blocked'])
                    ->exists();
                $passingCount = DB::table('v3_prompt_section_states')
                    ->where('case_id', $caseId)
                    ->where('status', 'qualified')
                    ->count();
                $blockedRequestsCount = DB::table('v3_authoring_requests')
                    ->where('case_id', $caseId)
                    ->where('state', 'blocked')
                    ->count();
                $candidateCount = DB::table('v3_scene_candidates')
                    ->where('case_id', $caseId)
                    ->count();

                if (! $cursorComplete || $hasNonTerminalPrompt || $passingCount === 0 || $blockedRequestsCount < $passingCount || $candidateCount > 0) {
                    return [
                        'status' => 422,
                        'error' => [
                            'code' => 'contract_invalid',
                            'message' => "Unproven scene authoring workflow failure for case '{$caseId}'.",
                        ],
                    ];
                }
            }

            $newSequence = $case->state_version + 1;

            $event = CaseEvent::forceCreate([
                'id' => (string) Str::uuid(),
                'case_id' => $case->id,
                'event_key' => $payload['event_key'],
                'payload_digest' => $payloadDigest,
                'sequence' => $newSequence,
                'workflow_id' => $payload['workflow_id'],
                'run_id' => $payload['run_id'],
                'event_type' => $payload['event_type'],
                'stage_code' => $payload['stage_code'],
                'status_code' => $payload['status_code'],
                'error_code' => $payload['error_code'] ?? null,
                'recorded_at' => now(),
            ]);

            // Update Case state and version
            $case->forceFill([
                'state' => $newStatus,
                'state_version' => $newSequence,
            ])->save();

            // Update Link projection
            $linkUpdates = [
                'stage_code' => $payload['stage_code'],
                'status_code' => $payload['status_code'],
                'error_code' => $payload['error_code'] ?? null,
                'last_event_at' => now(),
            ];

            if ($link->run_id === null) {
                $linkUpdates['run_id'] = $payload['run_id'];
            }
            if ($link->start_state !== 'closed') {
                $linkUpdates['start_state'] = 'started';
            }
            if ($link->started_at === null) {
                $linkUpdates['started_at'] = now();
            }

            $link->forceFill($linkUpdates)->save();

            return [
                'status' => 201,
                'event' => $event,
                'case' => $case,
                'is_duplicate' => false,
            ];
        });
    }, 50, function (\Throwable $e) {
        $msg = $e->getMessage();
        return str_contains($msg, 'database is locked')
            || str_contains($msg, 'busy')
            || str_contains($msg, 'Deadlock')
            || str_contains($msg, 'deadlock')
            || str_contains($msg, 'serialization')
            || str_contains($msg, 'Lock wait timeout');
    });
}

    public function computePayloadDigest(array $payload): string
    {
        $errorRep = array_key_exists('error_code', $payload) && $payload['error_code'] !== null
            ? $payload['error_code']
            : '-';

        $parts = [
            (string) ($payload['schema_version'] ?? 1),
            (string) ($payload['event_key'] ?? ''),
            (string) ($payload['case_id'] ?? ''),
            (string) ($payload['workflow_id'] ?? ''),
            (string) ($payload['run_id'] ?? ''),
            (string) ($payload['expected_state_version'] ?? 1),
            (string) ($payload['event_type'] ?? ''),
            (string) ($payload['stage_code'] ?? ''),
            (string) ($payload['status_code'] ?? ''),
            $errorRep,
        ];

        return hash('sha256', implode("\n", $parts));
    }

    protected function validatePayload(array $payload): void
    {
        if (($payload['schema_version'] ?? null) !== 1) {
            throw new \InvalidArgumentException('schema_version must be 1.');
        }

        $eventKey = $payload['event_key'] ?? '';
        if (! is_string($eventKey) || ! preg_match('/^[a-z0-9:_-]{1,128}$/', $eventKey)) {
            throw new \InvalidArgumentException('Invalid event_key.');
        }

        $caseId = $payload['case_id'] ?? '';
        if (! is_string($caseId) || ! Str::isUuid($caseId)) {
            throw new \InvalidArgumentException('Invalid case_id.');
        }

        $workflowId = $payload['workflow_id'] ?? '';
        if ($workflowId !== "v3-case-{$caseId}") {
            throw new \InvalidArgumentException('workflow_id does not match case_id.');
        }

        $runId = $payload['run_id'] ?? '';
        if (! is_string($runId) || empty($runId)) {
            throw new \InvalidArgumentException('Invalid run_id.');
        }

        $expectedVersion = $payload['expected_state_version'] ?? null;
        if (! is_int($expectedVersion) || $expectedVersion < 1) {
            throw new \InvalidArgumentException('expected_state_version must be positive integer.');
        }

        $eventType = $payload['event_type'] ?? '';
        if (! in_array($eventType, self::EVENT_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid event_type '{$eventType}'.");
        }

        $stageCode = $payload['stage_code'] ?? '';
        if (! in_array($stageCode, self::STAGE_CODES, true)) {
            throw new \InvalidArgumentException("Invalid stage_code '{$stageCode}'.");
        }

        $statusCode = $payload['status_code'] ?? '';
        if (! in_array($statusCode, self::STATUS_CODES, true)) {
            throw new \InvalidArgumentException("Invalid status_code '{$statusCode}'.");
        }

        $errorCode = $payload['error_code'] ?? null;
        if ($errorCode !== null && ! in_array($errorCode, self::ERROR_CODES, true)) {
            throw new \InvalidArgumentException("Invalid error_code '{$errorCode}'.");
        }

        if (in_array($statusCode, ['blocked', 'failed'], true) && empty($errorCode)) {
            throw new \InvalidArgumentException("error_code is mandatory for '{$statusCode}' status.");
        }

        if (in_array($statusCode, ['queued', 'running'], true) && $errorCode !== null) {
            throw new \InvalidArgumentException("error_code must be null for '{$statusCode}' status.");
        }

        if ($eventType === 'workflow_started') {
            if ($expectedVersion !== 1 || ! in_array($stageCode, ['extraction', 'sourcing'], true) || $statusCode !== 'queued' || $errorCode !== null) {
                throw new \InvalidArgumentException('workflow_started must have expected_version=1, stage=extraction|sourcing, status=queued, error=null.');
            }
        } elseif ($eventType === 'stage_started') {
            if ($statusCode !== 'running') {
                throw new \InvalidArgumentException('stage_started must have status=running.');
            }
        } elseif ($eventType === 'stage_completed') {
            if (! in_array($statusCode, ['queued', 'running'], true)) {
                throw new \InvalidArgumentException('stage_completed must have status=queued or running.');
            }
        } elseif ($eventType === 'workflow_failed') {
            if ($statusCode !== 'failed') {
                throw new \InvalidArgumentException('workflow_failed must have status=failed.');
            }
        } elseif ($eventType === 'source_blocked') {
            if (! in_array($stageCode, ['extraction', 'sourcing'], true) || $statusCode !== 'blocked') {
                throw new \InvalidArgumentException('source_blocked must have stage_code=extraction|sourcing and status=blocked.');
            }
            if (! in_array($errorCode, ['validation', 'provider_unavailable', 'internal', 'policy_denied'], true)) {
                throw new \InvalidArgumentException('source_blocked error_code must be validation, provider_unavailable, internal, or policy_denied.');
            }
        } elseif ($eventType === 'map_blocked') {
            if ($stageCode !== 'mapping' || $statusCode !== 'blocked') {
                throw new \InvalidArgumentException('map_blocked must have stage_code=mapping and status=blocked.');
            }
            if (! in_array($errorCode, ['validation', 'provider_unavailable', 'internal'], true)) {
                throw new \InvalidArgumentException('map_blocked error_code must be validation, provider_unavailable, or internal.');
            }
        }
    }

}
