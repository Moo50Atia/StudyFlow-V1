<?php

namespace Tests\Support\V3;

use App\Models\V3\ApprovedTemplate;
use App\Models\V3\Document;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonNode;
use App\Models\V3\MapActivation;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptJevAttempt;
use App\Models\V3\PromptQualification;
use App\Models\V3\PromptSectionState;
use App\Models\V3\PromptWorkItem;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SettingsVersion;
use App\Models\V3\TeacherSection;
use App\Models\V3\WorkflowLink;
use App\Services\V3\Settings\CanonicalSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AuthoringFixtures
{
    public static function createAuthoringEnvironment(array $settingsOverrides = []): array
    {
        $teacher = AccessFixtures::createEnrolledTeacher('Authoring Teacher ' . Str::random(5));
        $admin = AccessFixtures::createEnrolledAdmin('Authoring Admin ' . Str::random(5));

        SettingsFixtures::registerDefaultTemplates();
        $payload = SettingsFixtures::validPayload(0, $settingsOverrides);
        $canonical = CanonicalSettings::canonicalize($payload);
        $checksum = hash('sha256', CanonicalSettings::encode($canonical));

        $maxVer = (int) SettingsVersion::max('version');
        $nextVer = $maxVer + 1;

        $settingsVersion = SettingsVersion::create([
            'version' => $nextVer,
            'schema_version' => 1,
            'payload' => $canonical,
            'checksum' => $checksum,
            'published_by_user_id' => $admin->id,
            'published_at' => now(),
        ]);

        $document = Document::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $teacher->id,
            'sha256' => hash('sha256', 'authoring doc ' . Str::random(10)),
            'byte_size' => 2048,
            'page_count' => 2,
            'state' => 'ready',
            'storage_path' => 'accepted/doc-' . Str::random(8) . '.pdf',
            'accepted_at' => now(),
        ]);

        $case = ProcessingCase::forceCreate([
            'id' => (string) Str::uuid(),
            'owner_user_id' => $teacher->id,
            'document_id' => $document->id,
            'settings_version_id' => $settingsVersion->id,
            'idempotency_key' => (string) Str::uuid(),
            'action' => 'process',
            'state' => 'ready',
            'state_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $activation = \App\Models\V3\SourceActivation::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'workflow_id' => "v3-case-{$case->id}",
            'state' => 'ready',
        ]);

        $source = \App\Models\V3\ExtractedSource::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'document_id' => $document->id,
            'activation_id' => $activation->id,
            'settings_version_id' => $settingsVersion->id,
            'status' => 'ready',
            'artifact_path' => 'extractions/test.txt',
            'text_sha256' => hash('sha256', 'extracted text'),
            'text_bytes' => 100,
            'page_count' => 2,
            'ocr_page_count' => 0,
            'page_report' => [],
            'extractor_version' => '1.0.0',
        ]);

        $binding = \App\Models\V3\KnowledgeSourceBinding::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'extracted_source_id' => $source->id,
            'adapter_kind' => 'notebooklm',
            'adapter_version' => '1.0.0',
            'notebook_title' => "v3-case-{$case->id}",
            'source_title' => "v3-text-{$case->id}",
            'state' => 'bound',
            'text_sha256' => hash('sha256', 'extracted text'),
            'text_bytes' => 100,
            'transfer_status' => 'confirmed',
        ]);

        $mapActivation = \App\Models\V3\MapActivation::forceCreate([
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
            'page_count' => 2,
            'work_count' => 1,
        ]);

        return compact('teacher', 'admin', 'settingsVersion', 'document', 'case', 'map');
    }

    public static function createQualifiedSection(
        ProcessingCase $case,
        LessonMap $map,
        int $ordinal = 1,
        string $promptText = 'Process: Wave particles oscillating in place demonstrating transverse wave motion.',
        bool $qualifyPass = true
    ): array {
        $node = LessonNode::forceCreate([
            'id' => (string) Str::uuid(),
            'map_id' => $map->id,
            'parent_id' => null,
            'kind' => 'lesson',
            'position_path' => sprintf('%03d', $ordinal),
            'title' => "Lesson {$ordinal}",
            'ordinal_in_parent' => $ordinal,
            'work_ordinal' => $ordinal,
            'coverage_pages' => [1, 2],
        ]);

        $query = \App\Models\V3\LessonQuery::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'map_id' => $map->id,
            'kind' => 'first',
            'target_work_ordinal' => $ordinal,
            'canonical_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
            'request_sha256' => hash('sha256', "first-req-{$ordinal}"),
            'state' => 'recorded',
            'send_count' => 1,
            'conversation_in' => "conv-in-{$ordinal}",
            'conversation_out' => "conv-out-{$ordinal}",
            'sent_at' => now(),
            'recorded_at' => now(),
        ]);

        $section = TeacherSection::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'map_id' => $map->id,
            'work_node_id' => $node->id,
            'query_id' => $query->id,
            'ordinal_in_work' => $ordinal,
            'section_identity' => "Sec {$ordinal} Identity",
            'core_understanding' => "Core {$ordinal}",
            'visual_first_explanation' => "Visual {$ordinal}",
            'visual_scene_script' => "Script {$ordinal}",
            'dynamic_view_blueprint' => "Blueprint {$ordinal}",
            'visual_learning_outcome' => "Outcome {$ordinal}",
            'concept_check' => "Check {$ordinal}",
            'conceptual_summary' => "Summary {$ordinal}",
            'colloquial_egyptian_explanation' => "Egyptian {$ordinal}",
            'laws_if_any' => 'None',
            'real_life_example' => "Example {$ordinal}",
            'first_prompt_sha256' => \App\Services\V3\Lessons\LessonQueryLedger::CANONICAL_FIRST_SHA256,
            'answer_sha256' => hash('sha256', "first-ans-{$ordinal}"),
        ]);

        $workItem = PromptWorkItem::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'map_id' => $map->id,
            'work_node_id' => $node->id,
            'work_ordinal' => $ordinal,
            'expected_cursor_version' => 1,
            'section_query_id' => $query->id,
            'state' => 'completed',
            'section_refs' => [$section->id],
            'completion_ref' => "prompt-work-comp-{$ordinal}",
            'completion_sha256' => hash('sha256', "comp-{$ordinal}"),
        ]);

        $promptSha = hash('sha256', $promptText);
        $promptPath = "prompt-versions/{$case->id}/{$promptSha}.txt";
        Storage::disk('v3_private')->put($promptPath, $promptText);

        $version = ScenePromptVersion::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $case->id,
            'work_item_id' => $workItem->id,
            'section_id' => $section->id,
            'version_no' => 1,
            'origin' => 'third',
            'body_path' => $promptPath,
            'body_sha256' => $promptSha,
            'body_bytes' => strlen($promptText),
            'scene_type' => 'Process',
            'section_fields_sha256' => hash('sha256', 'fields'),
            'canonical_third_sha256' => hash('sha256', 'third'),
        ]);

        $attempt = PromptJevAttempt::forceCreate([
            'id' => (string) Str::uuid(),
            'prompt_version_id' => $version->id,
            'settings_version_id' => $case->settings_version_id,
            'attempt_no' => 1,
            'state' => 'recorded',
            'requested_model' => 'typesafe-jev-v1',
            'request_sha256' => hash('sha256', "jev-req-{$ordinal}"),
            'question_sha256' => hash('sha256', "questions-{$ordinal}"),
        ]);

        $qualification = PromptQualification::forceCreate([
            'id' => (string) Str::uuid(),
            'prompt_version_id' => $version->id,
            'winning_attempt_id' => $attempt->id,
            'case_id' => $case->id,
            'section_id' => $section->id,
            'settings_version_id' => $case->settings_version_id,
            'settings_checksum' => $case->settingsVersion->checksum,
            'request_sha256' => hash('sha256', "jev-req-{$ordinal}"),
            'answer_sha256' => hash('sha256', "ans-{$ordinal}"),
            'question_sha256' => hash('sha256', "questions-{$ordinal}"),
            'rule_version' => 'prompt-rule-v1',
            'requested_model' => 'typesafe-jev-v1',
            'returned_model' => 'typesafe-jev-v1',
            'choice' => $qualifyPass ? 'pass' : 'revise',
            'choice_confidence' => '0.95',
            'score' => $qualifyPass ? '90.0' : '50.0',
            'score_confidence' => '0.95',
            'noul_probability' => '0.95',
            'noul_yes' => true,
            'min_score' => 80,
            'min_confidence' => 80,
            'decision' => $qualifyPass ? 'pass' : 'needs_revision',
            'failed_checks' => [],
            'answer_path' => "raw/jev-{$ordinal}.json",
            'created_at' => now(),
        ]);

        $sectionState = PromptSectionState::forceCreate([
            'case_id' => $case->id,
            'section_id' => $section->id,
            'current_prompt_version_id' => $version->id,
            'status' => $qualifyPass ? 'qualified' : 'in_progress',
            'last_qualification_id' => $qualification->id,
            'revision_count' => 0,
        ]);

        return compact('node', 'query', 'section', 'workItem', 'version', 'promptText', 'promptSha', 'attempt', 'qualification', 'sectionState');
    }
}
