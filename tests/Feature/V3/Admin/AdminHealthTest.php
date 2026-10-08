<?php

namespace Tests\Feature\V3\Admin;

use App\Models\V3\WorkflowLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\V3\AccessFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');

    $this->admin = AccessFixtures::createEnrolledAdmin('Active Admin');
    $this->inactiveAdmin = AccessFixtures::createAdmin('Inactive Admin');
    AccessFixtures::provisionV3($this->inactiveAdmin, isActive: false);

    $this->teacher = AccessFixtures::createEnrolledTeacher('Active Teacher');
    $this->student = AccessFixtures::createStudent('Active Student');
});

test('O05: health returns reachable temporal bridge, safe unknown provider, and separate backlog counts', function () {
    Http::fake([
        '*/internal/v1/health' => Http::response([
            'data' => [
                'reachable' => true,
                'checked_at' => now()->toIso8601String(),
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->admin)->getJson('/v3/admin/operations/health');
    $response->assertStatus(200);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Cache-Control'))->toContain('private');

    $data = $response->json('data');
    expect(array_keys($data))->toEqualCanonicalizing([
        'checked_at',
        'temporal_bridge',
        'workflow_start_backlog',
        'review_delivery_backlog',
        'provider_health',
    ])
    ->and($data['temporal_bridge'])->toBe('reachable')
    ->and($data['provider_health'])->toBe('unknown')
    ->and($data['workflow_start_backlog'])->toBeGreaterThanOrEqual(0)
    ->and($data['review_delivery_backlog'])->toBeGreaterThanOrEqual(0);
});

test('O05: bridge timeout or failure maps to safe unavailable status without raw exception leak', function () {
    Http::fake([
        '*/internal/v1/health' => Http::response([
            'error' => [
                'code' => 'workflow_unavailable',
                'message' => 'Connection refused to 10.0.0.5:7233 with secret credentials',
            ],
        ], 503),
    ]);

    $response = $this->actingAs($this->admin)->getJson('/v3/admin/operations/health');
    $response->assertStatus(200);

    $data = $response->json('data');
    expect($data['temporal_bridge'])->toBe('unavailable')
        ->and($data['provider_health'])->toBe('unknown')
        ->and($response->getContent())->not->toContain('10.0.0.5')
        ->and($response->getContent())->not->toContain('secret credentials');
});

test('O05: guest, teacher and inactive admin are denied access to operations health', function () {
    $this->getJson('/v3/admin/operations/health')->assertStatus(401);
    $this->actingAs($this->teacher)->getJson('/v3/admin/operations/health')->assertStatus(403);
    $this->actingAs($this->inactiveAdmin)->getJson('/v3/admin/operations/health')->assertStatus(403);
    $this->actingAs($this->student)->getJson('/v3/admin/operations/health')->assertStatus(403);
});
