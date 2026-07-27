<?php

namespace App\Services;

use App\Models\ExpenseCategory;
use App\Models\FinancialExercise;
use App\Models\Organization;
use App\Models\Residence;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SupplierInvoiceDraftService
{
    public function __construct(private ExpenseResidenceAccessService $access) {}

    public function create(array $data, Organization $organization, Residence $activeResidence, User $actor): SupplierInvoice
    {
        return DB::transaction(function () use ($data, $organization, $activeResidence, $actor) {
            $lines = $data['lines'];
            unset($data['lines']);
            $residenceIds = collect($lines)->map(fn ($line) => (int) ($line['residence_id'] ?? $activeResidence->id))->unique()->values();
            $this->access->authorize($actor, $organization, $residenceIds->all(), $residenceIds->count() > 1 || $residenceIds->first() !== $activeResidence->id);

            $invoice = ! empty($data['idempotency_key'])
                ? SupplierInvoice::firstOrCreate(['organization_id' => $organization->id, 'idempotency_key' => $data['idempotency_key']], $data + ['primary_residence_id' => $activeResidence->id])
                : SupplierInvoice::create($data + ['organization_id' => $organization->id, 'primary_residence_id' => $activeResidence->id]);
            if ($invoice->lines()->exists()) {
                return $invoice;
            }
            if (! empty($data['duplicate_warning_reason'])) {
                $invoice->update(['duplicate_warning_acknowledged_at' => now(), 'duplicate_warning_acknowledged_by' => $actor->id]);
            }
            foreach ($lines as $order => $line) {
                $residenceId = (int) ($line['residence_id'] ?? $activeResidence->id);
                $exercise = FinancialExercise::query()->whereKey($line['financial_exercise_id'])->where('organization_id', $organization->id)->where('residence_id', $residenceId)->firstOrFail();
                $category = ExpenseCategory::query()->whereKey($line['expense_category_id'])->where('organization_id', $organization->id)->where('residence_id', $residenceId)->firstOrFail();
                $quantity = $this->scaled((string) $line['quantity'], 3);
                $taxRate = $this->scaled((string) $line['tax_rate'], 3);
                $subtotal = intdiv($quantity * (int) $line['unit_price_cents'] + 500, 1000);
                $tax = intdiv($subtotal * $taxRate + 50000, 100000);
                $invoice->lines()->create(array_merge($line, ['residence_id' => $residenceId, 'financial_exercise_id' => $exercise->id, 'expense_category_id' => $category->id, 'subtotal_cents' => $subtotal, 'tax_cents' => $tax, 'total_cents' => $subtotal + $tax, 'sort_order' => $order]));
            }
            $invoice->update(['subtotal_cents' => $invoice->lines()->sum('subtotal_cents'), 'tax_cents' => $invoice->lines()->sum('tax_cents'), 'total_cents' => $invoice->lines()->sum('total_cents')]);

            return $invoice->fresh('lines');
        });
    }

    private function scaled(string $value, int $scale): int
    {
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', $value), 2), 2, '');

        return (int) $whole * (10 ** $scale) + (int) str_pad(substr($fraction, 0, $scale), $scale, '0');
    }
}
