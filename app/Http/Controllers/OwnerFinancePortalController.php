<?php

namespace App\Http\Controllers;

use App\Models\Lot;
use App\Models\LotOwnership;
use App\Services\LotStatementService;
use App\Support\ArabicPdf;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OwnerFinancePortalController extends Controller
{
    public function __invoke(Request $request, TenantContext $context)
    {
        $residence = $context->residence();
        $contactIds = $request->user()->contacts()->where('contacts.organization_id', $residence->organization_id)->pluck('contacts.id');
        $ownerships = LotOwnership::query()->whereIn('contact_id', $contactIds)->whereHas('lot', fn ($q) => $q->where('residence_id', $residence->id))->with('lot')->get();
        abort_if($ownerships->isEmpty(), 403);
        $lotIds = $ownerships->pluck('lot_id')->unique();
        $lots = Lot::query()->whereIn('id', $lotIds)->with(['charges' => fn ($q) => $q->whereNull('cancelled_at')->with('fundCall')])->get()->map(function ($lot) use ($ownerships, $contactIds) {
            $periods = $ownerships->where('lot_id', $lot->id)->whereIn('contact_id', $contactIds);
            $allCharges = $lot->charges;
            $visibleCharges = $allCharges->filter(fn ($charge) => $periods->contains(fn ($period) => $period->starts_on->lte($charge->issue_date) && (! $period->ends_on || $period->ends_on->gte($charge->issue_date))));
            $isCurrentOwner = $periods->contains(fn ($period) => $period->starts_on->lte(now()) && (! $period->ends_on || $period->ends_on->gte(now())));
            $lot->setRelation('charges', $visibleCharges->values());
            $lot->setAttribute('balance_cents', ($isCurrentOwner ? $allCharges : $visibleCharges)->sum('outstanding_cents'));
            $lot->setAttribute('is_current_owner', $isCurrentOwner);
            $lot->setAttribute('inherited_debt_cents', $isCurrentOwner ? $allCharges->filter(fn ($charge) => $periods->every(fn ($period) => $charge->issue_date->lt($period->starts_on)))->sum('outstanding_cents') : 0);

            return $lot;
        });
        $payments = $residence->payments()->whereIn('payer_contact_id', $contactIds)->whereIn('status', ['validated', 'reversed'])->with('documents')->latest('payment_date')->get()->append(['allocated_cents', 'credit_cents']);

        return Inertia::render('Finance/OwnerPortal', ['lots' => $lots, 'combinedBalanceCents' => $lots->sum('balance_cents'), 'payments' => $payments, 'availableCreditCents' => $payments->where('status', 'validated')->sum('credit_cents')]);
    }

    public function statement(Request $request, TenantContext $context, Lot $lot, LotStatementService $statements)
    {
        $residence = $context->residence();
        abort_unless($lot->residence_id === $residence->id, 404);
        $contactIds = $request->user()->contacts()->where('contacts.organization_id', $residence->organization_id)->pluck('contacts.id');
        $periods = LotOwnership::query()->where('lot_id', $lot->id)->whereIn('contact_id', $contactIds)->get();
        abort_if($periods->isEmpty(), 403);

        $visibleOn = fn (string $date): bool => $periods->contains(fn ($period) => $period->starts_on->toDateString() <= $date && (! $period->ends_on || $period->ends_on->toDateString() >= $date));
        $all = $statements->build($residence->id, $lot->id)['transactions'];
        $transactions = $all->filter(fn ($row) => $visibleOn($row['date']))->values();
        $currentPeriod = $periods->first(fn ($period) => $period->starts_on->lte(today()) && (! $period->ends_on || $period->ends_on->gte(today())));
        if ($currentPeriod) {
            $targetClosing = $lot->charges()->whereNull('cancelled_at')->get()->sum('outstanding_cents');
            $visibleNet = $transactions->sum(fn ($row) => $row['debit_cents'] - $row['credit_cents']);
            $inherited = $targetClosing - $visibleNet;
            if ($inherited !== 0) {
                $transactions->prepend(['date' => $currentPeriod->starts_on->toDateString(), 'due_date' => null, 'type' => 'inherited_balance', 'reference' => 'REPORT', 'label' => __('Dette rattachée au lot avant la période de propriété'), 'debit_cents' => max(0, $inherited), 'credit_cents' => max(0, -$inherited)]);
            }
        }
        $balance = 0;
        $transactions = $transactions->sortBy([['date', 'asc'], ['reference', 'asc']])->values()->map(function ($row) use (&$balance) {
            $balance += $row['debit_cents'] - $row['credit_cents'];
            $row['balance_cents'] = $balance;

            return $row;
        });
        $statement = ['opening_balance_cents' => 0, 'closing_balance_cents' => $balance, 'transactions' => $transactions];
        $locale = $request->user()->preferred_language === 'ar' ? 'ar' : 'fr';
        activity()->causedBy($request->user())->withProperties(['organization_id' => $residence->organization_id, 'residence_id' => $residence->id, 'type' => 'owner_statement_pdf', 'lot_id' => $lot->id])->log('finance.exported');
        $html = view('pdf.statement', compact('residence', 'lot', 'transactions', 'statement', 'locale'))->render();

        return Pdf::loadHTML(ArabicPdf::shapeHtml($html, $locale))->setPaper('a4')->download('releve-'.$lot->reference.'.pdf');
    }
}
