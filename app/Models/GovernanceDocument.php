<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class GovernanceDocument extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    public function assembly()
    {
        return $this->belongsTo(Assembly::class);
    }

    public function versions()
    {
        return $this->hasMany(GovernanceDocumentVersion::class);
    }

    public function publishedVersion()
    {
        return $this->belongsTo(GovernanceDocumentVersion::class, 'published_version_id');
    }
}
