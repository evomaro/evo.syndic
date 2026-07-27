<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class SupplierCreditNote extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $model) {
            if ($model->getOriginal('status') !== 'draft' && $model->isDirty(['organization_id', 'residence_id', 'supplier_id', 'original_supplier_invoice_id', 'amount_cents', 'credit_date', 'number', 'supplier_credit_number', 'idempotency_key', 'validation_snapshot'])) {
                throw new \LogicException('Validated credit notes are immutable.');
            }
        });
        static::deleting(fn (self $model) => $model->status !== 'draft' ? throw new \LogicException('Validated credit notes cannot be deleted.') : null);
    }

    protected function casts(): array
    {
        return ['credit_date' => 'date', 'validated_at' => 'datetime', 'cancelled_at' => 'datetime', 'validation_snapshot' => 'array'];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function residence()
    {
        return $this->belongsTo(Residence::class);
    }

    public function allocations()
    {
        return $this->hasMany(SupplierCreditNoteAllocation::class);
    }
}
