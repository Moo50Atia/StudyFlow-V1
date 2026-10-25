<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Models\V3\ProcessingDevice;
use App\Services\LearningAccess;
use App\Services\V3\LocalWorker\DeviceManager;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerProtocolException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceManagementController extends Controller
{
    public function __construct(
        protected DeviceManager $deviceManager,
        protected LearningAccess $learningAccess
    ) {}

    public function index(Request $request): mixed
    {
        $devices = ProcessingDevice::query()
            ->select(['id', 'name', 'status', 'scopes', 'capabilities', 'expires_at', 'last_seen_at', 'created_at'])
            ->orderBy('created_at', 'desc')
            ->get();

        $data = [
            'devices' => $devices,
            'is_platform_owner' => $this->learningAccess->canManageProcessingDevices($request->user()),
        ];

        if (! $request->header('X-Inertia') && ($request->wantsJson() || $request->ajax() || $request->expectsJson())) {
            return response()->json($data);
        }

        return \App\Support\InertiaPage::render('Admin/Devices', $data);
    }

    public function createInvitation(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->learningAccess->canManageProcessingDevices($user)) {
            return response()->json(['error' => 'forbidden', 'message' => 'Only platform owner can create device invitations'], 403);
        }

        $name = (string) $request->input('device_name', 'Local Worker');
        $scopes = $request->input('scopes', ['tenant:all']);
        $capabilities = $request->input('capabilities', ['notebooklm_v1', 'static_three_file_v1']);

        try {
            $invitation = $this->deviceManager->createPairingInvitation($user, $name, null, $scopes, $capabilities);
            return response()->json($invitation, 201);
        } catch (LocalWorkerProtocolException $e) {
            return response()->json(['error' => $e->getProtocolCode(), 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function revoke(Request $request, string $device): JsonResponse
    {
        $user = $request->user();
        if (! $this->learningAccess->canManageProcessingDevices($user)) {
            return response()->json(['error' => 'forbidden', 'message' => 'Only platform owner can revoke devices'], 403);
        }

        $reason = (string) $request->input('reason', 'manual_revocation');

        try {
            $revoked = $this->deviceManager->revokeDevice($user, $device, $reason);
            return response()->json([
                'device_id' => $revoked->id,
                'status' => $revoked->status,
                'revoked_at' => $revoked->revoked_at?->toIso8601String(),
            ]);
        } catch (LocalWorkerProtocolException $e) {
            return response()->json(['error' => $e->getProtocolCode(), 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function rotate(Request $request, string $device): JsonResponse
    {
        $user = $request->user();
        if (! $this->learningAccess->canManageProcessingDevices($user)) {
            return response()->json(['error' => 'forbidden', 'message' => 'Only platform owner can rotate device tokens'], 403);
        }

        try {
            $result = $this->deviceManager->rotateDeviceToken($user, $device);
            return response()->json($result);
        } catch (LocalWorkerProtocolException $e) {
            return response()->json(['error' => $e->getProtocolCode(), 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }
}
