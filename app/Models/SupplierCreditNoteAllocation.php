<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierCreditNoteAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['reversed_at' => 'datetime'];
    }

    public function creditNote()
    {
        return $this->belongsTo(SupplierCreditNote::class, 'supplier_credit_note_id');
    }

    public function invoice()
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    public function line()
    {
        return $this->belongsTo(SupplierInvoiceLine::class, 'supplier_invoice_line_id');
    }
}
