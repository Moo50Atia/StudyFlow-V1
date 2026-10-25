<?php

namespace App\Services\V3\Settings;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class SettingsConflict extends Exception
{
    public function __construct(string $message = 'Settings changed. Reload and retry.')
    {
        parent::__construct($message);
    }

    public function render($request): JsonResponse|Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => [
                    'code' => 'settings_conflict',
                    'message' => 'Settings changed. Reload and retry.',
                ],
            ], 409)->header('Cache-Control', 'private, no-store');
        }

        return \App\Support\InertiaPage::render('v3.errors.409', [
            'message' => 'Settings changed. Reload and retry.',
        ], 409, ['Cache-Control' => 'private, no-store']);
    }
}
