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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class LessonIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_l12_complete_isolation_between_distinct_teachers(): void
    {
        DB::statement('PRAGMA foreign_keys = ON;');
        Storage::fake('v3_private');

        $teacher1 = AccessFixtures::createEnrolledTeacher('Teacher Isolation 1');
        $teacher2 = AccessFixtures::createEnrolledTeacher('Teacher Isolation 2');
        $admin = AccessFixtures::createEnrolledAdmin('Admin Isolation');

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

        $doc1 = Document::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $teacher1->id,
            'sha256' => hash('sha256', 'doc1'),
            'byte_size' => 2048,
            'page_count' => 2,
            'state' => 'ready',
            'storage_path' => 'accepted/doc1.pdf',
            'accepted_at' => now(),
        ]);

        // Create Case 1 for Teacher 1
        $case1 = ProcessingCase::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $teacher1->id,
            'document_id' => $doc1->id,
            'settings_version_id' => $settingsVersion->id,
            'idempotency_key' => (string) Str::uuid(),
            'action' => 'process',
            'state' => 'running',
            'state_version' => 8,
        ]);

        $srcAct1 = SourceActivation::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case1->id,
            'workflow_id' => "v3-case-{$case1->id}",
            'state' => 'accepted',
            'signal_accepted_at' => now(),
        ]);

        $ext1 = ExtractedSource::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case1->id,
            'activation_id' => $srcAct1->id,
            'document_id' => $doc1->id,
            'settings_version_id' => $settingsVersion->id,
            'status' => 'ready',
            'artifact_path' => 'extracted/doc1.txt',
            'text_sha256' => hash('sha256', 'text1'),
            'text_bytes' => 100,
            'page_count' => 2,
            'page_report' => [],
            'extractor_version' => '008-v1',
        ]);

        $bind1 = KnowledgeSourceBinding::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case1->id,
            'extracted_source_id' => $ext1->id,
            'adapter_kind' => 'notebooklm',
            'adapter_version' => '0.3.1',
            'notebook_title' => "v3-case-{$case1->id}",
            'source_title' => "v3-text-{$case1->id}",
            'notebook_id' => 'nb-iso-1',
            'source_id' => 'src-iso-1',
            'state' => 'bound',
            'text_sha256' => hash('sha256', 'text1'),
            'text_bytes' => 100,
            'transfer_status' => 'confirmed',
        ]);

        $mapAct1 = MapActivation::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case1->id,
            'binding_id' => $bind1->id,
            'workflow_id' => "v3-case-{$case1->id}",
            'state' => 'accepted',
            'signal_accepted_at' => now(),
        ]);

        $map1 = LessonMap::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case1->id,
            'binding_id' => $bind1->id,
            'activation_id' => $mapAct1->id,
            'state' => 'ready',
            'page_count' => 2,
            'work_count' => 1,
        ]);

        $node1 = LessonNode::forceCreate([
            'id' => (string) Str::uuid(),
            'map_id' => $map1->id,
            'kind' => 'lesson',
            'position_path' => '001',
            'ordinal_in_parent' => 1,
            'title' => 'Kinematics',
            'work_ordinal' => 1,
        ]);

        $query1 = LessonQuery::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case1->id,
            'map_id' => $map1->id,
            'kind' => 'first',
            'target_work_ordinal' => 1,
            'state' => 'recorded',
            'send_count' => 1,
            'request_sha256' => hash('sha256', 'iso-req-1'),
            'canonical_prompt_sha256' => hash('sha256', 'iso-prompt-1'),
            'conversation_in' => 'conv-iso-in',
            'conversation_out' => 'conv-iso-out',
            'sent_at' => now(),
            'recorded_at' => now(),
        ]);

        $sec1 = TeacherSection::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case1->id,
            'map_id' => $map1->id,
            'work_node_id' => $node1->id,
            'query_id' => $query1->id,
            'ordinal_in_work' => 1,
            'section_identity' => 'Teacher 1 Identity',
            'core_understanding' => 'Teacher 1 Concept',
            'visual_first_explanation' => 'T1 Visual',
            'visual_scene_script' => 'T1 Script',
            'dynamic_view_blueprint' => 'T1 Blueprint',
            'visual_learning_outcome' => 'T1 Outcome',
            'concept_check' => 'T1 Check',
            'conceptual_summary' => 'T1 Summary',
            'colloquial_egyptian_explanation' => 'شرح مدرس 1',
            'laws_if_any' => 'law 1',
            'real_life_example' => 'example 1',
            'first_prompt_sha256' => hash('sha256', ''),
            'answer_sha256' => hash('sha256', 'ans-1'),
        ]);

        // Teacher 2 must receive 404 when accessing Teacher 1's map or section
        $mapUrl = "/v3/teacher/cases/{$case1->id}/lesson-map";
        $this->actingAs($teacher2)->getJson($mapUrl)
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'resource_unavailable']]);

        $secUrl = "/v3/teacher/cases/{$case1->id}/sections/{$sec1->id}";
        $this->actingAs($teacher2)->getJson($secUrl)
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'resource_unavailable']]);
    }
}
