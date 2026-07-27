<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class GovernanceRuleSource extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['published_on' => 'date', 'effective_on' => 'date', 'last_verified_on' => 'date'];
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function versions()
    {
        return $this->hasMany(GovernanceRuleVersion::class);
    }
}
