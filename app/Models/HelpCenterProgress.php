<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HelpCenterProgress extends Model
{
    protected $table = 'help_center_progress';

    protected $fillable = ['organization_id', 'user_id', 'article_id', 'completed_at'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
