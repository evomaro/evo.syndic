<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class ResidenceDocument extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['document_date' => 'date', 'published_at' => 'datetime', 'archived_at' => 'datetime', 'expires_at' => 'datetime', 'scheduled_for' => 'datetime', 'last_publication_attempt_at' => 'datetime', 'publication_failed_at' => 'datetime', 'publication_failure_resolved_at' => 'datetime'];
    }

    public function residence()
    {
        return $this->belongsTo(Residence::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function versions()
    {
        return $this->hasMany(ResidenceDocumentVersion::class);
    }

    public function latestVersion()
    {
        return $this->hasOne(ResidenceDocumentVersion::class)->latestOfMany('version');
    }

    public function publishedVersion()
    {
        return $this->belongsTo(ResidenceDocumentVersion::class, 'published_version_id');
    }

    public function lots()
    {
        return $this->belongsToMany(Lot::class, 'residence_document_lots');
    }

    public function buildings()
    {
        return $this->belongsToMany(Building::class, 'residence_document_buildings');
    }

    public function contacts()
    {
        return $this->belongsToMany(Contact::class, 'residence_document_contacts');
    }
}
