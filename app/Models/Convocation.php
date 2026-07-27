<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class Convocation extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'legal_deadline_at' => 'datetime', 'late_exception' => 'boolean', 'frozen_payload' => 'array'];
    }

    public function assembly()
    {
        return $this->belongsTo(Assembly::class);
    }

    public function recipients()
    {
        return $this->hasMany(ConvocationRecipient::class);
    }
}
