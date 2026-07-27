<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceEvidence extends Model
{
    protected $table = 'compliance_evidence';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function obligation()
    {
        return $this->belongsTo(ComplianceObligation::class, 'obligation_id');
    }

    public function versions()
    {
        return $this->hasMany(ComplianceEvidenceVersion::class, 'evidence_id');
    }
}
