<?php

namespace Tests\Feature;

use App\Models\HelpCenterProgress;
use App\Models\Lot;
use App\Models\Organization;
use App\Models\Residence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HelpCenterTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $role = 'owner', string $locale = 'fr'): array
    {
        $user = User::factory()->create(['preferred_language' => $locale]);
        $organization = Organization::factory()->create();
        $residence = Residence::factory()->for($organization)->create();
        $organization->users()->attach($user, ['role' => $role, 'all_residences' => true]);
        $user->update([
            'current_organization_id' => $organization->id,
            'current_residence_id' => $residence->id,
        ]);

        return compact('user', 'organization', 'residence');
    }

    public function test_guest_is_rejected_and_authenticated_user_can_open_help_center(): void
    {
        $this->get(route('help.index'))->assertRedirect(route('login'));

        $context = $this->context();
        $this->actingAs($context['user'])->get(route('help.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HelpCenter/Index')
                ->where('articles.0.id', 'getting-started')
                ->has('categories', 6)
                ->has('checklist'));
    }

    public function test_french_and_arabic_are_rendered_separately_with_stable_ids(): void
    {
        $fr = $this->context('owner', 'fr');
        $this->actingAs($fr['user'])->get(route('help.index', 'getting-started'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedArticleId', 'getting-started')
                ->where('articles.0.title', 'Découvrir EvoSyndic')
                ->where('locale', 'fr'));

        $ar = $this->context('owner', 'ar');
        $this->actingAs($ar['user'])->get(route('help.index', 'getting-started'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedArticleId', 'getting-started')
                ->where('articles.0.title', 'التعرّف على EvoSyndic')
                ->where('locale', 'ar'));

        $articles = config('help_center.articles');
        $this->assertCount(count(array_unique(array_keys($articles))), $articles);
        foreach ($articles as $id => $article) {
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $id);
            $this->assertNotEmpty($article['fr']['title']);
            $this->assertNotEmpty($article['ar']['title']);
            $this->assertNotEmpty($article['fr']['sections']);
            $this->assertNotEmpty($article['ar']['sections']);
            foreach ($article['routes'] ?? [] as $routeName) {
                $this->assertTrue(Route::has($routeName), "Unknown contextual route [$routeName] in [$id].");
            }
        }
    }

    public function test_role_visibility_and_direct_restricted_article_access_are_enforced(): void
    {
        $accountant = $this->context('accountant');

        $this->actingAs($accountant['user'])->get(route('help.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('articles', fn ($articles) => $articles->contains('id', 'accounting')
                    && ! $articles->contains('id', 'maintenance')
                    && $articles->contains('id', 'role-accountant')
                    && ! $articles->contains('id', 'role-owner')));

        $this->actingAs($accountant['user'])
            ->get(route('help.index', 'maintenance'))
            ->assertForbidden();
    }

    public function test_manual_progress_is_persisted_per_user_and_organization_and_can_reset(): void
    {
        $first = $this->context();
        $secondOrganization = Organization::factory()->create();
        $secondResidence = Residence::factory()->for($secondOrganization)->create();
        $secondOrganization->users()->attach($first['user'], ['role' => 'owner', 'all_residences' => true]);

        $this->actingAs($first['user'])
            ->put(route('help.progress', 'setup-profile'), ['complete' => true])
            ->assertRedirect();

        $this->assertDatabaseHas('help_center_progress', [
            'organization_id' => $first['organization']->id,
            'user_id' => $first['user']->id,
            'article_id' => 'setup-profile',
        ]);

        $first['user']->update([
            'current_organization_id' => $secondOrganization->id,
            'current_residence_id' => $secondResidence->id,
        ]);
        $this->actingAs($first['user'])->get(route('help.index', 'first-use-checklist'))
            ->assertInertia(fn (Assert $page) => $page->where(
                'checklist',
                fn ($steps) => collect($steps)->firstWhere('id', 'setup-profile')['complete'] === false,
            ));

        $first['user']->update([
            'current_organization_id' => $first['organization']->id,
            'current_residence_id' => $first['residence']->id,
        ]);
        $this->actingAs($first['user'])->delete(route('help.progress.reset'))->assertRedirect();
        $this->assertSame(0, HelpCenterProgress::query()->where('organization_id', $first['organization']->id)->count());
    }

    public function test_automatic_completion_uses_tenant_data_and_cannot_be_manually_overridden(): void
    {
        $context = $this->context();

        $this->actingAs($context['user'])->get(route('help.index', 'first-use-checklist'))
            ->assertInertia(fn (Assert $page) => $page->where(
                'checklist',
                fn ($steps) => collect($steps)->firstWhere('id', 'setup-residence')['complete'] === true
                    && collect($steps)->firstWhere('id', 'setup-structure')['complete'] === false,
            ));

        Lot::factory()->for($context['residence'])->create();

        $this->actingAs($context['user'])->get(route('help.index', 'first-use-checklist'))
            ->assertInertia(fn (Assert $page) => $page->where(
                'checklist',
                fn ($steps) => collect($steps)->firstWhere('id', 'setup-structure')['complete'] === true,
            ));

        $this->actingAs($context['user'])
            ->put(route('help.progress', 'setup-structure'), ['complete' => false])
            ->assertStatus(422);
    }

    public function test_contextual_route_mapping_and_arabic_search_metadata_are_present(): void
    {
        $context = $this->context('owner', 'ar');

        $this->actingAs($context['user'])->get(route('residences.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('helpContext', fn ($map) => $map['residences.index'] === 'residence-setup-workflow'));

        $this->actingAs($context['user'])->get(route('help.index'))
            ->assertInertia(fn (Assert $page) => $page->where(
                'articles',
                fn ($articles) => collect($articles)
                    ->firstWhere('id', 'payments-credit')['keywords'] !== []
                    && collect(collect($articles)->firstWhere('id', 'payments-credit')['keywords'])
                        ->contains('دفعة'),
            ));
    }
}
