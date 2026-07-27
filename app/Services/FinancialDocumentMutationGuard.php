<?php

namespace App\Services;

class FinancialDocumentMutationGuard
{
    private int $depth = 0;

    public function authorized(callable $callback): mixed
    {
        $this->depth++;

        try {
            return $callback();
        } finally {
            $this->depth--;
        }
    }

    public function isAuthorized(): bool
    {
        return $this->depth > 0;
    }
}
