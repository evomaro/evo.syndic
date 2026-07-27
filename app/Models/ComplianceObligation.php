<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceObligation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reporting_starts_on' => 'date', 'reporting_ends_on' => 'date',
            'original_due_on' => 'date', 'current_due_on' => 'date',
            'deadline_inputs' => 'array', 'deadline_rule_snapshot' => 'array', 'generated_at' => 'datetime',
        ];
    }

    public function template()
    {
        return $this->belongsTo(ComplianceTemplate::class, 'template_id');
    }

    public function templateVersion()
    {
        return $this->belongsTo(ComplianceTemplateVersion::class, 'template_version_id');
    }

    public function source()
    {
        return $this->belongsTo(ComplianceSource::class, 'source_id');
    }

    public function applicabilityDecision()
    {
        return $this->belongsTo(ComplianceApplicabilityDecision::class, 'applicability_decision_id');
    }

    public function assignments()
    {
        return $this->hasMany(ComplianceObligationAssignment::class, 'obligation_id');
    }

    public function transitions()
    {
        return $this->hasMany(ComplianceObligationTransition::class, 'obligation_id');
    }

    public function submissions()
    {
        return $this->hasMany(ComplianceSubmission::class, 'obligation_id');
    }

    public function evidence()
    {
        return $this->hasMany(ComplianceEvidence::class, 'obligation_id');
    }
}
