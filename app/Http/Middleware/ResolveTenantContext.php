<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

class ResolveTenantContext
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $organizations = $user->organizations()->where('active', true)->get();
        $organization = $organizations->firstWhere('id', $user->current_organization_id) ?? ($organizations->count() === 1 ? $organizations->first() : null);
        if ($organization && $user->current_organization_id !== $organization->id) {
            $user->forceFill(['current_organization_id' => $organization->id])->save();
        }

        $residence = null;
        if ($organization) {
            $query = $organization->residences()->where('status', '!=', 'archived');
            $membership = $organization->users()->whereKey($user->id)->first()?->pivot;
            if ($membership && ! $membership->all_residences) {
                $query->whereHas('users', fn ($q) => $q->whereKey($user->id));
            }
            $allowed = $query->get();
            $residence = $allowed->firstWhere('id', $user->current_residence_id) ?? ($allowed->count() === 1 ? $allowed->first() : null);
            if ($residence && $user->current_residence_id !== $residence->id) {
                $user->forceFill(['current_residence_id' => $residence->id])->save();
            }
            if (! $residence && $user->current_residence_id) {
                $user->forceFill(['current_residence_id' => null])->save();
            }
        }
        app()->instance(TenantContext::class, new TenantContext($organization, $residence));
        app()->setLocale($user->preferred_language === 'ar' ? 'ar' : 'fr');

        return $next($request);
    }
}
