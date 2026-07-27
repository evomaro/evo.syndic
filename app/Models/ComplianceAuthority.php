<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceAuthority extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
