<?php

namespace App\Http\Middleware\V3;

use App\Services\V3\Access\AccessAuditRecorder;
use App\Services\V3\Access\V3AccessResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequireV3Access
{
    public function __construct(
        protected V3AccessResolver $resolver,
        protected AccessAuditRecorder $recorder
    ) {}

    public function handle(Request $request, Closure $next, ?string $requiredRole = null): Response
    {
        $requestId = $request->header('X-Request-Id') ?: (string) Str::uuid();
        $request->attributes->set('v3_request_id', $requestId);
        $routeName = $request->route()?->getName();

        $user = $request->user();

        // 1. Session authentication check
        if (! $user) {
            $this->recorder->recordAccessDenied(
                requestId: $requestId,
                actorUserId: null,
                reasonCode: 'unauthenticated',
                routeName: $routeName
            );

            if ($request->expectsJson()) {
                return $this->buildJsonResponse('unauthenticated', 'Unauthenticated.', 401);
            }

            return redirect()->guest(route('login'))->header('Cache-Control', 'private, no-store');
        }

        // 2. Resolve V3 eligibility with database error protection
        try {
            $resolution = $this->resolver->resolve($user);
        } catch (\Throwable $e) {
            $this->recorder->recordAccessDenied(
                requestId: $requestId,
                actorUserId: $user->id,
                reasonCode: 'access_unavailable',
                routeName: $routeName
            );

            if ($request->expectsJson()) {
                return $this->buildJsonResponse('access_unavailable', 'V3 access is temporarily unavailable.', 503);
            }

            return $this->buildHtmlResponse('V3 access is temporarily unavailable.', 503);
        }

        // 3. Check active state and general V3 eligibility
        if (! $resolution['allowed']) {
            $reason = in_array($resolution['reason'], AccessAuditRecorder::ALLOWED_DENIAL_REASONS, true)
                ? $resolution['reason']
                : 'access_denied';

            $this->recorder->recordAccessDenied(
                requestId: $requestId,
                actorUserId: $user->id,
                reasonCode: $reason,
                routeName: $routeName
            );

            if ($request->expectsJson()) {
                return $this->buildJsonResponse('access_denied', 'V3 access is unavailable.', 403);
            }

            return $this->buildHtmlResponse('V3 access is unavailable.', 403);
        }

        // 4. Check role requirement if specified
        if ($requiredRole !== null && $resolution['role'] !== $requiredRole) {
            $this->recorder->recordAccessDenied(
                requestId: $requestId,
                actorUserId: $user->id,
                reasonCode: 'wrong_role',
                routeName: $routeName
            );

            if ($request->expectsJson()) {
                return $this->buildJsonResponse('access_denied', 'V3 access is unavailable.', 403);
            }

            return $this->buildHtmlResponse('V3 access is unavailable.', 403);
        }

        $request->attributes->set('v3_actor', $resolution['user']);
        $request->attributes->set('v3_access', $resolution['access']);
        $request->attributes->set('v3_role', $resolution['role']);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    protected function buildJsonResponse(string $code, string $message, int $status): Response
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status)->header('Cache-Control', 'private, no-store');
    }

    protected function buildHtmlResponse(string $message, int $status): Response
    {
        $viewName = "v3.errors.{$status}";
        if (view()->exists($viewName)) {
            return response()->view($viewName, ['message' => $message], $status)
                ->header('Cache-Control', 'private, no-store');
        }

        return response("<!DOCTYPE html><html><head><title>{$message}</title></head><body><h1>{$message}</h1></body></html>", $status)
            ->header('Content-Type', 'text/html')
            ->header('Cache-Control', 'private, no-store');
    }
}
