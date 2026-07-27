<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;

class SupplierContract extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['residence_id', 'supplier_id', 'reference', 'title', 'description', 'starts_on', 'ends_on', 'renewal_type', 'notice_days', 'billing_frequency', 'amount_cents', 'status', 'terminated_on', 'termination_reason'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'auto_renew' => 'boolean'];
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
        return $this->belongsTo(Residence::class);
    }

    public function serviceCategory()
    {
        return $this->belongsTo(SupplierServiceCategory::class, 'supplier_service_category_id');
    }

    public function expenseCategory()
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    public function attachments()
    {
        return $this->hasMany(SupplierContractAttachment::class);
    }

    public function renewedFrom()
    {
        return $this->belongsTo(self::class, 'renewed_from_id');
    }

    public function renewals()
    {
        return $this->hasMany(self::class, 'renewed_from_id');
    }

    public function invoices()
    {
        return $this->hasMany(SupplierInvoice::class);
    }
}
