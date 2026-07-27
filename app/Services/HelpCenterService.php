<?php

namespace App\Services;

use App\Models\AllocationKey;
use App\Models\Budget;
use App\Models\ChargeCategory;
use App\Models\Contact;
use App\Models\FinancialAccount;
use App\Models\FinancialExercise;
use App\Models\HelpCenterProgress;
use App\Models\Lot;
use App\Models\LotOwnership;
use App\Models\Organization;
use App\Models\Residence;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;

class HelpCenterService
{
    public function __construct(private MembershipAuthorization $authorization) {}

    public function visibleArticles(User $user, Organization $organization, string $locale): Collection
    {
        $permissions = $this->authorization->permissions($user, $organization);
        $role = $user->organizations()->whereKey($organization->id)->first()?->pivot?->role;

        return collect(config('help_center.articles'))
            ->filter(function (array $article) use ($permissions, $role) {
                if (! empty($article['roles']) && ! in_array($role, $article['roles'], true)) {
                    return false;
                }

                return empty($article['permission']) || in_array($article['permission'], $permissions, true);
            })
            ->map(fn (array $article, string $id) => $this->localizeArticle($id, $article, $locale))
            ->values();
    }

    public function article(User $user, Organization $organization, string $id, string $locale): ?array
    {
        return $this->visibleArticles($user, $organization, $locale)->firstWhere('id', $id);
    }

    public function categories(string $locale): array
    {
        return collect(config('help_center.categories'))
            ->map(fn (array $category, string $id) => [
                'id' => $id,
                'label' => $category[$locale] ?? $category['fr'],
                'order' => $category['order'],
            ])->sortBy('order')->values()->all();
    }

    public function checklist(User $user, Organization $organization, string $locale): array
    {
        $manual = HelpCenterProgress::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->pluck('completed_at', 'article_id');
        $rules = $this->automaticRules($organization);

        return collect(config('help_center.checklist'))->map(function (array $step, string $id) use ($locale, $manual, $rules) {
            $automatic = isset($rules[$id]);
            $complete = $automatic ? $rules[$id] : $manual->has($id);

            return [
                'id' => $id,
                'article_id' => $step['article_id'],
                'title' => $step[$locale]['title'] ?? $step['fr']['title'],
                'purpose' => $step[$locale]['purpose'] ?? $step['fr']['purpose'],
                'who' => $step[$locale]['who'] ?? $step['fr']['who'],
                'prerequisites' => $step[$locale]['prerequisites'] ?? $step['fr']['prerequisites'],
                'path' => $step[$locale]['path'] ?? $step['fr']['path'],
                'fields' => $step[$locale]['fields'] ?? $step['fr']['fields'],
                'actions' => $step[$locale]['actions'] ?? $step['fr']['actions'],
                'result' => $step[$locale]['result'] ?? $step['fr']['result'],
                'mistakes' => $step[$locale]['mistakes'] ?? $step['fr']['mistakes'],
                'unlocks' => $step[$locale]['unlocks'] ?? $step['fr']['unlocks'],
                'automatic' => $automatic,
                'complete' => $complete,
            ];
        })->values()->all();
    }

    public function contextualMap(User $user, Organization $organization): array
    {
        return $this->visibleArticles($user, $organization, app()->getLocale())
            ->flatMap(fn (array $article) => collect($article['routes'])->mapWithKeys(fn (string $route) => [$route => $article['id']]))
            ->all();
    }

    public function mark(User $user, Organization $organization, string $id, bool $complete): void
    {
        abort_unless(collect(config('help_center.checklist'))->has($id), 404);
        abort_if(array_key_exists($id, $this->automaticRules($organization)), 422, __('Cette étape est vérifiée automatiquement.'));

        HelpCenterProgress::query()->updateOrCreate(
            ['organization_id' => $organization->id, 'user_id' => $user->id, 'article_id' => $id],
            ['completed_at' => $complete ? now() : null],
        );
    }

    public function reset(User $user, Organization $organization): void
    {
        HelpCenterProgress::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->delete();
    }

    private function localizeArticle(string $id, array $article, string $locale): array
    {
        $localized = $article[$locale] ?? $article['fr'];

        return [
            'id' => $id,
            'category' => $article['category'],
            'order' => $article['order'] ?? 0,
            'permission' => $article['permission'] ?? null,
            'roles' => $article['roles'] ?? [],
            'routes' => $article['routes'] ?? [],
            'keywords' => array_values(array_unique([
                ...($article['keywords'] ?? []),
                ...($article['keywords_'.$locale] ?? []),
            ])),
            'related' => $article['related'] ?? [],
            'updated_at' => $article['updated_at'] ?? config('help_center.updated_at'),
            'reading_minutes' => $article['reading_minutes'] ?? 5,
            'title' => $localized['title'],
            'summary' => $localized['summary'],
            'sections' => $localized['sections'],
        ];
    }

    private function automaticRules(Organization $organization): array
    {
        $residenceIds = Residence::query()->where('organization_id', $organization->id)->pluck('id');

        return [
            'setup-organization' => filled($organization->name),
            'setup-residence' => $residenceIds->isNotEmpty(),
            'setup-structure' => Lot::query()->whereIn('residence_id', $residenceIds)->exists(),
            'setup-contacts' => Contact::query()->where('organization_id', $organization->id)->exists(),
            'setup-ownerships' => LotOwnership::query()->whereHas('lot', fn ($q) => $q->whereIn('residence_id', $residenceIds))->whereNull('ends_on')->exists(),
            'setup-suppliers' => Supplier::query()->where('organization_id', $organization->id)->exists(),
            'setup-accounting' => FinancialExercise::query()->whereIn('residence_id', $residenceIds)->exists(),
            'setup-accounts' => FinancialAccount::query()->whereIn('residence_id', $residenceIds)->exists(),
            'setup-allocations' => AllocationKey::query()->whereIn('residence_id', $residenceIds)->exists(),
            'setup-charges' => ChargeCategory::query()->whereIn('residence_id', $residenceIds)->exists(),
            'setup-budget' => Budget::query()->whereIn('residence_id', $residenceIds)->exists(),
        ];
    }
}
