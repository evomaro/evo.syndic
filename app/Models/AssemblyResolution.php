<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class AssemblyResolution extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'financial_snapshot' => 'array', 'rule_snapshotted_at' => 'datetime',
            'reopened_at' => 'datetime', 'voting_opened_at' => 'datetime',
            'voting_closed_at' => 'datetime', 'immutable_at' => 'datetime',
            'challenged_at' => 'datetime', 'suspended_at' => 'datetime',
        ];
    }

    public function assembly()
    {
        return $this->belongsTo(Assembly::class);
    }

    public function agendaItem()
    {
        return $this->belongsTo(AssemblyAgendaItem::class);
    }

    public function ruleVersion()
    {
        return $this->belongsTo(GovernanceRuleVersion::class, 'governance_rule_version_id');
    }

    public function ruleSnapshot()
    {
        return $this->hasOne(ResolutionRuleSnapshot::class, 'resolution_id');
    }

    public function ballots()
    {
        return $this->hasMany(AssemblyBallot::class, 'resolution_id');
    }

    public function results()
    {
        return $this->hasMany(ResolutionResult::class, 'resolution_id');
    }

    public function finalResult()
    {
        return $this->hasOne(ResolutionResult::class, 'resolution_id')->latestOfMany('version');
    }

    public function executionActions()
    {
        return $this->hasMany(ResolutionExecutionAction::class, 'resolution_id');
    }

    public function transitions()
    {
        return $this->hasMany(AssemblyResolutionTransition::class, 'resolution_id');
    }

    public function secretBallotAggregate()
    {
        return $this->hasOne(AssemblySecretBallotAggregate::class, 'resolution_id');
    }
}
