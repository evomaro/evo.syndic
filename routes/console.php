<?php

use App\Models\FundCallSchedule;
use App\Models\User;
use App\Services\FundCallScheduleService;
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
