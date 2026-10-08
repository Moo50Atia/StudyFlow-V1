<?php

namespace Tests\Feature\V3\Evidence\fixtures;

use App\Models\User;
use App\Models\V3\ApprovedScene;
use App\Models\V3\CaseEvent;
use App\Models\V3\ReviewSectionState;
use App\Models\V3\TeacherReviewDecision;
use App\Models\V3\TeacherReviewItem;
use App\Models\V3\WorkflowLink;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\AuthoringFixtures;
use Tests\Support\V3\ReviewFixtures;

class EvidenceTestBuilder
{
    public static function createTeacher(?string $email = null): User
    {
        return AccessFixtures::createEnrolledTeacher('Teacher User '.Str::random(5), $email);
    }

    public static function createAdmin(?string $email = null): User
    {
        return AccessFixtures::createEnrolledAdmin('Admin User '.Str::random(5), $email);
    }

    public static function createCompleteCase(array $overrides = []): array
    {
        $env = AuthoringFixtures::createAuthoringEnvironment();
        $teacher = $env['teacher'];
        $case = $env['case'];
        $settings = $env['settingsVersion'];
        $document = $env['document'];
        $map = $env['map'];
        $binding = $map->knowledgeSourceBinding;
        $extractedSource = $binding->extractedSource;

        // Ensure extracted source storage file exists on v3_private disk matching its sha256
        $docContent = 'PDF binary content '.Str::random(8);
        \Illuminate\Support\Facades\DB::table('v3_documents')->where('id', $document->id)->update(['sha256' => hash('sha256', $docContent)]);
        Storage::disk('v3_private')->put($document->storage_path, $docContent);

        $extractedText = 'Extracted text content '.Str::random(8);
        \Illuminate\Support\Facades\DB::table('v3_extracted_sources')->where('id', $extractedSource->id)->update(['text_sha256' => hash('sha256', $extractedText)]);
        Storage::disk('v3_private')->put($extractedSource->artifact_path, $extractedText);

        // Create workflow link
        \Illuminate\Support\Facades\DB::table('v3_processing_cases')->where('id', $case->id)->update([
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(5),
        ]);
        $case->refresh();

        $workflowLink = WorkflowLink::forceCreate([
            'case_id' => $case->id,
            'workflow_id' => "v3-case-{$case->id}",
            'run_id' => 'run-evidence-test',
            'settings_version_id' => $settings->id,
            'stage_code' => 'terminal',
            'status_code' => 'approved',
            'start_state' => 'started',
            'started_at' => now()->subMinutes(30),
            'last_event_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(5),
        ]);

        // Case Events
        $event1 = CaseEvent::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'event_key' => 'evt-'.Str::uuid(),
            'payload_digest' => hash('sha256', 'evt-1'),
            'sequence' => 1,
            'workflow_id' => "v3-case-{$case->id}",
            'run_id' => 'run-evidence-test',
            'event_type' => 'workflow_started',
            'stage_code' => 'source_extraction',
            'status_code' => 'running',
            'recorded_at' => now()->subMinutes(29),
        ]);

        $event2 = CaseEvent::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'event_key' => 'evt-'.Str::uuid(),
            'payload_digest' => hash('sha256', 'evt-2'),
            'sequence' => 2,
            'workflow_id' => "v3-case-{$case->id}",
            'run_id' => 'run-evidence-test',
            'event_type' => 'scene_approved',
            'stage_code' => 'terminal',
            'status_code' => 'approved',
            'recorded_at' => now()->subMinutes(5),
        ]);

        // Qualified candidate setup
        $q = ReviewFixtures::createQualifiedCandidate($env, 1);
        $section = $q['section'];
        $candidate = $q['candidate'];
        $checkRun = $q['checkRun'];
        $sceneQualification = $q['qualification'];
        $promptVersion = $candidate->promptVersion;
        $authoringRequest = $candidate->request;
        $codingAttempt = $candidate->attempt;

        // 013 Review item
        $reviewItem = TeacherReviewItem::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'section_id' => $section->id,
            'candidate_id' => $candidate->id,
            'qualification_id' => $sceneQualification->id,
            'prompt_version_id' => $promptVersion->id,
            'candidate_version' => 1,
            'state_version' => 2,
            'status' => 'decided',
            'ready_at' => now()->subMinutes(15),
            'decided_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(15),
            'updated_at' => now()->subMinutes(5),
        ]);

        $decision = TeacherReviewDecision::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'review_item_id' => $reviewItem->id,
            'section_id' => $section->id,
            'candidate_id' => $candidate->id,
            'qualification_id' => $sceneQualification->id,
            'actor_user_id' => $teacher->id,
            'candidate_version' => 1,
            'expected_review_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', 'approve request'),
            'decision' => 'approve',
            'feedback_kind' => null,
            'note_path' => null,
            'note_sha256' => null,
            'note_bytes' => null,
            'created_at' => now()->subMinutes(5),
        ]);

        $approvedScene = ApprovedScene::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'section_id' => $section->id,
            'review_decision_id' => $decision->id,
            'candidate_id' => $candidate->id,
            'qualification_id' => $sceneQualification->id,
            'candidate_version' => 1,
            'approved_at' => now()->subMinutes(5),
        ]);

        $sectionState = ReviewSectionState::forceCreate([
            'section_id' => $section->id,
            'case_id' => $case->id,
            'current_review_item_id' => $reviewItem->id,
            'current_approval_id' => $approvedScene->id,
            'state_version' => 1,
            'created_at' => now()->subMinutes(15),
            'updated_at' => now()->subMinutes(5),
        ]);

        return [
            'teacher' => $teacher,
            'settings' => $settings,
            'document' => $document,
            'case' => $case,
            'events' => [$event1, $event2],
            'workflowLink' => $workflowLink,
            'extractedSource' => $extractedSource,
            'binding' => $binding,
            'lessonMap' => $map,
            'section' => $section,
            'promptVersion' => $promptVersion,
            'authoringRequest' => $authoringRequest,
            'codingAttempt' => $codingAttempt,
            'candidate' => $candidate,
            'checkRun' => $checkRun,
            'sceneQualification' => $sceneQualification,
            'reviewItem' => $reviewItem,
            'decision' => $decision,
            'approvedScene' => $approvedScene,
            'sectionState' => $sectionState,
        ];
    }
}
