<?php

use App\Models\FundCallSchedule;
use App\Models\User;
use App\Services\AnnouncementService;
use App\Services\BudgetThresholdNotificationService;
use App\Services\ContractExpirationNotificationService;
use App\Services\ExpenseAuditService;
use App\Services\FundCallScheduleService;
use App\Services\GovernanceDeadlineService;
use App\Services\MaintenanceSlaService;
use App\Services\OverdueSupplierInvoiceNotificationService;
use App\Services\PreventiveMaintenanceScheduler;
use App\Services\ResidenceDocumentService;
use App\Services\SupplierContractRenewalService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('evosyndic:generate-fund-calls {--residence=} {--date=} {--dry-run} {--apply}', function (FundCallScheduleService $service) {
    $apply = (bool) $this->option('apply') && ! $this->option('dry-run');
    $date = CarbonImmutable::parse($this->option('date') ?: now()->toDateString());
    $query = FundCallSchedule::query()->with('residence')->where('active', true)->whereDate('next_generation_on', '<=', $date);
    if ($this->option('residence')) {
        $query->where('residence_id', $this->option('residence'));
    }
    foreach ($query->get() as $schedule) {
        $actor = User::query()->whereHas('organizations', fn ($q) => $q->where('organizations.id', $schedule->organization_id)->whereIn('role', ['owner', 'accountant']))->first();
        if (! $actor) {
            $this->warn("Schedule {$schedule->id}: no authorized actor");

            continue;
        }
        $result = $service->generate($schedule, $date, $actor, $apply);
        $this->line("Schedule {$schedule->id}: {$result['status']}");
    }
    if (! $apply) {
        $this->info('Dry run only. Pass --apply to generate drafts.');
    }
})->purpose('Preview or generate due EvoSyndic fund calls safely');

Schedule::command('evosyndic:generate-fund-calls --apply')->dailyAt('01:15')->withoutOverlapping();

Artisan::command('evosyndic:publish-scheduled-announcements', function (AnnouncementService $service) {
    $this->info($service->publishDue().' scheduled announcement(s) published.');
})->purpose('Publish due resident announcements idempotently');

Artisan::command('evosyndic:audit-expenses {--organization=} {--residence=} {--exercise=} {--supplier=} {--invoice=} {--settlement=} {--json}', function (ExpenseAuditService $service) {
    $filters = collect(['organization', 'residence', 'exercise', 'supplier', 'invoice', 'settlement'])->mapWithKeys(fn ($key) => [$key => $this->option($key)])->filter()->all();
    $report = $service->run($filters);
    if ($this->option('json')) {
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $report['ok'] ? 0 : 1;
    }
    $this->line("Invoices: {$report['checked']['invoices']}; settlements: {$report['checked']['settlements']}; vouchers: {$report['checked']['vouchers']}");
    foreach ($report['violations'] as $violation) {
        $this->error("{$violation['check']} [{$violation['record']}]: {$violation['detail']}");
    }
    $report['ok'] ? $this->info('Expense audit passed.') : $this->warn('Expense audit found violations.');

    return $report['ok'] ? 0 : 1;
})->purpose('Read-only consistency audit for EvoSyndic expenses');

Artisan::command('evosyndic:notify-contract-expirations {--days=30}', function (ContractExpirationNotificationService $service) {
    $this->info($service->dispatch((int) $this->option('days')).' contract expiration notification(s) queued.');
})->purpose('Queue retry-safe contract expiration alerts');

Schedule::command('evosyndic:publish-scheduled-announcements')->everyMinute()->withoutOverlapping();
Schedule::command('evosyndic:notify-contract-expirations')->dailyAt('08:00')->withoutOverlapping();

Artisan::command('evosyndic:check-budget-thresholds {--organization=} {--residence=} {--exercise=} {--dry-run} {--apply}', function (BudgetThresholdNotificationService $service) {
    $apply = (bool) $this->option('apply') && ! $this->option('dry-run');
    $result = $service->dispatch(['organization' => $this->option('organization'), 'residence' => $this->option('residence'), 'exercise' => $this->option('exercise')], $apply);
    $this->info("{$result['events']} threshold event(s); {$result['deliveries']} delivery candidate(s).".($apply ? '' : ' Dry run; no writes.'));
})->purpose('Detect active-budget consumption thresholds without duplicate delivery');

