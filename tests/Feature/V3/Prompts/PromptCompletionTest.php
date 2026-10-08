<?php

use App\Services\V3\Lessons\LessonCursorService;
use App\Services\V3\Prompts\PromptReceiptSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Support\V3\WorkflowFixtures;

uses(RefreshDatabase::class);

test('signed prompt completion route commits the receipt through the Laravel services', function () {
    Config::set('services.v3.callback_hmac_key', 'test-callback-hmac-key');
    $caseId = '00000000-0000-4000-8000-000000000001';
    $workItemRef = '00000000-0000-4000-8000-000000000002';
    $payload = [
        'schema_version' => 1,
        'map_ref' => '00000000-0000-4000-8000-000000000003',
        'expected_cursor_version' => 1,
        'section_refs' => ['section-1'],
        'third_output_refs' => ['prompt-1'],
        'final_conversation_ref' => 'conversation-3',
        'completion_ref' => 'prompt-work-item-1',
        'completion_sha256' => str_repeat('a', 64),
    ];
    $receiptSender = Mockery::mock(PromptReceiptSender::class);
    $receiptSender->shouldReceive('sendReceipt')->once()->with($caseId, $workItemRef)->andReturn([
        'completion_ref' => 'prompt-work-item-1',
        'completion_sha256' => str_repeat('a', 64),
        'payload' => $payload,
    ]);
    $cursor = Mockery::mock(LessonCursorService::class);
    $cursor->shouldReceive('recordCompletion')->once()->with($caseId, $payload)->andReturn([
        'phase' => 'complete', 'cursor_version' => 2, 'next_work_ordinal' => null,
    ]);
    app()->instance(PromptReceiptSender::class, $receiptSender);
    app()->instance(LessonCursorService::class, $cursor);

    $path = "/api/internal/v1/cases/{$caseId}/prompt-work-items/{$workItemRef}/complete";
    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, ['schema_version' => 1]);

    $this->withHeaders($headers)->postJson($path, ['schema_version' => 1])
        ->assertOk()
        ->assertJsonPath('data.completion_ref', 'prompt-work-item-1')
        ->assertJsonPath('data.phase', 'complete')
        ->assertJsonPath('data.cursor_version', 2)
        ->assertJsonPath('data.next_work_ordinal', null);
});

test('prompt completion route rejects an unsupported schema without touching persistence', function () {
    Config::set('services.v3.callback_hmac_key', 'test-callback-hmac-key');
    $path = '/api/internal/v1/cases/00000000-0000-4000-8000-000000000001/prompt-work-items/00000000-0000-4000-8000-000000000002/complete';
    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, ['schema_version' => 2]);

    $this->withHeaders($headers)->postJson($path, ['schema_version' => 2])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'prompt_invalid');
});
