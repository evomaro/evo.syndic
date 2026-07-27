<?php

namespace App\Queries;

use App\Models\Budget;
use App\Services\BudgetService;
use App\Support\TenantContext;

class BudgetReportingQuery
{
    public function __construct(private BudgetService $service) {}

    public function paginate(TenantContext $context)
    {
        return Budget::query()->where('residence_id', $context->residence()->id)->with('lines.category:id,name')->latest('version')->paginate(20);
    }

    public function show(Budget $budget): array
    {
        return ['budget' => $budget->load(['lines.category', 'exercise']), 'metrics' => $this->service->metrics($budget)];
    }
}
