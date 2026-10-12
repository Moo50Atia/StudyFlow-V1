<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Services\V3\Workflow\BridgeClient;
use Illuminate\Http\JsonResponse;

class NotebookHealthController extends Controller
{
    public function check(BridgeClient $bridge): JsonResponse
    {
        $result = $bridge->checkNotebookLmHealth();
        $data = $result['data'] ?? [];

        if (($result['status'] ?? 503) === 200 && ($data['healthy'] ?? false) === true) {
            return response()->json([
                'data' => [
                    'healthy' => true,
                    'message' => 'All right. NotebookLM authentication is working.',
                    'checked_at' => $data['checked_at'] ?? now()->toIso8601String(),
                ],
            ])->header('Cache-Control', 'private, no-store');
        }

        if (($result['status'] ?? 503) === 200 && ($data['state'] ?? null) === 'authentication_required') {
            $command = 'cd ' . escapeshellarg(base_path())
                . ' && .venv/bin/python scripts/v3/setup_local_credentials.py'
                . ' --refresh-notebooklm --manual-notebooklm --bridge-sdk';

            return response()->json([
                'data' => [
                    'healthy' => false,
                    'state' => 'authentication_required',
                    'message' => 'NotebookLM needs a fresh Google session. Run this command in your project terminal, then check again:',
                    'terminal_command' => $command,
                    'after_command' => 'Restart the FastAPI bridge and Temporal worker so they load the refreshed session, then click Check Notebook Health again.',
                ],
            ])->header('Cache-Control', 'private, no-store');
        }

        return response()->json([
            'error' => [
                'code' => 'notebooklm_unavailable',
                'message' => 'The health check could not reach NotebookLM. Check that the bridge and network are available, then try again.',
            ],
        ], 503)->header('Cache-Control', 'private, no-store');
    }
}
