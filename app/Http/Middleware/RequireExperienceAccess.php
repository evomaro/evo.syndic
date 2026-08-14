<?php

namespace App\Http\Middleware;

use App\Services\ExperienceCapabilities;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

class RequireExperienceAccess
{
    public function handle(Request $request, Closure $next)
    {
        $organization = app(TenantContext::class)->organization();
        abort_unless(app(ExperienceCapabilities::class)->allowsRoute($request->user(), $organization, $request->route()?->getName()), 403);

        return $next($request);
    }
}
