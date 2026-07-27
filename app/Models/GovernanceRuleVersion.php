<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GovernanceRuleVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'proxy_restrictions' => 'array', 'eligibility_restrictions' => 'array', 'legal_payload' => 'array', 'active' => 'boolean'];
    }
}
