<?php

namespace Tests\Feature\V3\WebPipeline;

use App\Models\Subject;
use App\Models\User;
use App\Models\V3\NotebookFrameworkVersion;
use App\Models\V3\PipelineCommand;
use App\Models\V3\PipelineEvent;
use App\Models\V3\PipelineOutbox;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use App\Services\V3\NotebookLM\FrameworkCatalog;
use App\Services\V3\WebPipeline\RunEventStore;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class ProgressProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;
    protected User $otherTeacher;
    protected User $student;
    protected User $admin;
    protected Subject $subject;
    protected SettingsVersion $settingsVersion;
    protected NotebookFrameworkVersion $frameworkVersion;
    protected SubjectRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON;');
        Storage::fake('v3_private');

        $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Progress');
        $this->otherTeacher = AccessFixtures::createEnrolledTeacher('Teacher Other');
        $this->student = AccessFixtures::createStudent('Student Progress');
        $this->admin = AccessFixtures::createEnrolledAdmin('Admin Progress');

        $this->subject = Subject::forceCreate([
            'name' => 'Physics Operations',
            'description' => 'Test subject for operations',
        ]);

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
            'first_sha256' => hash('sha256', 'first'),
            'second_sha256' => hash('sha256', 'second'),
            'third_sha256' => hash('sha256', 'third'),
            'response_format_sha256' => FrameworkCatalog::RESPONSE_FORMAT_SHA256,
            'approved_by_user_id' => $this->admin->id,
            'approved_at' => now(),
        ]);

        $this->run = SubjectRun::create([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'origin' => 'web',
            'protocol' => 'notebooklm_per_pdf_web_v1',
            'settings_version_id' => $this->settingsVersion->id,
            'framework_version_id' => $this->frameworkVersion->id,
            'mode' => 'export_manual',
            'qualification_mode' => 'owner_waived_demo',
            'submission_key' => (string) Str::uuid(),
            'request_sha256' => hash('sha256', 'sub1'),
            'captured_settings_json' => json_encode(['mode' => 'export_manual']),
            'capture_sha256' => hash('sha256', json_encode(['mode' => 'export_manual'])),
            'state' => 'running',
            'state_version' => 1,
            'total_pdfs' => 2,
            'total_lessons' => null, // Unknown total
            'total_scenes' => null,  // Unknown total
            'last_updated_at' => now(),
        ]);

        SubjectRunItem::create([
            'id' => (string) Str::uuid(),
            'run_id' => $this->run->id,
            'owner_user_id' => $this->teacher->id,
            'pdf_ordinal' => 1,
            'display_title' => 'Secret Chapter 1',
            'original_filename' => 'chapter1.pdf',
            'state' => 'prompts_ready',
            'saved_lesson_count' => 3,
            'prompt_count' => 3,
            'state_version' => 1,
        ]);

        SubjectRunItem::create([
            'id' => (string) Str::uuid(),
            'run_id' => $this->run->id,
            'owner_user_id' => $this->teacher->id,
            'pdf_ordinal' => 2,
            'display_title' => 'Secret Chapter 2',
            'original_filename' => 'chapter2.pdf',
            'state' => 'staging',
            'saved_lesson_count' => 0,
            'prompt_count' => 0,
            'state_version' => 1,
        ]);
    }

    public function test_progress_projection_leaves_unknown_totals_as_null_and_reports_age_and_control(): void
    {
        // Add a control command
        $cmdId = (string) Str::uuid();
        PipelineCommand::create([
            'id' => $cmdId,
            'run_id' => $this->run->id,
            'actor_user_id' => $this->teacher->id,
            'action' => 'pause',
            'request_sha256' => hash('sha256', 'pause_req'),
            'expected_version' => 1,
            'state' => 'applied',
            'created_at' => now(),
        ]);

        $resp = $this->actingAs($this->teacher)
            ->getJson("/v3/teacher/subject-runs/{$this->run->id}/progress");

        $resp->assertStatus(200);
        $json = $resp->json();

        $this->assertSame(1, $json['schema_version']);
        $this->assertSame($this->run->id, $json['run_id']);
        $this->assertNull($json['counts']['lessons_total'], 'Unknown lessons_total must be null');
        $this->assertNull($json['counts']['scenes_total'], 'Unknown scenes_total must be null');
        $this->assertSame(2, $json['counts']['pdfs_total']);
        $this->assertSame(3, $json['counts']['lessons_saved']);
        $this->assertSame(3, $json['counts']['prompts_saved']);

        $this->assertSame('live', $json['projection_source']);
        $this->assertGreaterThanOrEqual(0, $json['projection_age_seconds']);

        $this->assertNotNull($json['control']);
        $this->assertSame($cmdId, $json['control']['command_id']);
        $this->assertSame('applied', $json['control']['state']);
        $this->assertSame('pause', $json['control']['action']);
    }

    public function test_progress_projection_reports_last_known_when_offline_or_old(): void
    {
        $this->run->update([
            'last_updated_at' => now()->subMinutes(10),
        ]);

        $resp = $this->actingAs($this->teacher)
            ->getJson("/v3/teacher/subject-runs/{$this->run->id}/progress");

        $resp->assertStatus(200);
        $json = $resp->json();

        $this->assertSame('last_known', $json['projection_source']);
        $this->assertGreaterThanOrEqual(600, $json['projection_age_seconds']);
    }

    public function test_progress_projection_uses_live_temporal_status_and_safe_phase_when_available(): void
    {
        Http::fake(['*' => Http::response([
            'run_id' => $this->run->id,
            'status' => 'paused',
            'progress' => [
                'phase' => 'lesson_loop',
                'generation_phase' => 'processing',
                'current_pdf_ordinal' => 2,
                'paused' => true,
                'cancelled' => false,
            ],
        ], 200)]);

        $projection = app(\App\Services\V3\WebPipeline\RunProgress::class)->project($this->run);
        $this->assertSame('paused', $projection['state']);
        $this->assertSame('live', $projection['projection_source']);
        $this->assertSame('lesson_loop', $projection['workflow']['phase']);
        $this->assertSame(2, $projection['workflow']['current_pdf_ordinal']);
        $this->assertTrue($projection['workflow']['paused']);
    }

    public function test_event_filtering_precedes_row_limit(): void
    {
        $eventStore = app(RunEventStore::class);

        // Record 15 events
        for ($i = 1; $i <= 15; $i++) {
            $eventStore->recordEvent($this->run->id, [
                'id' => (string) Str::uuid(),
                'kind' => "turn_step_{$i}",
                'outcome' => 'completed',
                'safe_code' => 'ok',
            ]);
        }

        // Request with after_sequence=10, limit=3
        $resp = $this->actingAs($this->teacher)
            ->getJson("/v3/teacher/subject-runs/{$this->run->id}/events?after_sequence=10&limit=3");

        $resp->assertStatus(200);
        $json = $resp->json();

        $events = $json['data'];
        $this->assertCount(3, $events);
        $this->assertSame(11, $events[0]['sequence']);
        $this->assertSame(12, $events[1]['sequence']);
        $this->assertSame(13, $events[2]['sequence']);
        $this->assertSame(13, $json['next_cursor']);
    }

    public function test_admin_summary_excludes_private_fields_and_discloses_only_safe_metadata(): void
    {
        $resp = $this->actingAs($this->admin)
            ->getJson("/v3/admin/subject-runs/{$this->run->id}/summary");

        $resp->assertStatus(200);
        $json = $resp->json();

        $this->assertSame($this->run->id, $json['run_id']);
        $this->assertSame(1, $json['state_version']);
        $this->assertArrayHasKey('counts', $json);

        // Verify NO sensitive or educational content is present anywhere in response
        $raw = json_encode($json);
        $this->assertStringNotContainsString('Secret Chapter', $raw, 'Teacher titles must never leak to admin');
        $this->assertStringNotContainsString('chapter1.pdf', $raw, 'Original filenames must never leak to admin');
        $this->assertStringNotContainsString('body_sha256', $raw);
        $this->assertStringNotContainsString('body_path', $raw);
        $this->assertStringNotContainsString('private_lineage_ref', $raw);
    }

    public function test_authorization_and_alternate_route_boundaries(): void
    {
        // Foreign teacher cannot view progress
        $respOther = $this->actingAs($this->otherTeacher)
            ->getJson("/v3/teacher/subject-runs/{$this->run->id}/progress");
        $respOther->assertStatus(404);

        // Student forbidden from teacher progress
        $respStudent = $this->actingAs($this->student)
            ->getJson("/v3/teacher/subject-runs/{$this->run->id}/progress");
        $respStudent->assertStatus(403);

        // Admin forbidden from teacher route
        $respAdminTeacher = $this->actingAs($this->admin)
            ->getJson("/v3/teacher/subject-runs/{$this->run->id}/progress");
        $respAdminTeacher->assertStatus(403);

        // Teacher forbidden from admin route
        $respTeacherAdmin = $this->actingAs($this->teacher)
            ->getJson("/v3/admin/subject-runs/{$this->run->id}/summary");
        $respTeacherAdmin->assertStatus(403);
    }

    public function test_command_version_fence_and_idempotent_replays(): void
    {
        $cmdId = (string) Str::uuid();

        // 1. Submit command with stale version -> 409
        $respStale = $this->actingAs($this->teacher)
            ->postJson("/v3/teacher/subject-runs/{$this->run->id}/commands", [
                'command_id' => $cmdId,
                'action' => 'pause',
                'expected_version' => 999, // Stale
            ]);
        $respStale->assertStatus(409);
        $this->assertSame('stale_version', $respStale->json('error.code'));

        // 2. Submit valid command -> 202
        $respValid = $this->actingAs($this->teacher)
            ->postJson("/v3/teacher/subject-runs/{$this->run->id}/commands", [
                'command_id' => $cmdId,
                'action' => 'pause',
                'expected_version' => 1,
            ]);
        $respValid->assertStatus(202);
        $this->assertSame(2, $respValid->json('state_version'));

        // 3. Exact duplicate replay -> 200
        $respReplay = $this->actingAs($this->teacher)
            ->postJson("/v3/teacher/subject-runs/{$this->run->id}/commands", [
                'command_id' => $cmdId,
                'action' => 'pause',
                'expected_version' => 1,
            ]);
        $respReplay->assertStatus(200);

        // 4. Same command ID with conflicting payload -> 409
        $respConflict = $this->actingAs($this->teacher)
            ->postJson("/v3/teacher/subject-runs/{$this->run->id}/commands", [
                'command_id' => $cmdId,
                'action' => 'cancel', // Changed action!
                'expected_version' => 1,
            ]);
        $respConflict->assertStatus(409);
        $this->assertSame('control_conflict', $respConflict->json('error.code'));
    }

    public function test_auth_blocked_run_can_be_explicitly_retried_with_original_input(): void
    {
        Http::fake(['*' => Http::response(['status' => 'paused_auth'], 200)]);
        $manifestRef = (string) Str::uuid();
        PipelineCommand::create([
            'id' => (string) Str::uuid(),
            'run_id' => $this->run->id,
            'actor_user_id' => $this->teacher->id,
            'action' => 'submit',
            'private_payload_ref' => $manifestRef,
            'request_sha256' => hash('sha256', 'submit'),
            'expected_version' => 1,
            'state' => 'applied',
            'accepted_at' => now(),
            'created_at' => now(),
        ]);
        $this->actingAs($this->teacher)
            ->getJson("/v3/teacher/subject-runs/{$this->run->id}/progress")
            ->assertOk()->assertJsonPath('state', 'paused_auth')->assertJsonPath('retry_available', true);
        $commandId = (string) Str::uuid();
        $request = ['action' => 'retry', 'command_id' => $commandId, 'expected_version' => 1];
        $this->actingAs($this->teacher)
            ->postJson("/v3/teacher/subject-runs/{$this->run->id}/commands", $request)
            ->assertAccepted()->assertJsonPath('status', 'requested');
        $this->assertDatabaseHas('v3_pipeline_commands', [
            'id' => $commandId, 'action' => 'retry', 'private_payload_ref' => $manifestRef,
        ]);
        $this->postJson("/v3/teacher/subject-runs/{$this->run->id}/commands", $request)
            ->assertOk()->assertJsonPath('status', 'replayed');
        $this->assertSame(2, $this->run->fresh()->state_version);
    }

    public function test_control_is_applied_to_run_only_after_temporal_confirms_delivery(): void
    {
        $commandId = (string) Str::uuid();
        $response = $this->actingAs($this->teacher)
            ->postJson("/v3/teacher/subject-runs/{$this->run->id}/commands", [
                'command_id' => $commandId,
                'action' => 'pause',
                'expected_version' => 1,
            ]);

        $response->assertAccepted()->assertJsonPath('state', 'requested');
        $this->assertSame('running', $this->run->fresh()->state);
        $this->assertDatabaseHas('v3_pipeline_commands', ['id' => $commandId, 'state' => 'requested']);
        $this->assertDatabaseHas('v3_pipeline_outbox', ['command_id' => $commandId, 'state' => 'pending']);

        $sentPayload = null;
        Http::fake(function ($request) use (&$sentPayload) {
            $sentPayload = $request->data();
            return Http::response([
                'outcome' => 'applied',
                'applied' => true,
                'command_id' => $sentPayload['command_id'],
            ], 202);
        });

        $this->assertSame(1, app(\App\Services\V3\WebPipeline\DispatchRunOutbox::class)->dispatchPending());
        $this->assertSame($commandId, $sentPayload['command_id']);
        $this->assertSame($this->run->id, $sentPayload['run_id']);
        $this->assertSame('pause', $sentPayload['action']);
        $this->assertSame(2, $sentPayload['expected_version']);
        $this->assertSame('paused', $this->run->fresh()->state);
        $this->assertDatabaseHas('v3_pipeline_commands', ['id' => $commandId, 'state' => 'applied']);
        $this->assertDatabaseHas('v3_pipeline_outbox', ['command_id' => $commandId, 'state' => 'acknowledged']);
    }

    public function test_bridge_outage_keeps_control_requested_without_changing_run_state(): void
    {
        $commandId = (string) Str::uuid();
        $this->actingAs($this->teacher)
            ->postJson("/v3/teacher/subject-runs/{$this->run->id}/commands", [
                'command_id' => $commandId,
                'action' => 'pause',
                'expected_version' => 1,
            ])->assertAccepted();

        Http::fake(['*' => Http::response(['error' => ['code' => 'storage_unavailable']], 503)]);
        $this->assertSame(0, app(\App\Services\V3\WebPipeline\DispatchRunOutbox::class)->dispatchPending());
        $this->assertSame('running', $this->run->fresh()->state);
        $this->assertDatabaseHas('v3_pipeline_commands', ['id' => $commandId, 'state' => 'requested']);
        $this->assertDatabaseHas('v3_pipeline_outbox', ['command_id' => $commandId, 'state' => 'pending']);
    }

    public function test_admin_commands_fenced_and_restricted(): void
    {
        $cmdId = (string) Str::uuid();

        // Admin can pause
        $respPause = $this->actingAs($this->admin)
            ->postJson("/v3/admin/subject-runs/{$this->run->id}/commands", [
                'command_id' => $cmdId,
                'action' => 'pause',
                'expected_version' => 1,
            ]);
        $respPause->assertStatus(202);

        // Admin CANNOT generate scenes -> 403
        $genCmdId = (string) Str::uuid();
        $respGen = $this->actingAs($this->admin)
            ->postJson("/v3/admin/subject-runs/{$this->run->id}/commands", [
                'command_id' => $genCmdId,
                'action' => 'generate',
                'expected_version' => 2,
                'selected_scene_ids' => [(string) Str::uuid()],
            ]);
        $respGen->assertStatus(403);
    }
}
