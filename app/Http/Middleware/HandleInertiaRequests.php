<?php

namespace App\Http\Middleware;

use App\Services\ExperienceCapabilities;
use App\Services\HelpCenterService;
use App\Services\MembershipAuthorization;
use Closure;
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

    public function handle(Request $request, Closure $next)
    {
        $response = parent::handle($request, $next);

        if ($request->user()) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }

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
        $organizations = $user?->organizations()->where('active', true)->get(['organizations.id', 'name', 'type', 'experience_mode']) ?? collect();
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
        $membership = $organization ? $user?->organizations()->whereKey($organization->id)->first()?->pivot : null;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                'role' => $membership?->role,
                'permissions' => $organization && $user ? app(MembershipAuthorization::class)->permissions($user, $organization) : [],
                'capabilities' => $organization && $user ? app(ExperienceCapabilities::class)->capabilities($user, $organization) : [],
            ],
            'tenant' => [
                'organization' => $organization,
                'residence' => $residence,
                'organizations' => $organizations,
                'residences' => $residences,
            ],
            'locale' => app()->getLocale(),
            'notificationUnreadCount' => fn () => $request->user()?->unreadNotifications()->count() ?? 0,
            'helpContext' => fn () => $organization && $user
                ? app(HelpCenterService::class)->contextualMap($user, $organization)
                : [],
            'flash' => ['success' => fn () => $request->session()->get('success')],
        ];
    }
}
