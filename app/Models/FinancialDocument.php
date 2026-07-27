<?php

namespace App\Models;

use App\Services\FinancialDocumentMutationGuard;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class FinancialDocument extends Model
{
    protected $guarded = [];

    protected $hidden = ['verification_token_hash', 'verification_token_encrypted', 'path'];

    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            $protected = [
                'organization_id',
                'residence_id',
                'type',
                'number',
                'subject_type',
                'subject_id',
                'locale',
                'version',
                'disk',
                'path',
                'checksum',
                'checksum_version',
                'verification_token_hash',
                'verification_token_encrypted',
                'generated_at',
                'generated_by',
            ];

            if ($document->isDirty($protected) && ! app(FinancialDocumentMutationGuard::class)->isAuthorized()) {
                throw new LogicException('Finalized financial document identity and checksum fields are immutable.');
            }
        });

        static::deleting(fn () => throw new LogicException('Finalized financial documents cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['generated_at' => 'datetime'];
    }

    public function subject()
    {
        return $this->morphTo();
    }

    public function residence()
    {
        return $this->belongsTo(Residence::class);
    }
}
