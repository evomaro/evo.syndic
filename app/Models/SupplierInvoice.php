<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class SupplierInvoice extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $invoice) {
            $locked = ['organization_id', 'primary_residence_id', 'supplier_id', 'supplier_contract_id', 'expense_commitment_id', 'number', 'supplier_invoice_number', 'invoice_date', 'due_date', 'subtotal_cents', 'tax_cents', 'total_cents'];
            if (! in_array($invoice->getOriginal('status'), [null, 'draft'], true) && array_intersect(array_keys($invoice->getDirty()), $locked)) {
                throw new \LogicException('Validated supplier invoices are immutable.');
            }
        });
        static::deleting(fn (self $invoice) => $invoice->status !== 'draft' ? throw new \LogicException('Validated supplier invoices cannot be deleted.') : null);
    }

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'due_date' => 'date', 'validation_snapshot' => 'array', 'validated_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function residence()
    {
        return $this->belongsTo(Residence::class, 'primary_residence_id');
    }

    public function contract()
    {
        return $this->belongsTo(SupplierContract::class, 'supplier_contract_id');
    }

    public function commitment()
    {
        return $this->belongsTo(ExpenseCommitment::class, 'expense_commitment_id');
    }

    public function maintenanceWorkOrder()
    {
        return $this->belongsTo(MaintenanceWorkOrder::class, 'maintenance_work_order_id');
    }

    public function lines()
    {
        return $this->hasMany(SupplierInvoiceLine::class);
    }

    public function attachments()
    {
        return $this->hasMany(SupplierInvoiceAttachment::class);
    }

    public function settlementAllocations()
    {
        return $this->hasMany(SupplierSettlementAllocation::class);
    }

    public function creditAllocations()
    {
        return $this->hasMany(SupplierCreditNoteAllocation::class);
    }

    public function getOutstandingCentsAttribute(): int
    {
        return max(0, $this->total_cents - $this->credited_cents - $this->paid_cents);
    }
}
