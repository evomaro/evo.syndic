<?php

namespace App\Queries;

use App\Models\Supplier;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class SupplierQuery
{
    public function paginate(Request $request, TenantContext $context)
    {
        $query = Supplier::query()->where('organization_id', $context->organization()->id)->with('categories:id,name');
        if ($term = trim((string) $request->query('q'))) {
            $query->where(fn ($row) => $row->where('legal_name', 'like', "%{$term}%")->orWhere('trade_name', 'like', "%{$term}%")->orWhere('ice', 'like', "%{$term}%"));
        }
        $result = $query->orderBy('legal_name')->paginate(20)->withQueryString();
        if (! $request->user()->canInOrganization('view_supplier_private_data', $context->organization())) {
            $result->getCollection()->each(fn (Supplier $supplier) => $this->hidePrivate($supplier));
        }

        return $result;
    }

    public function search(string $term, TenantContext $context, User $user)
    {
        $rows = Supplier::query()->where('organization_id', $context->organization()->id)
            ->where(fn ($query) => $query->where('legal_name', 'like', "%{$term}%")->orWhere('trade_name', 'like', "%{$term}%")->orWhere('ice', 'like', "%{$term}%"))
            ->orderBy('legal_name')->simplePaginate(15, ['id', 'legal_name', 'trade_name', 'ice'])->withQueryString();
        if (! $user->canInOrganization('view_supplier_private_data', $context->organization())) {
            $rows->getCollection()->each->makeHidden(['ice']);
        }

        return $rows;
    }

    public function hidePrivate(Supplier $supplier): Supplier
    {
        return $supplier->makeHidden(['rib', 'iban', 'bank_name', 'tax_id', 'registration_number', 'professional_tax_number', 'cin', 'metadata']);
    }
}
