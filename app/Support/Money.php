<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public static function cents(int|string $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        $normalized = trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', $value));
        if (! preg_match('/^-?(?:\d+|\d{1,3}(?: \d{3})+)(?:[.,]\d{1,2})?$/u', $normalized)) {
            throw new InvalidArgumentException('Invalid monetary amount.');
        }
        $normalized = str_replace([' ', ','], ['', '.'], $normalized);
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        if (strlen(ltrim($whole, '0')) > 15) {
            throw new InvalidArgumentException('Monetary amount is too large.');
        }
        $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $negative ? -$cents : $cents;
    }

    public static function decimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function formatted(int $cents, string $decimalSeparator = ',', string $thousandsSeparator = ' '): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        $whole = number_format(intdiv($cents, 100), 0, '', $thousandsSeparator);

        return $sign.$whole.$decimalSeparator.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function weight(string|int $value, int $scale = 6): int
    {
        $normalized = str_replace(',', '.', trim((string) $value));
        if (! preg_match('/^\d+(?:\.\d+)?$/', $normalized)) {
            return 0;
        }
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');

        return ((int) $whole * (10 ** $scale)) + (int) str_pad(substr($fraction, 0, $scale), $scale, '0');
    }
}
