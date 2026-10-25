<?php

namespace Tests\Unit\V3\LocalWorker;

use App\Services\V3\LocalWorker\Protocol\LocalWorkerJsonDecoder;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerProtocolException;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerV1Validator;
use PHPUnit\Framework\TestCase;

class LocalWorkerProtocolTest extends TestCase
{
    public function test_json_decoder_rejects_duplicate_keys(): void
    {
        $json = '{"key": "first", "other": 123, "key": "second"}';

        $this->expectException(LocalWorkerProtocolException::class);
        $this->expectExceptionMessage("Duplicate key 'key' detected");

        LocalWorkerJsonDecoder::decode($json);
    }

    public function test_json_decoder_accepts_clean_json(): void
    {
        $json = '{"key": "first", "other": 123, "nested": {"a": 1, "b": 2}}';
        $decoded = LocalWorkerJsonDecoder::decode($json);

        $this->assertIsArray($decoded);
        $this->assertSame('first', $decoded['key']);
        $this->assertSame(123, $decoded['other']);
        $this->assertSame(['a' => 1, 'b' => 2], $decoded['nested']);
    }

    public function test_json_decoder_rejects_bounds_exceeded(): void
    {
        $json = '{"a": "' . str_repeat('x', 500) . '"}';

        $this->expectException(LocalWorkerProtocolException::class);
        $this->expectExceptionMessage("exceeds maximum allowed size");

        LocalWorkerJsonDecoder::decode($json, 200);
    }

    public function test_json_decoder_rejects_invalid_utf8(): void
    {
        $json = "{\"bad\": \"\xC3\x28\"}";

        $this->expectException(LocalWorkerProtocolException::class);
        $this->expectExceptionMessage("invalid UTF-8");

        LocalWorkerJsonDecoder::decode($json);
    }

    public function test_validator_rejects_unsupported_schema_version(): void
    {
        $pkg = [
            'schema_version' => 2,
            'files' => [
                ['path' => 'index.html', 'content' => '<h1>Hello</h1>'],
                ['path' => 'styles.css', 'content' => 'body { color: red; }'],
                ['path' => 'script.js', 'content' => 'console.log("ok");'],
            ],
        ];

        $this->expectException(LocalWorkerProtocolException::class);
        $this->expectExceptionMessage("Unsupported package schema_version '2'");

        LocalWorkerV1Validator::validateStaticThreeFilePackage($pkg);
    }

    public function test_validator_rejects_unknown_keys(): void
    {
        $pkg = [
            'schema_version' => 1,
            'files' => [
                ['path' => 'index.html', 'content' => '<h1>Hello</h1>'],
                ['path' => 'styles.css', 'content' => 'body { color: red; }'],
                ['path' => 'script.js', 'content' => 'console.log("ok");'],
            ],
            'extra_field' => 'forbidden',
        ];

        $this->expectException(LocalWorkerProtocolException::class);
        $this->expectExceptionMessage("Unknown field 'extra_field'");

        LocalWorkerV1Validator::validateStaticThreeFilePackage($pkg);
    }

    public function test_validator_static_three_file_package_validates_successfully(): void
    {
        $pkg = [
            'schema_version' => 1,
            'files' => [
                ['path' => 'index.html', 'content' => '<h1>Hello</h1>'],
                ['path' => 'styles.css', 'content' => 'body { color: red; }'],
                ['path' => 'script.js', 'content' => 'console.log("ok");'],
            ],
        ];

        LocalWorkerV1Validator::validateStaticThreeFilePackage($pkg);
        $this->assertTrue(true);
    }

    public function test_validator_rejects_null_byte_in_package(): void
    {
        $pkg = [
            'schema_version' => 1,
            'files' => [
                ['path' => 'index.html', 'content' => "<h1>Hello\0world</h1>"],
                ['path' => 'styles.css', 'content' => 'body { color: red; }'],
                ['path' => 'script.js', 'content' => 'console.log("ok");'],
            ],
        ];

        $this->expectException(LocalWorkerProtocolException::class);
        $this->expectExceptionMessage("forbidden null bytes");

        LocalWorkerV1Validator::validateStaticThreeFilePackage($pkg);
    }

