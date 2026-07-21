<?php

namespace App\Rules;

use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

class ValidMoney implements ValidationRule
{
    public function __construct(private bool $positive = true) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $cents = Money::cents((string) $value);
        } catch (InvalidArgumentException) {
            $fail(__('Le montant doit être un nombre avec au maximum deux décimales.'));

            return;
        }

        if ($this->positive && $cents <= 0) {
            $fail(__('Le montant doit être supérieur à zéro.'));
        }
    }
}
