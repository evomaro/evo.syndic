<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierServiceCategory extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function suppliers()
    {
        return $this->belongsToMany(Supplier::class, 'supplier_service_category');
    }
}
