<?php

namespace App\Providers;

use App\Contracts\ReceiptPdfRenderer;
use App\Models\Assembly;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceWorkOrder;
use App\Models\Residence;
use App\Notifications\PortalNotification;
use App\Policies\AssemblyPolicy;
use App\Policies\MaintenanceRequestPolicy;
use App\Policies\MaintenanceWorkOrderPolicy;
use App\Policies\ResidencePolicy;
use App\Services\DompdfReceiptRenderer;
use App\Services\FinancialDocumentMutationGuard;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        $this->app->singleton(FinancialDocumentMutationGuard::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Residence::class, ResidencePolicy::class);
        Gate::policy(MaintenanceRequest::class, MaintenanceRequestPolicy::class);
        Gate::policy(MaintenanceWorkOrder::class, MaintenanceWorkOrderPolicy::class);
        Gate::policy(Assembly::class, AssemblyPolicy::class);
        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            if ($event->notification instanceof PortalNotification && $event->notification->eventKey) {
                DB::table('notification_dispatches')->where('user_id', $event->notifiable->getKey())->where('event_key', $event->notification->eventKey)->where('channel', $event->channel)->update(['status' => 'delivered', 'last_error' => null, 'updated_at' => now()]);
            }
        });
        Event::listen(NotificationFailed::class, function (NotificationFailed $event): void {
            if ($event->notification instanceof PortalNotification && $event->notification->eventKey) {
                DB::table('notification_dispatches')->where('user_id', $event->notifiable->getKey())->where('event_key', $event->notification->eventKey)->where('channel', $event->channel)->update(['status' => 'failed', 'last_error' => __('La livraison a échoué; une nouvelle tentative sera effectuée.'), 'updated_at' => now()]);
            }
        });
        Vite::prefetch(concurrency: 3);
    }
}
