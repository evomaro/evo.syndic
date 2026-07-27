<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AccountingFramework extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['publication_date' => 'date:Y-m-d', 'effective_date' => 'date:Y-m-d', 'published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $framework) {
            if ($framework->getOriginal('status') !== 'draft') {
                throw new LogicException('Published accounting framework versions are immutable.');
            }
        });
        static::deleting(fn (self $framework) => $framework->status !== 'draft'
            ? throw new LogicException('Published accounting framework versions cannot be deleted.')
            : null);
    }

    public function templates()
    {
        return $this->hasMany(AccountingAccountTemplate::class);
    }

    public function successor()
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }
}
