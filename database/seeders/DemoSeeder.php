<?php

namespace Database\Seeders;

use App\Models\ChargeCategory;
use App\Models\FinancialAccount;
use App\Models\FinancialExercise;
use App\Models\FundCall;
use App\Models\FundCallSchedule;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\Services\FundCallWorkflow;
use App\Services\PaymentWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
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
    }
}
