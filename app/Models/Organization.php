<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    use HasFactory, LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function users()
    {
        return $this->belongsToMany(User::class)->withPivot(['role', 'all_residences', 'permissions'])->withTimestamps();
    }

    public function residences()
    {
        return $this->hasMany(Residence::class);
    }

    public function contacts()
    {
        return $this->hasMany(Contact::class);
    }

    public function invitations()
    {
        return $this->hasMany(TeamInvitation::class);
    }

    public function financialExercises()
    {
        return $this->hasMany(FinancialExercise::class);
    }

    public function financialAccounts()
    {
        return $this->hasMany(FinancialAccount::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
