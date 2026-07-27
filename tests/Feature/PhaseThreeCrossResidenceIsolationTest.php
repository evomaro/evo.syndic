<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\ExpenseCommitment;
use App\Models\Supplier;
use App\Models\SupplierContract;
use App\Models\SupplierContractAttachment;
use App\Models\SupplierCreditNote;
use App\Models\SupplierSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\CreatesPhaseThreeContext;
use Tests\TestCase;

class PhaseThreeCrossResidenceIsolationTest extends TestCase
{
    use CreatesPhaseThreeContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public static function modelActions(): array
    {
        return [
            'supplier update' => ['PUT', 'suppliers.update', 'supplier'],
            'contract transition' => ['POST', 'supplier-contracts.transition', 'contract'],
            'contract attachment upload' => ['POST', 'supplier-contracts.attachments.store', 'contract'],
            'contract attachment download' => ['GET', 'supplier-contracts.attachments.download', 'attachment'],
            'contract attachment archive' => ['DELETE', 'supplier-contracts.attachments.destroy', 'attachment'],
            'commitment transition' => ['POST', 'expense-commitments.transition', 'commitment'],
            'invoice validate' => ['POST', 'supplier-invoices.validate', 'invoice'],
            'invoice cancel' => ['POST', 'supplier-invoices.cancel', 'invoice'],
            'invoice attachment' => ['POST', 'supplier-invoices.attachments.store', 'invoice'],
            'settlement validate' => ['POST', 'supplier-settlements.validate', 'settlement'],
            'settlement reverse' => ['POST', 'supplier-settlements.reverse', 'settlement'],
            'voucher retry' => ['POST', 'supplier-settlements.voucher.retry', 'settlement'],
            'credit validate' => ['POST', 'supplier-credit-notes.validate', 'credit'],
            'credit cancel' => ['POST', 'supplier-credit-notes.cancel', 'credit'],
            'budget approve' => ['POST', 'budgets.approve', 'budget'],
            'budget revise' => ['POST', 'budgets.revise', 'budget'],
            'budget lock' => ['POST', 'budgets.lock', 'budget'],
            'budget unlock' => ['POST', 'budgets.unlock', 'budget'],
            'budget archive' => ['POST', 'budgets.archive', 'budget'],
            'prepare fund call' => ['POST', 'budgets.prepare-fund-call', 'budget'],
            'supplier statement' => ['GET', 'suppliers.statement', 'supplier'],
        ];
    }

    #[DataProvider('modelActions')]
    public function test_every_bound_action_rejects_cross_organization_model(string $method, string $routeName, string $kind): void
    {
        $local = $this->phaseThreeContext();
        $foreign = $this->phaseThreeContext();
        $model = $this->foreignModel($foreign, $kind);

        $this->actingAs($local['user'])->call($method, route($routeName, $model), $this->payload($routeName))->assertNotFound();
    }

    public function test_supplier_and_invoice_selectors_are_scoped_permission_controlled_and_paginated(): void
    {
        $local = $this->phaseThreeContext();
        $foreign = $this->phaseThreeContext();
        foreach (range(1, 17) as $index) {
            Supplier::create(['organization_id' => $local['organization']->id, 'legal_name' => "Searchable {$index}"]);
        }
        Supplier::create(['organization_id' => $foreign['organization']->id, 'legal_name' => 'Searchable foreign']);
        $this->makePhaseThreeInvoice($local, 100, '2026-05-01');
        $this->makePhaseThreeInvoice($foreign, 100, '2026-05-01');

        $this->actingAs($local['user'])->getJson(route('suppliers.search', ['q' => 'Searchable']))
            ->assertOk()->assertJsonCount(15, 'data')->assertJsonStructure(['data', 'next_page_url', 'per_page']);
        $this->getJson(route('suppliers.search', ['q' => 'Searchable', 'page' => 2]))->assertOk()->assertJsonMissing(['legal_name' => 'Searchable foreign']);
        $this->getJson(route('supplier-invoices.search', ['q' => 'F-']))->assertOk()->assertJsonCount(1, 'data');

        $denied = $this->phaseThreeContext('coproprietaire');
        $this->actingAs($denied['user'])->getJson(route('suppliers.search', ['q' => 'Searchable']))->assertForbidden();
        $this->getJson(route('supplier-invoices.search', ['q' => 'F-']))->assertForbidden();
    }

    private function foreignModel(array $context, string $kind)
    {
        $contract = fn () => SupplierContract::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'supplier_id' => $context['supplier']->id, 'reference' => 'X-'.str()->random(6), 'title' => 'Foreign', 'starts_on' => '2026-01-01']);

        return match ($kind) {
            'supplier' => $context['supplier'],
            'contract' => $contract(),
            'attachment' => tap(SupplierContractAttachment::create(['supplier_contract_id' => $contract()->id, 'version' => 1, 'name' => 'x.pdf', 'disk' => 'local', 'path' => 'x.pdf', 'mime_type' => 'application/pdf', 'size' => 4, 'checksum' => hash('sha256', '%PDF'), 'uploaded_by' => $context['user']->id, 'status' => 'active']), fn () => Storage::disk('local')->put('x.pdf', '%PDF')),
            'commitment' => ExpenseCommitment::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'financial_exercise_id' => $context['exercise']->id, 'expense_category_id' => $context['category']->id, 'title' => 'Foreign', 'committed_on' => '2026-01-01', 'amount_cents' => 100]),
            'invoice' => $this->makePhaseThreeInvoice($context),
            'settlement' => SupplierSettlement::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'financial_exercise_id' => $context['exercise']->id, 'supplier_id' => $context['supplier']->id, 'financial_account_id' => $context['account']->id, 'settlement_date' => '2026-01-01', 'amount_cents' => 100, 'method' => 'cash']),
            'credit' => SupplierCreditNote::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'supplier_id' => $context['supplier']->id, 'credit_date' => '2026-01-01', 'amount_cents' => 100]),
            'budget' => Budget::create(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'financial_exercise_id' => $context['exercise']->id, 'version' => 1, 'title' => 'Foreign']),
        };
    }

    private function payload(string $routeName): array
    {
        return match ($routeName) {
            'supplier-contracts.transition' => ['action' => 'terminate', 'reason' => 'Foreign model'],
            'expense-commitments.transition' => ['action' => 'submit'],
            'supplier-invoices.cancel', 'supplier-credit-notes.cancel', 'supplier-settlements.reverse' => ['reason' => 'Foreign model'],
            'supplier-settlements.validate' => ['mode' => 'fifo'],
            'supplier-credit-notes.validate' => ['allocations' => []],
            'budgets.revise', 'budgets.unlock' => ['reason' => 'Foreign model reason'],
            default => [],
        };
    }
}
