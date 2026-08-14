<?php

namespace App\Http\Controllers;

use App\Models\Lot;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LotController extends Controller
{
    public function show(Lot $lot, TenantContext $context)
    {
        abort_unless($lot->residence_id === $context->residence()->id, 404);
        $charge = $lot->charges()->whereNull('cancelled_at')->withSum(['allocations as paid_cents' => fn ($query) => $query->whereNull('reversed_at')], 'amount_cents')->latest('issue_date')->first();
        $paid = (int) ($charge?->paid_cents ?? 0);
        $remaining = max(0, (int) ($charge?->amount_cents ?? 0) - $paid);

        return Inertia::render('Lots/Show', [
            'lot' => $lot->load([
                'building:id,name', 'entrance:id,name', 'floor:id,name',
                'ownerships' => fn ($query) => $query->with('contact')->latest('starts_on'),
                'occupancies' => fn ($query) => $query->with('contact')->latest('starts_on'),
                'allocationValues.allocationKey:id,name,code,expected_total',
            ]),
            'currentCotisation' => $charge ? [
                'period' => $charge->issue_date->format('Y-m'),
                'expected_cents' => (int) $charge->amount_cents,
                'paid_cents' => $paid,
                'remaining_cents' => $remaining,
                'status' => $remaining === 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
            ] : null,
        ]);
    }

    public function contacts(Request $request, TenantContext $context)
    {
        $data = $request->validate(['search' => 'nullable|string|max:100']);
        $search = $data['search'] ?? '';

        return $context->organization()->contacts()->where('active', true)
            ->when($search, fn ($query) => $query->where(function ($inner) use ($search) {
                $inner->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%")
                    ->orWhere('primary_phone', 'like', "%{$search}%");
            }))
            ->limit(20)->get(['id', 'type', 'first_name', 'last_name', 'company_name', 'primary_phone']);
    }
}
