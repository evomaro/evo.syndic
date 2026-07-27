<?php

namespace Database\Seeders;

use App\Models\Budget;
use App\Models\ChargeCategory;
use App\Models\ExpenseCategory;
use App\Models\ExpenseCommitment;
use App\Models\FinancialAccount;
use App\Models\FinancialExercise;
use App\Models\FundCall;
use App\Models\FundCallSchedule;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\ResidenceAnnouncement;
use App\Models\ResidenceDocument;
use App\Models\Supplier;
use App\Models\SupplierContract;
use App\Models\SupplierCreditNote;
use App\Models\SupplierInvoice;
use App\Models\SupplierSettlement;
use App\Models\User;
use App\Notifications\PortalNotification;
use App\Services\BudgetService;
use App\Services\CommitmentWorkflow;
use App\Services\CreditNoteWorkflow;
use App\Services\FundCallWorkflow;
use App\Services\PaymentWorkflow;
use App\Services\SupplierInvoiceWorkflow;
use App\Services\SupplierSettlementWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoSeeder is disabled in production.');
        }
        $org = Organization::create(['name' => 'Atlas Syndic Conseil', 'code' => 'ATLAS-DEMO', 'type' => 'professional_syndic', 'legal_name' => 'Atlas Syndic Conseil SARL', 'ice' => '001234567000089', 'address' => '12 avenue Hassan II', 'city' => 'Casablanca', 'phone' => '+212 522 00 00 00', 'email' => 'contact@atlas-demo.ma']);
        $users = [['Propriétaire Demo', 'owner@evosyndic.test', 'owner'], ['Gestionnaire Demo', 'manager@evosyndic.test', 'manager'], ['Auditeur Demo', 'auditor@evosyndic.test', 'auditor']];
        foreach ($users as [$name,$email,$role]) {
            $u = User::create(['name' => $name, 'email' => $email, 'email_verified_at' => now(), 'password' => Hash::make('password'), 'preferred_language' => 'fr']);
            $org->users()->attach($u, ['role' => $role, 'all_residences' => true]);
        }
        foreach ([['Résidence Al Qods', 'AL-QODS', 'Rabat'], ['Les Jardins de l’Océan', 'OCEAN', 'Casablanca']] as [$name,$code,$city]) {
            $res = $org->residences()->create(['name' => $name, 'code' => $code, 'address_line_1' => '10 rue de la Résidence', 'city' => $city, 'status' => 'setup']);
            foreach (['A', 'B'] as $bi) {
                $b = $res->buildings()->create(['name' => 'Immeuble '.$bi, 'code' => $bi]);
                $e = $b->entrances()->create(['name' => 'Entrée '.$bi, 'code' => $bi]);
                foreach ([0, 1, 2] as $level) {
                    $f = $b->floors()->create(['name' => $level === 0 ? 'Rez-de-chaussée' : 'Étage '.$level, 'level_number' => $level, 'entrance_id' => $e->id]);
                    foreach ([1, 2] as $door) {
                        $n = $bi.$level.$door;
                        $res->lots()->create(['building_id' => $b->id, 'entrance_id' => $e->id, 'floor_id' => $f->id, 'reference' => 'APT-'.$n, 'lot_number' => $n, 'type' => 'apartment', 'surface' => 78 + $door, 'occupancy_status' => $door === 2 ? 'vacant' : 'owner_occupied']);
                    }
                }
            }
            foreach ([['COM-01', 'shop'], ['P-01', 'parking'], ['CAVE-01', 'storage']] as [$ref,$type]) {
                $res->lots()->create(['reference' => $ref, 'lot_number' => $ref, 'type' => $type]);
            }
        }
        $contacts = collect([['Amina', 'El Mansouri'], ['Youssef', 'Bennani'], ['Sara', 'Alaoui'], ['Omar', 'Idrissi']])->map(fn ($n, $i) => $org->contacts()->create(['type' => 'individual', 'first_name' => $n[0], 'last_name' => $n[1], 'primary_phone' => '+212 6'.fake()->numerify(' ## ## ## ##'), 'preferred_language' => $i === 1 ? 'ar' : 'fr']));
        $company = $org->contacts()->create(['type' => 'company', 'company_name' => 'Café Andalou SARL', 'ice' => '002345678000012', 'primary_phone' => '+212 522 11 22 33', 'preferred_language' => 'fr']);
        foreach ($org->residences as $res) {
            $lots = $res->lots;
            $general = $res->allocationKeys()->firstOrCreate(['code' => 'general'], ['name' => 'Tantièmes généraux', 'type' => 'general', 'is_default' => true, 'default_slot' => 1, 'applies_to_all_lots' => true]);
            $lots[0]->ownerships()->create(['contact_id' => $contacts[0]->id, 'ownership_percentage' => 60, 'is_primary_contact' => true, 'starts_on' => '2024-01-01']);
            $lots[0]->ownerships()->create(['contact_id' => $contacts[1]->id, 'ownership_percentage' => 40, 'starts_on' => '2024-01-01']);
            $lots[1]->ownerships()->create(['contact_id' => $contacts[2]->id, 'ownership_percentage' => 100, 'is_primary_contact' => true, 'starts_on' => '2024-01-01']);
            $lots[1]->occupancies()->create(['contact_id' => $contacts[3]->id, 'type' => 'tenant', 'is_primary_occupant' => true, 'starts_on' => '2025-01-01']);
            $shop = $lots->firstWhere('type', 'shop');
            $shop->ownerships()->create(['contact_id' => $company->id, 'ownership_percentage' => 100, 'is_primary_contact' => true, 'starts_on' => '2024-01-01']);
            $special = $res->allocationKeys()->create(['name' => 'Ascenseur', 'code' => 'elevator', 'type' => 'special', 'expected_total' => 1000]);
            $special->lots()->sync($lots->whereNotNull('building_id')->pluck('id'));
            foreach ($lots as $lot) {
                $general->values()->create(['lot_id' => $lot->id, 'value' => round(1000 / $lots->count(), 4)]);
                if ($lot->building_id) {
                    $special->values()->create(['lot_id' => $lot->id, 'value' => round(1000 / $lots->whereNotNull('building_id')->count(), 4)]);
                }
            }
            $lots[2]->ownerships()->create(['contact_id' => $contacts[3]->id, 'ownership_percentage' => 100, 'is_primary_contact' => true, 'starts_on' => '2024-01-01', 'ends_on' => '2026-06-30']);
            $lots[2]->ownerships()->create(['contact_id' => $contacts[2]->id, 'ownership_percentage' => 100, 'is_primary_contact' => true, 'starts_on' => '2026-07-01']);
        }
        $owner = User::where('email', 'owner@evosyndic.test')->first();
        $owner->update(['current_organization_id' => $org->id, 'current_residence_id' => $org->residences()->first()->id]);

        $residence = $org->residences()->first();
        $resident = User::create(['name' => 'Résidente Demo', 'email' => 'resident@evosyndic.test', 'email_verified_at' => now(), 'password' => Hash::make('password'), 'preferred_language' => 'ar']);
        $org->users()->attach($resident, ['role' => 'coproprietaire', 'all_residences' => false]);
        $residence->users()->attach($resident);
        $contacts[0]->users()->attach($resident, ['organization_id' => $org->id, 'linked_by' => $owner->id, 'linked_at' => now()]);
        $resident->update(['current_organization_id' => $org->id, 'current_residence_id' => $residence->id]);
        $exercise = FinancialExercise::create(['organization_id' => $org->id, 'residence_id' => $residence->id, 'name' => 'Exercice 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open', 'opened_at' => now(), 'opened_by' => $owner->id]);
        $bank = FinancialAccount::create(['organization_id' => $org->id, 'residence_id' => $residence->id, 'name' => 'Compte bancaire principal', 'code' => 'BANK-MAIN', 'type' => 'bank', 'bank_name' => 'Banque Démo', 'rib' => '000 000 0000000000000000 00', 'opening_balance_cents' => 250000, 'opening_balance_on' => '2026-01-01', 'active' => true, 'default_slot' => 1]);
        $cash = FinancialAccount::create(['organization_id' => $org->id, 'residence_id' => $residence->id, 'name' => 'Caisse', 'code' => 'CASH', 'type' => 'cash', 'opening_balance_cents' => 20000, 'opening_balance_on' => '2026-01-01', 'active' => true]);
        $category = ChargeCategory::create(['organization_id' => $org->id, 'residence_id' => $residence->id, 'name' => 'Charges ordinaires', 'code' => 'ordinary', 'type' => 'ordinary', 'default_distribution_method' => 'allocation_key', 'default_allocation_key_id' => $residence->allocationKeys()->where('is_default', true)->value('id')]);
        $works = ChargeCategory::create(['organization_id' => $org->id, 'residence_id' => $residence->id, 'name' => 'Travaux exceptionnels', 'code' => 'works', 'type' => 'exceptional', 'default_distribution_method' => 'equal']);
        $ordinary = FundCall::create(['organization_id' => $org->id, 'residence_id' => $residence->id, 'financial_exercise_id' => $exercise->id, 'title' => 'Charges ordinaires - janvier', 'issue_date' => '2026-01-05', 'due_date' => '2026-01-20']);
        $ordinary->lines()->create(['charge_category_id' => $category->id, 'label' => 'Entretien et services communs', 'distribution_method' => 'allocation_key', 'allocation_key_id' => $residence->allocationKeys()->where('is_default', true)->value('id'), 'target_type' => 'all', 'amount_cents' => 150000]);
        app(FundCallWorkflow::class)->validate($ordinary, $owner);
        $exceptional = FundCall::create(['organization_id' => $org->id, 'residence_id' => $residence->id, 'financial_exercise_id' => $exercise->id, 'title' => 'Réfection étanchéité terrasse', 'issue_date' => '2026-06-01', 'due_date' => '2026-09-01']);
        $exceptional->lines()->create(['charge_category_id' => $works->id, 'label' => 'Travaux d’étanchéité', 'distribution_method' => 'equal', 'target_type' => 'all', 'amount_cents' => 300000]);
        app(FundCallWorkflow::class)->validate($exceptional, $owner);
        foreach ([[15000, $contacts[0]->id, $bank->id, 'demo-full'], [7000, $contacts[2]->id, $cash->id, 'demo-partial']] as [$amount, $payer, $account, $key]) {
            $payment = Payment::create(['organization_id' => $org->id, 'residence_id' => $residence->id, 'financial_exercise_id' => $exercise->id, 'payer_contact_id' => $payer, 'payment_date' => '2026-02-10', 'amount_cents' => $amount, 'method' => $account === $cash->id ? 'cash' : 'bank_transfer', 'financial_account_id' => $account, 'idempotency_key' => $key]);
            app(PaymentWorkflow::class)->validate($payment, $owner);
        }
        $credit = Payment::create(['organization_id' => $org->id, 'residence_id' => $residence->id, 'financial_exercise_id' => $exercise->id, 'payer_contact_id' => $contacts[1]->id, 'payment_date' => '2026-03-15', 'amount_cents' => 10000, 'method' => 'bank_transfer', 'financial_account_id' => $bank->id, 'idempotency_key' => 'demo-credit']);
        app(PaymentWorkflow::class)->validate($credit, $owner, 'selected_lots', []);
        $reversed = Payment::create(['organization_id' => $org->id, 'residence_id' => $residence->id, 'financial_exercise_id' => $exercise->id, 'received_from' => 'Paiement à extourner', 'payment_date' => '2026-04-01', 'amount_cents' => 5000, 'method' => 'cheque', 'financial_account_id' => $bank->id, 'cheque_number' => 'CHK-DEMO-01', 'idempotency_key' => 'demo-reversed']);
        app(PaymentWorkflow::class)->validate($reversed, $owner, 'selected_lots', []);
        app(PaymentWorkflow::class)->reverse($reversed, $owner, 'Chèque rejeté - démonstration');
        FundCallSchedule::create(['organization_id' => $org->id, 'residence_id' => $residence->id, 'name' => 'Charges ordinaires mensuelles', 'template' => ['title' => 'Charges ordinaires mensuelles', 'lines' => [['charge_category_id' => $category->id, 'label' => 'Charges mensuelles', 'distribution_method' => 'allocation_key', 'allocation_key_id' => $residence->allocationKeys()->where('is_default', true)->value('id'), 'target_type' => 'all', 'amount' => '1500.00']]], 'frequency' => 'monthly', 'starts_on' => '2026-08-01', 'generation_day' => 1, 'due_offset_days' => 15, 'next_generation_on' => '2026-08-01', 'created_by' => $owner->id]);

        $expenseCategories = collect(['Nettoyage', 'Gardiennage', 'Eau', 'Électricité', 'Ascenseur', 'Assurance', 'Jardinage', 'Entretien et réparations', 'Honoraires', 'Frais bancaires', 'Fournitures', 'Travaux exceptionnels', 'Taxes et redevances', 'Autres charges'])->map(fn ($name, $index) => ExpenseCategory::create(['organization_id' => $org->id, 'residence_id' => $residence->id, 'name' => $name, 'code' => 'EXP-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT), 'type' => $name === 'Travaux exceptionnels' ? 'exceptional' : 'ordinary', 'default_visibility' => $index < 4 ? 'category_summary' : 'private', 'sort_order' => $index]));
        $suppliers = collect([
            ['Propreté Atlas SARL', '001111111000012', 'Nettoyage'],
            ['Sécurité Al Amane', '002222222000023', 'Gardiennage'],
            ['Ascenseurs Maghreb', '003333333000034', 'Ascenseur'],
        ])->map(fn ($row) => Supplier::create(['organization_id' => $org->id, 'legal_name' => $row[0], 'ice' => $row[1], 'contact_name' => 'Service copropriété', 'email' => str($row[0])->slug().'@example.test', 'phone' => '+212 522 00 11 22', 'bank_name' => 'Banque Démo', 'rib' => 'RIB-DEMO-PRIVE', 'preferred_language' => 'fr']));
        $suppliers[2]->update(['preferred_language' => 'ar']);
        SupplierContract::create(['organization_id' => $org->id, 'residence_id' => $residence->id, 'supplier_id' => $suppliers[0]->id, 'reference' => 'CTR-CLEAN-2026', 'title' => 'Nettoyage des parties communes', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'amount_cents' => 1080000, 'billing_frequency' => 'monthly', 'renewal_type' => 'manual', 'notice_days' => 30]);
        SupplierContract::create(['organization_id' => $org->id, 'residence_id' => $residence->id, 'supplier_id' => $suppliers[2]->id, 'reference' => 'CTR-LIFT-2025', 'title' => 'Maintenance ascenseurs', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-10', 'amount_cents' => 240000, 'billing_frequency' => 'quarterly', 'renewal_type' => 'automatic', 'notice_days' => 45]);
        $approvedCommitment = ExpenseCommitment::create(['organization_id' => $org->id, 'residence_id' => $residence->id, 'financial_exercise_id' => $exercise->id, 'supplier_id' => $suppliers[0]->id, 'expense_category_id' => $expenseCategories[0]->id, 'title' => 'Nettoyage premier semestre', 'committed_on' => '2026-01-02', 'amount_cents' => 540000]);
        app(CommitmentWorkflow::class)->transition($approvedCommitment, $owner, 'submit');
        app(CommitmentWorkflow::class)->transition($approvedCommitment->fresh(), $owner, 'approve');
        ExpenseCommitment::create(['organization_id' => $org->id, 'residence_id' => $residence->id, 'financial_exercise_id' => $exercise->id, 'expense_category_id' => $expenseCategories[11]->id, 'title' => 'Étude réfection façade', 'committed_on' => '2026-07-01', 'amount_cents' => 350000]);

        $makeInvoice = function (Supplier $supplier, ExpenseCategory $expenseCategory, string $reference, string $date, string $due, int $amount, string $visibility = 'private', ?ExpenseCommitment $commitment = null) use ($org, $residence, $exercise, $owner) {
            $invoice = SupplierInvoice::create(['organization_id' => $org->id, 'primary_residence_id' => $residence->id, 'supplier_id' => $supplier->id, 'expense_commitment_id' => $commitment?->id, 'supplier_invoice_number' => $reference, 'invoice_date' => $date, 'due_date' => $due, 'subtotal_cents' => $amount, 'tax_cents' => 0, 'total_cents' => $amount]);
            $invoice->lines()->create(['residence_id' => $residence->id, 'financial_exercise_id' => $exercise->id, 'expense_category_id' => $expenseCategory->id, 'description' => $expenseCategory->name, 'quantity' => 1, 'unit_price_cents' => $amount, 'tax_rate' => 0, 'subtotal_cents' => $amount, 'tax_cents' => 0, 'total_cents' => $amount, 'visibility' => $visibility]);
            $path = "supplier-invoices/demo/{$invoice->id}.pdf";
            $bytes = '%PDF-1.4 Facture fournisseur démo '.$reference;
            Storage::disk('local')->put($path, $bytes);
            $invoice->attachments()->create(['kind' => 'original', 'version' => 1, 'name' => $reference.'.pdf', 'disk' => 'local', 'path' => $path, 'mime_type' => 'application/pdf', 'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes), 'immutable' => false, 'uploaded_by' => $owner->id]);

            return app(SupplierInvoiceWorkflow::class)->validate($invoice, $owner);
        };
        $partialInvoice = $makeInvoice($suppliers[0], $expenseCategories[0], 'NET-2026-01', '2026-01-31', '2026-02-15', 90000, 'category_summary', $approvedCommitment);
        $paidInvoice = $makeInvoice($suppliers[1], $expenseCategories[1], 'SEC-2026-Q1', '2026-03-31', '2026-04-15', 75000, 'invoice_summary');
        $overdueInvoice = $makeInvoice($suppliers[2], $expenseCategories[4], 'ASC-2026-01', '2026-02-01', '2026-02-28', 120000, 'private');
        foreach ([[$partialInvoice, 30000, 'demo-supplier-partial'], [$paidInvoice, 75000, 'demo-supplier-paid']] as [$invoice, $amount, $key]) {
            $settlement = SupplierSettlement::create(['organization_id' => $org->id, 'residence_id' => $residence->id, 'financial_exercise_id' => $exercise->id, 'supplier_id' => $invoice->supplier_id, 'financial_account_id' => $bank->id, 'settlement_date' => '2026-05-05', 'amount_cents' => $amount, 'method' => 'bank_transfer', 'idempotency_key' => $key]);
            app(SupplierSettlementWorkflow::class)->validate($settlement, $owner);
        }
        $creditNote = SupplierCreditNote::create(['organization_id' => $org->id, 'residence_id' => $residence->id, 'supplier_id' => $suppliers[2]->id, 'original_supplier_invoice_id' => $overdueInvoice->id, 'supplier_credit_number' => 'AV-ASC-01', 'credit_date' => '2026-05-10', 'amount_cents' => 10000, 'reason' => 'Intervention non réalisée']);
        app(CreditNoteWorkflow::class)->validate($creditNote, $owner, [['supplier_invoice_id' => $overdueInvoice->id, 'amount_cents' => 10000]]);
        $reversedSettlement = SupplierSettlement::create(['organization_id' => $org->id, 'residence_id' => $residence->id, 'financial_exercise_id' => $exercise->id, 'supplier_id' => $suppliers[2]->id, 'financial_account_id' => $bank->id, 'settlement_date' => '2026-05-20', 'amount_cents' => 10000, 'method' => 'cheque', 'cheque_number' => 'FOUR-DEMO-01']);
        app(SupplierSettlementWorkflow::class)->validate($reversedSettlement, $owner);
        app(SupplierSettlementWorkflow::class)->reverse($reversedSettlement->fresh(), $owner, 'Chèque annulé — démonstration');

        $budget = Budget::create(['organization_id' => $org->id, 'residence_id' => $residence->id, 'financial_exercise_id' => $exercise->id, 'version' => 1, 'title' => 'Budget 2026']);
        foreach ($expenseCategories->take(5) as $expenseCategory) {
            $budget->lines()->create(['expense_category_id' => $expenseCategory->id, 'planned_cents' => $expenseCategory->id === $expenseCategories[0]->id ? 50000 : 150000]);
        }
        app(BudgetService::class)->approve($budget, $owner);
        app(BudgetService::class)->revise($budget->fresh(), $owner, 'Révision de démonstration documentée');

        foreach ([['Règlement de copropriété', 'regulation', 'all_residents'], ['Contrat nettoyage', 'contract', 'staff'], ['Note lot ciblé', 'notice', 'selected_lots']] as $index => [$title, $documentCategory, $audience]) {
            $document = ResidenceDocument::create(['organization_id' => $org->id, 'residence_id' => $residence->id, 'title' => $title, 'category' => $documentCategory, 'status' => 'published', 'audience' => $audience, 'published_at' => now(), 'published_by' => $owner->id, 'created_by' => $owner->id]);
            if ($audience === 'selected_lots') {
                $document->lots()->sync([$residence->lots()->first()->id]);
            }
            $path = "residence-documents/demo/{$index}.pdf";
            Storage::disk('local')->put($path, '%PDF-1.4 EvoSyndic demo '.$title);
            $bytes = Storage::disk('local')->get($path);
            $version = $document->versions()->create(['version' => 1, 'name' => str($title)->slug().'.pdf', 'disk' => 'local', 'path' => $path, 'mime_type' => 'application/pdf', 'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes), 'uploaded_by' => $owner->id]);
            $document->update(['published_version_id' => $version->id]);
        }
        ResidenceAnnouncement::create(['organization_id' => $org->id, 'residence_id' => $residence->id, 'title' => 'Bienvenue sur le portail', 'body' => 'Les documents et dépenses publiés sont désormais disponibles.', 'title_fr' => 'Bienvenue sur le portail', 'body_fr' => 'Les documents et dépenses publiés sont désormais disponibles.', 'title_ar' => 'مرحبا بكم في البوابة', 'body_ar' => 'الوثائق والمصاريف المنشورة متاحة الآن.', 'priority' => 'important', 'status' => 'published', 'audience' => 'all_residents', 'audience_snapshot' => ['demo' => true], 'published_at' => now(), 'published_by' => $owner->id, 'created_by' => $owner->id]);
        $resident->notify(new PortalNotification(['type' => 'announcement.published', 'organization_id' => $org->id, 'residence_id' => $residence->id, 'title' => 'إعلان جديد', 'message' => 'مرحبا بكم في بوابة السكان.', 'url' => route('portal.index')], ['database']));
    }
}
