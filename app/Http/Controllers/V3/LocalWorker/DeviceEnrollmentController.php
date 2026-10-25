<?php

namespace App\Http\Controllers\V3\LocalWorker;

use App\Http\Controllers\Controller;
use App\Models\V3\ProcessingDevice;
use App\Services\V3\LocalWorker\DeviceManager;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerProtocolException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceEnrollmentController extends Controller
{
    public function __construct(
        protected DeviceManager $deviceManager
    ) {}

    public function redeem(Request $request): JsonResponse
    {
        $secret = (string) ($request->input('pairing_secret') ?? $request->input('pairing_code') ?? '');
        if (empty(trim($secret))) {
            return response()->json([
                'error' => 'invalid_pairing',
                'message' => 'Missing pairing_secret',
            ], 422);
        }

        try {
            $result = $this->deviceManager->redeemPairing($secret);
            return response()->json($result, 200);
        } catch (LocalWorkerProtocolException $e) {
            return response()->json([
                'error' => $e->getProtocolCode(),
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }
    }

    public function getDevice(Request $request): JsonResponse
    {
        /** @var ProcessingDevice $device */
        $device = $request->attributes->get('processing_device');

        return response()->json([
            'schema_version' => 1,
            'device_id' => $device->id,
            'name' => $device->name,
            'status' => $device->status,
            'scopes' => $device->scopes,
            'capabilities' => $device->capabilities,
            'expires_at' => $device->expires_at->toIso8601String(),
            'server_time' => now()->toIso8601String(),
        ], 200);
    }
}
