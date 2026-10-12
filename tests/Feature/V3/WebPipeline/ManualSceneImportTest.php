<?php

namespace Tests\Feature\V3\WebPipeline;

use App\Models\Subject;
use App\Models\User;
use App\Models\V3\ManualSceneImport;
use App\Models\V3\PipelineCommand;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptSectionState;
use App\Models\V3\PromptWorkItem;
use App\Models\V3\SceneCandidate;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use App\Models\V3\TeacherSection;
use App\Services\V3\Authoring\ImportManualScene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\WebPipelineFixtures;
use Tests\TestCase;

class ManualSceneImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacherA;
    protected User $teacherB;
    protected User $admin;
    protected Subject $subject;
    protected SubjectRun $run;
    protected SubjectRunItem $item;
    protected ProcessingCase $case;
    protected TeacherSection $section;
    protected ScenePromptVersion $promptVersion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = WebPipelineFixtures::createAdmin();
        $this->teacherA = WebPipelineFixtures::createTeacherA();
        $this->teacherB = WebPipelineFixtures::createTeacherB();
        $this->subject = WebPipelineFixtures::createSubjectForTeacher($this->teacherA);

        $settingsId = DB::table('v3_settings_versions')->insertGetId([
            'version' => 1,
            'schema_version' => 1,
            'payload' => json_encode([
                'templates' => [
                    'scene' => [
                        'id' => 'tmpl-scene-v1',
                        'checksum' => hash('sha256', 'tmpl'),
                    ],
                ],
                'limits' => ['coding_attempts' => 3],
                'providers' => ['coding_order' => ['gemini']],
            ]),
            'checksum' => hash('sha256', '{}'),
            'published_by_user_id' => $this->admin->id,
            'published_at' => now(),
        ]);

        $frameworkId = (string) Str::uuid();
        DB::table('v3_notebook_framework_versions')->insert([
            'id' => $frameworkId,
            'profile' => 'expanded-root-v1',
            'body_path' => 'frameworks/' . $frameworkId . '.md',
            'body_sha256' => hash('sha256', 'mock_framework'),
            'body_bytes' => 14,
            'first_sha256' => hash('sha256', 'first'),
            'second_sha256' => hash('sha256', 'second'),
            'third_sha256' => hash('sha256', 'third'),
            'response_format_sha256' => hash('sha256', 'fmt'),
            'approved_by_user_id' => $this->admin->id,
            'approved_at' => now(),
            'created_at' => now(),
        ]);

        $this->run = SubjectRun::create([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacherA->id,
            'subject_id' => $this->subject->id,
            'origin' => 'web',
            'protocol' => 'notebooklm_per_pdf_web_v1',
            'settings_version_id' => $settingsId,
            'framework_version_id' => $frameworkId,
            'mode' => 'export_manual',
            'qualification_mode' => 'owner_waived_demo',
            'submission_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', 'run1'),
            'captured_settings_json' => '{}',
            'capture_sha256' => hash('sha256', '{}'),
            'state' => 'prompts_ready',
            'state_version' => 2,
            'total_pdfs' => 1,
            'total_lessons' => 1,
            'total_scenes' => 1,
            'last_event_sequence' => 1,
        ]);

        $doc = \App\Models\V3\Document::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacherA->id,
            'sha256' => hash('sha256', 'doc1'),
            'byte_size' => 1024,
            'page_count' => 1,
            'state' => 'ready',
            'storage_path' => 'accepted/d1.pdf',
            'accepted_at' => now(),
        ]);

        $this->item = SubjectRunItem::create([
            'id' => (string) Str::uuid(),
            'run_id' => $this->run->id,
            'owner_user_id' => $this->teacherA->id,
            'pdf_ordinal' => 1,
            'display_title' => 'Mechanics',
            'original_filename' => 'mechanics.pdf',
            'input_sha256' => hash('sha256', 'pdf1'),
            'input_bytes' => 1024,
            'state' => 'prompts_ready',
            'document_id' => $doc->id,
            'saved_lesson_count' => 1,
            'prompt_count' => 1,
            'state_version' => 1,
        ]);

        $this->case = ProcessingCase::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacherA->id,
            'document_id' => $doc->id,
            'settings_version_id' => $settingsId,
            'idempotency_key' => 'item:' . $this->item->id,
            'action' => 'web_subject',
            'state' => 'prompts_ready',
            'state_version' => 1,
            'pipeline_version' => 'notebooklm_per_pdf_web_v1',
            'notebook_framework_version_id' => $frameworkId,
            'subject_run_item_id' => $this->item->id,
        ]);
        $this->item->update(['case_id' => $this->case->id]);

        $srcAct = (string) Str::uuid();
        DB::table('v3_source_activations')->insert(['id' => $srcAct, 'case_id' => $this->case->id, 'workflow_id' => 'wf', 'state' => 'accepted']);
        $src = (string) Str::uuid();
        DB::table('v3_extracted_sources')->insert(['id' => $src, 'case_id' => $this->case->id, 'activation_id' => $srcAct, 'document_id' => $doc->id, 'settings_version_id' => $settingsId, 'status' => 'ready', 'page_report' => '{}', 'extractor_version' => 'v1']);
        $bind = (string) Str::uuid();
        DB::table('v3_knowledge_source_bindings')->insert(['id' => $bind, 'case_id' => $this->case->id, 'extracted_source_id' => $src, 'adapter_kind' => 'nb', 'adapter_version' => '1', 'notebook_title' => 't', 'source_title' => 's', 'state' => 'bound', 'text_sha256' => 't', 'text_bytes' => 1]);
        $mapAct = (string) Str::uuid();
        DB::table('v3_map_activations')->insert(['id' => $mapAct, 'case_id' => $this->case->id, 'binding_id' => $bind, 'workflow_id' => 'w', 'state' => 'ready']);
        $map = (string) Str::uuid();
        DB::table('v3_lesson_maps')->insert(['id' => $map, 'case_id' => $this->case->id, 'binding_id' => $bind, 'activation_id' => $mapAct, 'state' => 'ready', 'page_count' => 1]);
        $node = (string) Str::uuid();
        DB::table('v3_lesson_nodes')->insert(['id' => $node, 'map_id' => $map, 'kind' => 'l', 'position_path' => '001', 'ordinal_in_parent' => 1, 'title' => 'L1', 'work_ordinal' => 1]);
        $q = (string) Str::uuid();
        DB::table('v3_lesson_queries')->insert(['id' => $q, 'case_id' => $this->case->id, 'map_id' => $map, 'kind' => 'first', 'target_work_ordinal' => 1, 'state' => 'recorded', 'send_count' => 1, 'request_sha256' => 'r']);

        $secId = (string) Str::uuid();
        DB::table('v3_teacher_sections')->insert([
            'id' => $secId,
            'case_id' => $this->case->id,
            'map_id' => $map,
            'work_node_id' => $node,
            'query_id' => $q,
            'ordinal_in_work' => 1,
            'section_identity' => 'Harmonic Oscillator',
            'core_understanding' => 'Oscillator dynamics.',
            'first_prompt_sha256' => hash('sha256', 'f'),
            'answer_sha256' => hash('sha256', 'a'),
            'origin' => 'pipeline',
            'subject_run_item_id' => $this->item->id,
        ]);
        $this->section = TeacherSection::find($secId);

        $promptText = "Create an interactive harmonic oscillator simulation with spring stiffness slider.";
        $promptSha = hash('sha256', $promptText);
        $bodyPath = "prompt-versions/{$this->case->id}/{$secId}/v1_{$promptSha}.txt";
        Storage::disk('v3_private')->put($bodyPath, $promptText);

        $wi = PromptWorkItem::create([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'map_id' => $map,
            'work_node_id' => $node,
            'work_ordinal' => 1,
            'expected_cursor_version' => 1,
            'section_query_id' => $q,
            'state' => 'receipt_committed',
            'section_refs' => [$secId],
        ]);

        $this->promptVersion = ScenePromptVersion::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $this->case->id,
            'work_item_id' => $wi->id,
            'section_id' => $secId,
            'version_no' => 1,
            'origin' => 'third',
            'body_path' => $bodyPath,
            'body_sha256' => $promptSha,
            'body_bytes' => strlen($promptText),
            'scene_type' => 'Interactive 2D Canvas',
            'section_fields_sha256' => hash('sha256', 'fields'),
            'canonical_third_sha256' => hash('sha256', 'third'),
            'created_at' => now(),
        ]);

        PromptSectionState::create([
            'section_id' => $secId,
            'case_id' => $this->case->id,
            'current_prompt_version_id' => $this->promptVersion->id,
            'status' => 'awaiting_manual',
            'revision_count' => 0,
        ]);
    }

    public function test_valid_three_file_package_creates_candidate_with_manual_provenance(): void
    {
        $package = WebPipelineFixtures::makeValidStaticScenePackage('Harmonic Oscillator');
        $commandId = (string) Str::uuid();

        $response = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subject-runs/{$this->run->id}/scenes/{$this->section->id}/imports", [
                'command_id' => $commandId,
                'expected_version' => 2,
                'prompt_version_id' => $this->promptVersion->id,
                'package' => $package,
                'user_reported_provider' => 'Anthropic Claude',
                'user_reported_model' => 'claude-3-7-sonnet',
                'waiver_acknowledged' => true,
            ]);

        $response->assertStatus(201);
        $candidateId = $response->json('candidate_id');
        $importId = $response->json('import_id');

        $this->assertNotEmpty($candidateId);
        $this->assertNotEmpty($importId);

        // Verify Candidate
        $candidate = SceneCandidate::find($candidateId);
        $this->assertNotNull($candidate);
        $this->assertSame('manual_import', $candidate->origin);
        $this->assertSame('static_three_file_v1', $candidate->package_profile);
        $this->assertSame($this->promptVersion->id, $candidate->prompt_version_id);
        $this->assertNull($candidate->request_id, 'Manual import must have no authoring request ID');
        $this->assertNull($candidate->attempt_id, 'Manual import must have no CodingAttempt ID');
        $this->assertNull($candidate->qualification_id, 'Manual import must have no JEV qualification ID');

        // Verify ManualSceneImport
        $import = ManualSceneImport::find($importId);
        $this->assertNotNull($import);
        $this->assertSame('Anthropic Claude', $import->user_reported_provider);
        $this->assertSame('claude-3-7-sonnet', $import->user_reported_model);
        $this->assertSame($candidate->id, $import->candidate_id);
    }

    public function test_duplicate_import_submission_returns_idempotent_receipt(): void
    {
        $package = WebPipelineFixtures::makeValidStaticScenePackage('Harmonic Oscillator');
        $commandId = (string) Str::uuid();

        $payload = [
            'command_id' => $commandId,
            'expected_version' => 2,
            'prompt_version_id' => $this->promptVersion->id,
            'package' => $package,
            'waiver_acknowledged' => true,
        ];

        // First call
        $resp1 = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subject-runs/{$this->run->id}/scenes/{$this->section->id}/imports", $payload);
        $resp1->assertStatus(201);
        $cId1 = $resp1->json('candidate_id');

        // Second call with same command and payload
        $resp2 = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subject-runs/{$this->run->id}/scenes/{$this->section->id}/imports", $payload);
        $resp2->assertStatus(200);
        $this->assertSame($cId1, $resp2->json('candidate_id'));

        $this->assertSame(1, SceneCandidate::where('prompt_version_id', $this->promptVersion->id)->count());
    }

    public function test_import_with_conflicting_package_under_same_command_is_rejected(): void
    {
        $package1 = WebPipelineFixtures::makeValidStaticScenePackage('Title 1');
        $package2 = WebPipelineFixtures::makeValidStaticScenePackage('Title 2 Differing');
        $commandId = (string) Str::uuid();

        $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subject-runs/{$this->run->id}/scenes/{$this->section->id}/imports", [
                'command_id' => $commandId,
                'expected_version' => 2,
                'prompt_version_id' => $this->promptVersion->id,
                'package' => $package1,
                'waiver_acknowledged' => true,
            ])->assertStatus(201);

        $resp2 = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subject-runs/{$this->run->id}/scenes/{$this->section->id}/imports", [
                'command_id' => $commandId,
                'expected_version' => 2,
                'prompt_version_id' => $this->promptVersion->id,
                'package' => $package2,
                'waiver_acknowledged' => true,
            ]);

        $resp2->assertStatus(409);
    }

    public function test_malformed_package_is_rejected_with_422(): void
    {
        $malformed = WebPipelineFixtures::makeMalformedStaticScenePackage('missing_file');
        $commandId = (string) Str::uuid();

        $resp = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subject-runs/{$this->run->id}/scenes/{$this->section->id}/imports", [
                'command_id' => $commandId,
                'expected_version' => 2,
                'prompt_version_id' => $this->promptVersion->id,
                'package' => $malformed,
                'waiver_acknowledged' => true,
            ]);

        $resp->assertStatus(422);
        $this->assertSame('scene_package_invalid', $resp->json('error.code'));
    }

    public function test_import_against_obsolete_prompt_version_is_rejected(): void
    {
        $package = WebPipelineFixtures::makeValidStaticScenePackage();
        $obsoletePromptId = (string) Str::uuid(); // not the current prompt version

        $resp = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subject-runs/{$this->run->id}/scenes/{$this->section->id}/imports", [
                'command_id' => (string) Str::uuid(),
                'expected_version' => 2,
                'prompt_version_id' => $obsoletePromptId,
                'package' => $package,
                'waiver_acknowledged' => true,
            ]);

        $resp->assertStatus(409);
        $this->assertSame('prompt_conflict', $resp->json('error.code'));
    }

    public function test_non_owner_teacher_is_forbidden_from_importing_scene(): void
    {
        $package = WebPipelineFixtures::makeValidStaticScenePackage();

        $resp = $this->actingAs($this->teacherB)
            ->postJson("/v3/teacher/subject-runs/{$this->run->id}/scenes/{$this->section->id}/imports", [
                'command_id' => (string) Str::uuid(),
                'expected_version' => 2,
                'prompt_version_id' => $this->promptVersion->id,
                'package' => $package,
                'waiver_acknowledged' => true,
            ]);

        $this->assertTrue(in_array($resp->status(), [403, 404]));
    }

    public function test_oversized_file_in_package_is_rejected_with_422(): void
    {
        $oversizedContent = str_repeat('a', 1048577);
        $package = [
            'schema_version' => 1,
            'files' => [
                ['path' => 'index.html', 'content' => $oversizedContent],
                ['path' => 'styles.css', 'content' => 'body { color: black; }'],
                ['path' => 'script.js', 'content' => 'console.log("ok");'],
            ],
        ];

        $resp = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subject-runs/{$this->run->id}/scenes/{$this->section->id}/imports", [
                'command_id' => (string) Str::uuid(),
                'expected_version' => 2,
                'prompt_version_id' => $this->promptVersion->id,
                'package' => $package,
                'waiver_acknowledged' => true,
            ]);

        $resp->assertStatus(422);
        $this->assertSame('scene_package_invalid', $resp->json('error.code'));
        $this->assertStringContainsString('exceeds the maximum limit', $resp->json('error.message'));
    }

    public function test_extra_file_in_package_is_rejected_with_422(): void
    {
        $package = [
            'schema_version' => 1,
            'files' => [
                ['path' => 'index.html', 'content' => '<div>Scene</div>'],
                ['path' => 'styles.css', 'content' => 'body { color: black; }'],
                ['path' => 'script.js', 'content' => 'console.log("ok");'],
                ['path' => 'extra.txt', 'content' => 'extra disallowed file'],
            ],
        ];

        $resp = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subject-runs/{$this->run->id}/scenes/{$this->section->id}/imports", [
                'command_id' => (string) Str::uuid(),
                'expected_version' => 2,
                'prompt_version_id' => $this->promptVersion->id,
                'package' => $package,
                'waiver_acknowledged' => true,
            ]);

        $resp->assertStatus(422);
        $this->assertSame('scene_package_invalid', $resp->json('error.code'));
        $this->assertStringContainsString('Package must contain exactly 3 files', $resp->json('error.message'));
    }

    public function test_path_traversal_in_package_is_rejected_with_422(): void
    {
        $package = [
            'schema_version' => 1,
            'files' => [
                ['path' => '../index.html', 'content' => '<div>Traversal</div>'],
                ['path' => 'styles.css', 'content' => 'body { color: black; }'],
                ['path' => 'script.js', 'content' => 'console.log("ok");'],
            ],
        ];

        $resp = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subject-runs/{$this->run->id}/scenes/{$this->section->id}/imports", [
                'command_id' => (string) Str::uuid(),
                'expected_version' => 2,
                'prompt_version_id' => $this->promptVersion->id,
                'package' => $package,
                'waiver_acknowledged' => true,
            ]);

        $resp->assertStatus(422);
        $this->assertSame('scene_package_invalid', $resp->json('error.code'));
        $this->assertStringContainsString('Disallowed or invalid file path', $resp->json('error.message'));
    }

    public function test_contracted_three_file_package_preserves_exact_bytes_and_manifest(): void
    {
        $html = '<!DOCTYPE html><html><body><div id="app">Test App</div></body></html>';
        $css = 'body { margin: 0; background: #000; }';
        $js = 'window.addEventListener("load", () => { console.log("loaded"); });';

        $package = [
            'schema_version' => 1,
            'files' => [
                ['path' => 'index.html', 'content' => $html],
                ['path' => 'styles.css', 'content' => $css],
                ['path' => 'script.js', 'content' => $js],
            ],
        ];

        $resp = $this->actingAs($this->teacherA)
            ->postJson("/v3/teacher/subject-runs/{$this->run->id}/scenes/{$this->section->id}/imports", [
                'command_id' => (string) Str::uuid(),
                'expected_version' => 2,
                'prompt_version_id' => $this->promptVersion->id,
                'package' => $package,
                'waiver_acknowledged' => true,
            ]);

        $resp->assertStatus(201);
        $candidate = SceneCandidate::findOrFail($resp->json('candidate_id'));

        $this->assertSame(3, $candidate->file_count);
        $expectedBytes = strlen($html) + strlen($css) + strlen($js);
        $this->assertSame($expectedBytes, $candidate->total_bytes);

        $disk = Storage::disk('v3_private');
        $this->assertTrue($disk->exists($candidate->manifest_path));
        $manifest = json_decode($disk->get($candidate->manifest_path), true);
        $this->assertSame(3, count($manifest['files']));
        $this->assertSame(hash('sha256', $html), $manifest['files'][0]['sha256']);
        $this->assertSame(hash('sha256', $css), $manifest['files'][1]['sha256']);
        $this->assertSame(hash('sha256', $js), $manifest['files'][2]['sha256']);
    }
}
