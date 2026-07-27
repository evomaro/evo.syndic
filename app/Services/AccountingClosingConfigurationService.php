<?php

namespace App\Services;

use App\Models\AccountingBook;
use App\Models\AccountingClosingAccountClassification;
use App\Models\AccountingClosingConfiguration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingClosingConfigurationService
{
    public function create(AccountingBook $book, array $data, User $actor): AccountingClosingConfiguration
    {
        return DB::transaction(function () use ($book, $data, $actor) {
            $book = AccountingBook::query()->lockForUpdate()->findOrFail($book->id);
            foreach ([
                'closing_journal_id' => $data['closing_journal_id'] ?? null,
                'opening_journal_id' => $data['opening_journal_id'] ?? null,
            ] as $field => $id) {
                if ($id && ! $book->journals()->whereKey($id)->exists()) {
                    throw ValidationException::withMessages([$field => __('Le journal doit appartenir au livre comptable courant.')]);
                }
            }
            if (($data['result_transfer_account_id'] ?? null)
                && ! $book->accounts()->whereKey($data['result_transfer_account_id'])->exists()) {
                throw ValidationException::withMessages([
                    'result_transfer_account_id' => __('Le compte de résultat doit appartenir au livre comptable courant.'),
                ]);
            }
            $configuration = AccountingClosingConfiguration::create([
                'organization_id' => $book->organization_id,
                'residence_id' => $book->residence_id,
                'accounting_book_id' => $book->id,
                'version' => $data['version'],
                'status' => 'draft',
                'currency' => 'MAD',
                'closing_journal_id' => $data['closing_journal_id'] ?? null,
                'opening_journal_id' => $data['opening_journal_id'] ?? null,
                'result_transfer_account_id' => $data['result_transfer_account_id'] ?? null,
                'professional_review_status' => 'pending_professional_review',
                'counsel_review_status' => 'pending_counsel_review',
                'effective_from' => $data['effective_from'],
                'prepared_by' => $actor->id,
            ]);
            foreach ($data['classifications'] ?? [] as $classification) {
                if (! $book->accounts()->whereKey($classification['ledger_account_id'])->exists()
                    || ! in_array($classification['closing_role'], AccountingClosingAccountClassification::ROLES, true)) {
                    throw ValidationException::withMessages(['classifications' => __('Une classification de compte est invalide ou hors périmètre.')]);
                }
                $configuration->classifications()->create([
                    'ledger_account_id' => $classification['ledger_account_id'],
                    'closing_role' => $classification['closing_role'],
                    'carry_forward_eligible' => (bool) ($classification['carry_forward_eligible'] ?? false),
                    'requires_third_party_dimensions' => (bool) ($classification['requires_third_party_dimensions'] ?? false),
                    'requires_analytical_dimensions' => (bool) ($classification['requires_analytical_dimensions'] ?? false),
                    'review_status' => 'pending_professional_review',
                ]);
            }
            $this->event($configuration, 'closing_configuration_created', $actor);

            return $configuration->fresh('classifications');
        }, 5);
    }

    public function professionalReview(AccountingClosingConfiguration $configuration, User $actor): AccountingClosingConfiguration
    {
        return DB::transaction(function () use ($configuration, $actor) {
            $configuration = AccountingClosingConfiguration::query()
                ->with('classifications')->lockForUpdate()->findOrFail($configuration->id);
            if ($configuration->status === 'approved') {
                return $configuration;
            }
            if ($configuration->status !== 'draft' || (int) $configuration->prepared_by === (int) $actor->id) {
                throw ValidationException::withMessages(['actor' => __('La revue professionnelle doit être réalisée par une autre personne.')]);
            }
            $book = AccountingBook::findOrFail($configuration->accounting_book_id);
            $postingAccounts = $book->accounts()->where('active', true)->where('posting_allowed', true)->pluck('id');
            if ($configuration->classifications->count() !== $postingAccounts->count()
                || $postingAccounts->diff($configuration->classifications->pluck('ledger_account_id'))->isNotEmpty()) {
                throw ValidationException::withMessages(['classifications' => __('Chaque compte mouvementable doit recevoir une classification explicite.')]);
            }
            $result = $configuration->classifications
                ->where('ledger_account_id', $configuration->result_transfer_account_id)
                ->where('closing_role', 'result_transfer');
            if (! $configuration->closing_journal_id || ! $configuration->opening_journal_id
                || ! $configuration->result_transfer_account_id || $result->count() !== 1) {
                throw ValidationException::withMessages(['configuration' => __('Les journaux dédiés et le compte de transfert du résultat sont obligatoires.')]);
            }
            $configuration->classifications()->update([
                'review_status' => 'approved',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);
            $configuration->update([
                'status' => 'approved',
                'professional_review_status' => 'approved',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);
            $this->event($configuration, 'closing_configuration_professionally_reviewed', $actor, [
                'classification_count' => $postingAccounts->count(),
                'counsel_review_status' => $configuration->counsel_review_status,
            ]);

            return $configuration->fresh('classifications');
        }, 5);
    }

    private function event(
        AccountingClosingConfiguration $configuration,
        string $action,
        User $actor,
        array $evidence = [],
    ): void {
        DB::table('accounting_activity_events')->insert([
            'organization_id' => $configuration->organization_id,
            'residence_id' => $configuration->residence_id,
            'record_type' => AccountingClosingConfiguration::class,
            'record_id' => $configuration->id,
            'action' => $action,
            'actor_id' => $actor->id,
            'after_evidence' => json_encode($evidence),
            'context' => app()->runningInConsole() ? 'command' : 'http',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
