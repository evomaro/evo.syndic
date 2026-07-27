<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GovernanceDocumentVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['frozen_at' => 'datetime'];
    }

    public function document()
    {
        return $this->belongsTo(GovernanceDocument::class, 'governance_document_id');
    }
}
