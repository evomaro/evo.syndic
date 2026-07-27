<?php

namespace App\Services;

use Closure;

class AccountingMutationGuard
{
    private int $depth = 0;

    public function run(Closure $operation): mixed
    {
        $this->depth++;
        try {
            return $operation();
        } finally {
            $this->depth--;
        }
    }

    public function active(): bool
    {
        return $this->depth > 0;
    }
}
