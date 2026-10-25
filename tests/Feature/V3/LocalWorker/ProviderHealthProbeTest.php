<?php

namespace Tests\Feature\V3\LocalWorker;

use App\Models\User;
use App\Models\V3\ProcessingDevice;
use App\Services\V3\LocalWorker\ProviderHealthProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\V3\AccessFixtures;
use Tests\TestCase;

class ProviderHealthProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_probe_is_owner_scoped_and_accepts_a_safe_worker_report(): void
    {
        Cache::flush();
        $owner = User::factory()->create(['email' => 'admin@test.com', 'role' => 'admin']);
        $otherOwner = User::factory()->create(['email' => 'other-admin@test.com', 'role' => 'admin']);
        $device = ProcessingDevice::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Local worker',
            'platform_owner_id' => $owner->id,
            'token_hash' => hash('sha256', random_bytes(32)),
            'scopes' => ['local_worker_v1'],
            'capabilities' => ['notebooklm_authoring_v1'],
            'status' => 'active',
            'expires_at' => now()->addDay(),
        ]);

        $service = app(ProviderHealthProbeService::class);
        $probe = $service->begin($owner);

        self::assertSame('pending', $probe['state']);
        self::assertSame('Local worker', $service->status($owner, $probe['probe_id'])['device_name']);
        self::assertNull($service->status($otherOwner, $probe['probe_id']));
        self::assertSame($probe['probe_id'], $service->pendingForDevice($device)['probes'][0]['probe_id']);

        self::assertSame(['schema_version' => 1, 'probe_id' => $probe['probe_id'], 'accepted' => true], $service->report($device, [
            'probe_id' => $probe['probe_id'],
            'state' => 'ready',
            'safe_code' => 'ok',
            'profile_generation' => 4,
        ]));
        self::assertTrue($service->status($owner, $probe['probe_id'])['healthy']);
        self::assertSame(['schema_version' => 1, 'probe_id' => $probe['probe_id'], 'accepted' => true], $service->report($device, [
            'probe_id' => $probe['probe_id'], 'state' => 'ready', 'safe_code' => 'ok', 'profile_generation' => 4,
        ]));
    }

    public function test_worker_report_rejects_secrets_and_inconsistent_status(): void
    {
        Cache::flush();
        $owner = User::factory()->create(['email' => 'admin@test.com', 'role' => 'admin']);
        $device = ProcessingDevice::create([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Worker',
            'platform_owner_id' => $owner->id, 'token_hash' => hash('sha256', random_bytes(32)),
            'scopes' => ['local_worker_v1'], 'capabilities' => ['notebooklm_v1'],
            'status' => 'active', 'expires_at' => now()->addDay(),
        ]);
        $service = app(ProviderHealthProbeService::class);
        $probe = $service->begin($owner);

        self::assertSame(['error' => 'invalid_report'], $service->report($device, [
            'probe_id' => $probe['probe_id'], 'state' => 'ready', 'safe_code' => 'ok',
            'profile_generation' => 1, 'cookie' => 'must-not-be-accepted',
        ]));
        self::assertSame(['error' => 'invalid_report'], $service->report($device, [
            'probe_id' => $probe['probe_id'], 'state' => 'ready', 'safe_code' => 'provider_unavailable',
            'profile_generation' => 1,
        ]));
    }

    public function test_admin_health_probe_crosses_the_authenticated_worker_api(): void
    {
        Cache::flush();
        $owner = AccessFixtures::createEnrolledAdmin('Platform Owner', 'admin@test.com');
        $plainToken = 'local-worker-test-token';
        $device = ProcessingDevice::create([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Remote worker',
            'platform_owner_id' => $owner->id, 'token_hash' => hash('sha256', $plainToken),
            'scopes' => ['local_worker_v1'], 'capabilities' => ['notebooklm_authoring_v1'],
            'status' => 'active', 'expires_at' => now()->addDay(),
        ]);

        $started = $this->actingAs($owner)->postJson('/v3/admin/notebooklm/health-check', ['target' => 'local_worker']);
        $started->assertStatus(202)->assertJsonPath('data.state', 'pending');
        $probeId = $started->json('data.probe_id');

        $this->withToken($plainToken)->getJson('/api/v3/local-worker/v1/device/provider-health/requests')
            ->assertOk()->assertJsonPath('probes.0.probe_id', $probeId);
        $this->withToken($plainToken)->postJson('/api/v3/local-worker/v1/device/provider-health/reports', [
            'probe_id' => $probeId, 'state' => 'ready', 'safe_code' => 'ok', 'profile_generation' => 2,
        ])->assertOk()->assertJsonPath('accepted', true);
        $this->actingAs($owner)->getJson('/v3/admin/notebooklm/health-check/'.$probeId)
            ->assertOk()->assertJsonPath('data.healthy', true)
            ->assertJsonPath('data.target', 'local_worker')
            ->assertJsonMissingPath('data.cookie');
    }
}
