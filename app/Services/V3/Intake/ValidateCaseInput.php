<?php

namespace App\Services\V3\Intake;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ValidateCaseInput
{
    /**
     * Validate the case start input after document visibility has been resolved.
     *
     * @return array{valid: bool, reason_code?: string, message?: string, action?: string, idempotency_key?: string}
     */
    public function validate(Request $request): array
    {
        $allKeys = array_keys($request->all());
        $allowedKeys = ['_token', 'document_id', 'action', 'idempotency_key'];
        $extraKeys = array_diff($allKeys, $allowedKeys);

        if (! empty($extraKeys)) {
            return [
                'valid' => false,
                'reason_code' => 'validation_error',
                'message' => 'Request contains unrecognized fields.',
            ];
        }

        $action = $request->input('action');
        if (! in_array($action, ['process', 'process_again'], true)) {
            return [
                'valid' => false,
                'reason_code' => 'validation_error',
                'message' => 'Action must be either "process" or "process_again".',
            ];
        }

        $idempotencyKey = $request->input('idempotency_key');
        if (! is_string($idempotencyKey) || ! Str::isUuid($idempotencyKey)) {
            return [
                'valid' => false,
                'reason_code' => 'validation_error',
                'message' => 'Idempotency key must be a valid UUID.',
            ];
        }

        return [
            'valid' => true,
            'action' => $action,
            'idempotency_key' => strtolower($idempotencyKey),
        ];
    }
}
