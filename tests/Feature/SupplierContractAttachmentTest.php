<?php

namespace Tests\Feature;

use App\Models\SupplierContract;
use App\Models\SupplierContractAttachment;
use App\Services\SupplierContractAttachmentService;
use App\Services\SupplierContractWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\CreatesPhaseThreeContext;
use Tests\TestCase;

class SupplierContractAttachmentTest extends TestCase
{
    use CreatesPhaseThreeContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_upload_list_replace_archive_and_historical_download_are_versioned_and_private(): void
    {
        $context = $this->phaseThreeContext();
        $contract = $this->contract($context);
        $this->actingAs($context['user'])->post(route('supplier-contracts.attachments.store', $contract), ['file' => UploadedFile::fake()->createWithContent('Contrat privé.pdf', '%PDF v1'), 'reusable_on_renewal' => true])->assertRedirect();
        $first = SupplierContractAttachment::firstOrFail();
        $this->assertSame('contrat-prive.pdf', $first->name);
        $this->assertStringNotContainsString('contrat-prive', $first->path);
        $this->get(route('supplier-contracts.show', $contract))->assertOk()->assertDontSee($first->path);

        $this->post(route('supplier-contracts.attachments.store', $contract), ['file' => UploadedFile::fake()->createWithContent('replacement.pdf', '%PDF v2'), 'replaces_id' => $first->id])->assertRedirect();
        $second = SupplierContractAttachment::latest('id')->firstOrFail();
        $this->assertSame(2, $second->version);
        $this->assertSame($first->id, $second->replaces_id);
        $this->assertSame('archived', $first->fresh()->status);

        $this->get(route('supplier-contracts.attachments.download', $first))->assertOk()->assertHeader('cache-control', 'max-age=0, no-store, private')->assertHeader('x-content-type-options', 'nosniff');
        $this->delete(route('supplier-contracts.attachments.destroy', $second))->assertRedirect();
        $this->assertSame('archived', $second->fresh()->status);
        $this->get(route('supplier-contracts.attachments.download', $second))->assertOk();
        $this->assertDatabaseHas('activity_log', ['description' => 'supplier_contract_attachment.downloaded']);
    }

    public static function invalidFiles(): array
    {
        return [
            'invalid extension' => ['malware.exe', 10, 'application/octet-stream'],
            'invalid mime' => ['fake.pdf', 10, 'text/plain'],
            'oversized' => ['large.pdf', 20481, 'application/pdf'],
        ];
    }

    #[DataProvider('invalidFiles')]
    public function test_upload_validation_rejects_invalid_files(string $name, int $kilobytes, string $mime): void
    {
        $context = $this->phaseThreeContext();
        $contract = $this->contract($context);

        $this->actingAs($context['user'])->post(route('supplier-contracts.attachments.store', $contract), ['file' => UploadedFile::fake()->create($name, $kilobytes, $mime)])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('supplier_contract_attachments', 0);
    }

    public function test_checksum_manipulated_id_cross_tenant_and_resident_access_are_denied(): void
    {
        $context = $this->phaseThreeContext();
        $contract = $this->contract($context);
        $attachment = app(SupplierContractAttachmentService::class)->upload($contract, UploadedFile::fake()->createWithContent('contract.pdf', '%PDF valid'), $context['user'], false);
        Storage::disk('local')->put($attachment->path, 'tampered');
        $this->actingAs($context['user'])->get(route('supplier-contracts.attachments.download', $attachment))->assertStatus(409);
        $this->get(route('supplier-contracts.attachments.download', 999999))->assertNotFound();

        $foreign = $this->phaseThreeContext();
        $this->actingAs($foreign['user'])->get(route('supplier-contracts.attachments.download', $attachment))->assertNotFound();
        $resident = $this->phaseThreeContext('coproprietaire');
        $context['organization']->users()->attach($resident['user'], ['role' => 'coproprietaire', 'all_residences' => true]);
        $resident['user']->update(['current_organization_id' => $context['organization']->id, 'current_residence_id' => $context['residence']->id]);
        $this->actingAs($resident['user'])->get(route('supplier-contracts.attachments.download', $attachment))->assertForbidden();
    }

    public function test_terminated_and_renewed_contract_history_remains_downloadable(): void
    {
        $context = $this->phaseThreeContext();
        $terminated = $this->contract($context, ['reference' => 'TERM']);
        $terminatedAttachment = app(SupplierContractAttachmentService::class)->upload($terminated, UploadedFile::fake()->createWithContent('terminated.pdf', '%PDF terminated'), $context['user'], false);
        app(SupplierContractWorkflow::class)->terminate($terminated, $context['user'], 'Services terminated');
        $this->actingAs($context['user'])->get(route('supplier-contracts.attachments.download', $terminatedAttachment))->assertOk();

        $renewed = $this->contract($context, ['reference' => 'RENEW', 'ends_on' => '2026-01-31']);
        $renewedAttachment = app(SupplierContractAttachmentService::class)->upload($renewed, UploadedFile::fake()->createWithContent('historical.pdf', '%PDF historical'), $context['user'], false);
        app(SupplierContractWorkflow::class)->renew($renewed, $context['user'], '2026-02-01', '2026-02-28', 'Monthly renewal');
        $this->actingAs($context['user'])->get(route('supplier-contracts.attachments.download', $renewedAttachment))->assertOk();
    }

    private function contract(array $context, array $overrides = []): SupplierContract
    {
        return SupplierContract::create(array_merge(['organization_id' => $context['organization']->id, 'residence_id' => $context['residence']->id, 'supplier_id' => $context['supplier']->id, 'reference' => 'CTR-'.str()->random(8), 'title' => 'Contract', 'starts_on' => '2026-01-01', 'status' => 'active'], $overrides));
    }
}
