<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingAutomation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'readiness_evidence' => 'array',
            'activated_at' => 'datetime',
            'deactivated_from' => 'date:Y-m-d',
            'deactivated_at' => 'datetime',
        ];
    }

    public function book()
    {
        return $this->belongsTo(AccountingBook::class, 'accounting_book_id');
    }
}
