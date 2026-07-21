<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $requested = $request->query('locale');
        if (in_array($requested, ['fr', 'ar'], true)) {
            $request->session()->put('locale', $requested);
        }
        $locale = $request->user()?->preferred_language ?? $request->session()->get('locale', 'fr');
        app()->setLocale($locale === 'ar' ? 'ar' : 'fr');

        return $next($request);
    }
}
