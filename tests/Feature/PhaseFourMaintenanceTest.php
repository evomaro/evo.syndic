<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\FinancialExercise;
use App\Models\MaintenanceCategory;
use App\Models\MaintenanceEquipment;
use App\Models\MaintenanceQuotation;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceWorkOrder;
use App\Models\Organization;
use App\Models\PreventiveMaintenancePlan;
use App\Models\Residence;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\PortalNotification;
use App\Services\MaintenanceQuotationWorkflow;
use App\Services\MaintenanceRequestWorkflow;
use App\Services\MaintenanceSlaService;
use App\Services\MaintenanceWorkOrderWorkflow;
use App\Services\PreventiveMaintenanceScheduler;
use App\Services\WorkOrderInvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PhaseFourMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function context(string $role = 'owner', bool $allResidences = true): array
    {
        $organization = Organization::factory()->create();
        $residence = Residence::factory()->for($organization)->create(['status' => 'operational']);
        $user = User::factory()->create(['preferred_language' => 'fr']);
        $organization->users()->attach($user, ['role' => $role, 'all_residences' => $allResidences]);
        if (! $allResidences) {
            $residence->users()->attach($user);
        }
        $user->update(['current_organization_id' => $organization->id, 'current_residence_id' => $residence->id]);
        $category = MaintenanceCategory::create(['organization_id' => $organization->id, 'name_fr' => 'Ascenseur', 'name_ar' => 'المصعد', 'default_priority' => 'high', 'ack_target_minutes' => 60, 'schedule_target_minutes' => 120, 'resolution_target_minutes' => 1440]);
        $supplier = Supplier::create(['organization_id' => $organization->id, 'legal_name' => 'Atlas Lift', 'active' => true]);

        return compact('organization', 'residence', 'user', 'category', 'supplier');
    }

    private function request(array $c, string $status = 'draft', ?User $reporter = null): MaintenanceRequest
    {
        return MaintenanceRequest::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'maintenance_category_id' => $c['category']->id, 'reporter_user_id' => ($reporter ?? $c['user'])->id, 'reporter_role' => 'owner', 'reference' => uniqid('DM-'), 'title' => 'Panne ascenseur', 'description' => 'Arrêt au troisième étage', 'priority' => 'high', 'status' => $status, 'sla_snapshot' => ['ack_target_minutes' => 60, 'schedule_target_minutes' => 120, 'resolution_target_minutes' => 1440]]);
    }

    public function test_request_workflow_persists_sla_snapshot_and_idempotent_history(): void
    {
        $c = $this->context();
        $request = $this->request($c);
        $workflow = app(MaintenanceRequestWorkflow::class);
        $workflow->transition($request, 'submitted', $c['user'], null, 'submit-1');
        $this->assertEquals(60, $request->fresh()->submitted_at->diffInMinutes($request->fresh()->ack_deadline_at));
        $c['category']->update(['ack_target_minutes' => 999]);
        $this->assertEquals(60, $request->fresh()->sla_snapshot['ack_target_minutes']);
        $workflow->transition($request, 'submitted', $c['user'], null, 'submit-1');
        $this->assertDatabaseCount('maintenance_request_transitions', 1);
        $workflow->transition($request, 'under_review', $c['user'], null, 'review-1');
        $workflow->transition($request, 'approved', $c['user'], null, 'approve-1');
        $workflow->transition($request, 'in_progress', $c['user'], null, 'start-1');
        $this->expectException(ValidationException::class);
        $workflow->transition($request, 'resolved', $c['user'], null, 'resolve-invalid');
    }

    public function test_only_one_quotation_can_be_accepted_and_amounts_are_minor_units(): void
    {
        $c = $this->context();
        $request = $this->request($c, 'approved');
        $one = MaintenanceQuotation::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'maintenance_request_id' => $request->id, 'supplier_id' => $c['supplier']->id, 'subtotal_cents' => 100000, 'tax_cents' => 20000, 'total_cents' => 120000, 'submitted_on' => today()]);
        $two = MaintenanceQuotation::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'maintenance_request_id' => $request->id, 'supplier_id' => $c['supplier']->id, 'subtotal_cents' => 90000, 'tax_cents' => 18000, 'total_cents' => 108000, 'submitted_on' => today()]);
        app(MaintenanceQuotationWorkflow::class)->accept($one, $c['user']);
        try {
            app(MaintenanceQuotationWorkflow::class)->accept($two, $c['user']);
            $this->fail('Competing quotation was accepted.');
        } catch (ValidationException) {
        }
        $this->assertSame(1, $request->quotations()->where('status', 'accepted')->count());
        $this->assertSame(120000, $one->fresh()->total_cents);
    }

    public function test_work_completion_validation_and_invoice_creation_are_separate(): void
    {
        $c = $this->context();
        $request = $this->request($c, 'approved');
        $workflow = app(MaintenanceWorkOrderWorkflow::class);
        $order = $workflow->create(['maintenance_request_id' => $request->id, 'supplier_id' => $c['supplier']->id, 'scope_of_work' => 'Réparer le moteur', 'actual_cost_cents' => 50000], $c['user']);
        $workflow->transition($order, 'scheduled', $c['user']);
        $this->assertNotNull($request->fresh()->scheduled_at);
        $workflow->transition($order, 'in_progress', $c['user']);
        $workflow->transition($order, 'completed', $c['user'], 'Travaux terminés');
        $this->assertNull($order->fresh()->validated_at);
        $this->assertSame('completed', $order->fresh()->status);
        $workflow->transition($order, 'validated', $c['user'], 'Contrôle gestionnaire conforme');
        $exercise = FinancialExercise::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        $expense = ExpenseCategory::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'name' => 'Maintenance', 'code' => 'maintenance']);
        $invoice = app(WorkOrderInvoiceService::class)->createDraft($order, ['financial_exercise_id' => $exercise->id, 'expense_category_id' => $expense->id, 'invoice_date' => '2026-07-22', 'due_date' => '2026-08-22', 'total_cents' => 50000], $c['user']);
        $this->assertSame('draft', $invoice->status);
        $this->assertSame($order->id, $invoice->maintenance_work_order_id);
        $this->expectException(ValidationException::class);
        app(WorkOrderInvoiceService::class)->createDraft($order, ['financial_exercise_id' => $exercise->id, 'expense_category_id' => $expense->id, 'invoice_date' => '2026-07-22', 'due_date' => '2026-08-22', 'total_cents' => 50000], $c['user']);
    }

    public function test_preventive_generation_and_sla_evaluation_are_idempotent(): void
    {
        $c = $this->context();
        $plan = PreventiveMaintenancePlan::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'name' => 'Contrôle mensuel', 'frequency_type' => 'monthly', 'frequency_interval' => 1, 'starts_on' => '2026-07-01', 'next_intervention_on' => '2026-07-22', 'reminder_days' => 3, 'checklist' => ['Freins', 'Alarme'], 'active' => true]);
        $scheduler = app(PreventiveMaintenanceScheduler::class);
        $scheduler->generate(CarbonImmutable::parse('2026-07-22 23:59:59'), true);
        $scheduler->generate(CarbonImmutable::parse('2026-07-22 23:59:59'), true);
        $this->assertSame(1, $plan->interventions()->count());
        $this->assertSame(['Freins', 'Alarme'], $plan->interventions()->first()->checklist_snapshot);
        $request = $this->request($c, 'submitted');
        $request->update(['submitted_at' => now()->subHours(3), 'ack_deadline_at' => now()->subHour(), 'schedule_deadline_at' => now()->addHour(), 'resolution_deadline_at' => now()->addDay()]);
        app(MaintenanceSlaService::class)->evaluate();
        app(MaintenanceSlaService::class)->evaluate();
        $this->assertSame(1, $request->slaEvents()->count());
    }

    public function test_resident_privacy_and_cross_scope_routes_are_enforced(): void
    {
        $c = $this->context();
        $resident = User::factory()->create(['preferred_language' => 'ar']);
        $c['organization']->users()->attach($resident, ['role' => 'coproprietaire', 'all_residences' => false]);
        $c['residence']->users()->attach($resident);
        $resident->update(['current_organization_id' => $c['organization']->id, 'current_residence_id' => $c['residence']->id]);
        $own = $this->request($c, 'submitted', $resident);
        $own->update(['contact_details' => '0600000000']);
        $other = $this->request($c, 'submitted');
        $own->updates()->create(['author_id' => $c['user']->id, 'visibility' => 'internal', 'body' => 'Code porte secret']);
        $own->updates()->create(['author_id' => $c['user']->id, 'visibility' => 'resident', 'body' => 'Technicien programmé']);
        $this->actingAs($resident)->get(route('portal.maintenance.show', $own))->assertOk()->assertInertia(fn ($page) => $page->missing('maintenanceRequest.contact_details')->has('maintenanceRequest.updates', 1)->where('maintenanceRequest.updates.0.body', 'Technicien programmé'));
        $this->actingAs($resident)->get(route('portal.maintenance.show', $other))->assertNotFound();
        $this->actingAs($resident)->get(route('maintenance.requests.index'))->assertForbidden();
    }

    public function test_resident_request_creation_redirects_to_the_resident_portal(): void
    {
        $c = $this->context();
        $resident = User::factory()->create(['preferred_language' => 'fr']);
        $c['organization']->users()->attach($resident, ['role' => 'coproprietaire', 'all_residences' => false]);
        $c['residence']->users()->attach($resident);
        $resident->update(['current_organization_id' => $c['organization']->id, 'current_residence_id' => $c['residence']->id]);

        $response = $this->actingAs($resident)->post(route('portal.maintenance.store'), [
            'maintenance_category_id' => $c['category']->id,
            'title' => 'Fuite dans le couloir',
            'description' => 'Une fuite est visible près de la cage d’escalier.',
        ]);

        $created = MaintenanceRequest::latest('id')->firstOrFail();
        $response->assertRedirect(route('portal.maintenance.show', $created));
    }

    public function test_phase_four_pages_authorization_and_notification_locales(): void
    {
        $c = $this->context();
        foreach (['maintenance.dashboard', 'maintenance.requests.index', 'maintenance.requests.create', 'maintenance.work-orders.index', 'maintenance.preventive.index', 'maintenance.equipment.index', 'maintenance.categories.index', 'maintenance.operations.kanban', 'maintenance.operations.calendar', 'maintenance.operations.reports'] as $route) {
            $this->actingAs($c['user'])->get(route($route))->assertOk();
        }
        $request = $this->request($c);
        app(MaintenanceRequestWorkflow::class)->transition($request, 'submitted', $c['user'], null, 'notify-submit');
        Notification::assertSentTo($c['user'], PortalNotification::class, fn ($notification) => $notification->payload['language'] === 'fr' && ! array_key_exists('cost', $notification->payload));
    }

    public function test_defaults_and_private_attachments_are_idempotent_scoped_and_no_store(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $request = $this->request($c);
        $this->actingAs($c['user'])->post(route('maintenance.categories.seed'))->assertRedirect();
        $count = MaintenanceCategory::where('organization_id', $c['organization']->id)->count();
        $this->post(route('maintenance.categories.seed'))->assertRedirect();
        $this->assertSame($count, MaintenanceCategory::where('organization_id', $c['organization']->id)->count());
        $this->post(route('maintenance.attachments.store', ['type' => 'request', 'id' => $request->id]), ['file' => UploadedFile::fake()->createWithContent('preuve.pdf', '%PDF-1.4 evidence'), 'kind' => 'internal', 'visibility' => 'internal'])->assertRedirect();
        $attachment = $request->attachments()->firstOrFail();
        $download = $this->get(route('maintenance.attachments.download', $attachment))->assertOk()->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('no-store', $download->headers->get('cache-control'));
        $other = $this->context();
        $this->actingAs($other['user'])->get(route('maintenance.attachments.download', $attachment))->assertNotFound();
        Storage::disk('local')->put($attachment->path, 'tampered');
        $this->actingAs($c['user'])->get(route('maintenance.attachments.download', $attachment))->assertStatus(409);
        $this->post(route('maintenance.attachments.store', ['type' => 'request', 'id' => $request->id]), ['file' => UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream'), 'kind' => 'evidence', 'visibility' => 'resident'])->assertSessionHasErrors('file');
    }

    public function test_resident_cannot_replace_an_internal_attachment_or_use_a_forged_extension(): void
    {
        Storage::fake('local');
        $c = $this->context();
        $resident = User::factory()->create();
        $c['organization']->users()->attach($resident, ['role' => 'coproprietaire', 'all_residences' => false]);
        $c['residence']->users()->attach($resident);
        $resident->update(['current_organization_id' => $c['organization']->id, 'current_residence_id' => $c['residence']->id]);
        $request = $this->request($c, 'draft', $resident);

        $this->actingAs($c['user'])->post(route('maintenance.attachments.store', ['type' => 'request', 'id' => $request->id]), [
            'file' => UploadedFile::fake()->createWithContent('interne.pdf', '%PDF-1.4 internal'),
            'kind' => 'internal',
            'visibility' => 'internal',
        ])->assertRedirect();
        $internal = $request->attachments()->firstOrFail();

        $this->actingAs($resident)->post(route('maintenance.attachments.store', ['type' => 'request', 'id' => $request->id]), [
            'file' => UploadedFile::fake()->createWithContent('preuve.pdf', '%PDF-1.4 resident'),
            'kind' => 'evidence',
            'visibility' => 'resident',
            'replaces_id' => $internal->id,
        ])->assertNotFound();
        $this->assertNull($internal->fresh()->archived_at);

        $this->actingAs($resident)->post(route('maintenance.attachments.store', ['type' => 'request', 'id' => $request->id]), [
            'file' => UploadedFile::fake()->createWithContent('preuve.exe', '%PDF-1.4 forged extension'),
            'kind' => 'evidence',
            'visibility' => 'resident',
        ])->assertSessionHasErrors('file');
    }

    public function test_accepted_quotation_replacement_requires_reason_and_preserves_history(): void
    {
        $c = $this->context();
        $request = $this->request($c, 'approved');
        $accepted = MaintenanceQuotation::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'maintenance_request_id' => $request->id, 'supplier_id' => $c['supplier']->id, 'subtotal_cents' => 10000, 'tax_cents' => 0, 'total_cents' => 10000, 'submitted_on' => today()]);
        $replacement = MaintenanceQuotation::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'maintenance_request_id' => $request->id, 'supplier_id' => $c['supplier']->id, 'subtotal_cents' => 12000, 'tax_cents' => 0, 'total_cents' => 12000, 'submitted_on' => today()]);
        $workflow = app(MaintenanceQuotationWorkflow::class);
        $workflow->accept($accepted, $c['user']);
        $workflow->replace($accepted, $replacement, $c['user'], 'Portée corrigée');

        $this->assertSame('rejected', $accepted->fresh()->status);
        $this->assertSame('Portée corrigée', $accepted->fresh()->end_reason);
        $this->assertSame('accepted', $replacement->fresh()->status);
        $this->assertSame(1, $request->quotations()->where('status', 'accepted')->count());
    }

    public function test_equipment_detail_and_schedule_change_are_scoped_and_audited(): void
    {
        $c = $this->context();
        $equipment = MaintenanceEquipment::create(['organization_id' => $c['organization']->id, 'residence_id' => $c['residence']->id, 'maintenance_category_id' => $c['category']->id, 'name' => 'Pompe', 'condition' => 'good']);
        $this->actingAs($c['user'])->get(route('maintenance.equipment.show', $equipment))->assertOk()->assertInertia(fn ($page) => $page->where('equipment.id', $equipment->id));

        $request = $this->request($c, 'approved');
        $order = app(MaintenanceWorkOrderWorkflow::class)->create(['maintenance_request_id' => $request->id, 'supplier_id' => $c['supplier']->id, 'scope_of_work' => 'Inspection'], $c['user']);
        $this->put(route('maintenance.work-orders.schedule', $order), ['planned_start_at' => '2026-08-01 09:00:00', 'planned_end_at' => '2026-08-01 10:00:00', 'reason' => 'Accès fournisseur'])->assertRedirect();
        $this->assertSame('2026-08-01 09:00:00', $order->fresh()->planned_start_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('activity_log', ['subject_type' => MaintenanceWorkOrder::class, 'subject_id' => $order->id, 'description' => 'maintenance_work_order.rescheduled']);
    }
}
