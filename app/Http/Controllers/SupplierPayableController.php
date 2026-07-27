<?php

namespace App\Http\Controllers;

use App\Queries\SupplierPayableQuery;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupplierPayableController extends Controller
{
    public function __invoke(Request $request, TenantContext $context, SupplierPayableQuery $query)
    {
        return Inertia::render('SupplierPayables/Index', ['payables' => $query->get($request, $context), 'filters' => $request->only('supplier_id')]);
    }
}
