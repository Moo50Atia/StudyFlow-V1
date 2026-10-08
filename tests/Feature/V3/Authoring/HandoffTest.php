<?php

namespace Tests\Feature\V3\Authoring;

use App\Models\V3\AuthoringRequest;
use App\Models\V3\PromptSectionState;
use App\Models\V3\ScenePromptVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\AuthoringFixtures;
use Tests\Support\V3\WorkflowFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');

    $env = AuthoringFixtures::createAuthoringEnvironment();
    $this->teacher = $env['teacher'];
    $this->admin = $env['admin'];
    $this->settingsVersion = $env['settingsVersion'];
    $this->case = $env['case'];
    $this->map = $env['map'];

    // Create 2 qualified passing sections and 1 failing section
    $this->sec1 = AuthoringFixtures::createQualifiedSection($this->case, $this->map, 1, 'Prompt for section 1', true);
    $this->sec2 = AuthoringFixtures::createQualifiedSection($this->case, $this->map, 2, 'Prompt for section 2', true);
    $this->sec3 = AuthoringFixtures::createQualifiedSection($this->case, $this->map, 3, 'Prompt for section 3', false);
});

test('A01: eligible-prompts lists only current same-case passes in 009 ordinal order', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/eligible-prompts";
    $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);

    $response = $this->withHeaders($headers)->get($path);
    $response->assertStatus(200);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $data = $response->json('data');
    expect($data)->toHaveCount(2);
    expect($data[0]['section_ref'])->toBe($this->sec1['section']->id);
    expect($data[0]['prompt_version_ref'])->toBe($this->sec1['version']->id);
    expect($data[1]['section_ref'])->toBe($this->sec2['section']->id);
    expect($data[1]['prompt_version_ref'])->toBe($this->sec2['version']->id);
});

test('A01: POST requests claims initial authoring request and returns 201, replay returns 200', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/requests";
    $body = [
        'schema_version' => 1,
        'prompt_version_ref' => $this->sec1['version']->id,
    ];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $body);

    $response = $this->withHeaders($headers)->postJson($path, $body);
    $response->assertStatus(201);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $data = $response->json('data');
    expect($data['section_ref'])->toBe($this->sec1['section']->id);
    expect($data['state'])->toBe('pending');
    expect($data['settings_version_id'])->toBe($this->settingsVersion->id);
    expect($data)->toHaveKey('request_ref');

    $requestRef = $data['request_ref'];

    // Verify row in database
    $req = AuthoringRequest::findOrFail($requestRef);
    expect($req->case_id)->toBe($this->case->id);
    expect($req->section_id)->toBe($this->sec1['section']->id);
    expect($req->prompt_version_id)->toBe($this->sec1['version']->id);
    expect($req->qualification_id)->toBe($this->sec1['qualification']->id);
    expect($req->prompt_body_sha256)->toBe($this->sec1['promptSha']);
    expect($req->origin)->toBe('initial');
    expect($req->state)->toBe('pending');
    expect($req->state_version)->toBe(1);

    // Exact replay with same payload returns 200
    $replayResponse = $this->withHeaders($headers)->postJson($path, $body);
    $replayResponse->assertStatus(200);
    expect($replayResponse->json('data.request_ref'))->toBe($requestRef);
    expect(AuthoringRequest::count())->toBe(1);
});

test('A02: missing, failed, foreign-case or mismatched prompt returns 404 and 0 rows', function () {
    $path = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/requests";

    // 1. Non-existent prompt
    $body1 = ['schema_version' => 1, 'prompt_version_ref' => (string) Str::uuid()];
    $headers1 = WorkflowFixtures::signCallbackRequest('POST', $path, $body1);
    $resp1 = $this->withHeaders($headers1)->postJson($path, $body1);
    $resp1->assertStatus(404);
    expect($resp1->json('error.code'))->toBe('authoring_unavailable');

    // 2. Unpassed/failing prompt
    $body2 = ['schema_version' => 1, 'prompt_version_ref' => $this->sec3['version']->id];
    $headers2 = WorkflowFixtures::signCallbackRequest('POST', $path, $body2);
    $resp2 = $this->withHeaders($headers2)->postJson($path, $body2);
    $resp2->assertStatus(404);
    expect($resp2->json('error.code'))->toBe('authoring_unavailable');

    // 3. Foreign case prompt
    $foreignEnv = AuthoringFixtures::createAuthoringEnvironment();
    $foreignSec = AuthoringFixtures::createQualifiedSection($foreignEnv['case'], $foreignEnv['map'], 1, 'Foreign prompt', true);

    $body3 = ['schema_version' => 1, 'prompt_version_ref' => $foreignSec['version']->id];
    $headers3 = WorkflowFixtures::signCallbackRequest('POST', $path, $body3);
    $resp3 = $this->withHeaders($headers3)->postJson($path, $body3);
    $resp3->assertStatus(404);
    expect($resp3->json('error.code'))->toBe('authoring_unavailable');

    // Ensure 0 authoring requests were created
    expect(AuthoringRequest::count())->toBe(0);
});

