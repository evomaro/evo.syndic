<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierContractAttachment extends Model
{
    protected $guarded = [];

    protected $hidden = ['path'];

    public function contract()
    {
        return $this->belongsTo(SupplierContract::class, 'supplier_contract_id');
    }
}
