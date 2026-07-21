<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasFactory, LogsDomainActivity;

    protected $guarded = [];

    protected $appends = ['display_name'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['organization_id', 'linked_by', 'linked_at', 'revoked_at', 'revoked_by'])
            ->wherePivotNull('revoked_at');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'payer_contact_id');
    }

    public function ownerships()
    {
        return $this->hasMany(LotOwnership::class);
    }

    public function occupancies()
    {
        return $this->hasMany(LotOccupancy::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->type === 'company' ? (string) $this->company_name : trim("{$this->first_name} {$this->last_name}");
    }

    public function setPrimaryPhoneAttribute(?string $value): void
    {
        $this->attributes['primary_phone'] = $value;
        $this->attributes['phone_normalized'] = $value ? preg_replace('/[^0-9+]/', '', $value) : null;
    }
}
