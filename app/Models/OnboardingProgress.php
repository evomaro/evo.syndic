<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnboardingProgress extends Model
{
    protected $table = 'onboarding_progress';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['completed_steps' => 'array'];
    }
}
