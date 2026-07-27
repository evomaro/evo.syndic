<?php

namespace Tests\Feature\Concerns;

use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\FinancialExercise;
use App\Models\Organization;
use App\Models\Residence;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Services\SupplierInvoiceWorkflow;
use Illuminate\Support\Facades\Storage;

trait CreatesPhaseThreeContext
{
    protected function phaseThreeContext(string $role = 'owner', bool $allResidences = true): array
    {
        $user = User::factory()->create(['preferred_language' => 'fr']);
        $organization = Organization::factory()->create();
        $residence = Residence::factory()->for($organization)->create(['status' => 'operational']);
        $organization->users()->attach($user, ['role' => $role, 'all_residences' => $allResidences]);
        if (! $allResidences) {
            $residence->users()->attach($user);
        }
        $user->update(['current_organization_id' => $organization->id, 'current_residence_id' => $residence->id]);
        $exercise = FinancialExercise::create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        $account = FinancialAccount::create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'name' => 'Banque', 'code' => 'BANK', 'type' => 'bank', 'active' => true, 'opening_balance_cents' => 1000000]);
        $category = ExpenseCategory::create(['organization_id' => $organization->id, 'residence_id' => $residence->id, 'name' => 'Nettoyage', 'code' => 'cleaning']);
        $supplier = Supplier::create(['organization_id' => $organization->id, 'legal_name' => 'Atlas Services', 'preferred_language' => 'fr']);

        return compact('user', 'organization', 'residence', 'exercise', 'account', 'category', 'supplier');
    }

    protected function addResidence(array $context, bool $authorizeUser = true): Residence
    {
        $residence = Residence::factory()->for($context['organization'])->create(['status' => 'operational']);
        if ($authorizeUser) {
            $residence->users()->syncWithoutDetaching([$context['user']->id]);
        }

        return $residence;
    }

    protected function makePhaseThreeInvoice(array $context, int $total = 10000, string $dueDate = '2026-03-01', string $status = 'draft'): SupplierInvoice
    {
        $invoice = SupplierInvoice::create([
            'organization_id' => $context['organization']->id,
            'primary_residence_id' => $context['residence']->id,
            'supplier_id' => $context['supplier']->id,
            'supplier_invoice_number' => 'F-'.str()->uuid(),
            'invoice_date' => '2026-02-01',
            'due_date' => $dueDate,
            'subtotal_cents' => $total,
            'tax_cents' => 0,
            'total_cents' => $total,
        ]);
        $invoice->lines()->create([
            'residence_id' => $context['residence']->id,
            'financial_exercise_id' => $context['exercise']->id,
            'expense_category_id' => $context['category']->id,
            'description' => 'Service',
            'quantity' => 1,
            'unit_price_cents' => $total,
            'tax_rate' => 0,
            'subtotal_cents' => $total,
            'tax_cents' => 0,
            'total_cents' => $total,
        ]);
        $bytes = '%PDF-1.4 invoice';
        $path = "test-invoices/{$invoice->id}.pdf";
        Storage::disk('local')->put($path, $bytes);
        $invoice->attachments()->create(['kind' => 'original', 'version' => 1, 'name' => 'invoice.pdf', 'disk' => 'local', 'path' => $path, 'mime_type' => 'application/pdf', 'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes), 'uploaded_by' => $context['user']->id]);

        if ($status !== 'draft') {
            $invoice = app(SupplierInvoiceWorkflow::class)->validate($invoice, $context['user']);
        }

        return $invoice;
    }
}
