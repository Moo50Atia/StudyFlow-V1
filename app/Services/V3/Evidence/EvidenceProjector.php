<?php

namespace App\Services\V3\Evidence;

use App\Models\V3\AdminCommand;
use App\Models\V3\ApprovedScene;
use App\Models\V3\AuthoringRequest;
use App\Models\V3\CaseEvent;
use App\Models\V3\CodingAttempt;
use App\Models\V3\DeletionAssessment;
use App\Models\V3\Document;
use App\Models\V3\ExtractedSource;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\LessonMap;
use App\Models\V3\PostapprovalCorrection;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptQualification;
use App\Models\V3\ReviewReminder;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCheckRun;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SceneQualification;
use App\Models\V3\SettingsVersion;
use App\Models\V3\TeacherReviewDecision;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\TeacherSection;
use Illuminate\Support\Carbon;

class EvidenceProjector
{
    public function __construct(
        protected EvidenceReferenceVerifier $verifier = new EvidenceReferenceVerifier
    ) {}

    /**
     * Project the timeline of events for an owned case.
     */
    public function projectCaseTimeline(ProcessingCase $case, int $page = 1, int $perPage = 20): array
    {
        $allEvents = [];
        $gaps = [];

        // 1. 007 Case events
        $caseEvents = CaseEvent::where('case_id', $case->id)
            ->orderBy('sequence')
            ->get();

        foreach ($caseEvents as $event) {
            $allEvents[] = [
                'kind' => 'case_event',
                'ref' => (string) $event->id,
                'state' => "{$event->stage_code}:{$event->status_code}",
                'recorded_at' => Carbon::parse($event->recorded_at)->toIso8601String(),
                'content_state' => 'not_applicable',
                'content_ref' => null,
                'event_type' => (string) $event->event_type,
            ];
        }

        // 2. 014 Deletion assessments
        $deletionAssessments = DeletionAssessment::where('case_id', $case->id)
            ->orderBy('assessed_at')
            ->get();

        foreach ($deletionAssessments as $assessment) {
            $allEvents[] = [
                'kind' => 'deletion_assessment',
                'ref' => (string) $assessment->id,
                'state' => (string) $assessment->status,
                'recorded_at' => Carbon::parse($assessment->assessed_at ?? $assessment->created_at)->toIso8601String(),
                'content_state' => 'not_applicable',
                'content_ref' => null,
                'event_type' => (string) $assessment->reason_code,
            ];
        }

        // 3. 014 Admin commands
        $adminCommands = AdminCommand::where('case_id', $case->id)
            ->orderBy('created_at')
            ->get();

        foreach ($adminCommands as $cmd) {
            $allEvents[] = [
                'kind' => 'admin_command',
                'ref' => (string) $cmd->id,
                'state' => (string) $cmd->status,
                'recorded_at' => Carbon::parse($cmd->created_at)->toIso8601String(),
                'content_state' => 'not_applicable',
                'content_ref' => null,
                'event_type' => (string) $cmd->command_type,
            ];
        }

        // 4. 014 Review reminders
        $reminders = ReviewReminder::where('case_id', $case->id)
            ->orderBy('created_at')
            ->get();

        foreach ($reminders as $rem) {
            $allEvents[] = [
                'kind' => 'review_reminder',
                'ref' => (string) $rem->id,
                'state' => (string) $rem->status,
                'recorded_at' => Carbon::parse($rem->created_at)->toIso8601String(),
                'content_state' => 'not_applicable',
                'content_ref' => null,
                'event_type' => 'review_reminder',
            ];
        }

        // Sort events ascending by recorded_at, then ref
        usort($allEvents, function ($a, $b) {
            $timeCmp = strcmp($a['recorded_at'], $b['recorded_at']);
            if ($timeCmp !== 0) {
                return $timeCmp;
            }

            return strcmp($a['ref'], $b['ref']);
        });

        // Current and historical approvals
        [$currentApprovalRefs, $historicalApprovalRefs] = $this->getApprovalRefs($case);

        $total = count($allEvents);
        $offset = ($page - 1) * $perPage;
        $pagedEvents = array_slice($allEvents, $offset, $perPage);
        $hasMore = ($offset + $perPage) < $total;

        return [
            'case_ref' => (string) $case->id,
            'as_of' => now()->toIso8601String(),
            'integrity' => count($gaps) > 0 ? 'incomplete' : 'complete',
            'current_approval_refs' => $currentApprovalRefs,
            'historical_approval_refs' => $historicalApprovalRefs,
            'events' => $pagedEvents,
            'gaps' => $gaps,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'has_more' => $hasMore,
            ],
        ];
    }

    /**
     * Project the complete graph for an approval.
     */
    public function projectApprovalGraph(ProcessingCase $case, string|ApprovedScene $approval, ?Carbon $asOf = null): ?array
    {
        $approvedScene = $approval instanceof ApprovedScene
            ? $approval
            : ApprovedScene::where('case_id', $case->id)
                ->where('id', (string) $approval)
                ->first();

        if (! $approvedScene) {
            return null;
        }

        $asOf = $asOf ?? now()->setTimezone('UTC');

        $nodes = [];
        $links = [];
        $gaps = [];

        [$currentApprovalRefs, $historicalApprovalRefs] = $this->getApprovalRefs($case);
        $isCurrent = in_array($approvedScene->id, $currentApprovalRefs, true);

        // 1. ApprovedScene Node
        $nodes[] = [
            'kind' => 'approved_scene',
            'ref' => (string) $approvedScene->id,
            'state' => $isCurrent ? 'current' : 'historical',
            'recorded_at' => Carbon::parse($approvedScene->approved_at)->toIso8601String(),
            'content_state' => 'not_applicable',
            'content_ref' => null,
        ];

        // 2. TeacherReviewDecision
        $decision = TeacherReviewDecision::where('id', $approvedScene->review_decision_id)->first();
        if (! $decision) {
            $gaps[] = ['code' => 'missing_row', 'parent_ref' => (string) $approvedScene->id];
        } elseif ($decision->case_id !== $case->id) {
            $gaps[] = ['code' => 'parent_mismatch', 'parent_ref' => (string) $approvedScene->id];
        } else {
            $nodes[] = [
                'kind' => 'teacher_review_decision',
                'ref' => (string) $decision->id,
                'state' => (string) $decision->decision,
                'recorded_at' => Carbon::parse($decision->created_at)->toIso8601String(),
                'content_state' => 'not_applicable',
                'content_ref' => null,
            ];
            $links[] = [
                'from_kind' => 'approved_scene',
                'from_ref' => (string) $approvedScene->id,
                'to_kind' => 'teacher_review_decision',
                'to_ref' => (string) $decision->id,
                'relation' => 'review_decision_id',
            ];
        }

        // 3. TeacherReviewItem
        $reviewItem = $decision ? TeacherReviewItem::where('id', $decision->review_item_id)->first() : null;
        if (! $reviewItem) {
            if ($decision) {
                $gaps[] = ['code' => 'missing_row', 'parent_ref' => (string) $decision->id];
            }
        } elseif ($reviewItem->case_id !== $case->id) {
            $gaps[] = ['code' => 'parent_mismatch', 'parent_ref' => (string) $decision->id];
        } else {
            $reviewUrl = route('v3.teacher.cases.reviews.show', ['case' => $case->id, 'review' => $reviewItem->id]);
            $nodes[] = [
                'kind' => 'teacher_review_item',
                'ref' => (string) $reviewItem->id,
                'state' => (string) $reviewItem->status,
                'recorded_at' => Carbon::parse($reviewItem->created_at)->toIso8601String(),
                'content_state' => 'not_applicable',
                'content_ref' => $reviewUrl,
            ];
            $links[] = [
                'from_kind' => 'teacher_review_decision',
                'from_ref' => (string) $decision->id,
                'to_kind' => 'teacher_review_item',
                'to_ref' => (string) $reviewItem->id,
                'relation' => 'review_item_id',
            ];
        }

        // 4. SceneQualification
        $qualificationId = $approvedScene->qualification_id;
        $sceneQual = SceneQualification::where('id', $qualificationId)->first();
        if (! $sceneQual) {
            $gaps[] = ['code' => 'missing_row', 'parent_ref' => (string) ($reviewItem?->id ?? $approvedScene->id)];
        } elseif ($sceneQual->case_id !== $case->id || ($reviewItem && $reviewItem->qualification_id !== $sceneQual->id)) {
            $gaps[] = ['code' => 'parent_mismatch', 'parent_ref' => (string) ($reviewItem?->id ?? $approvedScene->id)];
        } else {
            $nodes[] = [
                'kind' => 'scene_qualification',
                'ref' => (string) $sceneQual->id,
                'state' => (string) $sceneQual->verdict,
                'recorded_at' => Carbon::parse($sceneQual->created_at)->toIso8601String(),
                'content_state' => 'not_applicable',
                'content_ref' => null,
            ];
            if ($reviewItem) {
                $links[] = [
                    'from_kind' => 'teacher_review_item',
                    'from_ref' => (string) $reviewItem->id,
                    'to_kind' => 'scene_qualification',
                    'to_ref' => (string) $sceneQual->id,
                    'relation' => 'qualification_id',
                ];
            }
        }

        // 5. SceneCheckRun
        $checkRun = $sceneQual ? SceneCheckRun::where('id', $sceneQual->check_run_id)->first() : null;
        if (! $checkRun) {
            if ($sceneQual) {
                $gaps[] = ['code' => 'missing_row', 'parent_ref' => (string) $sceneQual->id];
            }
        } elseif ($checkRun->case_id !== $case->id || $checkRun->candidate_id !== $approvedScene->candidate_id) {
            $gaps[] = ['code' => 'parent_mismatch', 'parent_ref' => (string) $sceneQual->id];
        } else {
            $nodes[] = [
                'kind' => 'scene_check_run',
                'ref' => (string) $checkRun->id,
                'state' => (string) $checkRun->verdict,
                'recorded_at' => Carbon::parse($checkRun->created_at)->toIso8601String(),
                'content_state' => 'not_applicable',
                'content_ref' => null,
            ];
            $links[] = [
                'from_kind' => 'scene_qualification',
                'from_ref' => (string) $sceneQual->id,
                'to_kind' => 'scene_check_run',
                'to_ref' => (string) $checkRun->id,
                'relation' => 'check_run_id',
            ];
        }

        // 6. SceneCandidate
        $candidate = SceneCandidate::where('id', $approvedScene->candidate_id)->first();
        if (! $candidate) {
            $gaps[] = ['code' => 'missing_row', 'parent_ref' => (string) ($reviewItem?->id ?? $approvedScene->id)];
        } elseif ($candidate->case_id !== $case->id || ($reviewItem && $candidate->id !== $reviewItem->candidate_id)) {
            $gaps[] = ['code' => 'parent_mismatch', 'parent_ref' => (string) ($reviewItem?->id ?? $approvedScene->id)];
        } else {
            // Verify candidate manifest/artifact file on disk
            $candidateFileCheck = $this->verifier->verifyFile($candidate->manifest_path, $candidate->manifest_sha256);
            if ($candidateFileCheck['gap_code']) {
                $gaps[] = [
                    'code' => $candidateFileCheck['gap_code'],
                    'parent_ref' => (string) $candidate->id,
                ];
            }

            $nodes[] = [
                'kind' => 'scene_candidate',
                'ref' => (string) $candidate->id,
                'state' => 'completed',
                'recorded_at' => Carbon::parse($candidate->collected_at)->toIso8601String(),
                'content_state' => $candidateFileCheck['content_state'],
                'content_ref' => null,
            ];

            if ($reviewItem) {
                $links[] = [
                    'from_kind' => 'teacher_review_item',
                    'from_ref' => (string) $reviewItem->id,
                    'to_kind' => 'scene_candidate',
                    'to_ref' => (string) $candidate->id,
                    'relation' => 'candidate_id',
                ];
            }
        }

        // 7. AuthoringRequest
        $authoringRequest = $candidate ? AuthoringRequest::where('id', $candidate->request_id)->first() : null;
        if (! $authoringRequest) {
            if ($candidate) {
                $gaps[] = ['code' => 'missing_row', 'parent_ref' => (string) $candidate->id];
            }
        } elseif ($authoringRequest->case_id !== $case->id || $authoringRequest->section_id !== $candidate->section_id) {
            $gaps[] = ['code' => 'parent_mismatch', 'parent_ref' => (string) $candidate->id];
        } else {
            $nodes[] = [
                'kind' => 'authoring_request',
                'ref' => (string) $authoringRequest->id,
                'state' => 'completed',
                'recorded_at' => Carbon::parse($authoringRequest->created_at)->toIso8601String(),
                'content_state' => 'not_applicable',
                'content_ref' => null,
            ];
            $links[] = [
                'from_kind' => 'scene_candidate',
                'from_ref' => (string) $candidate->id,
                'to_kind' => 'authoring_request',
                'to_ref' => (string) $authoringRequest->id,
                'relation' => 'request_id',
            ];
        }

        // 8. CodingAttempt
        $codingAttempt = $candidate ? CodingAttempt::where('id', $candidate->attempt_id)->first() : null;
        if (! $codingAttempt) {
            if ($candidate) {
                $gaps[] = ['code' => 'missing_row', 'parent_ref' => (string) $candidate->id];
            }
        } elseif ($codingAttempt->case_id !== $case->id || ($authoringRequest && $codingAttempt->request_id !== $authoringRequest->id)) {
            $gaps[] = ['code' => 'parent_mismatch', 'parent_ref' => (string) $candidate->id];
        } else {
            $nodes[] = [
                'kind' => 'coding_attempt',
                'ref' => (string) $codingAttempt->id,
                'state' => (string) $codingAttempt->state,
                'recorded_at' => Carbon::parse($codingAttempt->created_at)->toIso8601String(),
                'content_state' => 'not_applicable',
                'content_ref' => null,
            ];
            $links[] = [
                'from_kind' => 'scene_candidate',
                'from_ref' => (string) $candidate->id,
                'to_kind' => 'coding_attempt',
                'to_ref' => (string) $codingAttempt->id,
                'relation' => 'attempt_id',
            ];
        }

        // 9. ScenePromptVersion
        $promptVersionId = $authoringRequest?->prompt_version_id ?? $candidate?->prompt_version_id;
        $promptVersion = $promptVersionId ? ScenePromptVersion::where('id', $promptVersionId)->first() : null;
        if (! $promptVersion) {
            if ($authoringRequest) {
                $gaps[] = ['code' => 'missing_row', 'parent_ref' => (string) $authoringRequest->id];
            }
        } elseif ($promptVersion->case_id !== $case->id || ($candidate && $promptVersion->id !== $candidate->prompt_version_id)) {
            $gaps[] = ['code' => 'parent_mismatch', 'parent_ref' => (string) ($authoringRequest?->id ?? $candidate?->id)];
        } else {
            $nodes[] = [
                'kind' => 'prompt_version',
                'ref' => (string) $promptVersion->id,
                'state' => 'accepted',
                'recorded_at' => Carbon::parse($promptVersion->created_at)->toIso8601String(),
                'content_state' => 'not_applicable',
                'content_ref' => null,
            ];
            if ($authoringRequest) {
                $links[] = [
                    'from_kind' => 'authoring_request',
                    'from_ref' => (string) $authoringRequest->id,
                    'to_kind' => 'prompt_version',
                    'to_ref' => (string) $promptVersion->id,
                    'relation' => 'prompt_version_id',
                ];
            }
        }

        // 10. PromptQualification
        $promptQualId = $authoringRequest?->qualification_id ?? $candidate?->qualification_id;
        $promptQual = $promptQualId ? PromptQualification::where('id', $promptQualId)->first() : null;
        if (! $promptQual) {
            if ($authoringRequest) {
                $gaps[] = ['code' => 'missing_row', 'parent_ref' => (string) $authoringRequest->id];
            }
        } elseif ($promptQual->case_id !== $case->id || ($promptVersion && $promptQual->prompt_version_id !== $promptVersion->id)) {
            $gaps[] = ['code' => 'parent_mismatch', 'parent_ref' => (string) ($authoringRequest?->id ?? $candidate?->id)];
        } else {
            $nodes[] = [
                'kind' => 'prompt_qualification',
                'ref' => (string) $promptQual->id,
                'state' => (string) $promptQual->verdict,
                'recorded_at' => Carbon::parse($promptQual->created_at)->toIso8601String(),
                'content_state' => 'not_applicable',
                'content_ref' => null,
            ];
            if ($authoringRequest) {
                $links[] = [
                    'from_kind' => 'authoring_request',
                    'from_ref' => (string) $authoringRequest->id,
                    'to_kind' => 'prompt_qualification',
                    'to_ref' => (string) $promptQual->id,
                    'relation' => 'qualification_id',
                ];
            }
        }

        // 11. TeacherSection
        $sectionId = $approvedScene->section_id;
        $section = TeacherSection::where('id', $sectionId)->first();
        if (! $section) {
            $gaps[] = ['code' => 'missing_row', 'parent_ref' => (string) $approvedScene->id];
        } elseif ($section->case_id !== $case->id || ($candidate && $candidate->section_id !== $section->id)) {
            $gaps[] = ['code' => 'parent_mismatch', 'parent_ref' => (string) $approvedScene->id];
        } else {
            $sectionUrl = route('v3.teacher.cases.sections.show', ['case' => $case->id, 'section' => $section->id]);
            $nodes[] = [
                'kind' => 'teacher_section',
                'ref' => (string) $section->id,
                'state' => 'ready',
                'recorded_at' => Carbon::parse($section->created_at)->toIso8601String(),
                'content_state' => 'not_applicable',
                'content_ref' => $sectionUrl,
            ];
            if ($authoringRequest) {
                $links[] = [
                    'from_kind' => 'authoring_request',
                    'from_ref' => (string) $authoringRequest->id,
                    'to_kind' => 'teacher_section',
                    'to_ref' => (string) $section->id,
                    'relation' => 'section_id',
                ];
            }
        }

        // 12. LessonMap
        $lessonMap = $section ? LessonMap::where('id', $section->map_id)->first() : null;
        if (! $lessonMap) {
            if ($section) {
                $gaps[] = ['code' => 'missing_row', 'parent_ref' => (string) $section->id];
            }
        } elseif ($lessonMap->case_id !== $case->id) {
            $gaps[] = ['code' => 'parent_mismatch', 'parent_ref' => (string) $section->id];
        } else {
            $mapUrl = route('v3.teacher.cases.lesson-map', ['case' => $case->id]);
            $nodes[] = [
                'kind' => 'lesson_map',
                'ref' => (string) $lessonMap->id,
                'state' => (string) $lessonMap->state,
                'recorded_at' => Carbon::parse($lessonMap->created_at)->toIso8601String(),
                'content_state' => 'not_applicable',
                'content_ref' => $mapUrl,
            ];
            $links[] = [
                'from_kind' => 'teacher_section',
                'from_ref' => (string) $section->id,
                'to_kind' => 'lesson_map',
                'to_ref' => (string) $lessonMap->id,
                'relation' => 'map_id',
            ];
        }

        // 13. KnowledgeSourceBinding
        $binding = $lessonMap ? KnowledgeSourceBinding::where('id', $lessonMap->binding_id)->first() : null;
        if (! $binding) {
            if ($lessonMap) {
                $gaps[] = ['code' => 'missing_row', 'parent_ref' => (string) $lessonMap->id];
            }
        } elseif ($binding->case_id !== $case->id) {
            $gaps[] = ['code' => 'parent_mismatch', 'parent_ref' => (string) $lessonMap->id];
        } else {
            $nodes[] = [
                'kind' => 'knowledge_source_binding',
                'ref' => (string) $binding->id,
                'state' => (string) $binding->state,
                'recorded_at' => Carbon::parse($binding->created_at)->toIso8601String(),
                'content_state' => 'not_applicable',
                'content_ref' => null,
            ];
            $links[] = [
                'from_kind' => 'lesson_map',
                'from_ref' => (string) $lessonMap->id,
                'to_kind' => 'knowledge_source_binding',
                'to_ref' => (string) $binding->id,
                'relation' => 'binding_id',
            ];
        }

        // 14. ExtractedSource or Direct Provenance
        $isDirect = ($case->pipeline_version === 'notebooklm_combined_v1') || ($binding && $binding->source_mode === 'original_pdf');

        if ($isDirect) {
            $frameworkVersionId = $case->notebook_framework_version_id ?? $binding?->framework_version_id;
            $frameworkVersion = $frameworkVersionId ? \App\Models\V3\NotebookFrameworkVersion::find($frameworkVersionId) : null;

            if ($frameworkVersion) {
                $fwCheck = $this->verifier->verifyFile($frameworkVersion->body_path, $frameworkVersion->body_sha256);
                if ($fwCheck['gap_code']) {
                    $gaps[] = [
                        'code' => $fwCheck['gap_code'],
                        'parent_ref' => (string) $frameworkVersion->id,
                    ];
                }

                $nodes[] = [
                    'kind' => 'notebook_framework_version',
                    'ref' => (string) $frameworkVersion->id,
                    'state' => 'approved',
                    'recorded_at' => Carbon::parse($frameworkVersion->approved_at ?? $frameworkVersion->created_at)->toIso8601String(),
                    'content_state' => $fwCheck['content_state'],
                    'content_ref' => null,
                ];

                if ($binding) {
                    $links[] = [
                        'from_kind' => 'knowledge_source_binding',
                        'from_ref' => (string) $binding->id,
                        'to_kind' => 'notebook_framework_version',
                        'to_ref' => (string) $frameworkVersion->id,
                        'relation' => 'framework_version_id',
                    ];
                }
            }

            $lessonNode = $section ? \App\Models\V3\LessonNode::where('id', $section->work_node_id)->first() : null;

            $bundle = \App\Models\V3\NotebookLessonBundle::where('case_id', $case->id)
                ->where('work_node_id', $lessonNode?->id ?? '')
                ->first()
                ?? \App\Models\V3\NotebookLessonBundle::where('case_id', $case->id)->first();

            if ($bundle) {
                $nodes[] = [
                    'kind' => 'notebook_lesson_bundle',
                    'ref' => (string) $bundle->id,
                    'state' => (string) $bundle->status,
                    'recorded_at' => Carbon::parse($bundle->created_at)->toIso8601String(),
                    'content_state' => 'not_applicable',
                    'content_ref' => null,
                ];

                if ($section) {
                    $links[] = [
                        'from_kind' => 'teacher_section',
                        'from_ref' => (string) $section->id,
                        'to_kind' => 'notebook_lesson_bundle',
                        'to_ref' => (string) $bundle->id,
                        'relation' => 'bundle_id',
                    ];
                }
            }
        } else {
            $extractedSource = $binding ? ExtractedSource::where('id', $binding->extracted_source_id)->first() : null;
            if (! $extractedSource) {
                if ($binding) {
                    $gaps[] = ['code' => 'missing_row', 'parent_ref' => (string) $binding->id];
                }
            } elseif ($extractedSource->case_id !== $case->id) {
                $gaps[] = ['code' => 'parent_mismatch', 'parent_ref' => (string) $binding->id];
            } else {
                $extractCheck = $this->verifier->verifyFile($extractedSource->artifact_path, $extractedSource->text_sha256);
                if ($extractCheck['gap_code']) {
                    $gaps[] = [
                        'code' => $extractCheck['gap_code'],
                        'parent_ref' => (string) $extractedSource->id,
                    ];
                }

                $nodes[] = [
                    'kind' => 'extracted_source',
                    'ref' => (string) $extractedSource->id,
                    'state' => (string) $extractedSource->status,
                    'recorded_at' => Carbon::parse($extractedSource->created_at)->toIso8601String(),
                    'content_state' => $extractCheck['content_state'],
                    'content_ref' => null,
                ];
                $links[] = [
                    'from_kind' => 'knowledge_source_binding',
                    'from_ref' => (string) $binding->id,
                    'to_kind' => 'extracted_source',
                    'to_ref' => (string) $extractedSource->id,
                    'relation' => 'extracted_source_id',
                ];
            }
        }

        // 15. Document
        $document = Document::where('id', $case->document_id)->first();
        if (! $document) {
            $gaps[] = ['code' => 'missing_row', 'parent_ref' => (string) $case->id];
        } else {
            $docCheck = $this->verifier->verifyFile($document->storage_path, $document->sha256);
            if ($docCheck['gap_code']) {
                $gaps[] = [
                    'code' => $docCheck['gap_code'],
                    'parent_ref' => (string) $document->id,
                ];
            }

            $docUrl = route('v3.teacher.documents.download', ['document' => $document->id]);
            $nodes[] = [
                'kind' => 'document',
                'ref' => (string) $document->id,
                'state' => 'ready',
                'recorded_at' => Carbon::parse($document->accepted_at ?? $document->created_at)->toIso8601String(),
                'content_state' => $docCheck['content_state'],
                'content_ref' => $docUrl,
            ];

            if (isset($extractedSource) && $extractedSource) {
                $links[] = [
                    'from_kind' => 'extracted_source',
                    'from_ref' => (string) $extractedSource->id,
                    'to_kind' => 'document',
                    'to_ref' => (string) $document->id,
                    'relation' => 'document_id',
                ];
            } elseif ($binding && $isDirect) {
                $links[] = [
                    'from_kind' => 'knowledge_source_binding',
                    'from_ref' => (string) $binding->id,
                    'to_kind' => 'document',
                    'to_ref' => (string) $document->id,
                    'relation' => 'document_id',
                ];
            }
        }

        // 16. SettingsVersion
        $settings = SettingsVersion::where('id', $case->settings_version_id)->first();
        if (! $settings) {
            $gaps[] = ['code' => 'missing_row', 'parent_ref' => (string) $case->id];
        } else {
            $nodes[] = [
                'kind' => 'settings_version',
                'ref' => (string) $settings->version,
                'state' => 'active',
                'recorded_at' => Carbon::parse($settings->published_at ?? $settings->created_at)->toIso8601String(),
                'content_state' => 'not_applicable',
                'content_ref' => null,
            ];
            $links[] = [
                'from_kind' => 'processing_case',
                'from_ref' => (string) $case->id,
                'to_kind' => 'settings_version',
                'to_ref' => (string) $settings->version,
                'relation' => 'settings_version_id',
            ];
        }

        // Branches: Sibling attempts and postapproval corrections
        if ($approvedScene) {
            $corrections = PostapprovalCorrection::where('approval_id', $approvedScene->id)->get();
            foreach ($corrections as $corr) {
                $nodes[] = [
                    'kind' => 'postapproval_correction',
                    'ref' => (string) $corr->id,
                    'state' => (string) $corr->feedback_kind,
                    'recorded_at' => Carbon::parse($corr->created_at)->toIso8601String(),
                    'content_state' => 'not_applicable',
                    'content_ref' => null,
                ];
                $links[] = [
                    'from_kind' => 'postapproval_correction',
                    'from_ref' => (string) $corr->id,
                    'to_kind' => 'approved_scene',
                    'to_ref' => (string) $approvedScene->id,
                    'relation' => 'approval_id',
                ];
            }
        }

        if ($authoringRequest) {
            $otherAttempts = CodingAttempt::where('request_id', $authoringRequest->id)
                ->where('id', '!=', $candidate?->attempt_id)
                ->get();

            foreach ($otherAttempts as $att) {
                $nodes[] = [
                    'kind' => 'coding_attempt',
                    'ref' => (string) $att->id,
                    'state' => (string) $att->state,
                    'recorded_at' => Carbon::parse($att->created_at)->toIso8601String(),
                    'content_state' => 'not_applicable',
                    'content_ref' => null,
                ];
                $links[] = [
                    'from_kind' => 'coding_attempt',
                    'from_ref' => (string) $att->id,
                    'to_kind' => 'authoring_request',
                    'to_ref' => (string) $authoringRequest->id,
                    'relation' => 'request_id',
                ];
            }
        }

        $integrity = count($gaps) > 0 ? 'incomplete' : 'complete';

        return [
            'case_ref' => (string) $case->id,
            'approval_ref' => (string) $approvedScene->id,
            'current' => $isCurrent,
            'as_of' => $asOf->toIso8601String(),
            'integrity' => $integrity,
            'nodes' => $nodes,
            'links' => $links,
            'gaps' => $gaps,
            'current_approval_refs' => $currentApprovalRefs,
            'historical_approval_refs' => $historicalApprovalRefs,
        ];
    }

    /**
     * Get current and historical approval refs for a case.
     *
     * @return array{0: array<string>, 1: array<string>}
     */
    protected function getApprovalRefs(ProcessingCase $case): array
    {
        $currentRefs = ReviewSectionState::where('case_id', $case->id)
            ->whereNotNull('current_approval_id')
            ->pluck('current_approval_id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();

        $allApprovalIds = ApprovedScene::where('case_id', $case->id)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();

        $historicalRefs = array_values(array_diff($allApprovalIds, $currentRefs));

        return [$currentRefs, $historicalRefs];
    }

    /**
     * Project full case graph for an owned case.
     */
    public function projectCaseGraph(ProcessingCase $case, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ?? now()->setTimezone('UTC');
        [$currentApprovalRefs, $historicalApprovalRefs] = $this->getApprovalRefs($case);
        $allApprovedScenes = ApprovedScene::where('case_id', $case->id)->get();

        $allNodes = [];
        $allLinks = [];
        $allGaps = [];

        foreach ($allApprovedScenes as $approvedScene) {
            $graph = $this->projectApprovalGraph($case, $approvedScene, $asOf);
            foreach ($graph['nodes'] as $node) {
                $key = "{$node['kind']}:{$node['ref']}";
                $allNodes[$key] = $node;
            }
            foreach ($graph['links'] as $link) {
                $linkKey = "{$link['from_kind']}:{$link['from_ref']}:{$link['to_kind']}:{$link['to_ref']}:{$link['relation']}";
                $allLinks[$linkKey] = $link;
            }
            foreach ($graph['gaps'] as $gap) {
                $gapKey = "{$gap['code']}:{$gap['parent_ref']}";
                $allGaps[$gapKey] = $gap;
            }
        }

        // If no approved scenes exist yet, project baseline document, settings, and events
        if ($allApprovedScenes->isEmpty()) {
            $doc = Document::find($case->document_id);
            if ($doc) {
                $docCheck = $this->verifier->verifyFile($doc->storage_path, $doc->sha256);
                $contentRef = $docCheck['content_state'] === 'available'
                    ? route('v3.teacher.documents.download', ['document' => $doc->id])
                    : null;
                $allNodes["document:{$doc->id}"] = [
                    'kind' => 'document',
                    'ref' => (string) $doc->id,
                    'state' => (string) $doc->state,
                    'recorded_at' => Carbon::parse($doc->created_at)->toIso8601String(),
                    'content_state' => $docCheck['content_state'],
                    'content_ref' => $contentRef,
                ];
            }
            $settings = SettingsVersion::find($case->settings_version_id);
            if ($settings) {
                $allNodes["settings_version:{$settings->id}"] = [
                    'kind' => 'settings_version',
                    'ref' => (string) $settings->id,
                    'state' => "v{$settings->version}",
                    'recorded_at' => Carbon::parse($settings->published_at)->toIso8601String(),
                    'content_state' => 'not_applicable',
                    'content_ref' => null,
                ];
            }
        }

        // Also add case events as nodes
        $caseEvents = CaseEvent::where('case_id', $case->id)->orderBy('sequence')->get();
        foreach ($caseEvents as $event) {
            $allNodes["case_event:{$event->id}"] = [
                'kind' => 'case_event',
                'ref' => (string) $event->id,
                'state' => "{$event->stage_code}:{$event->status_code}",
                'recorded_at' => Carbon::parse($event->recorded_at)->toIso8601String(),
                'content_state' => 'not_applicable',
                'content_ref' => null,
            ];
        }

        $nodes = array_values($allNodes);
        $links = array_values($allLinks);
        $gaps = array_values($allGaps);

        $integrity = count($gaps) > 0 ? 'incomplete' : 'complete';

        return [
            'case_ref' => (string) $case->id,
            'as_of' => $asOf->toIso8601String(),
            'integrity' => $integrity,
            'nodes' => $nodes,
            'links' => $links,
            'gaps' => $gaps,
            'current_approval_refs' => $currentApprovalRefs,
            'historical_approval_refs' => $historicalApprovalRefs,
        ];
    }
}
