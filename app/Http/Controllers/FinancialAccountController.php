<?php

namespace App\Http\Controllers;

use App\Models\FinancialAccount;
use App\Rules\ValidMoney;
use App\Support\Money;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class FinancialAccountController extends Controller
{
    public function index(TenantContext $context)
    {
        return Inertia::render('Finance/Accounts', ['accounts' => $context->residence()->financialAccounts()->get()->map(function ($a) {
            $a->setAttribute('current_balance_cents', $a->current_balance_cents);

            return $a;
        })]);
    }

    public function store(Request $request, TenantContext $context)
    {
        $residence = $context->residence();
        $data = $this->validated($request, $residence->id);
        DB::transaction(function () use ($residence, $data, $request) {
            if ($data['is_default']) {
                $residence->financialAccounts()->update(['default_slot' => null]);
            } $account = $residence->financialAccounts()->create(collect($data)->except(['opening_balance', 'is_default'])->all() + ['organization_id' => $residence->organization_id, 'opening_balance_cents' => Money::cents($data['opening_balance']), 'default_slot' => $data['is_default'] ? 1 : null]);
            activity()->performedOn($account)->causedBy($request->user())->withProperties(['organization_id' => $residence->organization_id, 'residence_id' => $residence->id])->log('financial_account.created');
        });

        return back()->with('success', __('Compte financier créé.'));
    }

    public function update(Request $request, FinancialAccount $account, TenantContext $context)
    {
        $this->tenant($account, $context);
        $data = $this->validated($request, $account->residence_id, $account->id);
        $oldOpeningBalance = (int) $account->opening_balance_cents;
        $newOpeningBalance = Money::cents($data['opening_balance']);
        if ($account->movements()->exists() && $newOpeningBalance !== $oldOpeningBalance && ! $request->filled('change_reason')) {
            throw ValidationException::withMessages(['change_reason' => __('Un motif est requis après le premier mouvement.')]);
        }
        DB::transaction(function () use ($account, $data, $request, $oldOpeningBalance, $newOpeningBalance) {
            if ($data['is_default']) {
                FinancialAccount::where('residence_id', $account->residence_id)->whereKeyNot($account->id)->update(['default_slot' => null]);
            } $account->update(collect($data)->except(['opening_balance', 'is_default', 'change_reason'])->all() + ['opening_balance_cents' => Money::cents($data['opening_balance']), 'default_slot' => $data['is_default'] ? 1 : null]);
            activity()->performedOn($account)->causedBy($request->user())->withProperties(['organization_id' => $account->organization_id, 'residence_id' => $account->residence_id, 'reason' => $data['change_reason'] ?? null, 'opening_balance_before_cents' => $oldOpeningBalance, 'opening_balance_after_cents' => $newOpeningBalance])->log('financial_account.updated');
        });

        return back()->with('success', __('Compte financier mis à jour.'));
    }

    public function archive(FinancialAccount $account, TenantContext $context)
    {
        $this->tenant($account, $context);
        if ($account->default_slot || $account->payments()->where('status', 'validated')->exists()) {
            throw ValidationException::withMessages(['account' => __('Ce compte référencé ou par défaut ne peut pas être archivé.')]);
        } $account->update(['active' => false]);

        return back();
    }

    private function validated(Request $r, int $residenceId, ?int $ignore = null): array
    {
        return $r->validate(['name' => 'required|string|max:120', 'code' => ['required', 'alpha_dash', Rule::unique('financial_accounts')->where('residence_id', $residenceId)->ignore($ignore)], 'type' => ['required', Rule::in(['bank', 'cash'])], 'bank_name' => 'nullable|string|max:120', 'rib' => 'nullable|string|max:60', 'iban' => 'nullable|string|max:60', 'opening_balance' => ['required', new ValidMoney(false)], 'opening_balance_on' => 'nullable|date', 'active' => 'boolean', 'is_default' => 'boolean', 'notes' => 'nullable|string|max:2000', 'change_reason' => 'nullable|string|max:1000']);
    }

    private function tenant(FinancialAccount $a, TenantContext $c): void
    {
        abort_unless($a->organization_id === $c->organization()->id && $a->residence_id === $c->residence()->id, 404);
    }
}
