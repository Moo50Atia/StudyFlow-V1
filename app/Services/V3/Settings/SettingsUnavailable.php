<?php

namespace App\Services\V3\Settings;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class SettingsUnavailable extends Exception
{
    public function __construct(string $message = 'V3 settings are unavailable.')
    {
        parent::__construct($message);
    }

    public function render($request): JsonResponse|Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => [
                    'code' => 'settings_unavailable',
                    'message' => 'V3 settings are unavailable.',
                ],
            ], 503)->header('Cache-Control', 'private, no-store');
        }

        if (view()->exists('v3.errors.503')) {
            return response()->view('v3.errors.503', [
                'message' => 'V3 settings are unavailable.',
            ], 503)->header('Cache-Control', 'private, no-store');
        }

        return response('V3 settings are unavailable.', 503)
            ->header('Cache-Control', 'private, no-store');
    }
}
