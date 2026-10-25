<?php

namespace App\Services\V3\WebPipeline;

use App\Models\Lecture;
use App\Models\V3\Document;
use App\Models\V3\LecturePipelineLink;
use App\Models\V3\ProcessingCase;
use App\Models\V3\MapActivation;
use App\Models\V3\SourceActivation;
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use App\Models\V3\UploadAttempt;
use App\Services\V3\Intake\IntakeCoordinator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AcceptRunItem
{
    public function __construct(
        protected IntakeCoordinator $intakeCoordinator
    ) {}

    /**
     * Process intake for a staged SubjectRunItem, committing an accepted unchanged
     * private document, case, and lecture, or marking the item rejected.
     *
     * @param SubjectRunItem $item
     * @return array{outcome: string, safe_code?: string, lecture_id?: int, item_id?: string}
     */
    public function accept(SubjectRunItem $item): array
    {
        // A worker may retry after receiving a lost HTTP acknowledgment. Reuse
        // the already committed intake rather than creating another lecture,
        // case, or document association.
        $item->refresh();
        if ($item->document_id && $item->lecture_id && $item->case_id) {
            $document = Document::find($item->document_id);
            $store = app(\App\Services\V3\Intake\PrivatePdfStore::class);
            if ($document && $document->state === 'ready'
                && $store->exists($document->storage_path)
                && $store->size($document->storage_path) === (int) $document->byte_size
                && $store->sha256($document->storage_path) === $document->sha256) {
                return [
                    'outcome' => 'completed',
                    'item_id' => $item->id,
                    'lecture_id' => $item->lecture_id,
                    'duplicate' => true,
                ];
            }

            return ['outcome' => 'failed', 'safe_code' => 'storage_unavailable', 'item_id' => $item->id];
        }

        if (! $item->upload_attempt_id) {
            return [
                'outcome' => 'failed',
                'safe_code' => 'artifact_missing',
            ];
        }

        // 1. Process attempt through existing IntakeCoordinator
        $this->intakeCoordinator->processAttempt($item->upload_attempt_id);

        $attempt = UploadAttempt::find($item->upload_attempt_id);
        if (! $attempt) {
            return [
                'outcome' => 'failed',
                'safe_code' => 'artifact_missing',
            ];
        }

        // 2. Branch on attempt outcome
        if ($attempt->state === 'accepted' && $attempt->document_id) {
            return DB::transaction(function () use ($item, $attempt) {
                $doc = Document::find($attempt->document_id);
                $run = SubjectRun::find($item->run_id);

                // Create Lecture (never set public pdf_path)
                $lecture = Lecture::create([
                    'subject_id' => $run->subject_id,
                    'title' => $item->display_title,
                    'pdf_path' => null,
                ]);

                // Create LecturePipelineLink
                LecturePipelineLink::create([
                    'lecture_id' => $lecture->id,
                    'item_id' => $item->id,
                    'owner_user_id' => $item->owner_user_id,
                    'protocol' => $run->protocol,
                ]);

                // Create ProcessingCase
                $caseId = (string) Str::uuid();
                $case = ProcessingCase::forceCreate([
                    'id' => $caseId,
                    'owner_user_id' => $item->owner_user_id,
                    'document_id' => $doc->id,
                    'settings_version_id' => $run->settings_version_id,
                    'idempotency_key' => 'web_item:' . $item->id,
                    'action' => 'web_subject',
                    'state' => 'staged',
                    'state_version' => 1,
                    // Reuse the existing direct-PDF lesson query/map/bundle
                    // persistence contract for each item in a web run.
                    'pipeline_version' => 'notebooklm_combined_v1',
                    'notebook_framework_version_id' => $run->framework_version_id,
                    'subject_run_item_id' => $item->id,
                ]);

                // Each PDF keeps its own case/map/cursor while sharing the
                // single run notebook. Provider IDs stay in Laravel's private
                // lineage tables and never enter Temporal history.
                $binding = app(\App\Services\V3\NotebookLM\DirectSourceCoordinator::class)->prepare($case);
                $notebook = $run->notebookInstance;
                if ($notebook) {
                    $binding->notebook_instance_id = $notebook->id;
                    $binding->subject_run_item_id = $item->id;
                    $binding->save();

                    // The run framework is uploaded once. New per-PDF cases
                    // attach the verified shared source receipt instead of
                    // asking NotebookLM to upload it again.
                    if ($notebook->provider_framework_source_id) {
                        $frameworkFile = \App\Models\V3\KnowledgeSourceFile::where('binding_id', $binding->id)
                            ->where('role', 'framework_steps')->firstOrFail();
                        $frameworkFile->update([
                            'provider_source_id' => $notebook->provider_framework_source_id,
                            'state' => 'ready',
                            'registered_at' => now(),
                            'ready_at' => now(),
                        ]);
                    }
                }

                SourceActivation::forceCreate([
                    'id' => (string) Str::uuid(),
                    'case_id' => $case->id,
                    'workflow_id' => 'web-' . $case->id,
                    'state' => 'accepted',
                    'signal_attempts' => 0,
                ]);

                MapActivation::forceCreate([
                    'id' => (string) Str::uuid(),
                    'case_id' => $case->id,
                    'binding_id' => $binding->id,
                    'workflow_id' => 'web-' . $case->id,
                    'state' => 'accepted',
                    'signal_attempts' => 0,
                ]);

                // Advance item to accepted
                $item->document_id = $doc->id;
                $item->accepted_input_ref = $doc->id;
                $item->lecture_id = $lecture->id;
                $item->case_id = $case->id;
                $item->state = 'accepted';
                $item->safe_code = null;
                $item->state_version = $item->state_version + 1;
                $item->save();

                return [
                    'outcome' => 'completed',
                    'item_id' => $item->id,
                    'lecture_id' => $lecture->id,
                ];
            });
        }

        // Attempt was rejected or blocked
        return DB::transaction(function () use ($item, $attempt) {
            $reason = $attempt->reason_code ?? 'rejected';

            $item->state = 'rejected';
            $item->outcome = 'failed';
            $item->safe_code = $reason;
            $item->state_version = $item->state_version + 1;
            $item->save();

            return [
                'outcome' => 'failed',
                'safe_code' => $reason,
                'item_id' => $item->id,
            ];
        });
    }
}
