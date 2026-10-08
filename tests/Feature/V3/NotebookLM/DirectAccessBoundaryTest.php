<?php

namespace Tests\Feature\V3\NotebookLM;

use App\Models\V3\Document;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\KnowledgeSourceFile;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\NotebookFrameworkVersion;
use App\Models\V3\ProcessingCase;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SourceActivation;
use App\Models\V3\TeacherSection;
use App\Services\V3\NotebookLM\FrameworkCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class DirectAccessBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected $ownerTeacher;
    protected $foreignTeacher;
    protected $admin;
    protected $settingsVersion;
    protected $frameworkVersion;
    protected $document;
    protected $processingCase;
    protected $binding;
    protected $section;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON;');
        Storage::fake('v3_private');

        $this->ownerTeacher = AccessFixtures::createEnrolledTeacher('Owner Teacher Boundary');
        $this->foreignTeacher = AccessFixtures::createEnrolledTeacher('Foreign Teacher Boundary');
        $this->admin = AccessFixtures::createEnrolledAdmin('Admin Boundary');

        SettingsFixtures::registerDefaultTemplates();
        $payload = SettingsFixtures::validPayload();
        $canonical = \App\Services\V3\Settings\CanonicalSettings::canonicalize($payload);
        $checksum = hash('sha256', \App\Services\V3\Settings\CanonicalSettings::encode($canonical));

        $this->settingsVersion = SettingsVersion::create([
            'version' => 1,
            'schema_version' => 1,
            'payload' => $canonical,
            'checksum' => $checksum,
            'published_by_user_id' => $this->admin->id,
            'published_at' => now(),
        ]);

        $frameworkBytes = file_get_contents(base_path('steps.txt'));
        $frameworkSha256 = hash('sha256', $frameworkBytes);
        Storage::disk('v3_private')->put('frameworks/steps.txt', $frameworkBytes);

        $this->frameworkVersion = NotebookFrameworkVersion::create([
            'id' => (string) Str::uuid(),
            'profile' => 'expanded-root-v1',
            'body_path' => 'frameworks/steps.txt',
            'body_sha256' => $frameworkSha256,
            'body_bytes' => strlen($frameworkBytes),
            'first_sha256' => hash('sha256', 'first-slice'),
            'second_sha256' => hash('sha256', 'second-slice'),
            'third_sha256' => hash('sha256', 'third-slice'),
            'response_format_sha256' => FrameworkCatalog::RESPONSE_FORMAT_SHA256,
            'approved_by_user_id' => $this->admin->id,
            'approved_at' => now(),
        ]);

        $docContent = "%PDF-1.4 boundary test pdf";
        $docSha256 = hash('sha256', $docContent);
        $docPath = 'accepted/boundary-test.pdf';
        Storage::disk('v3_private')->put($docPath, $docContent);

        $this->document = Document::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->ownerTeacher->id,
            'sha256' => $docSha256,
            'byte_size' => strlen($docContent),
            'page_count' => 10,
            'state' => 'ready',
            'storage_path' => $docPath,
            'accepted_at' => now(),
        ]);

        $this->processingCase = ProcessingCase::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->ownerTeacher->id,
            'document_id' => $this->document->id,
            'settings_version_id' => $this->settingsVersion->id,
            'idempotency_key' => (string) Str::uuid(),
            'action' => 'process',
            'pipeline_version' => 'notebooklm_combined_v1',
            'notebook_framework_version_id' => $this->frameworkVersion->id,
            'state' => 'running',
            'state_version' => 1,
        ]);

        $activation = SourceActivation::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->processingCase->id,
            'workflow_id' => "v3-case-{$this->processingCase->id}",
            'state' => 'accepted',
        ]);

        $this->binding = KnowledgeSourceBinding::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->processingCase->id,
            'source_mode' => 'original_pdf',
            'adapter_kind' => 'notebooklm_py',
            'adapter_version' => '0.8.4',
            'notebook_title' => "v3-case-{$this->processingCase->id}",
            'notebook_id' => 'nb-boundary-1',
            'state' => 'bound',
            'document_id' => $this->document->id,
            'framework_version_id' => $this->frameworkVersion->id,
            'chat_configuration_state' => 'configured',
            'response_format_sha256' => FrameworkCatalog::RESPONSE_FORMAT_SHA256,
        ]);

        $mapActivation = \App\Models\V3\MapActivation::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->processingCase->id,
            'binding_id' => $this->binding->id,
            'workflow_id' => "v3-case-{$this->processingCase->id}",
            'state' => 'accepted',
        ]);

        $map = LessonMap::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->processingCase->id,
            'binding_id' => $this->binding->id,
            'activation_id' => $mapActivation->id,
            'state' => 'ready',
            'coverage_mode' => 'not_requested_direct_pdf',
            'page_count' => 10,
            'work_count' => 1,
        ]);

        $node = LessonNode::forceCreate([
            'id' => (string) Str::uuid(),
            'map_id' => $map->id,
            'work_ordinal' => 1,
            'position_path' => '001.001',
            'ordinal_in_parent' => 1,
            'kind' => 'lesson',
            'title' => 'Functions Boundary',
        ]);

        $query = \App\Models\V3\LessonQuery::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->processingCase->id,
            'map_id' => $map->id,
            'binding_id' => $this->binding->id,
            'kind' => 'lesson_start',
            'target_work_ordinal' => 1,
            'expected_cursor_version' => 1,
            'state' => 'recorded',
            'request_sha256' => hash('sha256', 'req'),
            'answer_sha256' => hash('sha256', 'ans'),
            'conversation_out' => 'convo-boundary',
        ]);

        $this->section = TeacherSection::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->processingCase->id,
            'map_id' => $map->id,
            'work_node_id' => $node->id,
            'query_id' => $query->id,
            'ordinal_in_work' => 1,
            'section_identity' => 'Section 1 Boundary',
            'core_understanding' => 'Mapping inputs to outputs',
            'visual_first_explanation' => 'Visual mapping',
            'visual_scene_script' => 'Script',
            'dynamic_view_blueprint' => 'Blueprint',
            'visual_learning_outcome' => 'Outcome',
            'concept_check' => 'Check',
            'conceptual_summary' => 'Summary',
            'colloquial_egyptian_explanation' => 'شرح تجريبي للدرس',
            'laws_if_any' => 'None',
            'real_life_example' => 'Example',
            'first_prompt_sha256' => hash('sha256', 'p'),
            'answer_sha256' => hash('sha256', 'a'),
        ]);
    }

    public function test_n21_guest_is_denied_with_401_and_no_store_on_direct_routes(): void
    {
        $routes = [
            "/v3/teacher/cases/{$this->processingCase->id}/source-status",
            "/v3/teacher/cases/{$this->processingCase->id}/lesson-map",
            "/v3/teacher/cases/{$this->processingCase->id}/sections/{$this->section->id}",
            "/v3/teacher/cases/{$this->processingCase->id}/evidence",
            "/v3/teacher/documents/{$this->document->id}/download",
        ];

        foreach ($routes as $route) {
            $resp = $this->getJson($route);
            $this->assertSame(401, $resp->status(), "Route {$route} should return 401 for guest");
            $this->assertStringContainsString('no-store', (string) $resp->headers->get('Cache-Control'));
        }
    }

    public function test_n21_inactive_teacher_is_denied_with_403_access_denied_and_no_store(): void
    {
        DB::table('v3_account_access')
            ->where('user_id', $this->ownerTeacher->id)
            ->update(['is_active' => false]);

        $routes = [
            "/v3/teacher/cases/{$this->processingCase->id}/source-status",
            "/v3/teacher/cases/{$this->processingCase->id}/lesson-map",
            "/v3/teacher/cases/{$this->processingCase->id}/sections/{$this->section->id}",
            "/v3/teacher/cases/{$this->processingCase->id}/evidence",
        ];

        foreach ($routes as $route) {
            $resp = $this->actingAs($this->ownerTeacher)->getJson($route);
            $this->assertSame(403, $resp->status(), "Route {$route} should return 403 for inactive teacher");
            $this->assertSame('access_denied', $resp->json('error.code'));
            $this->assertStringContainsString('no-store', (string) $resp->headers->get('Cache-Control'));
        }
    }

    public function test_n21_admin_is_denied_with_403_from_teacher_direct_routes(): void
    {
        $routes = [
            "/v3/teacher/cases/{$this->processingCase->id}/source-status",
            "/v3/teacher/cases/{$this->processingCase->id}/lesson-map",
            "/v3/teacher/cases/{$this->processingCase->id}/sections/{$this->section->id}",
            "/v3/teacher/cases/{$this->processingCase->id}/evidence",
        ];

        foreach ($routes as $route) {
            $resp = $this->actingAs($this->admin)->getJson($route);
            $this->assertSame(403, $resp->status(), "Route {$route} should return 403 for admin on teacher route");
            $this->assertStringContainsString('no-store', (string) $resp->headers->get('Cache-Control'));
        }
    }

    public function test_n21_foreign_teacher_receives_404_resource_unavailable_identical_to_missing_case(): void
    {
        $routes = [
            "/v3/teacher/cases/{$this->processingCase->id}/source-status",
            "/v3/teacher/cases/{$this->processingCase->id}/lesson-map",
            "/v3/teacher/cases/{$this->processingCase->id}/sections/{$this->section->id}",
            "/v3/teacher/cases/{$this->processingCase->id}/evidence",
            "/v3/teacher/documents/{$this->document->id}/download",
        ];

        $randomCaseId = (string) Str::uuid();

        foreach ($routes as $route) {
            $foreignResp = $this->actingAs($this->foreignTeacher)->getJson($route);
            $this->assertSame(404, $foreignResp->status(), "Foreign teacher should get 404 for {$route}");
            $this->assertSame('resource_unavailable', $foreignResp->json('error.code'));
            $this->assertStringContainsString('no-store', (string) $foreignResp->headers->get('Cache-Control'));

            // Missing case/resource produces identical error payload and status
            if (str_contains($route, '/documents/')) {
                $missingRoute = "/v3/teacher/documents/{$randomCaseId}/download";
            } else {
                $missingRoute = str_replace($this->processingCase->id, $randomCaseId, $route);
            }
            $missingResp = $this->actingAs($this->ownerTeacher)->getJson($missingRoute);
            $this->assertSame(404, $missingResp->status());
            $this->assertSame('resource_unavailable', $missingResp->json('error.code'));
        }
    }

    public function test_n21_owner_teacher_can_access_source_status_with_original_pdf_mode(): void
    {
        $resp = $this->actingAs($this->ownerTeacher)->getJson("/v3/teacher/cases/{$this->processingCase->id}/source-status");
        $this->assertSame(200, $resp->status());
        $this->assertSame($this->processingCase->id, $resp->json('data.case_id'));
        $this->assertSame('bound', $resp->json('data.state'));
        $this->assertSame(10, $resp->json('data.page_count'));
        $this->assertSame(0, $resp->json('data.ocr_page_count'));
        $this->assertEmpty($resp->json('data.gaps'));
        $this->assertStringContainsString('private', (string) $resp->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $resp->headers->get('Cache-Control'));
    }
}
