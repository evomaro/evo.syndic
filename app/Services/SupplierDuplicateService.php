<?php

namespace App\Services;

use App\Models\Supplier;
use Illuminate\Support\Str;

class SupplierDuplicateService
{
    public function candidates(int $organizationId, array $attributes, ?int $exceptId = null)
    {
        $name = $this->normalize((string) ($attributes['legal_name'] ?? ''));

        return Supplier::query()->where('organization_id', $organizationId)->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->where(function ($query) use ($attributes, $name) {
                foreach (['ice', 'tax_id', 'phone', 'email'] as $field) {
                    if (filled($attributes[$field] ?? null)) {
                        $query->orWhere($field, $attributes[$field]);
                    }
                }
                if ($name !== '') {
                    $query->orWhereRaw('LOWER(legal_name) = ?', [$name]);
                }
            })->limit(10)->get(['id', 'legal_name', 'ice', 'tax_id', 'phone', 'email']);
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }
}
