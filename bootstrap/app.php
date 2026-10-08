<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: [
            'answer',
            'query_text',
        ]);
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'teacher.access' => \App\Http\Middleware\TeacherAccessMiddleware::class,
            'v3.access' => \App\Http\Middleware\V3\RequireV3Access::class,
            'v3.role' => \App\Http\Middleware\V3\RequireV3Access::class,
            'v3.private' => \App\Http\Middleware\V3\V3PrivateResponse::class,
            'v3.worker.signature' => \App\Http\Middleware\V3\VerifyWorkerSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, \Illuminate\Http\Request $request) {
            if ($request->is('v3') || $request->is('v3/*')) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'error' => [
                            'code' => 'resource_unavailable',
                            'message' => 'V3 access is unavailable.',
                        ],
                    ], 404)->header('Cache-Control', 'private, no-store');
                }

                return response()->view('v3.errors.404', [
                    'message' => 'V3 access is unavailable.',
                ], 404)->header('Cache-Control', 'private, no-store');
            }
        });

        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('v3') || $request->is('v3/*')) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'error' => [
                            'code' => 'access_denied',
                            'message' => 'V3 access is unavailable.',
                        ],
                    ], 403)->header('Cache-Control', 'private, no-store');
                }

                return response()->view('v3.errors.403', [
                    'message' => 'V3 access is unavailable.',
                ], 403)->header('Cache-Control', 'private, no-store');
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e, \Illuminate\Http\Request $request) {
            if ($request->is('v3') || $request->is('v3/*')) {
                $status = $e->getStatusCode();
                if (in_array($status, [401, 403, 404, 409, 503], true)) {
                    if ($request->expectsJson()) {
                        $code = match ($status) {
                            401 => 'unauthenticated',
                            403 => 'access_denied',
                            404 => 'resource_unavailable',
                            409 => 'access_conflict',
                            503 => 'access_unavailable',
                            default => 'access_denied',
                        };

                        return response()->json([
                            'error' => [
                                'code' => $code,
                                'message' => 'V3 access is unavailable.',
                            ],
                        ], $status)->header('Cache-Control', 'private, no-store');
                    }

                    if (view()->exists("v3.errors.{$status}")) {
                        return response()->view("v3.errors.{$status}", [
                            'message' => $e->getMessage() ?: 'V3 access is unavailable.',
                        ], $status)->header('Cache-Control', 'private, no-store');
                    }
                }
            }
        });

        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response, \Throwable $e, \Illuminate\Http\Request $request) {
            if ($request->is('v3') || $request->is('v3/*')) {
                $response->headers->set('Cache-Control', 'private, no-store');
            }

            return $response;
        });
    })->create();
