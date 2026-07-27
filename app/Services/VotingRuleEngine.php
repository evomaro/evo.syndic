<?php

namespace App\Services;

use App\Models\GovernanceRuleVersion;
use InvalidArgumentException;

class VotingRuleEngine
{
    public function decide(GovernanceRuleVersion|array $rule, int $numerator, int $denominator): bool
    {
        $thresholdNumerator = (int) data_get($rule, 'threshold_numerator');
        $thresholdDenominator = (int) data_get($rule, 'threshold_denominator');
        $comparison = data_get($rule, 'comparison');
        if ($numerator < 0 || $denominator <= 0 || $thresholdNumerator < 0 || $thresholdDenominator <= 0) {
            throw new InvalidArgumentException('Voting ratios must be non-negative with positive denominators.');
        }

        // Cross multiplication is exact and deliberately avoids floating-point threshold errors.
        $left = $numerator * $thresholdDenominator;
        $right = $denominator * $thresholdNumerator;

        return match ($comparison) {
            'gt' => $left > $right,
            'gte' => $left >= $right,
            default => throw new InvalidArgumentException('Unsupported voting comparison.'),
        };
    }

    public function formatted(int $numerator, int $denominator, string $locale = 'fr'): string
    {
        if ($denominator <= 0) {
            return '0 %';
        }
        $basisPoints = intdiv($numerator * 10000, $denominator);
        $value = number_format($basisPoints / 100, 2, $locale === 'fr' ? ',' : '.', $locale === 'fr' ? ' ' : ',');

        return $value.' %';
    }
}
