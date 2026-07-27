<?php

namespace App\Services;

use App\Models\ComplianceTemplateVersion;
use Carbon\CarbonImmutable;
use DateTimeZone;

class ComplianceDeadlineService
{
    public function calculate(ComplianceTemplateVersion $version, array $inputs, string $timezone): array
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            return ['status' => 'unavailable', 'due_on' => null, 'reason' => 'invalid_timezone'];
        }
        $rule = $version->deadline_rule;
        if (($rule['unit'] ?? null) === 'business_days') {
            return ['status' => 'unavailable', 'due_on' => null, 'reason' => 'approved_business_calendar_missing'];
        }
        $basis = $rule['basis'] ?? null;
        if ($basis === 'manual_authoritative_date') {
            $date = $inputs['manual_due_on'] ?? null;
        } elseif ($basis === 'fixed_calendar_date') {
            $date = $rule['date'] ?? null;
        } else {
            $date = $inputs[$basis] ?? null;
        }
        if (! $date) {
            return ['status' => 'unavailable', 'due_on' => null, 'reason' => 'required_deadline_input_missing'];
        }
        $due = CarbonImmutable::parse($date, $timezone)->startOfDay();
        if (($rule['unit'] ?? 'calendar_days') === 'calendar_days') {
            $due = $due->addDays((int) ($rule['offset'] ?? 0));
        }

        return ['status' => 'available', 'due_on' => $due->toDateString(), 'reason' => null];
    }

    public function classification(?string $dueOn, string $timezone, int $dueSoonDays = 7): string
    {
        if (! $dueOn) {
            return 'unavailable';
        }
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $due = CarbonImmutable::parse($dueOn, $timezone)->startOfDay();
        if ($due->isBefore($today)) {
            return 'overdue';
        }

        return $due->lte($today->addDays($dueSoonDays)) ? 'due_soon' : 'upcoming';
    }
}
