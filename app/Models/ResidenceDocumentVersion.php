<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResidenceDocumentVersion extends Model
{
    protected $guarded = [];

    protected $hidden = ['path'];

    public function document()
    {
        return $this->belongsTo(ResidenceDocument::class, 'residence_document_id');
    }
}
