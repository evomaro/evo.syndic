<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierInvoiceAttachment extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn (self $model) => $model->getOriginal('immutable') && $model->isDirty(['disk', 'path', 'checksum', 'size', 'name']) ? throw new \LogicException('Immutable invoice attachments cannot be changed.') : null);
        static::deleting(fn (self $model) => $model->immutable ? throw new \LogicException('Immutable invoice attachments cannot be deleted.') : null);
    }

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['immutable' => 'boolean'];
    }

    public function invoice()
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }
}
