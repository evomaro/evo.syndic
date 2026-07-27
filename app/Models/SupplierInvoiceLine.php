<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierInvoiceLine extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        $guard = fn (self $line) => $line->invoice()->where('status', '!=', 'draft')->exists() ? throw new \LogicException('Validated invoice lines are immutable.') : null;
        static::updating($guard);
        static::deleting($guard);
    }

    public function invoice()
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    public function residence()
    {
        return $this->belongsTo(Residence::class);
    }

    public function exercise()
    {
        return $this->belongsTo(FinancialExercise::class, 'financial_exercise_id');
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }
}