Artisan::command('evosyndic:notify-overdue-supplier-invoices {--organization=} {--residence=} {--date=} {--dry-run} {--apply}', function (OverdueSupplierInvoiceNotificationService $service) {
    $apply = (bool) $this->option('apply') && ! $this->option('dry-run');
    $date = CarbonImmutable::parse($this->option('date') ?: now()->toDateString());
    $result = $service->dispatch($date, ['organization' => $this->option('organization'), 'residence' => $this->option('residence')], $apply);
    $this->info("{$result['events']} overdue event(s); {$result['deliveries']} delivery candidate(s).".($apply ? '' : ' Dry run; no writes.'));
})->purpose('Notify authorized managers of overdue supplier invoices idempotently');

Schedule::command('evosyndic:check-budget-thresholds --apply')->dailyAt('07:30')->withoutOverlapping();
Schedule::command('evosyndic:notify-overdue-supplier-invoices --apply')->dailyAt('07:45')->withoutOverlapping();

Artisan::command('evosyndic:renew-supplier-contracts {--organization=} {--residence=} {--date=} {--dry-run} {--apply}', function (SupplierContractRenewalService $service) {
    $apply = (bool) $this->option('apply') && ! $this->option('dry-run');
    $date = CarbonImmutable::parse($this->option('date') ?: now()->toDateString());
    $result = $service->dispatch($date, ['organization' => $this->option('organization'), 'residence' => $this->option('residence')], $apply);
    $this->info("{$result['eligible']} eligible; {$result['renewed']} renewed; {$result['failed']} configuration failure(s).".($apply ? '' : ' Dry run; no writes.'));
})->purpose('Renew eligible automatic supplier contracts idempotently');

Schedule::command('evosyndic:renew-supplier-contracts --apply')->dailyAt('06:45')->withoutOverlapping();

Artisan::command('evosyndic:publish-scheduled-documents', function (ResidenceDocumentService $service) {
    $result = $service->publishDue();
    $this->info("{$result['published']} document(s) published; {$result['failed']} failed.");
})->purpose('Publish due shared documents with recoverable failure state');

Schedule::command('evosyndic:publish-scheduled-documents')->everyMinute()->withoutOverlapping();

Artisan::command('evosyndic:generate-preventive-maintenance {--residence=} {--date=} {--dry-run} {--apply}', function (PreventiveMaintenanceScheduler $service) {
    $date = CarbonImmutable::parse($this->option('date') ?: today()->toDateString(), config('app.timezone'))->endOfDay();
    $apply = (bool) $this->option('apply') && ! $this->option('dry-run');
    $result = $service->generate($date, $apply, $this->option('residence') ? (int) $this->option('residence') : null);
    $this->line(json_encode($result + ['applied' => $apply], JSON_UNESCAPED_UNICODE));

    return 0;
})->purpose('Generate idempotent preventive-maintenance interventions');

Artisan::command('evosyndic:evaluate-maintenance-sla {--dry-run} {--apply}', function (MaintenanceSlaService $service) {
    $apply = (bool) $this->option('apply') && ! $this->option('dry-run');
    $this->line(json_encode($service->evaluate($apply) + ['applied' => $apply], JSON_UNESCAPED_UNICODE));

    return 0;
})->purpose('Persist overdue maintenance SLA thresholds idempotently');

Schedule::command('evosyndic:generate-preventive-maintenance --apply')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('evosyndic:evaluate-maintenance-sla --apply')->everyFifteenMinutes()->withoutOverlapping();

Artisan::command('evosyndic:evaluate-governance-deadlines {--date=} {--dry-run} {--apply}', function (GovernanceDeadlineService $service) {
    $date = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : null;
    $apply = $this->option('apply') && ! $this->option('dry-run');
    $this->line(json_encode($service->evaluate($date, $apply), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
})->purpose('Notify upcoming assemblies and governance execution or mandate deadlines');

Schedule::command('evosyndic:evaluate-governance-deadlines --apply')->dailyAt('08:15')->withoutOverlapping();
