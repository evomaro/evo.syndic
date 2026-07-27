<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierSettlementAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['reversed_at' => 'datetime'];
    }

    public function settlement()
    {
        return $this->belongsTo(SupplierSettlement::class, 'supplier_settlement_id');
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
