<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class ExpenseCommitment extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $model) {
            if (in_array($model->getOriginal('status'), ['approved', 'partially_invoiced', 'fully_invoiced'], true) && $model->isDirty('amount_cents')) {
                throw new \LogicException('Approved commitment amount is immutable.');
            }
        });
        static::deleting(fn (self $model) => $model->status !== 'draft' ? throw new \LogicException('Committed records cannot be deleted.') : null);
    }

    protected function casts(): array
    {
        return ['committed_on' => 'date', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function contract()
    {
        return $this->belongsTo(SupplierContract::class, 'supplier_contract_id');
    }

    public function exercise()
    {
        return $this->belongsTo(FinancialExercise::class, 'financial_exercise_id');
    }

    public function residence()
    {
        return $this->belongsTo(Residence::class);
    }

    public function invoices()
    {
        return $this->hasMany(SupplierInvoice::class);
    }
}
