<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;

class Supplier extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['legal_name', 'trade_name', 'contact_name', 'email', 'phone', 'address', 'city', 'country', 'website', 'preferred_language', 'payment_terms_days', 'active', 'status'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function categories()
    {
        return $this->belongsToMany(SupplierServiceCategory::class, 'supplier_service_category');
    }

    public function contracts()
    {
        return $this->hasMany(SupplierContract::class);
    }

    public function invoices()
    {
        return $this->hasMany(SupplierInvoice::class);
    }
}
