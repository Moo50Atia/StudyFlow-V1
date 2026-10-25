<?php

namespace App\Http\Middleware;

use App\Services\V3\LocalWorker\DeviceManager;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerProtocolException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LocalWorkerDeviceAuth
{
    public function __construct(
        protected DeviceManager $deviceManager
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization', '');
        if (! str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'error' => 'unauthenticated',
                'message' => 'Missing or invalid Bearer token',
            ], 401);
        }

        try {
            $device = $this->deviceManager->authenticateDevice($authHeader);
            $request->attributes->set('processing_device', $device);
            $request->attributes->set('local_worker_device', $device);
            $pairing = \App\Models\V3\DevicePairing::where('redeemed_device_id', $device->id)->first();
            $request->attributes->set('local_worker_pairing', $pairing);
        } catch (LocalWorkerProtocolException $e) {
            return response()->json([
                'error' => $e->getProtocolCode(),
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

        return $next($request);
    }
}
