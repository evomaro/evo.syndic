<?php

namespace App\Http\Controllers;

use App\Services\HelpCenterService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HelpCenterController extends Controller
{
    public function index(Request $request, TenantContext $context, HelpCenterService $help, ?string $article = null)
    {
        $organization = $context->organization();
        $locale = app()->getLocale() === 'ar' ? 'ar' : 'fr';
        $articles = $help->visibleArticles($request->user(), $organization, $locale);

        if ($article !== null) {
            abort_unless($articles->contains('id', $article), 403);
        }

        return Inertia::render('HelpCenter/Index', [
            'categories' => $help->categories($locale),
            'articles' => $articles,
            'selectedArticleId' => $article,
            'checklist' => $help->checklist($request->user(), $organization, $locale),
            'updatedAt' => config('help_center.updated_at'),
        ]);
    }

    public function progress(Request $request, TenantContext $context, HelpCenterService $help, string $step)
    {
        $data = $request->validate(['complete' => ['required', 'boolean']]);
        $help->mark($request->user(), $context->organization(), $step, $data['complete']);

        return back();
    }

    public function reset(Request $request, TenantContext $context, HelpCenterService $help)
    {
        $help->reset($request->user(), $context->organization());

        return back()->with('success', __('La progression du guide a été réinitialisée.'));
    }
}
