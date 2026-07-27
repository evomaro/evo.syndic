<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;

class LedgerAccount extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['posting_allowed' => 'boolean', 'reconciliation_required' => 'boolean', 'active' => 'boolean', 'effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $account) {
            if (! $account->parent_id) {
                return;
            }

            if ($account->exists && (int) $account->parent_id === (int) $account->id) {
                throw ValidationException::withMessages(['parent_id' => __('Un compte ne peut pas être son propre parent.')]);
            }

            $parent = self::query()->find($account->parent_id);
            if (! $parent || collect(['organization_id', 'residence_id', 'accounting_book_id', 'accounting_framework_id'])
                ->contains(fn (string $field) => (int) $parent->{$field} !== (int) $account->{$field})) {
                throw ValidationException::withMessages(['parent_id' => __('Le compte parent doit appartenir au même plan comptable.')]);
            }

            $cursor = $parent;
            while ($cursor) {
                if ($account->exists && (int) $cursor->id === (int) $account->id) {
                    throw ValidationException::withMessages(['parent_id' => __('La hiérarchie des comptes ne peut pas contenir de cycle.')]);
                }
                $cursor = $cursor->parent_id ? self::query()->find($cursor->parent_id) : null;
            }
        });
        static::updating(function (self $account) {
            if ($account->isDirty('code') && $account->lines()->whereHas('entry', fn ($q) => $q->whereIn('status', ['posted', 'reversed']))->exists()) {
                throw new LogicException('An account code used by posted entries is immutable.');
            }
        });
        static::deleting(function (self $account) {
            if ($account->lines()->whereHas('entry', fn ($q) => $q->whereIn('status', ['posted', 'reversed']))->exists()) {
                throw new LogicException('An account used by posted entries cannot be deleted.');
            }
        });
    }

    public function lines()
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
