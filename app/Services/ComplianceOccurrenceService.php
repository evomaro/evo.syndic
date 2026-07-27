<?php

namespace App\Services;

use App\Models\ComplianceApplicabilityDecision;
use App\Models\ComplianceObligation;
use App\Models\ComplianceTemplateVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ComplianceOccurrenceService
{
    public function __construct(private ComplianceDeadlineService $deadlines) {}

    public function generate(ComplianceApplicabilityDecision $decision, array $inputs, string $timezone): ComplianceObligation
    {
        return DB::transaction(function () use ($decision, $inputs, $timezone) {
            $decision = ComplianceApplicabilityDecision::query()->lockForUpdate()->findOrFail($decision->id);
            $version = ComplianceTemplateVersion::query()
                ->with(['template', 'source'])
                ->lockForUpdate()
                ->findOrFail($decision->template_version_id);
            if ($decision->outcome !== 'applies' || $decision->superseded_by_id
                || $version->template->organization_id !== $decision->organization_id
                || $version->source?->organization_id !== $decision->organization_id
                || $version->status !== 'active' || ! $version->source
                || $version->source->confidence === 'unverified_draft') {
                throw ValidationException::withMessages(['applicability' => __('Seule une décision applicable courante fondée sur une version active vérifiée peut générer une obligation.')]);
            }
            $deadline = $this->deadlines->calculate($version, $inputs, $timezone);
            $period = $inputs['reporting_period'] ?? ($inputs['event_date'] ?? $inputs['manual_due_on'] ?? 'one-time');
            $key = hash('sha256', implode('|', [$version->id, $decision->organization_id, $decision->residence_id ?? 'organization', $period]));

            return ComplianceObligation::query()->firstOrCreate(
                ['organization_id' => $decision->organization_id, 'occurrence_key' => $key],
                [
                    'residence_id' => $decision->residence_id,
                    'template_id' => $version->template_id, 'template_version_id' => $version->id,
                    'source_id' => $version->source_id, 'applicability_decision_id' => $decision->id,
                    'financial_exercise_id' => $decision->financial_exercise_id,
                    'reporting_period' => $period,
                    'reporting_starts_on' => $inputs['reporting_period_start'] ?? null,
                    'reporting_ends_on' => $inputs['reporting_period_end'] ?? null,
                    'original_due_on' => $deadline['due_on'], 'current_due_on' => $deadline['due_on'],
                    'deadline_status' => $deadline['status'] === 'available' ? $this->deadlines->classification($deadline['due_on'], $timezone) : 'unavailable',
                    'operational_status' => 'upcoming', 'deadline_inputs' => $inputs,
                    'deadline_rule_snapshot' => $version->deadline_rule + ['calculation_status' => $deadline['status'], 'unavailable_reason' => $deadline['reason']],
                    'timezone' => $timezone, 'generated_at' => now('UTC'),
                ]
            );
        }, 3);
    }

    public function refreshDeadlineStates(int $limit = 500): int
    {
        $count = 0;
        ComplianceObligation::query()->whereNotIn('operational_status', ['accepted', 'completed_internally', 'waived', 'not_applicable', 'cancelled', 'superseded'])
            ->orderBy('id')->limit($limit)->get()->each(function (ComplianceObligation $obligation) use (&$count) {
                $classification = $this->deadlines->classification($obligation->current_due_on?->toDateString(), $obligation->timezone);
                if ($classification !== $obligation->deadline_status) {
                    $obligation->update(['deadline_status' => $classification]);
                    $count++;
                }
            });

        return $count;
    }

    public function generateHorizon(CarbonImmutable $from, CarbonImmutable $to, bool $apply, int $limit = 500): array
    {
        if ($to->lt($from) || $to->diffInDays($from) > 366) {
            throw ValidationException::withMessages(['horizon' => __('L’horizon doit être compris entre 0 et 366 jours.')]);
        }
        $candidates = 0;
        $generated = 0;
        $unavailable = 0;
        ComplianceApplicabilityDecision::query()->where('outcome', 'applies')->whereNotNull('deadline_inputs')
            ->whereHas('templateVersion', fn ($q) => $q->where('status', 'active'))
            ->with('templateVersion')->orderBy('id')->limit($limit)->get()
            ->each(function (ComplianceApplicabilityDecision $decision) use ($from, $to, $apply, &$candidates, &$generated, &$unavailable) {
                $version = $decision->templateVersion;
                $basis = $version->deadline_rule['basis'] ?? null;
                $anchorValue = $decision->deadline_inputs[$basis] ?? $decision->deadline_inputs['manual_due_on'] ?? null;
                if (! $anchorValue) {
                    $unavailable++;

                    return;
                }
                $anchor = CarbonImmutable::parse($anchorValue);
                $months = match ($version->schedule_type) {
                    'monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'annual' => 12, default => null,
                };
                $dates = [];
                if ($months) {
                    while ($anchor->lt($from)) {
                        $anchor = $anchor->addMonthsNoOverflow($months);
                    }
                    while ($anchor->lte($to) && count($dates) < 24) {
                        $dates[] = $anchor;
                        $anchor = $anchor->addMonthsNoOverflow($months);
                    }
                } elseif ($anchor->betweenIncluded($from, $to)) {
                    $dates[] = $anchor;
                }
                foreach ($dates as $date) {
                    $candidates++;
                    if (! $apply) {
                        continue;
                    }
                    $inputs = $decision->deadline_inputs;
                    $inputs[$basis] = $date->toDateString();
                    $inputs['reporting_period'] = match ($version->schedule_type) {
                        'monthly' => $date->format('Y-m'),
                        'quarterly' => $date->format('Y').'-Q'.(int) ceil($date->month / 3),
                        default => $date->toDateString(),
                    };
                    $timezone = $decision->residence_id
                        ? (string) DB::table('residences')->where('id', $decision->residence_id)->value('timezone')
                        : (string) DB::table('organizations')->where('id', $decision->organization_id)->value('timezone');
                    $obligation = $this->generate($decision, $inputs, $timezone);
                    if ($obligation->wasRecentlyCreated) {
                        $generated++;
                    }
                }
            });

        return compact('candidates', 'generated', 'unavailable') + ['applied' => $apply, 'from' => $from->toDateString(), 'to' => $to->toDateString()];
    }
}