    public function test_validator_rejects_authoring_result_prompt_sha256_mismatch(): void
    {
        $taskId = '11111111-1111-1111-1111-111111111111';
        $jobId = '22222222-2222-2222-2222-222222222222';
        $runId = '33333333-3333-3333-3333-333333333333';
        $resultId = '44444444-4444-4444-4444-444444444444';
        $assignmentId = '55555555-5555-5555-5555-555555555555';
        $itemId = '66666666-6666-6666-6666-666666666666';
        $rawId = '77777777-7777-7777-7777-777777777777';

        $promptText = "Describe mechanics principles.";
        $wrongHash = str_repeat('a', 64);

        $result = [
            'schema_version' => 1,
            'kind' => 'local_worker_authoring_result',
            'result_id' => $resultId,
            'task_id' => $taskId,
            'job_id' => $jobId,
            'run_id' => $runId,
            'assignment_id' => $assignmentId,
            'assignment_epoch' => 1,
            'input_manifest_sha256' => str_repeat('b', 64),
            'protocol_version' => 'notebooklm_v1',
            'settings_sha256' => str_repeat('c', 64),
            'preparation_sha256' => str_repeat('d', 64),
            'artifact_references' => [],
            'lessons' => [
                [
                    'item_id' => $itemId,
                    'lesson_ordinal' => 0,
                    'lesson_title' => 'Introduction',
                    'raw_response_artifact_id' => $rawId,
                    'raw_response_sha256' => str_repeat('e', 64),
                    'scenes' => [
                        [
                            'scene_ordinal' => 0,
                            'scene_title' => 'Scene 1',
                            'prompt_text' => $promptText,
                            'prompt_sha256' => $wrongHash,
                            'generation_context' => 'Physics context',
                        ],
                    ],
                ],
            ],
            'archive_inventory' => [
                'archive_artifact_id' => '88888888-8888-8888-8888-888888888888',
                'archive_sha256' => str_repeat('f', 64),
                'item_count' => 1,
            ],
            'metadata' => ['measured_usage' => ['measurement_status' => 'not_recorded']],
        ];

        $this->expectException(LocalWorkerProtocolException::class);
        $this->expectExceptionMessage("prompt_sha256 mismatch");

        LocalWorkerV1Validator::validateAuthoringResult($result);
    }

    public function test_validator_rejects_non_contiguous_lesson_ordinals(): void
    {
        $taskId = '11111111-1111-1111-1111-111111111111';
        $jobId = '22222222-2222-2222-2222-222222222222';
        $runId = '33333333-3333-3333-3333-333333333333';
        $resultId = '44444444-4444-4444-4444-444444444444';
        $assignmentId = '55555555-5555-5555-5555-555555555555';
        $itemId = '66666666-6666-6666-6666-666666666666';
        $rawId = '77777777-7777-7777-7777-777777777777';

        $prompt1 = "Scene 1 prompt";
        $hash1 = hash('sha256', $prompt1);
        $prompt2 = "Scene 2 prompt";
        $hash2 = hash('sha256', $prompt2);

        $result = [
            'schema_version' => 1,
            'kind' => 'local_worker_authoring_result',
            'result_id' => $resultId,
            'task_id' => $taskId,
            'job_id' => $jobId,
            'run_id' => $runId,
            'assignment_id' => $assignmentId,
            'assignment_epoch' => 1,
            'input_manifest_sha256' => str_repeat('b', 64),
            'protocol_version' => 'notebooklm_v1',
            'settings_sha256' => str_repeat('c', 64),
            'preparation_sha256' => str_repeat('d', 64),
            'artifact_references' => [],
            'lessons' => [
                [
                    'item_id' => $itemId,
                    'lesson_ordinal' => 0, // lesson 0
                    'lesson_title' => 'Lesson 0',
                    'raw_response_artifact_id' => $rawId,
                    'raw_response_sha256' => str_repeat('e', 64),
                    'scenes' => [
                        [
                            'scene_ordinal' => 0,
                            'scene_title' => 'Scene 1',
                            'prompt_text' => $prompt1,
                            'prompt_sha256' => $hash1,
                            'generation_context' => 'Ctx',
                        ],
                    ],
                ],
                [
                    'item_id' => $itemId,
                    'lesson_ordinal' => 2, // lesson 2 skipping 1!
                    'lesson_title' => 'Lesson 2',
                    'raw_response_artifact_id' => $rawId,
                    'raw_response_sha256' => str_repeat('e', 64),
                    'scenes' => [
                        [
                            'scene_ordinal' => 0,
                            'scene_title' => 'Scene 2',
                            'prompt_text' => $prompt2,
                            'prompt_sha256' => $hash2,
                            'generation_context' => 'Ctx',
                        ],
                    ],
                ],
            ],
            'archive_inventory' => [
                'archive_artifact_id' => '88888888-8888-8888-8888-888888888888',
                'archive_sha256' => str_repeat('f', 64),
                'item_count' => 1,
            ],
            'metadata' => ['measured_usage' => ['measurement_status' => 'not_recorded']],
        ];

        $this->expectException(LocalWorkerProtocolException::class);
        $this->expectExceptionMessage("Non-contiguous lesson ordinals");

        LocalWorkerV1Validator::validateAuthoringResult($result);
    }
}