test('A03: superseded prompt at claim or context read is rejected/staled and cannot be revived', function () {
    // Supersede sec1 prompt in 010 by creating a version 2 and updating PromptSectionState
    $promptText2 = 'Process: Wave particles revised oscillation.';
    $promptSha2 = hash('sha256', $promptText2);
    $promptPath2 = "prompt-versions/{$this->case->id}/{$promptSha2}.txt";
    Storage::disk('v3_private')->put($promptPath2, $promptText2);

    $v2 = ScenePromptVersion::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'work_item_id' => $this->sec1['workItem']->id,
        'section_id' => $this->sec1['section']->id,
        'version_no' => 2,
        'origin' => 'revision',
        'body_path' => $promptPath2,
        'body_sha256' => $promptSha2,
        'body_bytes' => strlen($promptText2),
        'scene_type' => 'Process',
        'section_fields_sha256' => hash('sha256', 'fields2'),
        'canonical_third_sha256' => hash('sha256', 'third2'),
    ]);

    // Update section state to point to v2
    $this->sec1['sectionState']->update([
        'current_prompt_version_id' => $v2->id,
    ]);

    // Claiming old v1 must return 404
    $claimPath = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/requests";
    $body = ['schema_version' => 1, 'prompt_version_ref' => $this->sec1['version']->id];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $claimPath, $body);

    $resp = $this->withHeaders($headers)->postJson($claimPath, $body);
    $resp->assertStatus(404);
    expect($resp->json('error.code'))->toBe('authoring_unavailable');

    // Now test superseded during context read:
    // First, point back to sec2 version, claim it
    $body2 = ['schema_version' => 1, 'prompt_version_ref' => $this->sec2['version']->id];
    $headers2 = WorkflowFixtures::signCallbackRequest('POST', $claimPath, $body2);
    $resp2 = $this->withHeaders($headers2)->postJson($claimPath, $body2);
    $resp2->assertStatus(201);
    $reqRef2 = $resp2->json('data.request_ref');

    // Now supersede sec2 version before context read by creating version 2
    $promptTextSec2V2 = 'Process: Wave particles revised for sec 2.';
    $promptShaSec2V2 = hash('sha256', $promptTextSec2V2);
    $promptPathSec2V2 = "prompt-versions/{$this->case->id}/{$promptShaSec2V2}.txt";
    Storage::disk('v3_private')->put($promptPathSec2V2, $promptTextSec2V2);

    $v2Sec2 = ScenePromptVersion::forceCreate([
        'id' => (string) Str::uuid(),
        'case_id' => $this->case->id,
        'work_item_id' => $this->sec2['workItem']->id,
        'section_id' => $this->sec2['section']->id,
        'version_no' => 2,
        'origin' => 'revision',
        'body_path' => $promptPathSec2V2,
        'body_sha256' => $promptShaSec2V2,
        'body_bytes' => strlen($promptTextSec2V2),
        'scene_type' => 'Process',
        'section_fields_sha256' => hash('sha256', 'fields_sec2_v2'),
        'canonical_third_sha256' => hash('sha256', 'third_sec2_v2'),
    ]);

    $this->sec2['sectionState']->update([
        'current_prompt_version_id' => $v2Sec2->id,
    ]);

    // Read context
    $contextPath = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/requests/{$reqRef2}/context";
    $contextHeaders = WorkflowFixtures::signCallbackRequest('GET', $contextPath, []);
    $contextResp = $this->withHeaders($contextHeaders)->get($contextPath);

    $contextResp->assertStatus(404);
    expect($contextResp->json('error.code'))->toBe('authoring_unavailable');

    // Request state should be marked stale in database
    $req = AuthoringRequest::findOrFail($reqRef2);
    expect($req->state)->toBe('stale');
});
