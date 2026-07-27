<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\ExpenseCommitment;
use App\Models\NotificationPreference;
use App\Notifications\PortalNotification;
use App\Services\BudgetService;
use App\Services\BudgetThresholdNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\CreatesPhaseThreeContext;
use Tests\TestCase;

class BudgetThresholdNotificationTest extends TestCase
{
    use CreatesPhaseThreeContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
    }

    public static function percentageBoundaries(): array
    {
        return [
            '79.99 percent' => [7999, 0], 'exactly 80 percent' => [8000, 1], '80.01 percent' => [8001, 1],
            '99.99 percent' => [9999, 1], 'exactly 100 percent' => [10000, 2], '100.01 percent' => [10001, 3],
        ];
    }

    #[DataProvider('percentageBoundaries')]
    public function test_actual_percentage_boundaries(int $actualCents, int $expectedEvents): void
    {
        $context = $this->phaseThreeContext();
        $this->activeBudget($context);
        if ($actualCents > 0) {
            $this->makePhaseThreeInvoice($context, $actualCents, '2026-10-01', 'validated');
        }

        $result = app(BudgetThresholdNotificationService::class)->dispatch([], true);

        $this->assertSame($expectedEvents, $result['events']);
        $this->assertSame($expectedEvents * 2, DB::table('notification_dispatches')->where('event_type', 'budget_threshold')->count());
        Notification::assertSentToTimes($context['user'], PortalNotification::class, $expectedEvents * 2);
    }

    public static function consumptionSources(): array
    {
        return ['actual' => ['actual'], 'projected' => ['projected']];
    }

    #[DataProvider('consumptionSources')]
    public function test_threshold_records_actual_or_projected_source(string $source): void
    {
        $context = $this->phaseThreeContext();
        $this->activeBudget($context);
        if ($source === 'actual') {
            $this->makePhaseThreeInvoice($context, 8000, '2026-10-01', 'validated');
        } else {
            ExpenseCommitment::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'financial_exercise_id' => $context['exercise']->id, 'expense_category_id' => $context['category']->id, 'title' => 'Projected', 'committed_on' => '2026-01-01', 'amount_cents' => 8000, 'status' => 'approved']);
        }

        app(BudgetThresholdNotificationService::class)->dispatch([], true);

        Notification::assertSentTo($context['user'], PortalNotification::class, fn ($notification) => $notification->payload['basis'] === $source);
    }

    public function test_repeated_run_is_idempotent_but_revised_budget_has_new_event_key(): void
    {
        $context = $this->phaseThreeContext();
        $budget = $this->activeBudget($context);
        $this->makePhaseThreeInvoice($context, 8000, '2026-10-01', 'validated');
        $service = app(BudgetThresholdNotificationService::class);
        $service->dispatch([], true);
        $service->dispatch([], true);
        $this->assertDatabaseCount('notification_dispatches', 2);

        $revised = app(BudgetService::class)->revise($budget, $context['user'], 'Nouvelle enveloppe approuvée');
        app(BudgetService::class)->approve($revised, $context['user']);
        $service->dispatch([], true);

        $this->assertDatabaseCount('notification_dispatches', 4);
    }

    public function test_dry_run_and_inactive_budgets_do_not_write(): void
    {
        $context = $this->phaseThreeContext();
        foreach (['draft', 'archived'] as $status) {
            $budget = Budget::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'financial_exercise_id' => $context['exercise']->id, 'version' => $status === 'draft' ? 1 : 2, 'title' => $status, 'status' => $status]);
            $budget->lines()->create(['expense_category_id' => $context['category']->id, 'planned_cents' => 10000]);
        }
        $this->makePhaseThreeInvoice($context, 10001, '2026-10-01', 'validated');

        $result = app(BudgetThresholdNotificationService::class)->dispatch([], false);

        $this->assertSame(0, $result['events']);
        $this->assertDatabaseCount('notification_dispatches', 0);
        Notification::assertNothingSent();
    }

    public function test_locked_budget_is_active_and_channel_preferences_are_respected(): void
    {
        $context = $this->phaseThreeContext();
        $budget = $this->activeBudget($context);
        app(BudgetService::class)->lock($budget, $context['user']);
        NotificationPreference::create(['user_id' => $context['user']->id, 'organization_id' => $context['organization']->id, 'database_enabled' => true, 'email_enabled' => false]);
        $this->makePhaseThreeInvoice($context, 8000, '2026-10-01', 'validated');

        app(BudgetThresholdNotificationService::class)->dispatch([], true);

        $this->assertDatabaseHas('notification_dispatches', ['event_type' => 'budget_threshold', 'channel' => 'database']);
        $this->assertDatabaseMissing('notification_dispatches', ['event_type' => 'budget_threshold', 'channel' => 'mail']);
    }

    public function test_manager_from_other_residence_and_unauthorized_member_receive_nothing(): void
    {
        $context = $this->phaseThreeContext('accountant', false);
        $otherResidence = $this->addResidence($context, false);
        $unauthorized = $this->phaseThreeContext('coproprietaire');
        $context['organization']->users()->attach($unauthorized['user'], ['role' => 'coproprietaire', 'all_residences' => true]);
        $this->activeBudget($context);
        $this->makePhaseThreeInvoice($context, 8000, '2026-10-01', 'validated');
        $context['user']->residences()->detach($context['residence']->id);
        $context['user']->residences()->attach($otherResidence->id);

        app(BudgetThresholdNotificationService::class)->dispatch([], true);

        Notification::assertNotSentTo($context['user'], PortalNotification::class);
        Notification::assertNotSentTo($unauthorized['user'], PortalNotification::class);
    }

    private function activeBudget(array $context): Budget
    {
        $budget = Budget::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'financial_exercise_id' => $context['exercise']->id, 'version' => 1, 'title' => 'Budget']);
        $budget->lines()->create(['expense_category_id' => $context['category']->id, 'planned_cents' => 10000]);

        return app(BudgetService::class)->approve($budget, $context['user']);
    }
}
