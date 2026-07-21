<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

class RequireTenantPermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        $organization = app(TenantContext::class)->organization();
        abort_unless($request->user()->canInOrganization($permission, $organization), 403);

        return $next($request);
    }
}
