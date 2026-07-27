<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceEvidenceVersion extends Model
{
    protected $guarded = [];

    protected $hidden = ['path'];

    public function evidence()
    {
        return $this->belongsTo(ComplianceEvidence::class, 'evidence_id');
    }
}
