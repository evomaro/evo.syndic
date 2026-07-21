<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialDocument extends Model
{
    protected $guarded = [];

    protected $hidden = ['verification_token_hash', 'verification_token_encrypted', 'path'];

    protected function casts(): array
    {
        return ['generated_at' => 'datetime'];
    }

    public function subject()
    {
        return $this->morphTo();
    }

    public function residence()
    {
        return $this->belongsTo(Residence::class);
    }
}
