<?php

namespace App\Services;

use App\Models\AccountingAutomation;
use App\Models\AccountingBook;
use App\Models\AccountingPeriod;
use App\Models\AccountingPostingRule;
use App\Models\AccountingSourceMapping;
use App\Models\AccountingSourcePosting;
use App\Models\FundCall;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SupplierCreditNote;
use App\Models\SupplierInvoice;
use App\Models\SupplierSettlement;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AutomatedAccountingPostingService
{
    public function __construct(private readonly AccountingPostingService $posting) {}

    public function postFundCall(FundCall $call, User $actor): ?AccountingSourcePosting
    {
        $call->loadMissing('charges.line');
        $credits = $call->charges->groupBy(fn ($charge) => $charge->line->charge_category_id)
            ->map(fn ($rows, $categoryId) => [
                'category_id' => (int) $categoryId,
                'amount' => (int) $rows->sum('amount_cents'),
                'label' => __('Appel de fonds : :label', ['label' => $rows->first()->line->label]),
            ])->values();

        return $this->postEvent([
            'source' => $call,
            'source_type' => 'fund_call',
            'source_event' => 'validated',
            'organization_id' => $call->organization_id,
            'residence_id' => $call->residence_id,
            'date' => $call->issue_date->toDateString(),
            'reference' => $call->number,
            'description' => "Appel de fonds {$call->number}",
            'version_payload' => [
                'number' => $call->number,
                'date' => $call->issue_date->toDateString(),
                'total' => (int) $call->total_cents,
                'charges' => $call->charges->sortBy('id')->map->only(['id', 'fund_call_line_id', 'lot_id', 'amount_cents'])->values()->all(),
            ],
            'build_lines' => function (AccountingPostingRule $rule) use ($call, $credits) {
                $this->assertResolutions($rule, ['fixed_account', 'receivable_control'], ['charge_category']);
                $debit = $rule->debit_resolution === 'fixed_account'
                    ? $this->fixedAccount($rule, 'debit')
                    : $this->mapping($rule->accounting_book_id, 'receivable_control', 0, $call->issue_date->toDateString());

                return collect([['account' => $debit, 'debit' => (int) $call->total_cents, 'credit' => 0, 'label' => "Créances {$call->number}"]])
                    ->concat($credits->map(fn ($row) => [
                        'account' => $this->mapping($rule->accounting_book_id, 'charge_category', $row['category_id'], $call->issue_date->toDateString()),
                        'debit' => 0,
                        'credit' => $row['amount'],
                        'label' => $row['label'],
                    ]));
            },
        ], $actor);
    }

    public function postPayment(Payment $payment, User $actor): ?AccountingSourcePosting
    {
        $payment->loadMissing('allocations');
        $allocated = (int) $payment->allocations->whereNull('reversed_at')->sum('amount_cents');
        $advance = (int) $payment->amount_cents - $allocated;

        return $this->postEvent([
            'source' => $payment,
            'source_type' => 'payment',
            'source_event' => 'received',
            'organization_id' => $payment->organization_id,
            'residence_id' => $payment->residence_id,
            'date' => $payment->payment_date->toDateString(),
            'reference' => $payment->number,
            'description' => "Encaissement {$payment->number}",
            'version_payload' => [
                'number' => $payment->number,
                'date' => $payment->payment_date->toDateString(),
                'amount' => (int) $payment->amount_cents,
                'financial_account_id' => (int) $payment->financial_account_id,
            ],
            'build_lines' => function (AccountingPostingRule $rule) use ($payment, $allocated, $advance) {
                $this->assertResolutions($rule, ['financial_account'], ['payment_split']);
                $date = $payment->payment_date->toDateString();
                $lines = collect([[
                    'account' => $this->mapping($rule->accounting_book_id, 'financial_account', $payment->financial_account_id, $date),
                    'debit' => (int) $payment->amount_cents,
                    'credit' => 0,
                    'label' => "Encaissement {$payment->number}",
                ]]);
                if ($allocated > 0) {
                    $lines->push([
                        'account' => $this->mapping($rule->accounting_book_id, 'receivable_control', 0, $date),
                        'debit' => 0,
                        'credit' => $allocated,
                        'label' => __('Affectation aux créances'),
                    ]);
                }
                if ($advance > 0) {
                    $lines->push([
                        'account' => $this->mapping($rule->accounting_book_id, 'advance_control', 0, $date),
                        'debit' => 0,
                        'credit' => $advance,
                        'label' => __('Avance non affectée'),
                    ]);
                }

                return $lines;
            },
        ], $actor);
    }

    public function postPaymentAllocation(PaymentAllocation $allocation, User $actor): ?AccountingSourcePosting
    {
        $allocation->loadMissing('payment');
        $payment = $allocation->payment;

        return $this->postEvent([
            'source' => $allocation,
            'source_type' => 'payment_allocation',
            'source_event' => 'credit_applied',
            'organization_id' => $payment->organization_id,
            'residence_id' => $payment->residence_id,
            'date' => $allocation->allocated_on->toDateString(),
            'reference' => $payment->number.'-A'.$allocation->id,
            'description' => "Affectation de crédit {$payment->number}",
            'version_payload' => [
                'payment_id' => (int) $payment->id,
                'allocation_id' => (int) $allocation->id,
                'charge_id' => (int) $allocation->lot_charge_id,
                'amount' => (int) $allocation->amount_cents,
                'date' => $allocation->allocated_on->toDateString(),
            ],
            'build_lines' => function (AccountingPostingRule $rule) use ($allocation) {
                $this->assertResolutions($rule, ['advance_control'], ['receivable_control']);
                $date = $allocation->allocated_on->toDateString();

                return collect([
                    [
                        'account' => $this->mapping($rule->accounting_book_id, 'advance_control', 0, $date),
                        'debit' => (int) $allocation->amount_cents,
                        'credit' => 0,
                        'label' => __('Utilisation d’une avance'),
                    ],
                    [
                        'account' => $this->mapping($rule->accounting_book_id, 'receivable_control', 0, $date),
                        'debit' => 0,
                        'credit' => (int) $allocation->amount_cents,
                        'label' => __('Affectation aux créances'),
                    ],
                ]);
            },
        ], $actor);
    }

    public function postSupplierInvoice(SupplierInvoice $invoice, User $actor): Collection
    {
        $invoice->loadMissing('lines');

        return $invoice->lines->groupBy('residence_id')->map(function ($lines, $residenceId) use ($invoice, $actor) {
            $total = (int) $lines->sum('total_cents');

            return $this->postEvent([
                'source' => $invoice,
                'source_type' => 'supplier_invoice',
                'source_event' => 'validated',
                'organization_id' => $invoice->organization_id,
                'residence_id' => (int) $residenceId,
                'date' => $invoice->invoice_date->toDateString(),
                'reference' => $invoice->number,
                'description' => "Facture fournisseur {$invoice->number}",
                'version_payload' => [
                    'invoice_id' => (int) $invoice->id,
                    'residence_id' => (int) $residenceId,
                    'number' => $invoice->number,
                    'date' => $invoice->invoice_date->toDateString(),
                    'lines' => $lines->sortBy('id')->map->only(['id', 'expense_category_id', 'subtotal_cents', 'tax_cents', 'total_cents'])->values()->all(),
                ],
                'build_lines' => function (AccountingPostingRule $rule) use ($invoice, $lines, $total) {
                    $this->assertResolutions($rule, ['expense_category'], ['fixed_account', 'supplier_payable']);
                    $date = $invoice->invoice_date->toDateString();
                    $debits = $lines->groupBy('expense_category_id')->map(fn ($categoryLines, $categoryId) => [
                        'account' => $this->mapping($rule->accounting_book_id, 'expense_category', (int) $categoryId, $date),
                        'debit' => (int) $categoryLines->sum('total_cents'),
                        'credit' => 0,
                        'label' => __('Charge fournisseur'),
                    ])->values();
                    $payable = $rule->credit_resolution === 'fixed_account'
                        ? $this->fixedAccount($rule, 'credit')
                        : $this->mapping($rule->accounting_book_id, 'supplier_payable', 0, $date);

                    return $debits->push([
                        'account' => $payable,
                        'debit' => 0,
                        'credit' => $total,
                        'label' => __('Dette fournisseur'),
                    ]);
                },
            ], $actor);
        });
    }

    public function postSupplierSettlement(SupplierSettlement $settlement, User $actor): ?AccountingSourcePosting
    {
        $settlement->loadMissing('allocations');

        return $this->postEvent([
            'source' => $settlement,
            'source_type' => 'supplier_settlement',
            'source_event' => 'validated',
            'organization_id' => $settlement->organization_id,
            'residence_id' => $settlement->residence_id,
            'date' => $settlement->settlement_date->toDateString(),
            'reference' => $settlement->number,
            'description' => "Règlement fournisseur {$settlement->number}",
            'version_payload' => [
                'settlement_id' => (int) $settlement->id,
                'number' => $settlement->number,
                'date' => $settlement->settlement_date->toDateString(),
                'amount' => (int) $settlement->amount_cents,
                'financial_account_id' => (int) $settlement->financial_account_id,
                'allocations' => $settlement->allocations->sortBy('id')->map->only(['id', 'supplier_invoice_id', 'supplier_invoice_line_id', 'amount_cents'])->values()->all(),
            ],
            'build_lines' => function (AccountingPostingRule $rule) use ($settlement) {
                $this->assertResolutions($rule, ['fixed_account', 'supplier_payable'], ['financial_account']);
                $date = $settlement->settlement_date->toDateString();
                $payable = $rule->debit_resolution === 'fixed_account'
                    ? $this->fixedAccount($rule, 'debit')
                    : $this->mapping($rule->accounting_book_id, 'supplier_payable', 0, $date);

                return collect([
                    [
                        'account' => $payable,
                        'debit' => (int) $settlement->amount_cents,
                        'credit' => 0,
                        'label' => __('Règlement de dette fournisseur'),
                    ],
                    [
                        'account' => $this->mapping($rule->accounting_book_id, 'financial_account', $settlement->financial_account_id, $date),
                        'debit' => 0,
                        'credit' => (int) $settlement->amount_cents,
                        'label' => __('Sortie de trésorerie'),
                    ],
                ]);
            },
        ], $actor);
    }

    public function postSupplierCreditNote(SupplierCreditNote $credit, User $actor): ?AccountingSourcePosting
    {
        $credit->loadMissing('allocations');

        return $this->postEvent([
            'source' => $credit,
            'source_type' => 'supplier_credit_note',
            'source_event' => 'validated',
            'organization_id' => $credit->organization_id,
            'residence_id' => $credit->residence_id,
            'date' => $credit->credit_date->toDateString(),
            'reference' => $credit->number,
            'description' => "Avoir fournisseur {$credit->number}",
            'version_payload' => [
                'credit_id' => (int) $credit->id,
                'number' => $credit->number,
                'date' => $credit->credit_date->toDateString(),
                'amount' => (int) $credit->amount_cents,
                'allocations' => $credit->allocations->sortBy('id')->map->only(['id', 'supplier_invoice_id', 'expense_category_id', 'amount_cents'])->values()->all(),
            ],
            'build_lines' => function (AccountingPostingRule $rule) use ($credit) {
                $this->assertResolutions($rule, ['supplier_payable', 'fixed_account'], ['expense_category']);
                $date = $credit->credit_date->toDateString();
                $payable = $rule->debit_resolution === 'fixed_account'
                    ? $this->fixedAccount($rule, 'debit')
                    : $this->mapping($rule->accounting_book_id, 'supplier_payable', 0, $date);
                $lines = collect([[
                    'account' => $payable,
                    'debit' => (int) $credit->amount_cents,
                    'credit' => 0,
                    'label' => __('Réduction de dette fournisseur'),
                ]]);

                return $lines->concat($credit->allocations->groupBy('expense_category_id')->map(fn ($rows, $categoryId) => [
                    'account' => $this->mapping($rule->accounting_book_id, 'expense_category', (int) $categoryId, $date),
                    'debit' => 0,
                    'credit' => (int) $rows->sum('amount_cents'),
                    'label' => __('Réduction de charge'),
                ])->values());
            },
        ], $actor);
    }

    public function reverse(string $sourceType, int $sourceId, User $actor, string $reason): Collection
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Le motif est obligatoire.')]);
        }

        return DB::transaction(function () use ($sourceType, $sourceId, $actor, $reason) {
            $registries = AccountingSourcePosting::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->whereIn('status', ['posted', 'reversed'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            return $registries->map(function (AccountingSourcePosting $registry) use ($actor, $reason) {
                if ($registry->status === 'reversed') {
                    return $registry;
                }
                $period = AccountingPeriod::query()
                    ->where('organization_id', $registry->organization_id)
                    ->where('residence_id', $registry->residence_id)
                    ->where('status', 'open')
                    ->whereDate('starts_on', '<=', today())
                    ->whereDate('ends_on', '>=', today())
                    ->lockForUpdate()
                    ->first();
                if (! $period) {
                    throw ValidationException::withMessages(['period' => __('Une période comptable ouverte couvrant la date d’extourne est requise.')]);
                }
                $reversal = $this->posting->reverse($registry->entry()->firstOrFail(), $period, $actor, $reason);
                $registry->update(['status' => 'reversed', 'reversal_entry_id' => $reversal->id]);

                return $registry->fresh();
            });
        }, 5);
    }

    public function reversePayment(Payment $payment, User $actor, string $reason): Collection
    {
        $registries = collect();
        $allocationIds = $payment->allocations()->pluck('id');
        if ($allocationIds->isNotEmpty()) {
            $registries = AccountingSourcePosting::query()->where('source_type', 'payment_allocation')->whereIn('source_id', $allocationIds)->get()
                ->map(fn ($registry) => $this->reverse('payment_allocation', $registry->source_id, $actor, $reason)->first())
                ->filter();
        }

        return $registries->push($this->reverse('payment', $payment->id, $actor, $reason)->first())->filter();
    }

    private function postEvent(array $event, User $actor): ?AccountingSourcePosting
    {
        return DB::transaction(function () use ($event, $actor) {
            $book = AccountingBook::query()
                ->where('organization_id', $event['organization_id'])
                ->where('residence_id', $event['residence_id'])
                ->lockForUpdate()
                ->first();
            if (! $book) {
                return null;
            }
            $automation = AccountingAutomation::query()
                ->where('accounting_book_id', $book->id)
                ->where('status', 'active')
                ->whereDate('effective_from', '<=', $event['date'])
                ->lockForUpdate()
                ->first();
            if (! $automation) {
                return null;
            }

            $rule = AccountingPostingRule::query()
                ->where('accounting_book_id', $book->id)
                ->where('source_domain', $event['source_type'])
                ->where('source_event', $event['source_event'])
                ->where('status', 'active')
                ->where('professional_review_status', 'approved')
                ->whereDate('effective_from', '<=', $event['date'])
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $event['date']))
                ->lockForUpdate()
                ->first();
            if (! $rule) {
                throw ValidationException::withMessages(['accounting' => __('Aucune règle comptable active ne couvre cet événement.')]);
            }

            $exercise = DB::table('financial_exercises')
                ->where('accounting_book_id', $book->id)
                ->where('status', 'open')
                ->whereDate('starts_on', '<=', $event['date'])
                ->whereDate('ends_on', '>=', $event['date'])
                ->lockForUpdate()
                ->first();
            $period = AccountingPeriod::query()
                ->where('financial_exercise_id', $exercise?->id)
                ->where('status', 'open')
                ->whereDate('starts_on', '<=', $event['date'])
                ->whereDate('ends_on', '>=', $event['date'])
                ->lockForUpdate()
                ->first();
            if (! $exercise || ! $period) {
                throw ValidationException::withMessages(['accounting' => __('Aucun exercice et aucune période ouverts ne couvrent la date comptable.')]);
            }

            $sourceVersion = hash('sha256', json_encode($event['version_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $postingKey = hash('sha256', implode('|', [$book->id, $event['source_type'], $event['source']->getKey(), $event['source_event'], $sourceVersion]));
            $existing = AccountingSourcePosting::query()
                ->where('accounting_book_id', $book->id)
                ->where('source_type', $event['source_type'])
                ->where('source_id', $event['source']->getKey())
                ->where('source_event', $event['source_event'])
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if ($existing->source_version !== $sourceVersion) {
                    throw ValidationException::withMessages(['accounting' => __('La source comptabilisée a changé depuis sa première comptabilisation.')]);
                }
                if (in_array($existing->status, ['posted', 'reversed'], true)) {
                    return $existing;
                }
            }

            $registry = $existing ?: AccountingSourcePosting::create([
                'organization_id' => $book->organization_id,
                'residence_id' => $book->residence_id,
                'accounting_book_id' => $book->id,
                'source_type' => $event['source_type'],
                'source_id' => $event['source']->getKey(),
                'source_event' => $event['source_event'],
                'source_version' => $sourceVersion,
                'posting_key' => $postingKey,
                'accounting_posting_rule_id' => $rule->id,
                'status' => 'pending',
                'actor_id' => $actor->id,
                'context' => app()->runningInConsole() ? 'command' : 'http',
            ]);
            $registry->increment('attempt_count');

            $lines = value($event['build_lines'], $rule);
            $entry = JournalEntry::create([
                'organization_id' => $book->organization_id,
                'residence_id' => $book->residence_id,
                'accounting_book_id' => $book->id,
                'financial_exercise_id' => $exercise->id,
                'accounting_period_id' => $period->id,
                'accounting_journal_id' => $rule->accounting_journal_id,
                'entry_date' => $event['date'],
                'reference' => $event['reference'],
                'description_fr' => $event['description'],
                'status' => 'draft',
                'source_type' => $event['source_type'],
                'source_id' => (string) $event['source']->getKey(),
                'posting_key' => $postingKey,
                'metadata' => [
                    'source_event' => $event['source_event'],
                    'source_version' => $sourceVersion,
                    'posting_rule' => ['id' => $rule->id, 'code' => $rule->stable_code, 'version' => $rule->version],
                ],
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            foreach ($lines->values() as $index => $line) {
                $entry->lines()->create([
                    'sequence' => $index + 1,
                    'ledger_account_id' => $line['account']->id,
                    'label' => $line['label'],
                    'debit_minor' => $line['debit'],
                    'credit_minor' => $line['credit'],
                    'metadata' => ['source_type' => $event['source_type'], 'source_id' => $event['source']->getKey()],
                ]);
            }
            $entry = $this->posting->post($entry, $actor);
            $registry->update(['status' => 'posted', 'journal_entry_id' => $entry->id, 'posted_at' => now()]);
            $this->event($registry, $actor);

            return $registry->fresh('entry');
        }, 5);
    }

    private function mapping(int $bookId, string $type, int $sourceId, string $date): LedgerAccount
    {
        $mapping = AccountingSourceMapping::query()
            ->where('accounting_book_id', $bookId)
            ->where('mapping_type', $type)
            ->where('source_id', $sourceId)
            ->where('review_status', 'approved')
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->orderByDesc('effective_from')
            ->with('account')
            ->first();
        if (! $mapping
            || (int) $mapping->account?->accounting_book_id !== $bookId
            || ! $mapping->account?->active
            || ! $mapping->account->posting_allowed) {
            throw ValidationException::withMessages(['accounting_mapping' => __("Correspondance comptable manquante ou inactive : {$type}:{$sourceId}.")]);
        }

        return $mapping->account;
    }

    private function fixedAccount(AccountingPostingRule $rule, string $side): LedgerAccount
    {
        $accountId = $side === 'debit' ? $rule->debit_ledger_account_id : $rule->credit_ledger_account_id;
        $account = LedgerAccount::query()->whereKey($accountId)->where('accounting_book_id', $rule->accounting_book_id)->where('active', true)->where('posting_allowed', true)->first();
        if (! $account) {
            throw ValidationException::withMessages(['accounting_mapping' => __('Le compte fixe de la règle est manquant ou inactif.')]);
        }

        return $account;
    }

    private function assertResolutions(AccountingPostingRule $rule, array $debits, array $credits): void
    {
        if (! in_array($rule->debit_resolution, $debits, true) || ! in_array($rule->credit_resolution, $credits, true)) {
            throw ValidationException::withMessages(['accounting_rule' => __('La règle active utilise des résolutions incompatibles avec cet événement.')]);
        }
    }

    private function event(AccountingSourcePosting $registry, User $actor): void
    {
        DB::table('accounting_activity_events')->insert([
            'organization_id' => $registry->organization_id,
            'residence_id' => $registry->residence_id,
            'record_type' => AccountingSourcePosting::class,
            'record_id' => $registry->id,
            'action' => 'source_posted',
            'actor_id' => $actor->id,
            'after_evidence' => json_encode([
                'source_type' => $registry->source_type,
                'source_id' => $registry->source_id,
                'source_event' => $registry->source_event,
                'rule_id' => $registry->accounting_posting_rule_id,
                'entry_id' => $registry->journal_entry_id,
            ]),
            'context' => app()->runningInConsole() ? 'command' : 'http',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
