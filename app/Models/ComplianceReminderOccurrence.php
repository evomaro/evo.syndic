<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceReminderOccurrence extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'triggered_for_on' => 'date',
            'scheduled_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
