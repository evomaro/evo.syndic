<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    protected $guarded = [];

    protected $attributes = ['database_enabled' => true, 'email_enabled' => true];

    protected function casts(): array
    {
        return ['database_enabled' => 'boolean', 'email_enabled' => 'boolean', 'muted_events' => 'array'];
    }
}
