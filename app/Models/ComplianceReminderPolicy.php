<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceReminderPolicy extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'triggers' => 'array', 'recipient_types' => 'array', 'database_enabled' => 'boolean',
            'email_enabled' => 'boolean', 'digest' => 'boolean', 'active' => 'boolean',
        ];
    }
}
