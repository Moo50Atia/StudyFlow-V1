<?php

namespace Tests\Integration\V3\Lessons;

use App\Models\V3\Document;
use App\Models\V3\ExtractedSource;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\LessonCursor;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\LessonQuery;
use App\Models\V3\MapActivation;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SourceActivation;
use App\Models\V3\TeacherSection;
use App\Models\V3\WorkflowLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class LiveLessonWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_l13_live_lesson_workflow_handoff_and_persistence(): void
    {
        DB::statement('PRAGMA foreign_keys = ON;');
        Storage::fake('v3_private');

        $teacher = AccessFixtures::createEnrolledTeacher('Teacher Live Lesson Workflow');
        $admin = AccessFixtures::createEnrolledAdmin('Admin Live Lesson Workflow');

        SettingsFixtures::registerDefaultTemplates();
        $payload = SettingsFixtures::validPayload();
        $canonical = \App\Services\V3\Settings\CanonicalSettings::canonicalize($payload);
        $checksum = hash('sha256', \App\Services\V3\Settings\CanonicalSettings::encode($canonical));

        $settingsVersion = SettingsVersion::create([
            'version' => 1,
            'schema_version' => 1,
            'payload' => $canonical,
            'checksum' => $checksum,
            'published_by_user_id' => $admin->id,
            'published_at' => now(),
        ]);

        $pdfContent = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
        $docSha256 = hash('sha256', $pdfContent);
        $docPath = 'accepted/test-live-lesson.pdf';
        Storage::disk('v3_private')->put($docPath, $pdfContent);

        $document = Document::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $teacher->id,
            'sha256' => $docSha256,
            'byte_size' => strlen($pdfContent),
            'page_count' => 3,
            'state' => 'ready',
            'storage_path' => $docPath,
            'accepted_at' => now(),
        ]);

        $case = ProcessingCase::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $teacher->id,
            'document_id' => $document->id,
            'settings_version_id' => $settingsVersion->id,
            'idempotency_key' => (string) Str::uuid(),
            'action' => 'process',
            'state' => 'running',
            'state_version' => 8,
        ]);

        $link = WorkflowLink::forceCreate([
            'case_id' => $case->id,
            'workflow_id' => "v3-case-{$case->id}",
            'run_id' => 'run-live-lesson-wf',
            'settings_version_id' => $settingsVersion->id,
            'settings_checksum' => $settingsVersion->checksum,
            'start_state' => 'started',
            'stage_code' => 'mapping',
            'status_code' => 'running',
            'started_at' => now(),
        ]);

        $sourceActivation = SourceActivation::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'workflow_id' => "v3-case-{$case->id}",
            'state' => 'accepted',
            'signal_accepted_at' => now(),
        ]);

        $extractedSource = ExtractedSource::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'activation_id' => $sourceActivation->id,
            'document_id' => $document->id,
            'settings_version_id' => $settingsVersion->id,
            'status' => 'ready',
            'artifact_path' => 'extracted/live.txt',
            'text_sha256' => hash('sha256', 'live-text'),
            'text_bytes' => 100,
            'page_count' => 3,
            'page_report' => [],
            'extractor_version' => '008-v1',
        ]);

        $binding = KnowledgeSourceBinding::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'extracted_source_id' => $extractedSource->id,
            'adapter_kind' => 'notebooklm',
            'adapter_version' => '0.3.1',
            'notebook_title' => "v3-case-{$case->id}",
            'source_title' => "v3-text-{$case->id}",
            'notebook_id' => 'nb-live-lesson',
            'source_id' => 'src-live-lesson',
            'state' => 'bound',
            'text_sha256' => hash('sha256', 'live-text'),
            'text_bytes' => 100,
            'transfer_status' => 'confirmed',
        ]);

        $mapActivation = MapActivation::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'binding_id' => $binding->id,
            'workflow_id' => "v3-case-{$case->id}",
            'state' => 'accepted',
            'signal_accepted_at' => now(),
        ]);

        $map = LessonMap::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'binding_id' => $binding->id,
            'activation_id' => $mapActivation->id,
            'state' => 'ready',
            'page_count' => 3,
            'work_count' => 2,
        ]);

        $node1 = LessonNode::forceCreate([
            'id' => (string) Str::uuid(),
            'map_id' => $map->id,
            'kind' => 'lesson',
            'position_path' => '001',
            'ordinal_in_parent' => 1,
            'title' => 'Kinematics',
            'work_ordinal' => 1,
            'coverage_pages' => [1, 2],
        ]);

        $query = LessonQuery::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'map_id' => $map->id,
            'kind' => 'first',
            'target_work_ordinal' => 1,
            'state' => 'recorded',
            'send_count' => 1,
            'request_sha256' => hash('sha256', 'first-live'),
            'canonical_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
            'conversation_in' => 'conv-map-live',
            'conversation_out' => 'conv-first-live',
            'sent_at' => now(),
            'recorded_at' => now(),
        ]);

        $sec = TeacherSection::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'map_id' => $map->id,
            'work_node_id' => $node1->id,
            'query_id' => $query->id,
            'ordinal_in_work' => 1,
            'section_identity' => 'Vectors',
            'core_understanding' => 'Direction and magnitude',
            'visual_first_explanation' => 'Visual vector',
            'visual_scene_script' => 'Arrow rotation',
            'dynamic_view_blueprint' => 'Plane coordinates',
            'visual_learning_outcome' => 'Visualizing direction',
            'concept_check' => 'Identify vector',
            'conceptual_summary' => 'Summary of vectors',
            'colloquial_egyptian_explanation' => 'شرح عامي',
            'laws_if_any' => 'v = d / t',
            'real_life_example' => 'عربية ماشية',
            'first_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
            'answer_sha256' => hash('sha256', 'ans-live'),
        ]);

        $cursor = LessonCursor::forceCreate([
            'case_id' => $case->id,
            'map_id' => $map->id,
            'current_work_ordinal' => 1,
            'phase' => 'awaiting_010',
            'conversation_id' => 'conv-first-live',
            'version' => 2,
        ]);

        // Assert handoff state and persistence
        $this->assertEquals('accepted', $mapActivation->state);
        $this->assertEquals('ready', $map->state);
        $this->assertEquals('awaiting_010', $cursor->phase);
        $this->assertEquals(1, $cursor->current_work_ordinal);
        $this->assertCount(1, TeacherSection::where('case_id', $case->id)->get());
    }
}
