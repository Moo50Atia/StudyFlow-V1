<?php

namespace Tests\Feature\V3\WebPipeline;

use App\Models\Subject;
use App\Models\User;
use App\Models\V3\NotebookFrameworkVersion;
use App\Models\V3\PipelineEvent;
use App\Models\V3\SettingsVersion;
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use App\Services\V3\NotebookLM\FrameworkCatalog;
use App\Services\V3\WebPipeline\RunEventStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;
use Tests\Support\V3\SettingsFixtures;
use Tests\TestCase;

class PrivateMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;
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

        $this->teacher = AccessFixtures::createEnrolledTeacher('Teacher Privacy');
        $this->admin = AccessFixtures::createEnrolledAdmin('Admin Privacy');

        $this->subject = Subject::forceCreate([
            'name' => 'Physics Privacy',
            'description' => 'Test subject for privacy',
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
            'request_sha256' => hash('sha256', 'sub_priv'),
            'captured_settings_json' => json_encode(['mode' => 'export_manual']),
            'capture_sha256' => hash('sha256', json_encode(['mode' => 'export_manual'])),
            'state' => 'running',
            'state_version' => 1,
            'total_pdfs' => 1,
            'last_updated_at' => now(),
        ]);

        SubjectRunItem::create([
            'id' => (string) Str::uuid(),
            'run_id' => $this->run->id,
            'owner_user_id' => $this->teacher->id,
            'pdf_ordinal' => 1,
            'display_title' => 'Top Secret Educational Title',
            'original_filename' => 'sensitive_exam.pdf',
            'state' => 'prompts_ready',
            'saved_lesson_count' => 2,
            'prompt_count' => 2,
            'state_version' => 1,
        ]);
    }

    public function test_event_store_strictly_strips_disallowed_sensitive_keys(): void
    {
        $eventStore = app(RunEventStore::class);

        $eventId = (string) Str::uuid();
        $injected = [
            'id' => $eventId,
            'kind' => 'step_recorded',
            'phase' => 'lesson_parse',
            'outcome' => 'completed',
            'safe_code' => 'ok',
            // Injected forbidden fields
            'body' => 'SELECT * FROM students;',
            'prompt' => 'Tell me the secret prompt',
            'secret' => 'sk-live-1234567890',
            'token' => 'bearer-token-abc',
            'password' => 'supersecret',
            'traceback' => 'Traceback (most recent call last): line 42 in foo',
            'exception' => 'RuntimeError: leaked exception details',
            'payload' => '{"foo":"bar"}',
            'html' => '<script>alert(1)</script>',
            'student' => 'Alice Smith',
            'email' => 'alice@school.edu',
            'counts' => [
                'saved_lessons' => 2,
                'secret' => 999, // Should be stripped from counts
                'token' => 123,  // Should be stripped from counts
            ],
        ];

        $event = $eventStore->recordEvent($this->run->id, $injected);

        $row = DB::table('v3_pipeline_events')->where('id', $eventId)->first();
        $this->assertNotNull($row);

        $rawJson = json_encode($row);
        $this->assertStringNotContainsString('sk-live', $rawJson);
        $this->assertStringNotContainsString('secret prompt', $rawJson);
        $this->assertStringNotContainsString('supersecret', $rawJson);
        $this->assertStringNotContainsString('Traceback', $rawJson);
        $this->assertStringNotContainsString('alice@school.edu', $rawJson);
        $this->assertStringNotContainsString('<script>', $rawJson);

        $counts = json_decode($row->counts_json, true);
        $this->assertArrayHasKey('saved_lessons', $counts);
        $this->assertArrayNotHasKey('secret', $counts);
        $this->assertArrayNotHasKey('token', $counts);
    }

    public function test_admin_endpoints_never_disclose_teacher_titles_or_private_references(): void
    {
        // Admin index
        $respIndex = $this->actingAs($this->admin)
            ->getJson('/v3/admin/subject-runs');
        $respIndex->assertStatus(200);

        $rawIndex = json_encode($respIndex->json());
        $this->assertStringNotContainsString('Top Secret Educational Title', $rawIndex);
        $this->assertStringNotContainsString('sensitive_exam.pdf', $rawIndex);

        // Admin show
        $respShow = $this->actingAs($this->admin)
            ->getJson("/v3/admin/subject-runs/{$this->run->id}/summary");
        $respShow->assertStatus(200);

        $rawShow = json_encode($respShow->json());
        $this->assertStringNotContainsString('Top Secret Educational Title', $rawShow);
        $this->assertStringNotContainsString('sensitive_exam.pdf', $rawShow);
        $this->assertStringNotContainsString('prompt_body', $rawShow);
        $this->assertStringNotContainsString('body_path', $rawShow);
    }
}
