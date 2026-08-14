<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Residence extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, LogsDomainActivity;

    protected $guarded = [];

    protected $appends = ['logo_url', 'initials'];

    protected function casts(): array
    {
        return ['ownership_incomplete_acknowledged' => 'boolean', 'allocations_deferred' => 'boolean', 'syndic_mandate_start' => 'date', 'syndic_mandate_end' => 'date'];
    }

    protected static function booted(): void
    {
        static::created(fn (Residence $r) => $r->allocationKeys()->create(['name' => 'Tantièmes généraux', 'code' => 'general', 'type' => 'general', 'expected_total' => null, 'is_default' => true, 'default_slot' => 1]));
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->useDisk('public')->singleFile()->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->getFirstMediaUrl('logo') ?: null;
    }

    public function getInitialsAttribute(): string
    {
        return collect(preg_split('/\s+/u', trim($this->name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function buildings()
    {
        return $this->hasMany(Building::class);
    }

    public function lots()
    {
        return $this->hasMany(Lot::class);
    }

    public function allocationKeys()
    {
        return $this->hasMany(AllocationKey::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function financialExercises()
    {
        return $this->hasMany(FinancialExercise::class);
    }

    public function financialAccounts()
    {
        return $this->hasMany(FinancialAccount::class);
    }

    public function chargeCategories()
    {
        return $this->hasMany(ChargeCategory::class);
    }

    public function expenseCategories()
    {
        return $this->hasMany(ExpenseCategory::class);
    }

    public function fundCalls()
    {
        return $this->hasMany(FundCall::class);
    }

    public function lotCharges()
    {
        return $this->hasMany(LotCharge::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function fundCallSchedules()
    {
        return $this->hasMany(FundCallSchedule::class);
    }

    public function isOperational(): bool
    {
        if (! $this->lots()->where('active', true)->exists() || ! $this->allocationKeys()->where('is_default', true)->exists()) {
            return false;
        }
        $ownersComplete = ! $this->lots()->where('active', true)->whereDoesntHave('activeOwnerships')->exists();
        $allocationsComplete = ! $this->lots()->where('active', true)->whereDoesntHave('allocationValues', fn ($q) => $q->whereHas('allocationKey', fn ($k) => $k->where('is_default', true)))->exists();

        return ($ownersComplete || $this->ownership_incomplete_acknowledged) && ($allocationsComplete || $this->allocations_deferred);
    }
}
