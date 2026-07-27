<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AccountingAccountTemplate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['posting_allowed' => 'boolean', 'tenant_subaccounts_allowed' => 'boolean'];
    }

    protected static function booted(): void
    {
        $guard = function (self $template) {
            if ($template->framework()->where('status', '!=', 'draft')->exists()) {
                throw new LogicException('Templates in a published framework are immutable.');
            }
        };
        static::updating($guard);
        static::deleting($guard);
    }

    public function framework()
    {
        return $this->belongsTo(AccountingFramework::class, 'accounting_framework_id');
    }
}
