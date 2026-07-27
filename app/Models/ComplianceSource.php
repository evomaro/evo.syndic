<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceSource extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['published_on' => 'date', 'effective_on' => 'date', 'last_verified_on' => 'date'];
    }

    public function authority()
    {
        return $this->belongsTo(ComplianceAuthority::class, 'authority_id');
    }
}
