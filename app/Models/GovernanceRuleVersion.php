<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GovernanceRuleVersion extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $version) {
            if ($version->getOriginal('immutable_at') !== null) {
                throw new \LogicException('Active and historical governance rule versions are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'effective_from' => 'date', 'effective_until' => 'date',
            'proxy_restrictions' => 'array', 'eligibility_restrictions' => 'array',
            'notice_requirements' => 'array', 'required_evidence' => 'array',
            'legal_payload' => 'array', 'active' => 'boolean',
            'source_verified_at' => 'datetime', 'professionally_reviewed_at' => 'datetime',
            'counsel_reviewed_at' => 'datetime', 'approved_at' => 'datetime',
            'activated_at' => 'datetime', 'immutable_at' => 'datetime',
        ];
    }

    public function rule()
    {
        return $this->belongsTo(GovernanceRule::class, 'governance_rule_id');
    }

    public function source()
    {
        return $this->belongsTo(GovernanceRuleSource::class, 'governance_rule_source_id');
    }
}
