<?php

namespace App\Http\Middleware\V3;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class V3PrivateResponse
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
