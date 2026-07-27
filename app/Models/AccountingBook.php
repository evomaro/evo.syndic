<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingBook extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_date' => 'date:Y-m-d', 'confirmed_at' => 'datetime'];
    }

    public function framework()
    {
        return $this->belongsTo(AccountingFramework::class, 'accounting_framework_id');
    }

    public function automation()
    {
        return $this->hasOne(AccountingAutomation::class);
    }

    public function accounts()
    {
        return $this->hasMany(LedgerAccount::class);
    }

    public function journals()
    {
        return $this->hasMany(AccountingJournal::class);
    }

    public function sourceMappings()
    {
        return $this->hasMany(AccountingSourceMapping::class);
    }

    public function regimeAssessments()
    {
        return $this->hasMany(AccountingRegimeAssessment::class);
    }
}
