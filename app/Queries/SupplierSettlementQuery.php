<?php

namespace App\Queries;

use App\Models\SupplierSettlement;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class SupplierSettlementQuery
{
    public function paginate(Request $request, TenantContext $context)
    {
        $query = SupplierSettlement::query()->where('residence_id', $context->residence()->id)->with(['supplier:id,legal_name', 'documents']);
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return $query->latest('settlement_date')->paginate(20)->withQueryString();
    }
}
