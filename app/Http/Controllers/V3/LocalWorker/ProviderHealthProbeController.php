<?php

namespace App\Http\Controllers\V3\LocalWorker;

use App\Http\Controllers\Controller;
use App\Models\V3\ProcessingDevice;
use App\Services\V3\LocalWorker\ProviderHealthProbeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProviderHealthProbeController extends Controller
{
    public function pending(Request $request, ProviderHealthProbeService $probes): JsonResponse
    {
        $device = $request->attributes->get('processing_device');
        abort_unless($device instanceof ProcessingDevice && $device->isActive(), 401);
        return response()->json($probes->pendingForDevice($device))->header('Cache-Control', 'no-store');
    }

    public function report(Request $request, ProviderHealthProbeService $probes): JsonResponse
    {
        $device = $request->attributes->get('processing_device');
        abort_unless($device instanceof ProcessingDevice && $device->isActive(), 401);
        $result = $probes->report($device, $request->all());
        if (isset($result['error'])) {
            $status = $result['error'] === 'probe_expired' ? 410 : 422;
            return response()->json(['error' => $result['error']], $status)->header('Cache-Control', 'no-store');
        }
        return response()->json($result)->header('Cache-Control', 'no-store');
    }
}
