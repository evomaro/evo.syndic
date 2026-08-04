<?php

namespace App\Http\Controllers;

use App\Models\LotCharge;
use App\Services\LotStatementService;
use App\Support\ArabicPdf;
use App\Support\Money;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class FinanceController extends Controller
{
    public function index(TenantContext $context, Request $request)
    {
        $residence = $context->residence();
        $from = $request->date('from')?->toDateString() ?? now()->startOfYear()->toDateString();
        $to = $request->date('to')?->toDateString() ?? now()->endOfYear()->toDateString();
        $called = (int) $residence->lotCharges()->whereNull('cancelled_at')->whereBetween('issue_date', [$from, $to])->sum('amount_cents');
        $collected = (int) DB::table('payment_allocations')->join('lot_charges', 'lot_charges.id', '=', 'payment_allocations.lot_charge_id')->where('lot_charges.residence_id', $residence->id)->whereNull('lot_charges.cancelled_at')->whereNull('payment_allocations.reversed_at')->whereBetween('lot_charges.issue_date', [$from, $to])->sum('payment_allocations.amount_cents');
        $outstanding = max(0, $called - $collected);
        $overdueCharges = $residence->lotCharges()->whereNull('cancelled_at')->whereBetween('issue_date', [$from, $to])->where('due_date', '<', now()->toDateString());
        $overdue = (int) (clone $overdueCharges)->get()->sum('outstanding_cents');
        $accounts = $residence->financialAccounts()->where('active', true)->get()->map(fn ($account) => ['id' => $account->id, 'name' => $account->name, 'type' => $account->type, 'balance_cents' => $account->current_balance_cents]);
        $credit = (int) $residence->payments()->where('status', 'validated')->whereNotNull('payer_contact_id')->whereBetween('payment_date', [$from, $to])->get()->sum('credit_cents');

        return Inertia::render('Finance/Overview', [
            'metrics' => ['called_cents' => $called, 'collected_cents' => $collected, 'outstanding_cents' => $outstanding, 'overdue_cents' => $overdue, 'collection_rate' => $called ? round($collected * 100 / $called, 2) : 0, 'credit_cents' => $credit, 'overdue_lots' => (clone $overdueCharges)->distinct()->count('lot_id')],
            'accounts' => $accounts,
            'recentPayments' => $residence->payments()->with('payer')->latest('payment_date')->limit(8)->get()->append(['allocated_cents', 'credit_cents']),
            'upcomingCalls' => $residence->fundCalls()->where('status', 'validated')->where('due_date', '>=', now())->orderBy('due_date')->limit(6)->get(),
            'draftCalls' => $residence->fundCalls()->where('status', 'draft')->latest()->limit(6)->get(),
            'filters' => compact('from', 'to'),
            'generatedAt' => now()->toIso8601String(),
        ]);
    }

    public function outstanding(TenantContext $context, Request $request)
    {
        $request->validate(['minimum' => ['nullable', 'regex:/^(?:\d+|\d{1,3}(?: \d{3})+)(?:[.,]\d{1,2})?$/'], 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $residence = $context->residence();
        $query = LotCharge::query()->where('lot_charges.residence_id', $residence->id)->whereNull('cancelled_at')
            ->whereRaw('lot_charges.amount_cents > (SELECT COALESCE(SUM(payment_allocations.amount_cents), 0) FROM payment_allocations WHERE payment_allocations.lot_charge_id = lot_charges.id AND payment_allocations.reversed_at IS NULL)')
            ->with(['lot.building', 'billedContact', 'fundCall']);
        if ($request->boolean('overdue')) {
            $query->where('due_date', '<', now()->toDateString());
        }
        if ($request->filled('from')) {
            $query->whereDate('issue_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('issue_date', '<=', $request->input('to'));
        }
        if ($request->filled('building')) {
            $query->whereHas('lot', fn ($q) => $q->where('building_id', $request->integer('building')));
        }
        if ($request->filled('minimum')) {
            $minimum = Money::cents((string) $request->input('minimum'));
            $query->whereRaw('lot_charges.amount_cents - (SELECT COALESCE(SUM(payment_allocations.amount_cents), 0) FROM payment_allocations WHERE payment_allocations.lot_charge_id = lot_charges.id AND payment_allocations.reversed_at IS NULL) >= ?', [$minimum]);
        }
        $rows = $query->orderBy('due_date')->paginate(30)->withQueryString()->through(function ($charge) {
            $days = (int) $charge->due_date->diffInDays(today(), false);
            $charge->setAttribute('outstanding_cents', $charge->outstanding_cents);
            $charge->setAttribute('aging', $days <= 0 ? 'current' : ($days <= 30 ? '1-30' : ($days <= 60 ? '31-60' : ($days <= 90 ? '61-90' : '>90'))));

            return $charge;
        });

        return Inertia::render('Finance/Outstanding', ['charges' => $rows, 'buildings' => $residence->buildings()->where('active', true)->get(['id', 'name']), 'filters' => $request->only(['building', 'minimum', 'overdue', 'from', 'to'])]);
    }

    public function statements(TenantContext $context, Request $request, LotStatementService $statements)
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $residence = $context->residence();
        $lotId = $request->integer('lot');
        $lots = $residence->lots()->where('active', true)->orderBy('reference')->get(['id', 'reference']);
        $statement = ['transactions' => collect(), 'opening_balance_cents' => 0, 'closing_balance_cents' => 0];
        if ($lotId && $lots->contains('id', $lotId)) {
            $statement = $statements->build($residence->id, $lotId, $request->input('from'), $request->input('to'));
        }

        return Inertia::render('Finance/Statements', ['lots' => $lots, 'selectedLot' => $lotId, 'transactions' => $statement['transactions'], 'openingBalanceCents' => $statement['opening_balance_cents'], 'closingBalanceCents' => $statement['closing_balance_cents'], 'filters' => $request->only(['from', 'to'])]);
    }

    public function statementPdf(TenantContext $context, Request $request, LotStatementService $statements)
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $residence = $context->residence();
        $lot = $residence->lots()->findOrFail($request->integer('lot'));
        $statement = $statements->build($residence->id, $lot->id, $request->input('from'), $request->input('to'));
        $transactions = $statement['transactions'];
        $locale = $request->user()->preferred_language === 'ar' ? 'ar' : 'fr';
        activity()->causedBy($request->user())->withProperties(['organization_id' => $residence->organization_id, 'residence_id' => $residence->id, 'type' => 'statement_pdf', 'lot_id' => $lot->id])->log('finance.exported');

        $html = view('pdf.statement', compact('residence', 'lot', 'transactions', 'statement', 'locale'))->render();

        return Pdf::loadHTML(ArabicPdf::shapeHtml($html, $locale))->setPaper('a4')->download('releve-'.$lot->reference.'.pdf');
    }

    public function statementCsv(TenantContext $context, Request $request, LotStatementService $statements)
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $residence = $context->residence();
        $lot = $residence->lots()->findOrFail($request->integer('lot'));
        $statement = $statements->build($residence->id, $lot->id, $request->input('from'), $request->input('to'));
        $transactions = $statement['transactions'];
        activity()->causedBy($request->user())->withProperties(['organization_id' => $residence->organization_id, 'residence_id' => $residence->id, 'type' => 'statement_csv', 'lot_id' => $lot->id])->log('finance.exported');

        return response()->streamDownload(function () use ($transactions) {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['Date', 'Echeance', 'Type', 'Reference', 'Libelle', 'Debit MAD', 'Credit MAD', 'Solde MAD']);
            foreach ($transactions as $r) {
                fputcsv($h, array_map($this->csvCell(...), [$r['date'], $r['due_date'], $r['type'], $r['reference'], $r['label'], Money::decimal($r['debit_cents']), Money::decimal($r['credit_cents']), Money::decimal($r['balance_cents'])]));
            } fclose($h);
        }, 'releve-'.$lot->reference.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportOutstanding(TenantContext $context, Request $request)
    {
        $request->validate(['building' => 'nullable|integer', 'minimum' => ['nullable', 'regex:/^(?:\d+|\d{1,3}(?: \d{3})+)(?:[.,]\d{1,2})?$/'], 'overdue' => 'nullable|boolean', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $residence = $context->residence();
        activity()->causedBy(request()->user())->withProperties(['organization_id' => $residence->organization_id, 'residence_id' => $residence->id, 'type' => 'outstanding_csv'])->log('finance.exported');
        $rows = $residence->lotCharges()->whereNull('cancelled_at')->with(['lot', 'billedContact'])
            ->when($request->boolean('overdue'), fn ($query) => $query->where('due_date', '<', now()->toDateString()))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('issue_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('issue_date', '<=', $request->input('to')))
            ->when($request->filled('building'), fn ($query) => $query->whereHas('lot', fn ($lot) => $lot->where('building_id', $request->integer('building'))))
            ->when($request->filled('minimum'), fn ($query) => $query->whereRaw('lot_charges.amount_cents - (SELECT COALESCE(SUM(payment_allocations.amount_cents), 0) FROM payment_allocations WHERE payment_allocations.lot_charge_id = lot_charges.id AND payment_allocations.reversed_at IS NULL) >= ?', [Money::cents((string) $request->input('minimum'))]))
            ->whereRaw('lot_charges.amount_cents > (SELECT COALESCE(SUM(payment_allocations.amount_cents), 0) FROM payment_allocations WHERE payment_allocations.lot_charge_id = lot_charges.id AND payment_allocations.reversed_at IS NULL)')->get();

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Lot', 'Coproprietaire', 'Echeance', 'Montant MAD', 'Solde MAD']);
            foreach ($rows as $row) {
                fputcsv($handle, array_map($this->csvCell(...), [$row->lot->reference, $row->contact_name_snapshot, $row->due_date->format('Y-m-d'), Money::decimal($row->amount_cents), Money::decimal($row->outstanding_cents)]));
            }
            fclose($handle);
        }, 'impayes-'.$residence->code.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function search(TenantContext $context, Request $request)
    {
        $residence = $context->residence();
        $term = trim((string) $request->input('q'));
        $lots = $residence->lots()->where('active', true)->when($term, fn ($q) => $q->where(fn ($s) => $s->where('reference', 'like', "%{$term}%")->orWhere('lot_number', 'like', "%{$term}%")))->limit(20)->get(['id', 'reference', 'lot_number']);
        $contacts = $residence->organization->contacts()->where('active', true)->when($term, fn ($q) => $q->where(fn ($s) => $s->where('first_name', 'like', "%{$term}%")->orWhere('last_name', 'like', "%{$term}%")->orWhere('company_name', 'like', "%{$term}%")->orWhere('phone_normalized', 'like', "%{$term}%")))->limit(20)->get()->map(fn ($c) => ['id' => $c->id, 'name' => $c->display_name, 'phone' => $c->primary_phone]);

        return response()->json(compact('lots', 'contacts'));
    }

    private function csvCell(mixed $value): string
    {
        $cell = (string) $value;

        return preg_match('/^[=+\-@]/u', $cell) ? "'".$cell : $cell;
    }
}
