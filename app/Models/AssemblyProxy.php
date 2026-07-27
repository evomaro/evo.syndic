<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Illuminate\Database\Eloquent\Model;

class AssemblyProxy extends Model
{
    use LogsDomainActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'verified_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function assembly()
    {
        return $this->belongsTo(Assembly::class);
    }

    public function principal()
    {
        return $this->belongsTo(AssemblyElectorate::class, 'principal_electorate_id');
    }

    public function events()
    {
        return $this->hasMany(AssemblyProxyEvent::class, 'proxy_id');
    }
}
