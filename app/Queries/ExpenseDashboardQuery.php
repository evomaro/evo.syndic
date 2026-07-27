<?php

namespace App\Queries;

use App\Models\Budget;
use App\Models\ExpenseCommitment;
use App\Models\SupplierContract;
use App\Models\SupplierInvoice;
use App\Models\SupplierSettlement;
use App\Services\BudgetService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

class ExpenseDashboardQuery
{
    public function __construct(private BudgetService $budgets) {}

    public function get(TenantContext $context): array
    {
        $residence = $context->residence();
        $payableIds = SupplierInvoice::query()->where('organization_id', $context->organization()->id)
            ->whereIn('status', ['validated', 'partial'])->whereHas('lines', fn ($lines) => $lines->where('residence_id', $residence->id))->pluck('id');
        $approved = Budget::query()->where('residence_id', $residence->id)->whereIn('status', ['approved', 'locked'])->latest('version')->first();
        $budgetMetrics = $approved ? $this->budgets->metrics($approved) : [];
        $budgetTotal = (int) ($budgetMetrics['totals']['planned_cents'] ?? 0);
        $actual = (int) ($budgetMetrics['totals']['actual_cents'] ?? 0);

        return [
            'metrics' => [
                'payable_cents' => $this->outstanding($payableIds, $residence->id),
                'overdue_cents' => $this->outstanding(SupplierInvoice::query()->whereIn('id', $payableIds)->whereDate('due_date', '<', today())->pluck('id'), $residence->id),
                'invoices_count' => SupplierInvoice::query()->where('organization_id', $context->organization()->id)->whereHas('lines', fn ($lines) => $lines->where('residence_id', $residence->id))->count(),
                'settled_this_month_cents' => (int) SupplierSettlement::query()->where('residence_id', $residence->id)->where('status', 'validated')->whereBetween('settlement_date', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount_cents'),
                'budget_cents' => $budgetTotal,
                'actual_cents' => $actual,
                'budget_remaining_cents' => $budgetTotal - $actual,
                'commitments_cents' => (int) ExpenseCommitment::query()->where('residence_id', $residence->id)->whereIn('status', ['approved', 'partially_invoiced', 'fully_invoiced'])->sum('amount_cents'),
                'over_budget_count' => collect($budgetMetrics)->filter(fn ($row, $key) => is_int($key) && ($row['overspent'] ?? false))->count(),
                'expiring_contracts_count' => SupplierContract::query()->where('residence_id', $residence->id)->where('status', 'active')->whereBetween('ends_on', [today(), today()->addDays(60)])->count(),
            ],
            'activeBudget' => $approved,
            'budgetMetrics' => $budgetMetrics,
        ];
    }

    private function outstanding($invoiceIds, int $residenceId): int
    {
        $gross = (int) DB::table('supplier_invoice_lines')->whereIn('supplier_invoice_id', $invoiceIds)->where('residence_id', $residenceId)->sum('total_cents');
        $paid = (int) DB::table('supplier_settlement_allocations as sa')->join('supplier_invoice_lines as il', 'il.id', '=', 'sa.supplier_invoice_line_id')->whereIn('sa.supplier_invoice_id', $invoiceIds)->where('il.residence_id', $residenceId)->whereNull('sa.reversed_at')->sum('sa.amount_cents');
        $credited = (int) DB::table('supplier_credit_note_allocations')->whereIn('supplier_invoice_id', $invoiceIds)->where('residence_id', $residenceId)->whereNull('reversed_at')->sum('amount_cents');

        return max(0, $gross - $paid - $credited);
    }
}
