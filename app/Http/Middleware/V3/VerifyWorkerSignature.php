<?php

namespace App\Http\Middleware\V3;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyWorkerSignature
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $contractVersion = $request->header('X-V3-Contract-Version');
        $timestamp = $request->header('X-V3-Timestamp');
        $signature = $request->header('X-V3-Signature');

        $secret = config('services.v3.callback_hmac_key');

        if ($contractVersion !== '1' || empty($timestamp) || empty($signature) || empty($secret)) {
            Log::warning('worker_unauthenticated');
            return response()->json([
                'error' => [
                    'code' => 'worker_unauthenticated',
                    'message' => 'Invalid or missing worker signature headers.',
                ],
            ], 401)->header('Cache-Control', 'private, no-store');
        }

        if (! ctype_digit($timestamp)) {
            Log::warning('worker_unauthenticated');
            return response()->json([
                'error' => [
                    'code' => 'worker_unauthenticated',
                    'message' => 'Invalid timestamp header.',
                ],
            ], 401)->header('Cache-Control', 'private, no-store');
        }

        $now = time();
        $ts = (int) $timestamp;
        if (abs($now - $ts) > 300) {
            Log::warning('worker_unauthenticated');
            return response()->json([
                'error' => [
                    'code' => 'worker_unauthenticated',
                    'message' => 'Worker signature timestamp expired.',
                ],
            ], 401)->header('Cache-Control', 'private, no-store');
        }

        $rawBody = $request->getContent();
        $bodySha256 = strtolower(hash('sha256', $rawBody));
        $method = strtoupper($request->method());
        // exact path without query
        $path = '/' . ltrim($request->path(), '/');

        $stringToSign = "{$method}\n{$path}\n{$ts}\n{$bodySha256}";
        $expectedSignature = strtolower(hash_hmac('sha256', $stringToSign, $secret));

        if (! hash_equals($expectedSignature, strtolower($signature))) {
            Log::warning('worker_unauthenticated');
            return response()->json([
                'error' => [
                    'code' => 'worker_unauthenticated',
                    'message' => 'Worker signature verification failed.',
                ],
            ], 401)->header('Cache-Control', 'private, no-store');
        }

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
