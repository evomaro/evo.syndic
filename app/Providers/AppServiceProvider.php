<?php

namespace App\Providers;

use App\Contracts\ReceiptPdfRenderer;
use App\Models\Residence;
use App\Policies\ResidencePolicy;
use App\Services\DompdfReceiptRenderer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ReceiptPdfRenderer::class, DompdfReceiptRenderer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Residence::class, ResidencePolicy::class);
        Vite::prefetch(concurrency: 3);
    }
}
