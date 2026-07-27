<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ComplianceTemplateVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'applicability_rule' => 'array', 'deadline_rule' => 'array',
            'effective_from' => 'date', 'effective_until' => 'date',
            'professional_review_required' => 'boolean',
            'source_verified_at' => 'datetime', 'professional_reviewed_at' => 'datetime',
            'approved_at' => 'datetime', 'activated_at' => 'datetime', 'withdrawn_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $version) {
            if ($version->getOriginal('status') === 'active' && $version->isDirty()) {
                throw ValidationException::withMessages(['version' => __('Une version active est immuable. Créez une nouvelle version.')]);
            }
        });
    }

    public function template()
    {
        return $this->belongsTo(ComplianceTemplate::class, 'template_id');
    }

    public function source()
    {
        return $this->belongsTo(ComplianceSource::class, 'source_id');
    }
}
