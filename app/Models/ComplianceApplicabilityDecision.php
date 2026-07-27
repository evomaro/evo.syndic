<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceApplicabilityDecision extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['inputs' => 'array', 'deadline_inputs' => 'array', 'manual_override' => 'boolean', 'decided_at' => 'datetime'];
    }

    public function templateVersion()
    {
        return $this->belongsTo(ComplianceTemplateVersion::class, 'template_version_id');
    }

    public function predecessor()
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function successor()
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }
}
