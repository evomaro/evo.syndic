<?php

namespace App\Models;

use App\Services\MembershipAuthorization;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'preferred_language',
        'current_organization_id',
        'current_residence_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function organizations()
    {
        return $this->belongsToMany(Organization::class)->withPivot(['role', 'all_residences', 'permissions'])->withTimestamps();
    }

    public function residences()
    {
        return $this->belongsToMany(Residence::class)->withTimestamps();
    }

    public function contacts()
    {
        return $this->belongsToMany(Contact::class)
            ->withPivot(['organization_id', 'linked_by', 'linked_at', 'revoked_at', 'revoked_by'])
            ->wherePivotNull('revoked_at');
    }

    public function currentOrganization()
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    public function currentResidence()
    {
        return $this->belongsTo(Residence::class, 'current_residence_id');
    }

    public function belongsToOrganization(Organization|int $organization): bool
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;

        return $this->organizations()->whereKey($id)->exists();
    }

    public function canInOrganization(string $permission, Organization $organization): bool
    {
        return app(MembershipAuthorization::class)->can($this, $organization, $permission);
    }
}
