<?php

namespace Tests\Feature;

use App\Models\NotificationPreference;
use App\Notifications\PortalNotification;
use App\Services\ManagerNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\CreatesPhaseThreeContext;
use Tests\TestCase;

class PhaseThreeNotificationPreferenceTest extends TestCase
{
    use CreatesPhaseThreeContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public static function channelMatrix(): array
    {
        $events = ['budget_threshold', 'overdue_supplier_invoice', 'scheduled_publication_failed', 'document_generation_failed', 'contract_renewed', 'contract_renewal_failed', 'contract_expiring'];
        $channels = [
            'both on' => [true, true, ['database', 'mail']], 'in-app only' => [true, false, ['database']],
            'email only' => [false, true, ['mail']], 'both off' => [false, false, []],
        ];
        $cases = [];
        foreach ($events as $event) {
            foreach ($channels as $name => [$database, $email, $expected]) {
                $cases["{$event} / {$name}"] = [$event, $database, $email, $expected];
            }
        }

        return $cases;
    }

    #[DataProvider('channelMatrix')]
    public function test_each_event_respects_channel_matrix(string $event, bool $database, bool $email, array $expectedChannels): void
    {
        $context = $this->phaseThreeContext();
        NotificationPreference::create(['user_id' => $context['user']->id, 'organization_id' => $context['organization']->id, 'database_enabled' => $database, 'email_enabled' => $email]);

        $sent = $this->dispatch($context, $event, "{$event}:case");

        $this->assertSame(count($expectedChannels), $sent);
        $this->assertEqualsCanonicalizing($expectedChannels, DB::table('notification_dispatches')->pluck('channel')->all());
        if ($expectedChannels === []) {
            Notification::assertNothingSent();
        } else {
            Notification::assertSentToTimes($context['user'], PortalNotification::class, count($expectedChannels));
        }
    }

    public static function languageMatrix(): array
    {
        $cases = [];
        foreach (['budget_threshold', 'overdue_supplier_invoice', 'scheduled_publication_failed', 'document_generation_failed', 'contract_renewed', 'contract_renewal_failed', 'contract_expiring'] as $event) {
            foreach (['fr', 'ar'] as $locale) {
                $cases["{$event} / {$locale}"] = [$event, $locale];
            }
        }

        return $cases;
    }

    #[DataProvider('languageMatrix')]
    public function test_each_event_uses_preferred_language(string $event, string $locale): void
    {
        $context = $this->phaseThreeContext();
        $context['user']->update(['preferred_language' => $locale]);
        NotificationPreference::create(['user_id' => $context['user']->id, 'organization_id' => $context['organization']->id, 'database_enabled' => true, 'email_enabled' => false]);

        $this->dispatch($context, $event, "{$event}:locale:{$locale}");

        Notification::assertSentTo($context['user'], PortalNotification::class, fn ($notification) => $notification->payload['language'] === $locale);
    }

    public function test_revoked_membership_unauthorized_residence_muting_and_duplicate_execution_stop_delivery(): void
    {
        $revoked = $this->phaseThreeContext();
        $revoked['organization']->users()->detach($revoked['user']->id);
        $this->assertSame(0, $this->dispatch($revoked, 'budget_threshold', 'revoked'));

        $scoped = $this->phaseThreeContext('accountant', false);
        $otherResidence = $this->addResidence($scoped, true);
        $scoped['user']->residences()->detach($scoped['residence']->id);
        $scoped['user']->update(['current_residence_id' => $otherResidence->id]);
        $this->assertSame(0, $this->dispatch($scoped, 'budget_threshold', 'other-residence'));

        $muted = $this->phaseThreeContext();
        NotificationPreference::create(['user_id' => $muted['user']->id, 'organization_id' => $muted['organization']->id, 'database_enabled' => true, 'email_enabled' => true, 'muted_events' => ['contract_expiring']]);
        $this->assertSame(0, $this->dispatch($muted, 'contract_expiring', 'muted'));

        $duplicate = $this->phaseThreeContext();
        $this->dispatch($duplicate, 'contract_renewed', 'duplicate');
        $this->dispatch($duplicate, 'contract_renewed', 'duplicate');
        $this->assertSame(2, DB::table('notification_dispatches')->where('user_id', $duplicate['user']->id)->count());
    }

    public function test_failed_delivery_status_is_retryable_and_success_updates_status(): void
    {
        $context = $this->phaseThreeContext();
        NotificationPreference::create(['user_id' => $context['user']->id, 'organization_id' => $context['organization']->id, 'database_enabled' => true, 'email_enabled' => false]);
        $this->dispatch($context, 'document_generation_failed', 'retryable');
        DB::table('notification_dispatches')->update(['status' => 'failed', 'last_error' => 'safe failure']);

        $this->dispatch($context, 'document_generation_failed', 'retryable');

        $this->assertDatabaseHas('notification_dispatches', ['event_key' => 'retryable', 'status' => 'queued', 'attempt_count' => 2, 'last_error' => null]);
        $notification = new PortalNotification(['title' => 'x', 'message' => 'x'], ['database'], 'retryable');
        event(new NotificationSent($context['user'], $notification, 'database', null));
        $this->assertDatabaseHas('notification_dispatches', ['event_key' => 'retryable', 'status' => 'delivered']);
    }

    private function dispatch(array $context, string $event, string $key): int
    {
        return app(ManagerNotificationService::class)->dispatch($context['organization'], $context['residence'], $event, $key, ['title' => 'Title', 'message' => 'Message', 'data' => ['event' => $event]], '/notifications', true);
    }
}
