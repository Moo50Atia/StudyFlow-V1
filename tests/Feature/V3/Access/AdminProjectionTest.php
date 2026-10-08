<?php

namespace Tests\Feature\V3\Access;

use App\Data\V3\OperationSummaryData;
use App\Http\Resources\V3\OperationSummaryResource;
use Illuminate\Support\Str;

test('M01: DTO with valid operational scalars serializes key set exactly equal to contract allowlist', function () {
    $caseId = (string) Str::uuid();
    $createdAt = '2026-10-01T12:00:00Z';
    $updatedAt = '2026-10-01T12:30:00Z';

    $dto = new OperationSummaryData(
        caseId: $caseId,
        stageCode: 'scene_generation',
        statusCode: 'running',
        errorCode: null,
        createdAt: $createdAt,
        updatedAt: $updatedAt,
        attemptCount: 1,
        sceneCount: 3,
        approvedSceneCount: 0
    );

    $resource = new OperationSummaryResource($dto);
    $serialized = $resource->resolve();

    $expectedKeys = [
        'case_id',
        'stage_code',
        'status_code',
        'error_code',
        'created_at',
        'updated_at',
        'attempt_count',
        'scene_count',
        'approved_scene_count',
    ];

    expect(array_keys($serialized))->toEqualCanonicalizing($expectedKeys)
        ->and($serialized['case_id'])->toBe($caseId)
        ->and($serialized['stage_code'])->toBe('scene_generation')
        ->and($serialized['status_code'])->toBe('running')
        ->and($serialized['error_code'])->toBeNull()
        ->and($serialized['attempt_count'])->toBe(1)
        ->and($serialized['scene_count'])->toBe(3)
        ->and($serialized['approved_scene_count'])->toBe(0);
});

test('M02: sentinel educational content, prompts, HTML, files, notes, and metadata are excluded from serialized output', function () {
    $caseId = (string) Str::uuid();

    $dirtyInput = [
        'case_id' => $caseId,
        'stage_code' => 'prompting',
        'status_code' => 'failed',
        'error_code' => 'provider_unavailable',
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
        'attempt_count' => 2,
        'scene_count' => 1,
        'approved_scene_count' => 0,
        // Forbidden sentinel fields
        'source_text' => 'SECRET SOURCE CONTENT FOR COURSE',
        'prompt' => 'CANONICAL THREE STEP PROMPT BODY',
        'html' => '<script>dangerous()</script>',
        'teacher_notes' => 'Internal teacher feedback',
        'filename' => 'sensitive_exam.pdf',
        'file_hash' => 'd3b07384d113edec49eaa6238ad5ff00',
        'file_path' => '/storage/v3/private/file.pdf',
        'owner_email' => 'teacher@school.edu',
        'metadata' => ['arbitrary' => 'payload'],
    ];

    $dto = OperationSummaryData::fromArray($dirtyInput);
    $serialized = (new OperationSummaryResource($dto))->resolve();
    $serializedJson = json_encode($serialized);

    expect($serializedJson)->not->toContain('SECRET SOURCE CONTENT')
        ->and($serializedJson)->not->toContain('CANONICAL THREE STEP')
        ->and($serializedJson)->not->toContain('<script>')
        ->and($serializedJson)->not->toContain('Internal teacher feedback')
        ->and($serializedJson)->not->toContain('sensitive_exam.pdf')
        ->and($serializedJson)->not->toContain('file_hash')
        ->and($serializedJson)->not->toContain('teacher@school.edu')
        ->and($serializedJson)->not->toContain('arbitrary');

    expect(array_key_exists('metadata', $serialized))->toBeFalse()
        ->and(array_key_exists('source_text', $serialized))->toBeFalse();
});

test('M03: raw provider error or unrecognized stage/status codes are mapped to fixed unknown code without raw text leak', function () {
    $rawExceptionText = 'Fatal Gemini quota exceeded at api.google.com with secret key 12345';

    $dto = OperationSummaryData::fromArray([
        'case_id' => (string) Str::uuid(),
        'stage_code' => 'unrecognized_future_stage',
        'status_code' => 'weird_vendor_status',
        'error_code' => $rawExceptionText,
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
        'attempt_count' => 1,
        'scene_count' => 0,
        'approved_scene_count' => 0,
    ]);

    $serialized = (new OperationSummaryResource($dto))->resolve();

    expect($serialized['stage_code'])->toBe('unknown')
        ->and($serialized['status_code'])->toBe('unknown')
        ->and($serialized['error_code'])->toBe('unknown');

    $serializedJson = json_encode($serialized);
    expect($serializedJson)->not->toContain('Fatal Gemini quota')
        ->and($serializedJson)->not->toContain('api.google.com')
        ->and($serializedJson)->not->toContain('12345');
});

test('rejects negative counts or malformed scalar values', function () {
    expect(fn () => new OperationSummaryData(
        caseId: (string) Str::uuid(),
        stageCode: 'intake',
        statusCode: 'queued',
        errorCode: null,
        createdAt: now()->toIso8601String(),
        updatedAt: now()->toIso8601String(),
        attemptCount: -1,
        sceneCount: 0,
        approvedSceneCount: 0
    ))->toThrow(\InvalidArgumentException::class);
});
