<?php

namespace App\Http\Middleware;

use App\Services\MembershipAuthorization;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        if ($request->user()?->preferred_language) {
            app()->setLocale($request->user()->preferred_language === 'ar' ? 'ar' : 'fr');
        }
        $user = $request->user();
        $organizations = $user?->organizations()->where('active', true)->get(['organizations.id', 'name', 'type']) ?? collect();
        $organization = $organizations->firstWhere('id', $user?->current_organization_id);
        $residences = collect();
        $residence = null;
        if ($organization) {
            $query = $organization->residences()->with('media')->where('status', '!=', 'archived');
            $membership = $user->organizations()->whereKey($organization->id)->first()?->pivot;
            if ($membership && ! $membership->all_residences) {
                $query->whereHas('users', fn ($builder) => $builder->whereKey($user->id));
            }
            $residences = $query->get(['id', 'name', 'status']);
            $residence = $residences->firstWhere('id', $user->current_residence_id);
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                'permissions' => $organization && $user ? app(MembershipAuthorization::class)->permissions($user, $organization) : [],
            ],
            'tenant' => [
                'organization' => $organization,
                'residence' => $residence,
                'organizations' => $organizations,
                'residences' => $residences,
            ],
            'locale' => app()->getLocale(),
            'flash' => ['success' => fn () => $request->session()->get('success')],
        ];
    }
}
