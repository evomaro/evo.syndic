<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceTemplate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function authority()
    {
        return $this->belongsTo(ComplianceAuthority::class, 'authority_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function versions()
    {
        return $this->hasMany(ComplianceTemplateVersion::class, 'template_id');
    }
}
