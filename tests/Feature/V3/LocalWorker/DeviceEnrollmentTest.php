<?php

namespace Tests\Feature\V3\LocalWorker;

use App\Models\User;
use App\Models\V3\ProcessingDevice;
use App\Models\V3\V3AccountAccess;
use App\Services\V3\LocalWorker\DeviceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    protected function createPlatformOwner(): User
    {
        $owner = User::factory()->create([
            'email' => 'admin@test.com',
            'role' => 'admin',
        ]);
        V3AccountAccess::create([
            'user_id' => $owner->id,
            'is_active' => true,
            'provisioned_at' => now(),
        ]);
        return $owner;
    }

    protected function createNonOwnerAdmin(): User
    {
        $admin = User::factory()->create([
            'email' => 'ops@test.com',
            'role' => 'admin',
        ]);
        V3AccountAccess::create([
            'user_id' => $admin->id,
            'is_active' => true,
            'provisioned_at' => now(),
        ]);
        return $admin;
    }

    public function test_owner_can_create_pairing_and_worker_can_redeem(): void
    {
        $owner = $this->createPlatformOwner();
        $manager = app(DeviceManager::class);

        $invitation = $manager->createPairingInvitation($owner, "Mohammed's Laptop");
        $this->assertNotEmpty($invitation['pairing_secret']);
        $this->assertNotEmpty($invitation['invitation_id']);

        // Worker redeems pairing code
        $resp = $this->postJson('/api/v3/local-worker/v1/enrollments/redeem', [
            'pairing_secret' => $invitation['pairing_secret'],
        ]);

        $resp->assertStatus(200);
        $resp->assertJsonStructure([
            'schema_version',
            'device_id',
            'device_token',
            'device_name',
            'expires_at',
        ]);

        $deviceId = $resp->json('device_id');
        $deviceToken = $resp->json('device_token');

        // Verify device in DB has hash-only token
        $device = ProcessingDevice::findOrFail($deviceId);
        $this->assertSame(hash('sha256', $deviceToken), $device->token_hash);
        $this->assertSame('active', $device->status);

        // Authenticated GET /device
        $getDeviceResp = $this->withHeader('Authorization', 'Bearer ' . $deviceToken)
            ->getJson('/api/v3/local-worker/v1/device');

        $getDeviceResp->assertStatus(200);
        $getDeviceResp->assertJsonPath('device_id', $deviceId);
        $getDeviceResp->assertJsonPath('name', "Mohammed's Laptop");
        // Ensure no token or secret is echoed
        $this->assertArrayNotHasKey('device_token', $getDeviceResp->json());
        $this->assertArrayNotHasKey('token_hash', $getDeviceResp->json());
    }

    public function test_non_owner_admin_cannot_create_pairing(): void
    {
        $admin = $this->createNonOwnerAdmin();
        $manager = app(DeviceManager::class);

        $this->expectException(\App\Services\V3\LocalWorker\Protocol\LocalWorkerProtocolException::class);
        $this->expectExceptionCode(403);

        $manager->createPairingInvitation($admin, "Hacker Laptop");
    }

    public function test_pairing_code_reuse_is_rejected(): void
    {
        $owner = $this->createPlatformOwner();
        $manager = app(DeviceManager::class);
        $invitation = $manager->createPairingInvitation($owner, "Laptop");

        // First redemption succeeds
        $resp1 = $this->postJson('/api/v3/local-worker/v1/enrollments/redeem', [
            'pairing_secret' => $invitation['pairing_secret'],
        ]);
        $resp1->assertStatus(200);

        // Second redemption fails with 409
        $resp2 = $this->postJson('/api/v3/local-worker/v1/enrollments/redeem', [
            'pairing_secret' => $invitation['pairing_secret'],
        ]);
        $resp2->assertStatus(409);
        $resp2->assertJsonPath('error', 'pairing_already_consumed');
    }

    public function test_revoked_device_is_rejected(): void
    {
        $owner = $this->createPlatformOwner();
        $manager = app(DeviceManager::class);
        $invitation = $manager->createPairingInvitation($owner, "Laptop");

        $resp = $this->postJson('/api/v3/local-worker/v1/enrollments/redeem', [
            'pairing_secret' => $invitation['pairing_secret'],
        ]);
        $resp->assertStatus(200);
        $token = $resp->json('device_token');
        $deviceId = $resp->json('device_id');

        // Revoke device
        $manager->revokeDevice($owner, $deviceId, 'Lost laptop');

        // Attempt authentication
        $authResp = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v3/local-worker/v1/device');

        $authResp->assertStatus(403);
        $authResp->assertJsonPath('error', 'device_revoked');
    }

    public function test_admin_routes_owner_access_and_non_owner_denial(): void
    {
        $owner = $this->createPlatformOwner();
        $admin = $this->createNonOwnerAdmin();

        // Non-owner admin can list devices but is_platform_owner is false
        $respList = $this->actingAs($admin)->getJson('/v3/admin/devices');
        $respList->assertStatus(200);
        $respList->assertJsonPath('is_platform_owner', false);

        // Non-owner cannot create invitation
        $respCreateNonOwner = $this->actingAs($admin)->postJson('/v3/admin/devices/invitations', [
            'device_name' => 'Admin Laptop',
        ]);
        $respCreateNonOwner->assertStatus(403);

        // Owner can create invitation
        $respCreateOwner = $this->actingAs($owner)->postJson('/v3/admin/devices/invitations', [
            'device_name' => 'Owner Laptop',
        ]);
        $respCreateOwner->assertStatus(201);
        $this->assertNotEmpty($respCreateOwner->json('pairing_secret'));

        // Redeem it
        $redeemResp = $this->postJson('/api/v3/local-worker/v1/enrollments/redeem', [
            'pairing_secret' => $respCreateOwner->json('pairing_secret'),
        ]);
        $redeemResp->assertStatus(200);
        $deviceId = $redeemResp->json('device_id');

        // Non-owner cannot revoke
        $respRevokeNonOwner = $this->actingAs($admin)->postJson("/v3/admin/devices/{$deviceId}/revoke");
        $respRevokeNonOwner->assertStatus(403);

        // Owner can rotate token
        $respRotate = $this->actingAs($owner)->postJson("/v3/admin/devices/{$deviceId}/rotate");
        $respRotate->assertStatus(200);
        $this->assertNotEmpty($respRotate->json('device_token'));

        // Owner can revoke device
        $respRevokeOwner = $this->actingAs($owner)->postJson("/v3/admin/devices/{$deviceId}/revoke", [
            'reason' => 'Decommissioned',
        ]);
        $respRevokeOwner->assertStatus(200);
        $respRevokeOwner->assertJsonPath('status', 'revoked');
    }
}

