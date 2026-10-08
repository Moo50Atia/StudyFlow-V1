<?php

namespace Tests\Feature\V3\Authoring;

use App\Models\V3\ApprovedTemplate;
use App\Models\V3\AuthoringRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\V3\AuthoringFixtures;
use Tests\Support\V3\SettingsFixtures;
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

    $this->sec = AuthoringFixtures::createQualifiedSection($this->case, $this->map, 1, 'Transverse wave prompt', true);
});

test('A04: missing captured scene template in repository returns 503 authoring_unavailable', function () {
    // Delete the approved scene template
    ApprovedTemplate::where('id', SettingsFixtures::VALID_SCENE_TEMPLATE_ID)->delete();

    $path = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/requests";
    $body = ['schema_version' => 1, 'prompt_version_ref' => $this->sec['version']->id];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $body);

    $resp = $this->withHeaders($headers)->postJson($path, $body);
    $resp->assertStatus(503);
    expect($resp->json('error.code'))->toBe('authoring_unavailable');
    expect(AuthoringRequest::count())->toBe(0);
});

test('A04: corrupted captured scene template returns 503 authoring_unavailable', function () {
    // Corrupt the body of the approved scene template without changing checksum
    ApprovedTemplate::where('id', SettingsFixtures::VALID_SCENE_TEMPLATE_ID)->update([
        'body' => 'Corrupted template body that does not match checksum',
    ]);

    $path = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/requests";
    $body = ['schema_version' => 1, 'prompt_version_ref' => $this->sec['version']->id];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $body);

    $resp = $this->withHeaders($headers)->postJson($path, $body);
    $resp->assertStatus(503);
    expect($resp->json('error.code'))->toBe('authoring_unavailable');
    expect(AuthoringRequest::count())->toBe(0);
});

test('A04: request context returns exact approved template bytes and prompt without source or owner fields', function () {
    $claimPath = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/requests";
    $body = ['schema_version' => 1, 'prompt_version_ref' => $this->sec['version']->id];
    $headers = WorkflowFixtures::signCallbackRequest('POST', $claimPath, $body);

    $claimResp = $this->withHeaders($headers)->postJson($claimPath, $body);
    $claimResp->assertStatus(201);
    $reqRef = $claimResp->json('data.request_ref');

    $contextPath = "/api/internal/v1/cases/{$this->case->id}/scene-authoring/requests/{$reqRef}/context";
    $contextHeaders = WorkflowFixtures::signCallbackRequest('GET', $contextPath, []);

    $resp = $this->withHeaders($contextHeaders)->get($contextPath);
    $resp->assertStatus(200);

    $data = $resp->json('data');
    expect($data['request_ref'])->toBe($reqRef);
    expect($data['section_ref'])->toBe($this->sec['section']->id);
    expect($data['prompt_version_ref'])->toBe($this->sec['version']->id);
    expect($data['prompt'])->toBe('Transverse wave prompt');
    expect($data['body_sha256'])->toBe($this->sec['promptSha']);
    expect($data['template_id'])->toBe(SettingsFixtures::VALID_SCENE_TEMPLATE_ID);
    expect($data['template_text'])->toBe(SettingsFixtures::VALID_SCENE_BODY);
    expect($data['template_sha256'])->toBe(hash('sha256', SettingsFixtures::VALID_SCENE_BODY));
    expect($data['coding_attempts'])->toBe(3);
    expect($data['coding_order'])->toBeArray()->toHaveCount(2);

    // Assert NO raw PDF, NO eleven Section fields, NO teacher/owner info
    expect($data)->not->toHaveKey('source_text');
    expect($data)->not->toHaveKey('owner_user_id');
    expect($data)->not->toHaveKey('pdf_path');
    expect($data)->not->toHaveKey('visual_scene_script');
    expect($data)->not->toHaveKey('dynamic_view_blueprint');
    expect($data)->not->toHaveKey('colloquial_egyptian_explanation');
});
