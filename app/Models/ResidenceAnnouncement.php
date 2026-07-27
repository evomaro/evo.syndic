<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class ResidenceAnnouncement extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['audience_snapshot' => 'array', 'scheduled_for' => 'datetime', 'published_at' => 'datetime', 'expires_at' => 'datetime', 'last_publication_attempt_at' => 'datetime', 'publication_failed_at' => 'datetime', 'publication_failure_resolved_at' => 'datetime'];
    }

    public function residence()
    {
        return $this->belongsTo(Residence::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function lots()
    {
        return $this->belongsToMany(Lot::class, 'residence_announcement_lots');
    }

    public function documents()
    {
        return $this->belongsToMany(ResidenceDocument::class, 'announcement_document');
    }

    public function buildings()
    {
        return $this->belongsToMany(Building::class, 'residence_announcement_buildings');
    }

    public function contacts()
    {
        return $this->belongsToMany(Contact::class, 'residence_announcement_contacts');
    }
}
