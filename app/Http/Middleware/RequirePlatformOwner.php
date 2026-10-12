<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequirePlatformOwner
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(app(\App\Services\LearningAccess::class)->isOwner($request->user()), 403);
        return $next($request);
    }
}
