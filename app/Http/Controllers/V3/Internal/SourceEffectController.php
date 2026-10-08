<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Services\V3\Source\SourceEffectLedger;
use Illuminate\Http\Request;

class SourceEffectController extends Controller
{
    public function __construct(
        protected SourceEffectLedger $ledger,
    ) {}

    public function claim(string $caseId, string $operation, Request $request)
    {
        $raw = json_decode($request->getContent(), true) ?: [];
        $activationId = $raw['activation_id'] ?? $request->input('activation_id');
        $requestDigest = $raw['request_digest'] ?? $request->input('request_digest');

        if (! $activationId || ! $requestDigest) {
            return response()->json([
                'error' => [
                    'code' => 'source_invalid',
                    'message' => 'Source request is invalid.',
                ],
            ], 422, ['Cache-Control' => 'private, no-store']);
        }

        try {
            $data = $this->ledger->claimEffect($caseId, $operation, $activationId, $requestDigest);
            return response()->json(['data' => $data], 200, ['Cache-Control' => 'private, no-store']);
        } catch (\DomainException $e) {
            $code = $e->getMessage();
            $status = ($code === 'source_unavailable') ? 404 : 409;
            $message = ($code === 'source_unavailable') ? 'Source is unavailable.' : 'Source state changed.';
            return response()->json([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ], $status, ['Cache-Control' => 'private, no-store']);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'source_invalid',
                    'message' => 'Source request is invalid.',
                ],
            ], 422, ['Cache-Control' => 'private, no-store']);
        }
    }

    public function result(string $effectId, Request $request)
    {
        $raw = json_decode($request->getContent(), true) ?: [];
        $state = $raw['state'] ?? $request->input('state');
        $providerRef = $raw['provider_ref'] ?? $request->input('provider_ref');
        $proofTitle = $raw['proof_title'] ?? $request->input('proof_title');
        $proofSha256 = $raw['proof_sha256'] ?? $request->input('proof_sha256');
        $errorCode = $raw['error_code'] ?? $request->input('error_code');

        if (! in_array($state, ['uncertain', 'registered', 'confirmed', 'blocked'], true)) {
            return response()->json([
                'error' => [
                    'code' => 'source_invalid',
                    'message' => 'Source request is invalid.',
                ],
            ], 422, ['Cache-Control' => 'private, no-store']);
        }

        try {
            $data = $this->ledger->recordResult(
                $effectId,
                $state,
                $providerRef,
                $proofTitle,
                $proofSha256,
                $errorCode
            );
            return response()->json(['data' => $data], 200, ['Cache-Control' => 'private, no-store']);
        } catch (\DomainException $e) {
            $code = $e->getMessage();
            $status = ($code === 'source_unavailable') ? 404 : 409;
            $message = ($code === 'source_unavailable') ? 'Source is unavailable.' : 'Source state changed.';
            return response()->json([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ], $status, ['Cache-Control' => 'private, no-store']);
        }
    }
}
